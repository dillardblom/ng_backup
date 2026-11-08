#!/bin/bash
# The update from a released version to this working tree, on a server that was backing up.
# Usage: upgrade-from-release.sh <release package .tar.gz> [nextcloud-image-tag] [sqlite|pgsql] [new-version]
#   e.g. upgrade-from-release.sh ~/dev-local/release/ng_backup-1.0.3/ng_backup-1.0.3.tar.gz 34-apache pgsql 1.1.0
#
# The old version gets a key, a confirmed kit, a location with a daily time and one backup. Then the
# app files are replaced by this working tree (version raised to new-version when given) and
# occ upgrade runs. After it: the location keeps its time as its own schedule, the old restore point
# is listed, verifies and restores a file byte for byte, a new backup runs, the location gets its
# settings file, and the key counts as used.
set -euo pipefail
PKG=${1:?release package}
IMAGE="nextcloud:${2:-34-apache}"
DB=${3:-sqlite}
NEWVER=${4:-}
APP=$(cd "$(dirname "$0")/../.." && pwd)
N=ngb-up; NET=ngb-up-net
SHARED=$(mktemp -d)
NEWAPP=$(mktemp -d)
chmod 777 "$SHARED"
PASS='upgrade test passphrase 2026'
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }
cleanup() {
	docker rm -fv $N $N-db >/dev/null 2>&1 || true
	docker network rm $NET >/dev/null 2>&1 || true
	docker run --rm -v "$SHARED:/target" alpine sh -c 'rm -rf /target/*' >/dev/null 2>&1 || true
	rm -rf "$NEWAPP"
	rmdir "$SHARED" 2>/dev/null || true
	return 0
}
trap cleanup EXIT
occ() { docker exec -u www-data $N php occ "$@"; }

docker network create $NET >/dev/null
env=(-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin-upgrade-pw)
case $DB in
	sqlite) env+=(-e SQLITE_DATABASE=nextcloud) ;;
	pgsql) docker run -d --name $N-db --network $NET -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nc -e POSTGRES_PASSWORD=nc postgres:16-alpine >/dev/null
		env+=(-e POSTGRES_HOST=$N-db -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nc -e POSTGRES_PASSWORD=nc)
		sleep 8 ;;
	*) fail "unknown database $DB" ;;
esac
docker run -d --name $N --network $NET "${env[@]}" -v "$SHARED:/mnt/shared" "$IMAGE" >/dev/null
for i in $(seq 1 90); do occ status 2>/dev/null | grep -q "installed: true" && break; sleep 3; done
docker exec $N chown www-data:www-data /var/www/html/custom_apps
docker exec -i -u www-data $N tar xz -C /var/www/html/custom_apps < "$PKG"
OLDVER=$(docker exec $N sed -n 's#.*<version>\(.*\)</version>.*#\1#p' /var/www/html/custom_apps/ng_backup/appinfo/info.xml)
occ app:enable ng_backup >/dev/null || fail "enable $OLDVER"
ok "NG Backup $OLDVER on $(occ -V) / $DB"

# The old version at work: key, kit, a location with a daily time, a user file, one backup
docker exec $N sh -c "printf '%s\n' '$PASS' > /tmp/pass; chmod 644 /tmp/pass; mkdir -p /mnt/shared/loc; chown www-data /mnt/shared/loc"
occ backup:key:init --passphrase-file=/tmp/pass >/dev/null || fail "key init"
occ backup:key:kit --output=/tmp/kit.json >/dev/null
CODE=$(occ backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
occ backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
occ config:app:set ng_backup schedule_time --value=03:15 >/dev/null
occ backup:target:add offsite local -a null::null -o datadir=/mnt/shared/loc --path=repo >/dev/null || fail "target add"
docker exec -u www-data $N sh -c 'mkdir -p data/admin/files/Docs && head -c 3000000 /dev/urandom > data/admin/files/Docs/before.bin'
occ files:scan admin >/dev/null
SUM=$(docker exec $N sha256sum /var/www/html/data/admin/files/Docs/before.bin | cut -d' ' -f1)
occ backup:run offsite >/dev/null || fail "backup with $OLDVER"
OLDSNAP=$(occ backup:list offsite | grep -o '[0-9a-f]\{32\}' | head -1)
[ -n "$OLDSNAP" ] || fail "no restore point with $OLDVER"
ok "$OLDVER: key, kit, location at 03:15, restore point $OLDSNAP"

# The update: this working tree's runtime files in place of the old ones
mkdir -p "$NEWAPP/ng_backup/js"
cp -r "$APP/appinfo" "$APP/lib" "$APP/templates" "$APP/img" "$APP/css" "$NEWAPP/ng_backup/"
cp "$APP"/js/*.mjs "$NEWAPP/ng_backup/js/"
[ -n "$NEWVER" ] && sed -i "s#<version>.*</version>#<version>$NEWVER</version>#" "$NEWAPP/ng_backup/appinfo/info.xml"
docker exec -u www-data $N rm -rf /var/www/html/custom_apps/ng_backup
tar c -C "$NEWAPP" ng_backup | docker exec -i -u www-data $N tar x -C /var/www/html/custom_apps
OUT=$(occ upgrade 2>&1) || { printf '%s\n' "$OUT"; fail "occ upgrade"; }
VER=$(occ app:list --output=json | php -r '$a = json_decode(stream_get_contents(STDIN), true); echo $a["enabled"]["ng_backup"] ?? "";')
[ -z "$NEWVER" ] || [ "$VER" = "$NEWVER" ] || fail "after the upgrade the app has version '$VER'"
ok "occ upgrade to $VER"

LIST=$(occ backup:target:list)
printf '%s\n' "$LIST" | grep offsite | grep -q "03:15" || { printf '%s\n' "$LIST"; fail "the location did not keep 03:15 as its schedule"; }
occ backup:list offsite | grep -q "$OLDSNAP" || fail "the old restore point is not listed"
occ backup:verify offsite "$OLDSNAP" >/dev/null || fail "the old restore point does not verify"
occ backup:status >/dev/null || fail "backup:status"
docker exec $N sh -c 'rm -rf /tmp/rst && mkdir /tmp/rst && chown www-data /tmp/rst'
occ backup:restore offsite "$OLDSNAP" data/admin/files/Docs/before.bin --to-directory=/tmp/rst >/dev/null || fail "restore from the old restore point"
GOT=$(docker exec $N sh -c 'find /tmp/rst -name before.bin -exec sha256sum {} \;' | cut -d' ' -f1)
[ "$GOT" = "$SUM" ] || fail "the restored file differs"
ok "after the upgrade: schedule kept, old restore point listed, verified, a file restored byte for byte"

docker exec -u www-data $N sh -c 'head -c 2000000 /dev/urandom > data/admin/files/Docs/after.bin'
occ files:scan admin >/dev/null
occ backup:run offsite >/dev/null || fail "backup after the upgrade"
[ "$(occ backup:list offsite | grep -o '[0-9a-f]\{32\}' | sort -u | wc -l)" -ge 2 ] || fail "no new restore point after the upgrade"
docker exec $N test -f /mnt/shared/loc/repo/settings || fail "the location got no settings file"
OUT=$(occ backup:key:import /tmp/kit.json --passphrase-file=/tmp/pass --replace-unused-key 2>&1) && fail "a used key from before the upgrade was offered for replacement"
printf '%s\n' "$OUT" | grep -q "never replaced" || { printf '%s\n' "$OUT"; fail "the refusal does not say the key is never replaced"; }
ok "a new backup runs, the location got its settings, the key counts as used"
echo "UPGRADE $OLDVER -> ${VER:-working tree} PASS"
