#!/bin/bash
# Restore of single files and folders with server-side encryption (#33): what comes back must be
# the original content (not the encrypted bytes, encrypted once more), stored encrypted again by
# Nextcloud, and editable afterwards. Master key encryption (the default); per-user keys must be
# refused with a clear message.
# Usage: tests/integration/encrypted-restore.sh [nextcloud-version]
set -euo pipefail
V=${1:-35}
APP=$(cd "$(dirname "$0")/../.." && pwd)
NC=ngb-sse-nc
DATA=/var/www/html/data
U=alice
PW='Sse-restore-pw-2026'

cleanup() { docker rm -fv "$NC" >/dev/null 2>&1 || true; }
trap '[ -n "${KEEP:-}" ] || cleanup' EXIT
fail() { echo "FAIL: $*"; docker exec "$NC" sh -c "tail -c 3000 $DATA/nextcloud.log" 2>/dev/null | tr ',' '\n' | grep '"message"' | tail -4; exit 1; }
ok() { echo "ok   $*"; }
occ() { docker exec -u www-data "$NC" php occ "$@"; }
dav() { docker exec "$NC" curl -sf -u "$U:$PW" "http://localhost/remote.php/dav/files/$U/$1"; }
sum() { docker exec "$NC" sh -c "curl -sf -u '$U:$PW' 'http://localhost/remote.php/dav/files/$U/$1' | md5sum | cut -d' ' -f1"; }

docker run -d --name "$NC" -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin-sse-pw \
	-e NEXTCLOUD_TRUSTED_DOMAINS=localhost -e SQLITE_DATABASE=nextcloud \
	-v "$APP:/var/www/html/custom_apps/ng_backup:ro" "nextcloud:$V-apache" >/dev/null
for i in $(seq 1 90); do occ status 2>/dev/null | grep -q "installed: true" && break; sleep 4; done
docker exec "$NC" chown www-data:www-data /var/www/html/custom_apps
echo "=== $(occ -V)"
occ app:enable encryption >/dev/null
occ encryption:enable >/dev/null
occ app:enable ng_backup >/dev/null
sleep 8 # APCu app config cache
occ config:system:set skeletondirectory --value='' >/dev/null
docker exec -u www-data "$NC" sh -c "OC_PASS='$PW' php occ user:add --password-from-env $U >/dev/null"
occ encryption:status | grep -q "enabled: true" || fail "encryption not on"

# Files through WebDAV, so Nextcloud encrypts them: one written several times (encryption version
# above 1), an empty one, one larger than a block, and a folder with a subfolder.
put() { docker exec "$NC" sh -c "curl -sf -u '$U:$PW' -T '$2' 'http://localhost/remote.php/dav/files/$U/$1'" >/dev/null || fail "upload $1"; }
mkcol() { docker exec "$NC" curl -sf -u "$U:$PW" -X MKCOL "http://localhost/remote.php/dav/files/$U/$1" >/dev/null || fail "mkcol $1"; }
docker exec "$NC" sh -c 'head -c 300000 /dev/urandom > /tmp/big.bin; echo first > /tmp/v1; echo second version > /tmp/v2; echo third and last version > /tmp/v3; : > /tmp/empty; echo note > /tmp/note.txt'
mkcol Docs; mkcol Docs/Sub
put Docs/report.txt /tmp/v1; put Docs/report.txt /tmp/v2; put Docs/report.txt /tmp/v3
put Docs/big.bin /tmp/big.bin; put Docs/empty.txt /tmp/empty; put Docs/Sub/note.txt /tmp/note.txt
docker exec "$NC" sh -c "head -c 7 $DATA/$U/files/Docs/report.txt" | grep -q HBEGIN || fail "the file is not encrypted on disk"
VERSION=$(docker exec -u www-data "$NC" php -r '$db = new PDO("sqlite:/var/www/html/data/nextcloud.db"); echo $db->query("SELECT encrypted FROM oc_filecache WHERE path = \"files/Docs/report.txt\"")->fetchColumn();')
[ "$VERSION" -gt 1 ] || fail "test file has encryption version $VERSION, expected more than 1"
ok "encrypted test files (report.txt at encryption version $VERSION)"
SUM_REPORT=$(sum Docs/report.txt); SUM_BIG=$(sum Docs/big.bin); SUM_NOTE=$(sum Docs/Sub/note.txt)

