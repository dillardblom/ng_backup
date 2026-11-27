#!/bin/bash
# SFTP host key pinning (#34), end to end: a location with pinned host keys refuses a server
# with other keys under the same name, before it sends the password; a location without pins
# accepts it (the gap in files_external, nextcloud/server#14108). Pinning at creation gives no
# notification; accepting a new key or removing the pins notifies the admins.
# Two SFTP servers with their own keys share one volume and, in turn, one host name.
# Usage: tests/integration/host-key.sh [nextcloud-version]
set -euo pipefail
V=${1:-35}
APP=$(cd "$(dirname "$0")/../.." && pwd)
NET=ngb-hk-net
NC=ngb-hk-nc
VOL=ngb-hk-upload
HOST=ngb-hk-sftp

cleanup() {
	docker rm -fv "$NC" ngb-hk-sftp-a ngb-hk-sftp-b >/dev/null 2>&1 || true
	docker volume rm "$VOL" >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }
occ() { docker exec -u www-data "$NC" php occ "$@"; }

sftp_server() { # container name: answers as $HOST, the upload folder on the shared volume
	docker run -d --name "$1" --network "$NET" --network-alias "$HOST" -v "$VOL:/home/ngbackup/upload" \
		atmoz/sftp:alpine ngbackup:ngbackuppw:1001::upload >/dev/null
	for i in $(seq 1 30); do docker exec "$1" sh -c 'pgrep sshd >/dev/null && test -f /etc/ssh/ssh_host_ed25519_key.pub' 2>/dev/null && break; sleep 1; done
	docker exec "$1" chown 1001 /home/ngbackup/upload
}
fingerprints() { # container: SHA256 fingerprints of its host keys
	docker exec "$1" sh -c 'for f in /etc/ssh/ssh_host_*_key.pub; do ssh-keygen -lf "$f"; done' | awk '{print $2}' | sort
}
# Notifications of NG Backup security events with this event name, for any admin.
notifications() {
	docker exec -u www-data "$NC" php -r '$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
		$n = 0; foreach ($db->query("SELECT subject_parameters FROM oc_notifications WHERE app = \"ng_backup\" AND subject = \"security_event\"") as $r) {
			$n += (json_decode($r[0], true)["event"] ?? "") === $argv[1] ? 1 : 0; } echo $n;' "$1"
}

docker network create "$NET" >/dev/null
docker volume create "$VOL" >/dev/null
sftp_server ngb-hk-sftp-a
docker run -d --name "$NC" --network "$NET" \
	-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin-hostkey-pw -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
	-e SQLITE_DATABASE=nextcloud \
	-v "$APP:/var/www/html/custom_apps/ng_backup:ro" "nextcloud:$V-apache" >/dev/null
for i in $(seq 1 90); do occ status 2>/dev/null | grep -q "installed: true" && break; sleep 4; done
docker exec "$NC" chown www-data:www-data /var/www/html/custom_apps
echo "=== $(occ -V)"
occ app:enable ng_backup >/dev/null
occ app:enable files_external >/dev/null
sleep 8 # APCu app config cache
docker exec -u www-data "$NC" sh -c "printf 'host key passphrase 2026\n' > /tmp/ngb-hk-pass"
occ backup:key:init --passphrase-file=/tmp/ngb-hk-pass --label=CI --delete-delay-days=7 >/dev/null || fail "key init"
CODE=$(occ backup:key:kit | php -r 'echo json_decode(stream_get_contents(STDIN), true)["confirmation_code"];')
occ backup:key:confirm -u admin --code="$CODE" --confirm="Yes, I confirm" >/dev/null || fail "confirm"
LOCATION=(sftp -a password::password -o host=$HOST -o root=/upload -o user=ngbackup -o password=ngbackuppw)

# Pinned when it is added: the command shows the keys and asks; "y" pins all of them.
A_KEYS=$(fingerprints ngb-hk-sftp-a)
ADD=$(echo y | docker exec -i -u www-data "$NC" php occ backup:target:add pinned "${LOCATION[@]}" --path=pinned 2>&1) || fail "add pinned: $ADD"
for f in $A_KEYS; do echo "$ADD" | grep -qF "$f" || fail "the server's key $f was not shown when adding: $ADD"; done
[ "$(occ backup:target:host-key pinned | sed -n '/^Pinned:/,/^The server/p' | grep -c SHA256)" = "$(echo "$A_KEYS" | wc -l)" ] || fail "not all of the server's keys were pinned"
ok "all host keys shown and pinned when the location is added ($(echo "$A_KEYS" | wc -l) keys)"
occ backup:target:add open "${LOCATION[@]}" --path=open -n >/dev/null || fail "add open"
occ backup:status | grep -q "Host key not pinned: open" || fail "status shows no hint for the location without pins"
occ backup:status | grep "Host key not pinned" | grep -q pinned, && fail "status hints at the pinned location"
ok "status: hint for the location without pins only"
[ "$(notifications host_key_pinned)" = 0 ] || fail "pinning at creation notified the admins"

occ backup:run pinned >/dev/null || fail "backup to the pinned location"
ok "backup with the pinned key"

# Another server under the same name, with other keys.
docker rm -fv ngb-hk-sftp-a >/dev/null
sftp_server ngb-hk-sftp-b
B_KEYS=$(fingerprints ngb-hk-sftp-b)
[ "$A_KEYS" != "$B_KEYS" ] || fail "the second server has the same keys"

occ backup:run open >/dev/null || fail "the location without pins refused the other server"
ok "without pins the other server is accepted (files_external's gap)"
LOGINS=$(docker logs ngb-hk-sftp-b 2>&1 | grep -c "password for ngbackup" || true)
OUT=$(occ backup:run pinned 2>&1) && fail "the pinned location accepted a server with other keys"
echo "$OUT" | grep -q "does not match the pinned key" || fail "no clear error: $OUT"
for f in $B_KEYS; do echo "$OUT" | grep -qF "$f" && FOUND=1; done
[ "${FOUND:-}" = 1 ] || fail "the received fingerprint is not in the error: $OUT"
[ "$(docker logs ngb-hk-sftp-b 2>&1 | grep -c "password for ngbackup" || true)" = "$LOGINS" ] || fail "the password was sent to the other server"
ok "pinned: refused before login, with expected and received fingerprint"
SHOW=$(occ backup:target:host-key pinned 2>&1) && fail "host-key shows no problem: $SHOW"
echo "$SHOW" | grep -q "NOT PINNED" || fail "host-key does not mark the new keys: $SHOW"

occ backup:target:host-key pinned --pin -n >/dev/null || fail "accept the new keys"
[ "$(notifications host_key_changed)" = 1 ] || fail "accepting a new key did not notify the admins"
occ backup:run pinned >/dev/null || fail "backup after the new key was accepted"
ok "new key accepted: admins notified, backups go on"

occ backup:target:host-key pinned --remove -n >/dev/null || fail "remove the pins"
[ "$(notifications host_key_removed)" = 1 ] || fail "removing the pins did not notify the admins"
occ backup:status | grep "Host key not pinned" | grep -q "pinned" || fail "no hint after the pins were removed"
ok "pins removed: admins notified, hint shown"

SET=(); for f in $B_KEYS; do SET+=(--set "$f"); done
occ backup:target:host-key pinned "${SET[@]}" -n >/dev/null || fail "pin with --set"
[ "$(notifications host_key_pinned)" = 0 ] || fail "pinning a location without pins notified the admins"
occ backup:run pinned >/dev/null || fail "backup with the keys pinned by --set"
ok "--set pins the given keys, quietly"
echo "PASS: host keys on NC $V"
