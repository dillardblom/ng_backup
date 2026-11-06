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
# large.bin (40 MB) fills more than one pack: a restore to a directory reads ahead over several ranges
$X sh -c "mkdir -p $DATA/$U/files/Docs/Sub $DATA/$U/files/Docs/Empty/Nested $DATA/$U/files/Hollow && touch -d @1700000000 $DATA/$U/files/Hollow && for i in 1 2 3 4 5; do head -c 300000 /dev/urandom > $DATA/$U/files/Docs/f\$i.bin; done; echo hello > $DATA/$U/files/Docs/Sub/note.txt; head -c 6000000 /dev/urandom > $DATA/$U/files/big.bin; head -c 40000000 /dev/urandom > $DATA/$U/files/large.bin"
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
$X sh -c "cd $DATA/$U/files && rm Docs/Sub/note.txt && echo broken > Docs/f1.bin && echo extra > Docs/extra.txt && rm -r Docs/Empty && rmdir Hollow"
occ files:scan "$U" >/dev/null
occ backup:restore ci "$SNAP" "data/$U/files/Docs" -m new-folder >/dev/null || fail "restore new-folder"
$X sh -c "ls $DATA/$U/files | grep -q '^Restored '" || fail "no Restored folder"
$X sh -c "test -d $DATA/$U/files/Restored\ */Docs/Empty/Nested" || fail "the empty folder is not in the Restored folder"
occ backup:browse ci "$SNAP" "data/$U/files" | grep Hollow >/dev/null || fail "an empty folder is not shown when browsing the snapshot"
occ backup:restore ci "$SNAP" "data/$U/files/Hollow" -m merge >/dev/null || fail "restore of a folder that was empty"
$X sh -c "test -d $DATA/$U/files/Hollow" || fail "the empty folder did not come back by itself"
[ "$($X stat -c %Y "$DATA/$U/files/Hollow")" = 1700000000 ] || fail "the empty folder did not get its time back"
occ backup:restore ci "$SNAP" "data/$U/files/Docs" -m replace >/dev/null || fail "restore replace"
$X sh -c "test -d $DATA/$U/files/Docs/Empty/Nested" || fail "replace did not bring back the empty folder, or moved it to the trash"
ok "empty folders: restored in each mode, by themselves and shown when browsing"
$X sh -c "rm -rf '$DATA/$U/files/Restored '*" ; occ files:scan "$U" >/dev/null
SUM_AFTER=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")
[ "$SUM_BEFORE" = "$SUM_AFTER" ] || fail "files differ after restore"
$X sh -c "ls $DATA/$U/files_trashbin/files/ | grep -q '^extra.txt'" || fail "extra file not in trash"
ok "restore new-folder and replace: files identical, extra file in the trash"

# One file to a local directory, where a restore that was stopped left its temporary.
ONE=/tmp/ngb-rt-one/data/$U/files/Docs
$X sh -c "mkdir -p $ONE && echo partial > $ONE/f2.bin.ngb-restore-0123456789ab"
occ backup:restore ci "$SNAP" "data/$U/files/Docs/f2.bin" -d /tmp/ngb-rt-one | grep "Restored 1 files" >/dev/null || fail "restore of one file to a directory"
$X sh -c "cmp -s $ONE/f2.bin $DATA/$U/files/Docs/f2.bin" || fail "the file restored to a directory differs"
$X sh -c "ls -A $ONE | grep -q ngb-restore" && fail "the temporary of a stopped restore was left"
ok "restore of one file to a directory; the temporary of a stopped restore removed"

