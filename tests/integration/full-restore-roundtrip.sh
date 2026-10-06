#!/bin/bash
# Disaster recovery: occ backup:key:import + backup:target:add + backup:restore:full on a
# SECOND, fresh Nextcloud instance that never saw the first one, sharing only the backup
# location (a local directory, bind-mounted into both containers) -- see PLAN.md 3.7.
# Usage: full-restore-roundtrip.sh <nextcloud-image-tag>
#   e.g. full-restore-roundtrip.sh 35-apache
set -euo pipefail
IMAGE="nextcloud:${1:-35-apache}"
APP=$(cd "$(dirname "$0")/../.." && pwd)
NET=ngb-frt-net
SHARED=$(mktemp -d)
chmod 777 "$SHARED"
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }

cleanup() {
	docker rm -fv ngb-frt-origin ngb-frt-restore >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
	docker run --rm -v "$SHARED:/target" alpine sh -c 'rm -rf /target/*' >/dev/null 2>&1 || true
	rmdir "$SHARED" 2>/dev/null || true
	rm -f /tmp/ngb-frt-kit.json /tmp/ngb-frt-pass
}
trap cleanup EXIT

run_on() { docker exec -u www-data "$1" php occ "${@:2}"; }

docker network create "$NET" >/dev/null
docker run -d --name ngb-frt-origin --network "$NET" -e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
	-v "$APP:/var/www/html/custom_apps/ng_backup:ro" -v "$SHARED:/mnt/shared" "$IMAGE" >/dev/null
for i in $(seq 1 90); do run_on ngb-frt-origin status 2>/dev/null | grep -q "installed: true" && break; sleep 3; done
docker exec ngb-frt-origin chown www-data:www-data /var/www/html/custom_apps
run_on ngb-frt-origin app:enable ng_backup >/dev/null
ok "origin instance up"

docker exec ngb-frt-origin sh -c "printf 'full restore roundtrip passphrase 2026\n' > /tmp/pass"
run_on ngb-frt-origin backup:key:init --passphrase-file=/tmp/pass --label=CI >/dev/null || fail "key init"
run_on ngb-frt-origin backup:key:kit --output=/tmp/kit.json >/dev/null
CODE=$(run_on ngb-frt-origin backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
run_on ngb-frt-origin backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
run_on ngb-frt-origin backup:target:add offsite local -a null::null -o datadir=/mnt/shared --path=repo >/dev/null || fail "target add"
ok "key, kit and location on the origin"

NC_PASS=$(openssl rand -hex 16)
docker exec -u www-data -e OC_PASS="$NC_PASS" ngb-frt-origin php occ user:add --password-from-env frtest >/dev/null
docker exec ngb-frt-origin sh -c "mkdir -p /var/www/html/data/frtest/files/Docs && head -c 200000 /dev/urandom > /var/www/html/data/frtest/files/Docs/report.bin && echo 'hello disaster recovery' > /var/www/html/data/frtest/files/Docs/note.txt && chown -R www-data:www-data /var/www/html/data/frtest"
run_on ngb-frt-origin files:scan frtest >/dev/null
RUN_OUT=$(run_on ngb-frt-origin backup:run offsite -l disaster-test)
SNAP=$(printf '%s\n' "$RUN_OUT" | head -1 | awk -F'snapshot ' '{print $2}' | tr -d ',')
[ -n "$SNAP" ] || fail "no snapshot id from backup:run"
ok "test data backed up, snapshot $SNAP"

docker cp ngb-frt-origin:/tmp/kit.json /tmp/ngb-frt-kit.json
docker exec ngb-frt-origin sh -c "cat /tmp/pass" > /tmp/ngb-frt-pass
docker run -d --name ngb-frt-restore --network "$NET" -e SQLITE_DATABASE=nextcloud \
	-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
	-v "$APP:/var/www/html/custom_apps/ng_backup:ro" -v "$SHARED:/mnt/shared" "$IMAGE" >/dev/null
for i in $(seq 1 90); do run_on ngb-frt-restore status 2>/dev/null | grep -q "installed: true" && break; sleep 3; done
docker exec ngb-frt-restore chown www-data:www-data /var/www/html/custom_apps
run_on ngb-frt-restore app:enable ng_backup >/dev/null
docker cp /tmp/ngb-frt-kit.json ngb-frt-restore:/tmp/kit.json
docker exec ngb-frt-restore chmod 644 /tmp/kit.json
docker cp /tmp/ngb-frt-pass ngb-frt-restore:/tmp/pass
ok "fresh restore instance up"

run_on ngb-frt-restore backup:key:import /tmp/kit.json --passphrase-file=/tmp/pass >/dev/null || fail "key import"
run_on ngb-frt-restore backup:target:add offsite local -a null::null -o datadir=/mnt/shared --path=repo >/dev/null || fail "target add on the restore instance"
run_on ngb-frt-restore backup:list offsite | grep -q "$SNAP" || fail "the origin's snapshot is not listed after connecting the location"
ok "recovery kit imported, existing repository adopted, snapshot visible"

run_on ngb-frt-restore backup:restore:full offsite "$SNAP" --no-interaction >/dev/null || fail "restore:full"
ok "backup:restore:full completed"

# Everything below must work in a FRESH occ process (a new PHP process per docker exec), since
# earlier bugs here only showed up after config.php's secret was rewritten mid-restore.
run_on ngb-frt-restore backup:status >/dev/null || fail "backup:status fails after restore (own key/target undecryptable?)"
run_on ngb-frt-restore user:list | grep -q frtest || fail "the backed-up user is missing from the restored database"
docker exec ngb-frt-restore test -f /var/www/html/data/frtest/files/Docs/note.txt || fail "restored file is missing"
[ "$(docker exec ngb-frt-restore cat /var/www/html/data/frtest/files/Docs/note.txt)" = "hello disaster recovery" ] || fail "restored file content differs"
run_on ngb-frt-restore files:scan frtest >/dev/null || fail "files:scan fails after restore"
ok "database, files and ng_backup's own state are all intact and usable after the restore"

run_on ngb-frt-restore backup:run offsite -l post-restore-check >/dev/null || fail "a new backup after the restore fails"
ok "the restored instance can run a new backup of its own"

echo "FULL RESTORE ROUNDTRIP PASS"
