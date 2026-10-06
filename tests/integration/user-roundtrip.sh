#!/bin/bash
# Per-user backup/restore round trip via user_migration (optional dependency, see PLAN.md 3.8):
# occ backup:user:backup / occ backup:user:restore --mode=as-backup|replace.
# Usage: user-roundtrip.sh "<command prefix to run occ/php as the web user>" <datadir> [target-args]
#   e.g. user-roundtrip.sh "docker exec -u www-data ngb-nc35-pgsql" /var/www/html/data
set -euo pipefail
X="$1"; DATA="$2"
TARGET_ARGS="${3:-local -a null::null -o datadir=/tmp/ngb-urt-target}"
occ() { $X php occ "$@"; }
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }
U=ngbutest-$RANDOM

occ app:enable ng_backup >/dev/null
if ! occ app:install user_migration >/dev/null 2>&1 && ! occ app:enable user_migration >/dev/null 2>&1; then
	echo "SKIP: user_migration is not available (no network, or the app store is unreachable) -- per-user backup/restore not tested"
	exit 0
fi
sleep 8 # APCu app config cache

$X sh -c "printf 'user roundtrip passphrase 2026\n' > /tmp/ngb-urt-pass && mkdir -p /tmp/ngb-urt-target"
occ backup:key:init --passphrase-file=/tmp/ngb-urt-pass --label=CI --delete-delay-days=7 >/dev/null || fail "key init"
CODE=$(occ backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
occ backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
ok "key, kit code and confirmation"

occ backup:target:add ci $TARGET_ARGS --path=repo >/dev/null || fail "target add"
ok "location added"

$X sh -c "OC_PASS='User-roundtrip-pw-2026' php occ user:add --password-from-env $U >/dev/null"
$X sh -c "mkdir -p $DATA/$U/files/Docs && for i in 1 2 3; do head -c 100000 /dev/urandom > $DATA/$U/files/Docs/f\$i.bin; done"
occ files:scan "$U" >/dev/null
SUM_BEFORE=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")

MANIFEST=$(occ backup:user:backup ci "$U" | sed -n 's/.* to \(.*\)\.$/\1/p')
[ -n "$MANIFEST" ] || fail "backup:user:backup printed no manifest path"
ok "backed up $U to $MANIFEST"

# as-backup: restore into <uid>-bak; the original account must stay untouched.
occ backup:user:restore ci "$MANIFEST" --mode=as-backup >/dev/null || fail "restore as-backup"
occ files:scan "$U-bak" >/dev/null
SUM_BAK=$($X sh -c "cd $DATA/$U-bak/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")
[ "$SUM_BEFORE" = "$SUM_BAK" ] || fail "the <uid>-bak account's files differ from the original"
occ user:delete "$U-bak" >/dev/null
$X sh -c "[ -d '$DATA/$U/files/Docs' ]" || fail "the original account should be untouched by an as-backup restore"
ok "as-backup restores into <uid>-bak, byte-identical, original account untouched"

# replace: damage the account, then restore it in place under the same uid.
$X sh -c "rm '$DATA/$U/files/Docs/f1.bin' && echo damaged > '$DATA/$U/files/Docs/f1.bin'"
occ files:scan "$U" >/dev/null
occ backup:user:restore ci "$MANIFEST" --mode=replace >/dev/null || fail "restore replace"
occ files:scan "$U" >/dev/null
SUM_AFTER=$($X sh -c "cd $DATA/$U/files && find . -type f -print0 | sort -z | xargs -0 md5sum | md5sum")
[ "$SUM_BEFORE" = "$SUM_AFTER" ] || fail "files differ after a replace restore"
ok "replace restores the original content under the same uid, byte-identical"

# Retention: $U now has the manual export above plus the automatic safety-export the replace
# restore made of it just before deleting it (4 total after these two more), then --user-last=2
# must forget exactly the 2 oldest.
sleep 1.1 # exportId is time-based to the second; force each one to be distinct
occ backup:user:backup ci "$U" >/dev/null || fail "third backup:user:backup"
sleep 1.1
occ backup:user:backup ci "$U" >/dev/null || fail "fourth backup:user:backup"
occ backup:retention --user-last=2 >/dev/null || fail "retention --user-last"
PRUNE_OUT=$(occ backup:prune ci)
echo "$PRUNE_OUT" | grep -q "forgot 2 user export(s) for 1 user(s)" || fail "prune did not forget the expected two user exports"
ok "user-export retention forgets exactly the exports over the keep count"

occ user:delete "$U" >/dev/null
$X sh -c "rm -rf /tmp/ngb-urt-target /tmp/ngb-urt-pass"
echo "USER ROUNDTRIP PASS"
