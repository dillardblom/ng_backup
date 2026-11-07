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

# Test data: a user with files. No skeleton files on the first login, below, in the middle of the checks.
occ config:system:set skeletondirectory --value='' >/dev/null
occ config:system:set templatedirectory --value='' >/dev/null
$X sh -c "OC_PASS='Roundtrip-pw-2026' php occ user:add --password-from-env $U >/dev/null"
# large.bin (40 MB) fills more than one pack: a restore to a directory reads ahead over several ranges
$X sh -c "mkdir -p $DATA/$U/files/Docs/Sub $DATA/$U/files/Docs/Empty/Nested $DATA/$U/files/Hollow && touch -d @1700000000 $DATA/$U/files/Hollow && for i in 1 2 3 4 5; do head -c 300000 /dev/urandom > $DATA/$U/files/Docs/f\$i.bin; done; echo hello > $DATA/$U/files/Docs/Sub/note.txt; head -c 6000000 /dev/urandom > $DATA/$U/files/big.bin; head -c 40000000 /dev/urandom > $DATA/$U/files/large.bin"
$X sh -c "touch -d @1600000000 $DATA/$U/files/Docs/Sub $DATA/$U/files/Docs"
occ files:scan "$U" >/dev/null
SUM_BEFORE=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum")

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
# Folders with files: on disk and in what Nextcloud shows, the parent as well as the child.
RDIR=$($X sh -c "cd $DATA/$U/files && ls -d Restored\ *")
[ "$($X sh -c "stat -c %Y $DATA/$U/files/Restored\ */Docs/Sub")" = 1600000000 ] || fail "a folder with files did not get its time back"
[ "$($X sh -c "stat -c %Y $DATA/$U/files/Restored\ */Docs")" = 1600000000 ] || fail "the parent folder did not get its time back"
DAV=$($X curl -sf -u "$U:Roundtrip-pw-2026" -X PROPFIND -H 'Depth: 1' "http://localhost/remote.php/dav/files/$U/$(php -r 'echo rawurlencode($argv[1]);' "$RDIR")/Docs/") || fail "PROPFIND"
[ "$(echo "$DAV" | grep -o 'Sun, 13 Sep 2020 12:26:40 GMT' | wc -l)" = 2 ] || fail "Nextcloud does not show the time of the folders: $(echo "$DAV" | grep -o '<d:getlastmodified>[^<]*' | sort | uniq -c)"
occ backup:restore ci "$SNAP" "data/$U/files/Docs/Sub" -m merge >/dev/null || fail "restore into a folder that is there"
[ "$($X stat -c %Y "$DATA/$U/files/Docs/Sub")" != 1600000000 ] || fail "a folder that was already there got the old time"
occ backup:browse ci "$SNAP" "data/$U/files" | grep Hollow >/dev/null || fail "an empty folder is not shown when browsing the snapshot"
occ backup:restore ci "$SNAP" "data/$U/files/Hollow" -m merge >/dev/null || fail "restore of a folder that was empty"
$X sh -c "test -d $DATA/$U/files/Hollow" || fail "the empty folder did not come back by itself"
[ "$($X stat -c %Y "$DATA/$U/files/Hollow")" = 1700000000 ] || fail "the empty folder did not get its time back"
occ backup:restore ci "$SNAP" "data/$U/files/Docs" -m replace >/dev/null || fail "restore replace"
$X sh -c "test -d $DATA/$U/files/Docs/Empty/Nested" || fail "replace did not bring back the empty folder, or moved it to the trash"
ok "empty folders: restored in each mode, by themselves and shown when browsing"
$X sh -c "rm -rf '$DATA/$U/files/Restored '*" ; occ files:scan "$U" >/dev/null
SUM_AFTER=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum")
[ "$SUM_BEFORE" = "$SUM_AFTER" ] || fail "files differ after restore: $(diff <(echo "$SUM_BEFORE") <(echo "$SUM_AFTER") | cut -c1-120)"
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
SUM_DIR=$($X sh -c "cd /tmp/ngb-rt-dir/data/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum")
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
# Locations are backed up one after the other: the second one started is queued until the first is done.
for L in 2 3; do
  $X sh -c "mkdir -p /tmp/ngb-rt-target$L"
  occ backup:target:add ci$L local -a null::null -o datadir=/tmp/ngb-rt-target$L --path=repo >/dev/null || fail "target add ci$L"
