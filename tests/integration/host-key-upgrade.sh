#!/bin/bash
# Pinned SFTP host keys across a Nextcloud upgrade (#34). Nextcloud 32-34 (phpseclib 2) connect
# with the server's RSA key, Nextcloud 35 (phpseclib 3) with its Ed25519 key. A location with all
# of the server's keys pinned (what NG Backup pins when a location is added) keeps working after
# the upgrade; a location with only the RSA key pinned is refused after it, with the Ed25519
# fingerprint in the error, until an admin accepts the server's keys.
# Usage: tests/integration/host-key-upgrade.sh [from-version] [to-version]
set -euo pipefail
FROM=${1:-34}
TO=${2:-35}
APP=$(cd "$(dirname "$0")/../.." && pwd)
NET=ngb-up-net
NC=ngb-up-nc
HTML=ngb-up-html
SFTP=ngb-up-sftp

cleanup() {
	docker rm -fv "$NC" "$SFTP" >/dev/null 2>&1 || true
	docker volume rm "$HTML" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }
occ() { docker exec -u www-data "$NC" php occ "$@"; }

start_nc() { # image tag; the installation lives in the volume, so a newer image upgrades it
	docker run -d --name "$NC" --network "$NET" \
		-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin-upgrade-pw -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
		-e SQLITE_DATABASE=nextcloud -v "$HTML:/var/www/html" \
		-v "$APP:/var/www/html/custom_apps/ng_backup:ro" "nextcloud:$1-apache" >/dev/null
	for i in $(seq 1 120); do
		S=$(occ status 2>/dev/null || true)
		echo "$S" | grep -q "installed: true" && echo "$S" | grep -q "versionstring: $1\." && echo "$S" | grep -q "maintenance: false" \
			&& echo "$S" | grep -q "needsDbUpgrade: false" && break
		sleep 4
	done
	occ status | grep -q "versionstring: $1\." || fail "Nextcloud $1 did not come up: $(occ status 2>&1 | tail -3)"
	echo "=== $(occ -V)"
}

docker network create "$NET" >/dev/null
docker run -d --name "$SFTP" --network "$NET" atmoz/sftp:alpine ngbackup:ngbackuppw:::upload >/dev/null
for i in $(seq 1 30); do docker exec "$SFTP" test -f /etc/ssh/ssh_host_ed25519_key.pub 2>/dev/null && break; sleep 1; done
RSA=$(docker exec "$SFTP" ssh-keygen -lf /etc/ssh/ssh_host_rsa_key.pub | awk '{print $2}')
ED=$(docker exec "$SFTP" ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub | awk '{print $2}')

start_nc "$FROM"
docker exec "$NC" chown www-data:www-data /var/www/html/custom_apps
occ app:enable ng_backup >/dev/null
occ app:enable files_external >/dev/null
sleep 8 # APCu app config cache
docker exec -u www-data "$NC" sh -c "printf 'upgrade passphrase 2026\n' > /var/www/html/data/ngb-up-pass"
occ backup:key:init --passphrase-file=/var/www/html/data/ngb-up-pass --label=CI --delete-delay-days=7 >/dev/null || fail "key init"
CODE=$(occ backup:key:kit | docker exec -i "$NC" php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
occ backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
LOCATION=(sftp -a password::password -o host=$SFTP -o root=/upload -o user=ngbackup -o password=ngbackuppw)

# "all": pinned the way NG Backup does it when a location is added (all of the server's keys).
echo y | docker exec -i -u www-data "$NC" php occ backup:target:add all "${LOCATION[@]}" --path=all >/dev/null || fail "add all"
SHOW=$(occ backup:target:host-key all) || fail "host-key all: $SHOW"
echo "$SHOW" | sed -n '/^Pinned:/,/^The server/p' | grep -qF "$ED" || fail "the Ed25519 key was not pinned on Nextcloud $FROM: $SHOW"
# "rsa": only the key Nextcloud $FROM itself connects with.
occ backup:target:add rsa "${LOCATION[@]}" --path=rsa --host-key-fingerprint="$RSA" -n >/dev/null || fail "add rsa"
occ backup:run all >/dev/null || fail "backup all on $FROM"
occ backup:run rsa >/dev/null || fail "backup rsa on $FROM: Nextcloud $FROM does not connect with the RSA key"
ok "Nextcloud $FROM: both locations back up (RSA key); all keys were shown and pinned when adding"

docker rm -f "$NC" >/dev/null
start_nc "$TO"
occ app:list --enabled | grep -q ng_backup || fail "NG Backup is not enabled after the upgrade"
occ backup:run all >/dev/null || fail "backup to the location with all keys pinned after the upgrade"
ok "Nextcloud $TO: the location with all keys pinned goes on (Ed25519 key)"

OUT=$(occ backup:run rsa 2>&1) && fail "the location with only the RSA key pinned accepted the Ed25519 key"
echo "$OUT" | grep -q "does not match the pinned key" || fail "no clear error: $OUT"
echo "$OUT" | grep -qF "$ED" || fail "the received Ed25519 fingerprint is not in the error: $OUT"
echo "$OUT" | grep -qF "$RSA" || fail "the expected fingerprint is not in the error: $OUT"
ok "Nextcloud $TO: the location with only the RSA key is refused, error names both fingerprints"
occ backup:target:host-key rsa --pin -n >/dev/null || fail "accept the server's keys"
occ backup:run rsa >/dev/null || fail "backup after the keys were accepted"
ok "after accepting the server's keys (admins notified) the backup goes on"
echo "PASS: host keys across the upgrade $FROM -> $TO"