# All of the user's files to a directory: over S3 and SFTP the packs are read ahead
occ backup:restore ci "$SNAP" "data/$U/files" -d /tmp/ngb-rt-dir >/dev/null || fail "restore of a folder to a directory"
SUM_DIR=$($X sh -c "cd /tmp/ngb-rt-dir/data/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")
[ "$SUM_BEFORE" = "$SUM_DIR" ] || fail "the files restored to a directory differ"
$X sh -c "ls -A /tmp/ngb-rt-dir | grep -q ngb-restore-spool" && fail "the read-ahead spool was left in the directory"
$X sh -c "test -d /tmp/ngb-rt-dir/data/$U/files/Docs/Empty/Nested" || fail "the empty folder is not in the directory"
$X sh -c "rm -rf /tmp/ngb-rt-dir"
ok "restore of all the user's files to a directory: identical, no spool left"
# not grep -q: it stops reading at the first match, and occ, still writing, then fails (pipefail)
occ backup:status | grep "started (" >/dev/null || fail "backup:status does not name the time zone"
ok "backup:status names the time zone of its times"

# Without a system cron (#13): backup:status warns, and the open admin page moves a backup started
# with "Back up now" forward through /api/tick. Nothing runs cron in this container, so only the
# ticks below can finish this backup.
occ background:ajax >/dev/null
occ backup:status | grep "do not run on a system cron" >/dev/null || fail "backup:status gives no warning without system cron"
ADM=ngbadmin-$RANDOM; ADM_PW="Tick-admin-pw-$RANDOM-2026"
$X sh -c "OC_PASS='$ADM_PW' php occ user:add --password-from-env --group=admin $ADM >/dev/null"
api() { $X curl -sf -u "$ADM:$ADM_PW" -H 'OCS-APIRequest: true' -H 'Content-Type: application/json' "$@"; }
API=http://localhost/index.php/apps/ng_backup/api
STATUS=$(api "$API/status") || fail "status API"
echo "$STATUS" | php -r '$s = json_decode(stream_get_contents(STDIN), true); exit(!$s["cron"]["ok"] && $s["cron"]["mode"] === "ajax" && $s["cron"]["docs"] !== "" ? 0 : 1);' || fail "status API does not report the missing cron"
TID=$(echo "$STATUS" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["targets"][0]["id"];')
RUN=$(api -X POST -d "{\"targetId\": $TID, \"label\": \"page\"}" "$API/backups" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["id"];') || fail "start a backup through the API"
for i in $(seq 1 60); do
  api -X POST "$API/tick" >/dev/null || fail "tick"
  RS=$(api "$API/status" | php -r '$s = json_decode(stream_get_contents(STDIN), true); foreach ($s["runs"] as $r) { if ($r["id"] == '"$RUN"') { echo $r["status"]; } }')
  [ "$RS" = running ] || break
done
[ "$RS" = done ] || fail "the backup started through the API ended as '$RS' after $i ticks"
occ backup:list ci | grep " page " >/dev/null || fail "the snapshot of the ticked backup is not listed"
ok "without system cron: warning in backup:status and the API, backup finished by $i ticks of the open page"
# The Bin reads in parts, as the page does (what is in the parts: CatalogTest)
api "$API/targets/$TID/trash" | php -r '$p = json_decode(stream_get_contents(STDIN), true); exit(is_int($p["total"] ?? null) && is_array($p["items"] ?? null) && count($p["items"]) <= $p["total"] ? 0 : 1);' || fail "the Bin API does not give a total and items"
ok "the Bin API reads in parts, with a total"
occ background:cron >/dev/null
$X php -f /var/www/html/cron.php >/dev/null 2>&1 || true
occ backup:status | grep "system cron" >/dev/null && fail "backup:status still warns after cron ran"
sleep 8 # APCu app config cache of the web server
api "$API/status" | php -r 'exit(json_decode(stream_get_contents(STDIN), true)["cron"]["ok"] ? 0 : 1);' || fail "the status API still reports a missing cron after cron ran"
ok "with a system cron that ran: no warning in backup:status or the API"
occ user:delete "$ADM" >/dev/null

# Checksum audit: shallow (index presence) and deep (download, decrypt, verify every blob).
occ backup:verify ci "$SNAP" | grep "^OK$" >/dev/null || fail "shallow verify"
occ backup:verify ci "$SNAP" --deep | grep "^OK$" >/dev/null || fail "deep verify"
ok "shallow and deep checksum audit"

# Database dump/restore round trip (all tables, checksums, sequences on PostgreSQL).
$X php -d memory_limit=512M "$APPS/ng_backup/spikes/03-db-dump.php" /tmp/ngb-rt-db | grep "ALL PASS" >/dev/null || fail "database round trip"
ok "database dump and restore"

# Retention, trash and catalog.
occ backup:retention --last=1 --daily=0 --weekly=0 --monthly=0 >/dev/null
occ backup:prune ci | grep "forgot 2" >/dev/null || fail "prune" # first and second; "page" (above) is the last
occ backup:trash ci | grep " first " >/dev/null || fail "trash"
TID=$(occ backup:trash ci | awk -F'|' '!f && / first /{gsub(/ /,"",$3); print $3; f=1}')
occ backup:trash:restore ci "$TID" >/dev/null || fail "trash restore"
[ "$(occ backup:list ci | grep -c ' ci ')" = "2" ] || fail "snapshot not back from the trash"
ok "retention, trash and restore from trash"

# Damaged data on the location (last: the location is not used after this). A byte flipped every
# MiB of every pack hits big.bin (6 MB) for sure; a small file mostly survives. Without --salvage
# the restore stops and points at --salvage; with it, the rest is restored and the damage listed.
if [[ "$TARGET_ARGS" == local* ]]; then
  # "second" went to the trash above: the backup of the page is still there
  SNAP=$(occ backup:list ci | awk -F'|' '!f && / page /{gsub(/ /,"",$3); print $3; f=1}')
  [ -n "$SNAP" ] || fail "the snapshot of the ticked backup is not listed"
  $X php -r 'foreach (glob("/tmp/ngb-rt-target/repo/packs/*/*") as $p) { $h = fopen($p, "r+b"); for ($o = 4096; $o < filesize($p); $o += 1048576) { fseek($h, $o); $b = fread($h, 1); fseek($h, $o); fwrite($h, ~$b); } fclose($h); }'
  set +e
  OUT=$(occ backup:restore ci "$SNAP" "data/$U/files" -d /tmp/ngb-rt-salvage 2>&1); CODE=$?
  set -e
  [ "$CODE" = 1 ] || { echo "$OUT"; fail "a restore of damaged data did not stop (exit $CODE)"; }
  echo "$OUT" | grep -- "--salvage" >/dev/null || { echo "$OUT"; fail "the stopped restore does not point at --salvage"; }
  set +e
  OUT=$(occ backup:restore ci "$SNAP" "data/$U/files" -d /tmp/ngb-rt-salvage --salvage 2>&1); CODE=$?
  set -e
  [ "$CODE" = 2 ] || { echo "$OUT"; fail "restore --salvage of damaged data exited with $CODE, not 2"; }
  echo "$OUT" | grep "data/$U/files/big.bin: " >/dev/null || { echo "$OUT"; fail "the damaged big.bin is not listed"; }
  N=$(echo "$OUT" | sed -n 's/^Restored \([0-9]*\) files.*/\1/p')
  [ "${N:-0}" -ge 1 ] || { echo "$OUT"; fail "restore --salvage restored nothing"; }
  $X sh -c "test ! -e /tmp/ngb-rt-salvage/data/$U/files/big.bin" || fail "the damaged big.bin was written anyway"
  $X sh -c "find /tmp/ngb-rt-salvage -name '*.ngb-restore-*' | grep -q ." && fail "a temporary of a damaged file was left"
  ok "damaged data: the restore stops and points at --salvage; with --salvage $N files restored and the damaged ones listed (exit 2)"
  $X sh -c "rm -rf /tmp/ngb-rt-salvage"
fi

# No errors caused by loading files_external while it is disabled (its tables may not exist).
if $X sh -c "grep -q 'external_mounts' $DATA/nextcloud.log 2>/dev/null"; then fail "files_external table errors in the log"; fi
ok "no files_external errors in the log"

occ user:delete "$U" >/dev/null
$X sh -c "rm -rf /tmp/ngb-rt-target /tmp/ngb-rt-pass /tmp/ngb-rt-db"
echo "ROUNDTRIP PASS"
