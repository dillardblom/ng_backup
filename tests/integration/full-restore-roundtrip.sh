#!/bin/bash
# Disaster recovery: occ backup:key:import + backup:target:add + backup:restore:full on a
# SECOND, fresh Nextcloud instance that never saw the first one, sharing only the backup
# location (a local directory, bind-mounted into both containers) -- see PLAN.md 3.7.
# Usage: full-restore-roundtrip.sh <nextcloud-image-tag> [sqlite|pgsql|mysql] [legacy]
#   e.g. full-restore-roundtrip.sh 35-apache pgsql
# With "legacy" the origin runs NG Backup 1.0.0-rc.1 (0.9.7), whose backups do not record the
# structure of their tables: the restore must then leave the tables of the disabled app out
# with --skip-missing-tables, as for a real backup made before 1.0.0-rc.2.
# With pgsql or mysql the origin also has what broke real disaster recoveries: the Mail app with a synced
# account (foreign keys between its tables) and Deck installed and then switched off (tables of
# an app that is not enabled). The restore instance has neither; --install-apps adds Mail.
set -euo pipefail
IMAGE="nextcloud:${1:-35-apache}"
DB="${2:-sqlite}"
LEGACY="${3:-}"
APP=$(cd "$(dirname "$0")/../.." && pwd)
ORIGIN_APP="$APP"
NET=ngb-frt-net
SHARED=$(mktemp -d)
chmod 777 "$SHARED"
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "ok   $*"; }

cleanup() {
	docker rm -fv ngb-frt-origin ngb-frt-restore ngb-frt-db-origin ngb-frt-db-restore ngb-frt-imap >/dev/null 2>&1 || true
	docker network rm "$NET" >/dev/null 2>&1 || true
	docker run --rm -v "$SHARED:/target" alpine sh -c 'rm -rf /target/*' >/dev/null 2>&1 || true
	rmdir "$SHARED" 2>/dev/null || true
	rm -f /tmp/ngb-frt-kit.json /tmp/ngb-frt-pass
	[ -n "${LEGACY_DIR:-}" ] && rm -rf "$LEGACY_DIR"
	return 0
}
trap cleanup EXIT

if [ "$LEGACY" = legacy ]; then
	LEGACY_DIR=$(mktemp -d)
	curl -fsSL https://github.com/dillardblom/ng_backup/releases/download/v1.0.0-rc.1/ng_backup-0.9.7.tar.gz | tar -xz -C "$LEGACY_DIR"
	ORIGIN_APP="$LEGACY_DIR/ng_backup"
fi

run_on() { docker exec -u www-data "$1" php occ "${@:2}"; }

# Database settings for an instance: SQLite inside the container, or its own PostgreSQL container
db_env() {
	if [ "$DB" = pgsql ]; then
		docker run -d --name "ngb-frt-db-$1" --network "$NET" -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud \
			-e POSTGRES_PASSWORD=nextcloud postgres:16-alpine >/dev/null
		for i in $(seq 1 30); do docker exec "ngb-frt-db-$1" pg_isready -q 2>/dev/null && break; sleep 2; done
		echo "-e POSTGRES_HOST=ngb-frt-db-$1 -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD=nextcloud"
	elif [ "$DB" = mysql ]; then
		docker run -d --name "ngb-frt-db-$1" --network "$NET" -e MARIADB_DATABASE=nextcloud -e MARIADB_USER=nextcloud \
			-e MARIADB_PASSWORD=nextcloud -e MARIADB_ROOT_PASSWORD=nextcloud mariadb:11 >/dev/null
		for i in $(seq 1 60); do docker exec "ngb-frt-db-$1" mariadb-admin ping -unextcloud -pnextcloud --silent >/dev/null 2>&1 && break; sleep 2; done
		echo "-e MYSQL_HOST=ngb-frt-db-$1 -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=nextcloud -e MYSQL_PASSWORD=nextcloud"
	else
		echo "-e SQLITE_DATABASE=nextcloud"
	fi
}

docker network create "$NET" >/dev/null
ORIGIN_DB=$(db_env origin)
# shellcheck disable=SC2086
docker run -d --name ngb-frt-origin --network "$NET" $ORIGIN_DB \
	-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
	-v "$ORIGIN_APP:/var/www/html/custom_apps/ng_backup:ro" -v "$SHARED:/mnt/shared" "$IMAGE" >/dev/null
for i in $(seq 1 90); do run_on ngb-frt-origin status 2>/dev/null | grep -q "installed: true" && break; sleep 3; done
docker exec ngb-frt-origin chown www-data:www-data /var/www/html/custom_apps
run_on ngb-frt-origin app:enable ng_backup >/dev/null
ok "origin instance up ($DB${LEGACY:+, NG Backup 0.9.7})"

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