docker exec -u www-data "$NC" sh -c "printf 'sse passphrase 2026 test\n' > /tmp/p && mkdir -p /tmp/ngb-sse-target"
occ backup:key:init --passphrase-file=/tmp/p --label=CI --delete-delay-days=7 >/dev/null || fail "key init"
CODE=$(occ backup:key:kit | docker exec -i "$NC" php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
occ backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
occ backup:target:add ci local -a null::null -o datadir=/tmp/ngb-sse-target --path=repo >/dev/null || fail "target add"
occ backup:run ci -l sse >/dev/null || fail "backup"
LIST=$(occ backup:list ci); SNAP=$(echo "$LIST" | awk -F'|' '/ sse /{gsub(/ /,"",$3); print $3; exit}')
[ -n "$SNAP" ] || fail "snapshot not listed"
ok "backup"

occ backup:restore ci "$SNAP" "data/$U/files/Docs/report.txt" -m new-folder || fail "restore one file"
R=$(docker exec "$NC" sh -c "cd $DATA/$U/files && ls -d Restored\ * | head -1")
[ "$(sum "$(docker exec "$NC" php -r 'echo rawurlencode($argv[1]);' "$R")/Docs/report.txt")" = "$SUM_REPORT" ] || fail "the restored file is not the original: $(dav "$(docker exec "$NC" php -r 'echo rawurlencode($argv[1]);' "$R")/Docs/report.txt" | head -c 60)"
docker exec "$NC" sh -c "head -c 7 '$DATA/$U/files/$R/Docs/report.txt'" | grep -q HBEGIN || fail "the restored file is not encrypted on disk"
ok "one file: original content, encrypted again on disk"
docker exec "$NC" sh -c "cd $DATA/$U/files && ls -a '$R/Docs'" | grep -q ngb-restore && fail "a temporary was left behind"
ok "no temporaries left"
docker exec -u www-data "$NC" sh -c "rm -rf '$DATA/$U/files/$R'" && occ files:scan $U >/dev/null

occ backup:restore ci "$SNAP" "data/$U/files/Docs" -m new-folder >/dev/null || fail "restore a folder"
R=$(docker exec "$NC" sh -c "cd $DATA/$U/files && ls -d Restored\ * | head -1")
E=$(docker exec "$NC" php -r 'echo rawurlencode($argv[1]);' "$R")
[ "$(sum "$E/Docs/report.txt")" = "$SUM_REPORT" ] || fail "folder: report.txt"
[ "$(sum "$E/Docs/big.bin")" = "$SUM_BIG" ] || fail "folder: big.bin"
[ "$(sum "$E/Docs/Sub/note.txt")" = "$SUM_NOTE" ] || fail "folder: Sub/note.txt"
[ "$(dav "$E/Docs/empty.txt" | wc -c)" = 0 ] || fail "folder: empty.txt is not empty"
ok "a folder: every file, the empty one and the subfolder"

# Edit a restored file: it is a normal file now.
put "$E/Docs/report.txt" /tmp/v1
[ "$(dav "$E/Docs/report.txt")" = first ] || fail "the restored file cannot be changed"
ok "a restored file can be edited"
[ "$(docker exec "$NC" sh -c "find $DATA/$U -path '*trashbin*' -name '*ngb-restore*' | wc -l")" = 0 ] || fail "temporaries of the encrypted files went to the trash"
[ "$(docker exec "$NC" sh -c "find $DATA/$U -name '*ngb-restore*' | wc -l")" = 0 ] || fail "temporaries or their keys were left behind"
ok "new-folder restores leave nothing in the trash or on disk"

# Into the original place: the current content becomes a version.
put Docs/report.txt /tmp/v1
occ backup:restore ci "$SNAP" "data/$U/files/Docs/report.txt" -m merge >/dev/null || fail "restore in place"
[ "$(sum Docs/report.txt)" = "$SUM_REPORT" ] || fail "restore in place: not the original content"
ok "restore in place over a changed file"

# An encrypted file whose keys did not make it into the backup: refused, never written as
# encrypted bytes encrypted once more.
put Docs/nokeys.txt /tmp/v2
docker exec "$NC" rm -rf "$DATA/$U/files_encryption/keys/files/Docs/nokeys.txt"
occ backup:run ci -l nokeys >/dev/null || fail "backup without the keys of one file"
LIST=$(occ backup:list ci); SNAP2=$(echo "$LIST" | awk -F'|' '/ nokeys /{gsub(/ /,"",$3); print $3; exit}')
OUT=$(occ backup:restore ci "$SNAP2" "data/$U/files/Docs/nokeys.txt" -m new-folder 2>&1) && fail "restored an encrypted file without its keys: $OUT"
echo "$OUT" | grep -qi "keys" || fail "no clear message for missing keys: $OUT"
docker exec "$NC" sh -c "find $DATA/$U/files -name nokeys.txt -path '*Restored*'" | grep -q . && fail "the file without keys was written anyway"
ok "an encrypted file without its keys is refused"

# Per-user keys: refused with a clear message, nothing written.
occ config:app:set encryption useMasterKey --value=0 >/dev/null
sleep 8
OUT=$(occ backup:restore ci "$SNAP" "data/$U/files/Docs/report.txt" -m new-folder 2>&1) && fail "restored with per-user keys"
echo "$OUT" | grep -q "per-user keys" || fail "no clear message for per-user keys: $OUT"
occ config:app:set encryption useMasterKey --value=1 >/dev/null
ok "per-user keys: refused with a clear message"
echo "PASS: encrypted restore on NC $V"
