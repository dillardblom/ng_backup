#!/bin/bash
# Getting the backup key onto a new server, with and without the recovery kit, and what the
# administrator is told in each case. Every scenario starts on a fresh Nextcloud (SQLite) with a
# copy of the origin's location; nothing is restored (full-restore-roundtrip.sh does that).
# Usage: recovery-scenarios.sh [nextcloud-image-tag]   e.g. recovery-scenarios.sh 35-apache
#
#   kit                 kit + passphrase: backup:key:import, then backup:target:add asks nothing
#   kit-wrong           kit with a wrong passphrase: refused, no key left behind
#   no-kit              passphrase only: target:add asks for it (or --passphrase-file), a wrong one
#                       changes nothing; backups wait for a new kit
#   no-kit-old          as no-kit, on a location backed up only before 1.1.0 (no settings file):
#                       the deletion delay is the default, and the admin is told so
#   no-config-no-kit    the location's config is gone and there is no kit: told that only the kit helps
#   no-config-kit       config gone, kit there: --repair-config writes it again, the backups open
#   other-kit           the kit of another server: refused, told to use the old server's kit
#   empty               nothing at the location: told to check the details, not to make a new key
set -euo pipefail
IMAGE="nextcloud:${1:-35-apache}"
APP=$(cd "$(dirname "$0")/../.." && pwd)
SHARED=$(mktemp -d)
chmod 777 "$SHARED"
PASS='recovery scenarios passphrase 2026'
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }

cleanup() {
	docker rm -fv ngb-rs-origin ngb-rs-other ngb-rs-new >/dev/null 2>&1 || true
	docker run --rm -v "$SHARED:/target" alpine sh -c 'rm -rf /target/*' >/dev/null 2>&1 || true
	rmdir "$SHARED" 2>/dev/null || true
	return 0
}
trap cleanup EXIT

run_on() { docker exec -u www-data "$1" php occ "${@:2}"; }
# Output of a command that must fail; the scenario fails when it succeeds.
must_fail() { # container what args...
	local out
	if out=$(run_on "$1" "${@:3}" 2>&1); then printf '%s\n' "$out"; fail "$2: the command succeeded"; fi
	printf '%s\n' "$out"
}
expect() { # output text what
	printf '%s\n' "$1" | grep -qF -- "$2" || { printf '%s\n' "$1"; fail "$3: the output does not say \"$2\""; }
}
not_expect() {
	printf '%s\n' "$1" | grep -qF -- "$2" && { printf '%s\n' "$1"; fail "$3: the output says \"$2\""; }
	return 0
}

start() { # name
	docker rm -fv "$1" >/dev/null 2>&1 || true
	docker run -d --name "$1" -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
		-v "$APP:/var/www/html/custom_apps/ng_backup:ro" -v "$SHARED:/mnt/shared" "$IMAGE" >/dev/null
	for i in $(seq 1 90); do run_on "$1" status 2>/dev/null | grep -q "installed: true" && break; sleep 3; done
	docker exec "$1" chown www-data:www-data /var/www/html/custom_apps
	run_on "$1" app:enable ng_backup >/dev/null
	docker exec "$1" sh -c "printf '%s\n' '$PASS' > /tmp/pass; printf 'not the passphrase at all\n' > /tmp/wrongpass; chmod 644 /tmp/pass /tmp/wrongpass"
}
key_of() { run_on "$1" backup:status 2>&1 | sed -n 's/^Key: \([0-9a-f-]*\),.*/\1/p'; }
no_key() { # container what
	local s
	s=$(run_on "$1" backup:status 2>&1 || true)
	expect "$s" "Key: not created" "$2: a key was left behind"
	[ -z "$(run_on "$1" backup:target:list 2>&1 | grep offsite || true)" ] || fail "$2: a location was left behind"
}
location() { echo "local -a null::null -o datadir=/mnt/shared/$1 --path=repo"; }
# A fresh server with its own copy of the origin's location
scenario() { # name
	docker exec ngb-rs-origin sh -c "rm -rf /mnt/shared/$1 && mkdir -p /mnt/shared/$1 && cp -a /mnt/shared/origin/repo /mnt/shared/$1/ && chown -R www-data /mnt/shared/$1"
	start ngb-rs-new
	docker exec -i ngb-rs-new sh -c 'cat > /tmp/kit.json && chmod 644 /tmp/kit.json' < "$SHARED/kit.json"
}

