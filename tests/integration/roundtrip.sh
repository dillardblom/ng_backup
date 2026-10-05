#!/bin/bash
# End-to-end round trip of NG Backup inside a Nextcloud installation (CI and local matrix).
# Usage: roundtrip.sh "<command prefix to run occ/php as the web user in the instance>" <datadir> <apps-path> [target-args]
#   e.g. roundtrip.sh "docker exec -u www-data ngb-nc35-pgsql" /var/www/html/data /var/www/html/custom_apps
#   target-args defaults to a local files_external location; pass backend/auth/options to exercise
#   a different backend (e.g. amazons3 or sftp), see tests/integration/targets-matrix.sh.
set -euo pipefail
X="$1"; DATA="$2"; APPS="$3"
TARGET_ARGS="${4:-local -a null::null -o datadir=/tmp/ngb-rt-target}"
occ() { $X php occ "$@"; }
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }
U=ngbtest-$RANDOM

occ app:enable ng_backup >/dev/null
sleep 8 # APCu app config cache
$X sh -c "printf 'roundtrip passphrase 2026\n' > /tmp/ngb-rt-pass && mkdir -p /tmp/ngb-rt-target"
occ backup:key:init --passphrase-file=/tmp/ngb-rt-pass --label=CI --delete-delay-days=7 >/dev/null || fail "key init"
CODE=$(occ backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
occ backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
ok "key, kit code and confirmation"

occ backup:target:add ci $TARGET_ARGS --path=repo >/dev/null || fail "target add"
ok "location added"

# Test data: a user with files.
$X sh -c "OC_PASS='Roundtrip-pw-2026' php occ user:add --password-from-env $U >/dev/null"
$X sh -c "mkdir -p $DATA/$U/files/Docs/Sub && for i in 1 2 3 4 5; do head -c 300000 /dev/urandom > $DATA/$U/files/Docs/f\$i.bin; done; echo hello > $DATA/$U/files/Docs/Sub/note.txt; head -c 6000000 /dev/urandom > $DATA/$U/files/big.bin"
occ files:scan "$U" >/dev/null
SUM_BEFORE=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")

occ backup:run ci -l first >/dev/null || fail "first backup"
SECOND=$(occ backup:run ci -l second)
echo "$SECOND" | tail -2 | head -1
echo "$SECOND" | grep -qE "uploaded 0\.[0-9] MiB" || fail "unchanged second backup uploaded more than 1 MiB"
ok "full backup and unchanged incremental backup"
SNAP=$(occ backup:list ci | awk -F'|' '!f && / second /{gsub(/ /,"",$3); print $3; f=1}')
[ -n "$SNAP" ] || fail "snapshot not listed"

# Damage, then restore in each mode.
$X sh -c "cd $DATA/$U/files && rm Docs/Sub/note.txt && echo broken > Docs/f1.bin && echo extra > Docs/extra.txt"
occ files:scan "$U" >/dev/null
occ backup:restore ci "$SNAP" "data/$U/files/Docs" -m new-folder >/dev/null || fail "restore new-folder"
$X sh -c "ls $DATA/$U/files | grep -q '^Restored '" || fail "no Restored folder"
occ backup:restore ci "$SNAP" "data/$U/files/Docs" -m replace >/dev/null || fail "restore replace"
$X sh -c "rm -rf '$DATA/$U/files/Restored '*" ; occ files:scan "$U" >/dev/null
SUM_AFTER=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")
[ "$SUM_BEFORE" = "$SUM_AFTER" ] || fail "files differ after restore"
$X sh -c "ls $DATA/$U/files_trashbin/files/ | grep -q '^extra.txt'" || fail "extra file not in trash"
ok "restore new-folder and replace: files identical, extra file in the trash"

# Database dump/restore round trip (all tables, checksums, sequences on PostgreSQL).
$X php -d memory_limit=512M "$APPS/ng_backup/spikes/03-db-dump.php" /tmp/ngb-rt-db | grep "ALL PASS" >/dev/null || fail "database round trip"
ok "database dump and restore"

# Retention, trash and catalog.
occ backup:retention --last=1 --daily=0 --weekly=0 --monthly=0 >/dev/null
occ backup:prune ci | grep "forgot 1" >/dev/null || fail "prune"
occ backup:trash ci | grep " first " >/dev/null || fail "trash"
TID=$(occ backup:trash ci | awk -F'|' '!f && / first /{gsub(/ /,"",$3); print $3; f=1}')
occ backup:trash:restore ci "$TID" >/dev/null || fail "trash restore"
[ "$(occ backup:list ci | grep -c ' ci ')" = "2" ] || fail "snapshot not back from the trash"
ok "retention, trash and restore from trash"

# No errors caused by loading files_external while it is disabled (its tables may not exist).
if $X sh -c "grep -q 'external_mounts' $DATA/nextcloud.log 2>/dev/null"; then fail "files_external table errors in the log"; fi
ok "no files_external errors in the log"

occ user:delete "$U" >/dev/null
$X sh -c "rm -rf /tmp/ngb-rt-target /tmp/ngb-rt-pass /tmp/ngb-rt-db"
echo "ROUNDTRIP PASS"