done
IDS=$(api "$API/status" | php -r 'foreach (json_decode(stream_get_contents(STDIN), true)["targets"] as $t) { if ($t["name"] === "ci2" || $t["name"] === "ci3") { echo $t["id"], " "; } }')
for ID in $IDS; do api -X POST -d "{\"targetId\": $ID, \"label\": \"turn\"}" "$API/backups" >/dev/null || fail "start a backup to location $ID"; done
# Never two runs to one location: a second start gets the run that is there.
ID=${IDS%% *}
RUNS=$(api "$API/status" | php -r 'echo count(array_filter(json_decode(stream_get_contents(STDIN), true)["runs"], fn ($r) => $r["status"] === "running"));')
api -X POST -d "{\"targetId\": $ID, \"label\": \"again\"}" "$API/backups" >/dev/null || fail "second start"
AGAIN=$(api "$API/status" | php -r 'echo count(array_filter(json_decode(stream_get_contents(STDIN), true)["runs"], fn ($r) => $r["status"] === "running"));')
[ "$RUNS" = 2 ] && [ "$AGAIN" = 2 ] || fail "a second start to one location: $RUNS, then $AGAIN running"
occ backup:status | grep "queued" >/dev/null || fail "the second backup is not queued"
QUEUED=$(api "$API/status" | php -r 'echo count(array_filter(json_decode(stream_get_contents(STDIN), true)["runs"], fn ($r) => $r["queued"]));')
[ "$QUEUED" = 1 ] || fail "$QUEUED backups queued instead of 1"
for i in $(seq 1 60); do
  api -X POST "$API/tick" >/dev/null || fail "tick"
  RUNNING=$(api "$API/status" | php -r 'echo count(array_filter(json_decode(stream_get_contents(STDIN), true)["runs"], fn ($r) => $r["status"] === "running"));')
  [ "$RUNNING" != 0 ] || break
done
[ "$RUNNING" = 0 ] || fail "the queued backups did not finish"
occ backup:list ci3 | grep " turn " >/dev/null || fail "the queued backup made no snapshot"
ok "locations in turn: the second backup queued, and done after the first"
# Heavy background jobs of other apps wait during a backup, and only then. Ticks keep this one moving.
HEAVY='OCA\Recognize\BackgroundJobs\NgbTestJob'
reserved() { $X php -r 'require "lib/base.php"; $q = \OC::$server->get(\OCP\IDBConnection::class)->getQueryBuilder(); echo (int)$q->select("reserved_at")->from("jobs")->where($q->expr()->eq("class", $q->createNamedParameter($argv[1])))->executeQuery()->fetchOne();' "$HEAVY"; }
tick_until_done() {
  for i in $(seq 1 60); do
    api -X POST "$API/tick" >/dev/null || fail "tick"
    RUNNING=$(api "$API/status" | php -r 'echo count(array_filter(json_decode(stream_get_contents(STDIN), true)["runs"], fn ($r) => $r["status"] === "running"));')
    [ "$RUNNING" != 0 ] || return 0
  done
  fail "the backup did not finish"
}
$X php -r 'require "lib/base.php"; \OC::$server->get(\OCP\BackgroundJob\IJobList::class)->add($argv[1]);' "$HEAVY" || fail "add a job"
[ "$(reserved)" = 0 ] || fail "a new job is reserved"
L3=$(echo "$IDS" | awk '{print $2}')
api -X POST -d "{\"targetId\": $L3, \"label\": \"heavy\"}" "$API/backups" >/dev/null || fail "start a backup"
tick_until_done
[ "$(reserved)" != 0 ] || fail "the job was not paused during the backup"
api -X POST "$API/tick" >/dev/null
[ "$(reserved)" = 0 ] || fail "the job stays paused after the backup"
occ backup:pause-jobs off >/dev/null || fail "turn off"
sleep 8 # APCu app config cache of the web server
api -X POST -d "{\"targetId\": $L3, \"label\": \"heavy\"}" "$API/backups" >/dev/null || fail "start a backup"
tick_until_done
[ "$(reserved)" = 0 ] || fail "the job was paused with the setting off"
occ backup:pause-jobs on >/dev/null
$X php -r 'require "lib/base.php"; \OC::$server->get(\OCP\BackgroundJob\IJobList::class)->remove($argv[1]);' "$HEAVY"
ok "heavy background jobs: paused during a backup, back after it, left alone with the setting off"
occ background:cron >/dev/null
$X php -f /var/www/html/cron.php >/dev/null 2>&1 || true
occ backup:status | grep "system cron" >/dev/null && fail "backup:status still warns after cron ran"
sleep 8 # APCu app config cache of the web server
api "$API/status" | php -r 'exit(json_decode(stream_get_contents(STDIN), true)["cron"]["ok"] ? 0 : 1);' || fail "the status API still reports a missing cron after cron ran"
ok "with a system cron that ran: no warning in backup:status or the API"