# The origin: a key with a deletion delay of its own, a kit, one backup
start ngb-rs-origin
run_on ngb-rs-origin backup:key:init --passphrase-file=/tmp/pass --label=Safe --delete-delay-days=21 >/dev/null || fail "key init"
run_on ngb-rs-origin backup:key:kit --output=/tmp/kit.json >/dev/null
CODE=$(run_on ngb-rs-origin backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
run_on ngb-rs-origin backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
docker exec ngb-rs-origin sh -c 'mkdir -p /mnt/shared/origin && chown www-data /mnt/shared/origin'
# shellcheck disable=SC2046
run_on ngb-rs-origin backup:target:add offsite $(location origin) >/dev/null || fail "target add on the origin"
run_on ngb-rs-origin backup:run offsite >/dev/null || fail "backup on the origin"
SNAP=$(run_on ngb-rs-origin backup:list offsite | grep -o '[0-9a-f]\{32\}' | head -1)
FP=$(key_of ngb-rs-origin)
docker exec ngb-rs-origin cat /tmp/kit.json > "$SHARED/kit.json"
docker exec ngb-rs-origin test -f /mnt/shared/origin/repo/settings || fail "the origin's location has no settings file"
ok "origin: key $FP, kit, snapshot $SNAP, settings on the location"

# kit: the passphrase once, for the kit; target:add asks nothing more
scenario kit
OUT=$(run_on ngb-rs-new backup:key:import /tmp/kit.json --passphrase-file=/tmp/pass 2>&1) || { printf '%s\n' "$OUT"; fail "kit: key import"; }
expect "$OUT" "fingerprint $FP" "kit"
expect "$OUT" "21 days (from the kit)" "kit"
expect "$OUT" "backup:target:add" "kit: the next step"
# shellcheck disable=SC2046
OUT=$(run_on ngb-rs-new backup:target:add offsite $(location kit) --no-interaction 2>&1) || { printf '%s\n' "$OUT"; fail "kit: target add"; }
not_expect "$OUT" "Passphrase" "kit: target:add asked for a passphrase"
expect "$OUT" "found and adopted" "kit"
run_on ngb-rs-new backup:list offsite | grep -q "$SNAP" || fail "kit: the snapshot is not listed"
run_on ngb-rs-new backup:run offsite >/dev/null || fail "kit: a backup after the kit import"
ok "kit: imported with the passphrase, location added without asking again, backups run"

# kit-wrong
scenario kit-wrong
OUT=$(must_fail ngb-rs-new "kit-wrong" backup:key:import /tmp/kit.json --passphrase-file=/tmp/wrongpass)
expect "$OUT" "Wrong passphrase" "kit-wrong"
no_key ngb-rs-new "kit-wrong"
ok "kit-wrong: refused, nothing left behind"

# no-kit
scenario no-kit
docker exec ngb-rs-new rm /tmp/kit.json
# shellcheck disable=SC2046
OUT=$(must_fail ngb-rs-new "no-kit without a passphrase" backup:target:add offsite $(location no-kit) --no-interaction)
expect "$OUT" "this location holds backups" "no-kit: explains where the key comes from"
expect "$OUT" "--passphrase-file" "no-kit: says how to give the passphrase"
no_key ngb-rs-new "no-kit without a passphrase"
# shellcheck disable=SC2046
OUT=$(must_fail ngb-rs-new "no-kit wrong passphrase" backup:target:add offsite $(location no-kit) --passphrase-file=/tmp/wrongpass)
expect "$OUT" "Wrong passphrase" "no-kit wrong passphrase"
expect "$OUT" "Nothing was changed" "no-kit wrong passphrase"
no_key ngb-rs-new "no-kit wrong passphrase"
# shellcheck disable=SC2046
OUT=$(run_on ngb-rs-new backup:target:add offsite $(location no-kit) --passphrase-file=/tmp/pass 2>&1) || { printf '%s\n' "$OUT"; fail "no-kit: target add"; }
expect "$OUT" "fingerprint $FP" "no-kit"
expect "$OUT" "21 days (from the location)" "no-kit"
expect "$OUT" "download a new recovery kit" "no-kit: asks for a new kit"
run_on ngb-rs-new backup:list offsite | grep -q "$SNAP" || fail "no-kit: the snapshot is not listed"
OUT=$(must_fail ngb-rs-new "no-kit backup before a new kit" backup:run offsite)
expect "$OUT" "Confirm the recovery kit" "no-kit: a backup before a new kit"
CODE=$(run_on ngb-rs-new backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
run_on ngb-rs-new backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "no-kit: confirm the new kit"
run_on ngb-rs-new backup:run offsite >/dev/null || fail "no-kit: a backup after the new kit"
ok "no-kit: passphrase asked, wrong one changes nothing, key and delay from the location, backups wait for a new kit"

# no-kit-old: a location from before 1.1.0 has no settings file
scenario no-kit-old
docker exec ngb-rs-new rm /tmp/kit.json /mnt/shared/no-kit-old/repo/settings
# shellcheck disable=SC2046
OUT=$(run_on ngb-rs-new backup:target:add offsite $(location no-kit-old) --passphrase-file=/tmp/pass 2>&1) || { printf '%s\n' "$OUT"; fail "no-kit-old: target add"; }
expect "$OUT" "fingerprint $FP" "no-kit-old"
expect "$OUT" "7 days (the default" "no-kit-old: the default delay and why"
docker exec ngb-rs-new test -f /mnt/shared/no-kit-old/repo/settings || fail "no-kit-old: the settings were not written when the location was added"
ok "no-kit-old: key from the location, default delay with the reason, settings written now"

# no-config-no-kit
scenario no-config-no-kit
docker exec ngb-rs-new rm /tmp/kit.json /mnt/shared/no-config-no-kit/repo/config
# shellcheck disable=SC2046
OUT=$(must_fail ngb-rs-new "no-config-no-kit" backup:target:add offsite $(location no-config-no-kit) --passphrase-file=/tmp/pass)
expect "$OUT" "its config file is gone" "no-config-no-kit"
expect "$OUT" "only the recovery kit has the key" "no-config-no-kit"
expect "$OUT" "--repair-config" "no-config-no-kit"
no_key ngb-rs-new "no-config-no-kit"
docker exec ngb-rs-new test ! -f /mnt/shared/no-config-no-kit/repo/config || fail "no-config-no-kit: a config was written"
ok "no-config-no-kit: told the kit is needed, nothing written"

# no-config-kit
scenario no-config-kit
docker exec ngb-rs-new rm /mnt/shared/no-config-kit/repo/config
run_on ngb-rs-new backup:key:import /tmp/kit.json --passphrase-file=/tmp/pass >/dev/null || fail "no-config-kit: key import"
# shellcheck disable=SC2046
OUT=$(must_fail ngb-rs-new "no-config-kit without --repair-config" backup:target:add offsite $(location no-config-kit))
expect "$OUT" "--repair-config" "no-config-kit: points at --repair-config"
docker exec ngb-rs-new test ! -f /mnt/shared/no-config-kit/repo/config || fail "no-config-kit: a config was written without --repair-config"
# shellcheck disable=SC2046
OUT=$(run_on ngb-rs-new backup:target:add offsite $(location no-config-kit) --repair-config 2>&1) || { printf '%s\n' "$OUT"; fail "no-config-kit: --repair-config"; }
expect "$OUT" "written again" "no-config-kit"
expect "$OUT" "found and adopted" "no-config-kit"
run_on ngb-rs-new backup:list offsite | grep -q "$SNAP" || fail "no-config-kit: the snapshot is not listed after the repair"
run_on ngb-rs-new backup:browse offsite "$SNAP" >/dev/null 2>&1 || fail "no-config-kit: the snapshot cannot be read after the repair"
ok "no-config-kit: refused without --repair-config, config written again with it, the backups open"

# other-kit: the kit of another server
start ngb-rs-other
run_on ngb-rs-other backup:key:init --passphrase-file=/tmp/pass >/dev/null || fail "other: key init"
run_on ngb-rs-other backup:key:kit --output=/tmp/other.json >/dev/null
docker exec ngb-rs-other cat /tmp/other.json > "$SHARED/other.json"
docker rm -fv ngb-rs-other >/dev/null
scenario other-kit
docker exec ngb-rs-new rm /tmp/kit.json
docker exec -i ngb-rs-new sh -c 'cat > /tmp/other.json && chmod 644 /tmp/other.json' < "$SHARED/other.json"
run_on ngb-rs-new backup:key:import /tmp/other.json --passphrase-file=/tmp/pass >/dev/null || fail "other-kit: key import"
# shellcheck disable=SC2046
OUT=$(must_fail ngb-rs-new "other-kit" backup:target:add offsite $(location other-kit))
expect "$OUT" "different key" "other-kit"
expect "$OUT" "must be the old server's" "other-kit: points at the right kit"
[ -z "$(run_on ngb-rs-new backup:target:list 2>&1 | grep offsite || true)" ] || fail "other-kit: a location was left behind"
# shellcheck disable=SC2046
OUT=$(must_fail ngb-rs-new "other-kit repair" backup:target:add offsite $(location other-kit) --repair-config)
expect "$OUT" "leave out --repair-config" "other-kit: a config that is there is not repaired"
ok "other-kit: refused, told to use the old server's kit, the config untouched"

# empty
scenario empty
docker exec ngb-rs-new sh -c 'mkdir -p /mnt/shared/nothing && chown www-data /mnt/shared/nothing'
OUT=$(must_fail ngb-rs-new "empty" backup:target:add offsite local -a null::null -o datadir=/mnt/shared/nothing --path=repo --passphrase-file=/tmp/pass)
expect "$OUT" "no backups at this location" "empty"
expect "$OUT" "Check the connection details and --path" "empty"
no_key ngb-rs-new "empty"
ok "empty: told to check the details, no key made"

echo "RECOVERY SCENARIOS PASS"