# SQL on an instance's own database (pgsql/mysql only)
sql() {
	if [ "$DB" = pgsql ]; then docker exec "ngb-frt-db-$1" psql -U nextcloud -d nextcloud -Atc "$2"
	else docker exec "ngb-frt-db-$1" mariadb -unextcloud -pnextcloud -N -B nextcloud -e "$2"; fi
}
if [ "$DB" != sqlite ]; then
	# Mail with a synced account: oc_mail_accounts points at oc_mail_mailboxes (foreign keys),
	# while the restore loads tables in name order, accounts before mailboxes.
	docker run -d --name ngb-frt-imap --network "$NET" -e MAILNAME=mail.domain.tld -e MAIL_ADDRESS=user@domain.tld \
		-e MAIL_PASS=mypassword ghcr.io/christophwurst/docker-imap-devel:latest >/dev/null
	run_on ngb-frt-origin app:install mail >/dev/null || fail "install mail on the origin"
	run_on ngb-frt-origin config:system:set app.mail.verify-tls-peer --type=boolean --value=false >/dev/null
	sleep 10
	run_on ngb-frt-origin mail:account:create frtest Test user@domain.tld ngb-frt-imap 993 ssl user@domain.tld mypassword \
		ngb-frt-imap 25 tls user@domain.tld mypassword >/dev/null || fail "mail account"
	run_on ngb-frt-origin mail:account:sync -f 1 >/dev/null || fail "mail sync"
	[ "$(sql origin 'select count(*) from oc_mail_accounts where drafts_mailbox_id is not null')" = 1 ] \
		|| fail "the mail account does not point at its mailboxes"
	# Deck installed and then switched off: its tables stay in the database and in the backup
	run_on ngb-frt-origin app:install deck >/dev/null || fail "install deck on the origin"
	run_on ngb-frt-origin app:disable deck >/dev/null
	ok "origin has a synced Mail account and the tables of a disabled app"
fi

RUN_OUT=$(run_on ngb-frt-origin backup:run offsite -l disaster-test)
SNAP=$(printf '%s\n' "$RUN_OUT" | head -1 | awk -F'snapshot ' '{print $2}' | tr -d ',')
[ -n "$SNAP" ] || fail "no snapshot id from backup:run"
ok "test data backed up, snapshot $SNAP"

docker cp ngb-frt-origin:/tmp/kit.json /tmp/ngb-frt-kit.json
docker exec ngb-frt-origin sh -c "cat /tmp/pass" > /tmp/ngb-frt-pass
RESTORE_DB=$(db_env restore)
# shellcheck disable=SC2086
docker run -d --name ngb-frt-restore --network "$NET" $RESTORE_DB \
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

RESTORE_OPTS=(--no-interaction --install-apps)
if [ "$LEGACY" = legacy ] && [ "$DB" != sqlite ]; then
	# Without --skip-missing-tables an older backup must be refused, naming the app's tables
	REFUSED=$(run_on ngb-frt-restore backup:restore:full offsite "$SNAP" "${RESTORE_OPTS[@]}" 2>&1) \
		&& fail "an older backup with tables of unknown structure was restored without --skip-missing-tables"
	printf '%s\n' "$REFUSED" | grep -q "oc_deck_boards" || { printf '%s\n' "$REFUSED" | tail -20; fail "the refusal does not name the missing tables"; }
	ok "older backup refused without --skip-missing-tables, missing tables named"
	RESTORE_OPTS+=(--skip-missing-tables)
fi
OUT=$(run_on ngb-frt-restore backup:restore:full offsite "$SNAP" "${RESTORE_OPTS[@]}" 2>&1) || {
	printf '%s\n' "$OUT" | tail -40
	docker exec ngb-frt-restore sh -c 'tail -c 6000 /var/www/html/data/nextcloud.log' || true
	fail "restore:full"
}
ok "backup:restore:full completed"

# Everything below must work in a FRESH occ process (a new PHP process per docker exec), since
# earlier bugs here only showed up after config.php's secret was rewritten mid-restore.
run_on ngb-frt-restore backup:status >/dev/null || fail "backup:status fails after restore (own key/target undecryptable?)"
run_on ngb-frt-restore user:list | grep -q frtest || fail "the backed-up user is missing from the restored database"
docker exec ngb-frt-restore test -f /var/www/html/data/frtest/files/Docs/note.txt || fail "restored file is missing"
[ "$(docker exec ngb-frt-restore cat /var/www/html/data/frtest/files/Docs/note.txt)" = "hello disaster recovery" ] || fail "restored file content differs"
run_on ngb-frt-restore files:scan frtest >/dev/null || fail "files:scan fails after restore"
ok "database, files and ng_backup's own state are all intact and usable after the restore"

if [ "$DB" != sqlite ] && [ "$LEGACY" = legacy ]; then
	[ "$(sql restore "select count(*) from information_schema.tables where table_name = 'oc_deck_boards'")" = 0 ] || fail "a table of unknown structure was created"
	[ "$(sql restore "select count(*) from oc_mail_accounts where drafts_mailbox_id is not null")" = 1 ] || fail "the mail account was not restored"
	ok "older backup: tables of the disabled app left out, Mail restored"
elif [ "$DB" != sqlite ]; then
	[ "$(sql restore "select count(*) from information_schema.tables where table_name = 'oc_deck_boards'")" = 1 ] || fail "the tables of the disabled app were not created"
	[ "$(sql restore "select count(*) from oc_mail_accounts where drafts_mailbox_id is not null")" = 1 ] || fail "the mail account was not restored"
	[ "$(sql restore "select count(*) from information_schema.table_constraints where constraint_type = 'FOREIGN KEY' and table_name like 'oc_mail_%'")" -gt 0 ] \
		|| fail "the foreign keys of Mail are missing after the restore"
	printf '%s\n' "$OUT" | grep -q "Created .* tables" || fail "the restore did not report the tables it created"
	ok "tables of a disabled app created, Mail restored with its foreign keys"
fi

run_on ngb-frt-restore backup:run offsite -l post-restore-check >/dev/null || fail "a new backup after the restore fails"
ok "the restored instance can run a new backup of its own"

echo "FULL RESTORE ROUNDTRIP PASS ($DB${LEGACY:+, legacy})"