# A schedule per location. Choosing a time starts nothing; the moment itself does, and only there.
JOB=$(occ background-job:list --class='OCA\NgBackup\BackgroundJob\BackupJob' --output=json | php -r 'echo json_decode(stream_get_contents(STDIN), true)[0]["id"];')
job() { occ background-job:execute "$JOB" --force-execute >/dev/null; }
occ backup:target:schedule ci off >/dev/null
occ backup:target:schedule ci3 off >/dev/null
occ backup:target:schedule ci2 00:00 --timezone=UTC >/dev/null || fail "set a schedule"
occ backup:target:schedule ci2 | grep "00:00 (UTC)" >/dev/null || fail "schedule not shown"
job
[ "$(occ backup:list ci2 | grep -c ' scheduled ')" = 0 ] || fail "choosing a time started a backup"
occ backup:target:schedule ci2 "$($X date -u -d '+1 minute' +%H:%M)/6" --timezone=UTC >/dev/null || fail "set every 6 hours"
sleep 65
job
occ backup:list ci2 | grep " scheduled " >/dev/null || fail "the scheduled moment started no backup"
[ "$(occ backup:list ci3 | grep -c ' scheduled ')" = 0 ] || fail "a location that is off was backed up"
job
[ "$(occ backup:list ci2 | grep -c ' scheduled ')" = 1 ] || fail "one moment started more than one backup"
occ backup:target:schedule ci2 nonsense >/dev/null 2>&1 && fail "a bad schedule was accepted"
ok "schedule per location: nothing on choosing a time, one backup at the moment, none when off"

# What administrators are told, as Nextcloud shows it.
notes() { api "http://localhost/ocs/v2.php/apps/notifications/api/v2/notifications?format=json"; }
occ backup:target:schedule ci2 off >/dev/null
$X sh -c "touch '$DATA/ngb-rt-bad\\name.txt'"
occ backup:run ci3 -l names | grep "their name is not supported" >/dev/null || fail "occ does not report the name"
$X sh -c "rm '$DATA/ngb-rt-bad\\name.txt'"
NOTES=$(notes) || fail "read the notifications"
echo "$NOTES" | grep "turned off the schedule of backup location ci2" >/dev/null || fail "no notification about the schedule that was turned off"
echo "$NOTES" | grep "is missing because of its name" >/dev/null || fail "no notification about the name"
echo "$NOTES" | grep "Rename them" >/dev/null || fail "the notification about the name does not ask to rename"
job
notes | grep "has no schedule" >/dev/null && fail "a reminder right after turning it off"
for L in 2 3; do occ backup:target:remove ci$L >/dev/null || fail "target remove ci$L"; done
occ user:delete "$ADM" >/dev/null
ok "notifications: schedule turned off, name not supported, no early reminder"

# Checksum audit: shallow (index presence) and deep (download, decrypt, verify every blob).
occ backup:verify ci "$SNAP" | grep "^OK$" >/dev/null || fail "shallow verify"
occ backup:verify ci "$SNAP" --deep | grep "^OK$" >/dev/null || fail "deep verify"
ok "shallow and deep checksum audit"

# Database dump/restore round trip (all tables, checksums, sequences on PostgreSQL).
$X php -d memory_limit=512M "$APPS/ng_backup/spikes/03-db-dump.php" /tmp/ngb-rt-db | grep "ALL PASS" >/dev/null || fail "database round trip"
ok "database dump and restore"

# Retention, trash and catalog.
occ backup:retention --last=1 --daily=0 --weekly=0 --monthly=0 >/dev/null
# A pinned restore point stays, and a note can change afterwards.
FIRST=$(occ backup:list ci | awk -F'|' '!f && / first /{gsub(/ /,"",$3); print $3; f=1}')
occ backup:pin ci "$FIRST" >/dev/null || fail "pin"
occ backup:note ci "$FIRST" "a note made later" >/dev/null || fail "note"
occ backup:list ci | grep " first " | grep " yes " | grep "a note made later" >/dev/null || fail "pin and note not listed"
occ backup:prune ci --dry-run | grep "would forget 1 " >/dev/null || fail "a pinned snapshot would be removed"
occ backup:pin ci "$FIRST" --unpin >/dev/null || fail "unpin"
occ backup:prune ci --dry-run | grep "would forget 2 " >/dev/null || fail "an unpinned snapshot would stay"
occ backup:note ci "$FIRST" >/dev/null || fail "remove note"
occ backup:list ci | grep -q "a note made later" && fail "note not removed"
ok "pin and note on a restore point"
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
