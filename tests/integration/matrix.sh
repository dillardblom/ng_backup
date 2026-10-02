#!/bin/bash
# Local test matrix for NG Backup: fresh Nextcloud per (version, database), run the round trip, tear down.
# Usage: tests/integration/matrix.sh "32 35" "sqlite mariadb pgsql"
VERSIONS=${1:-"32 35"}; DBS=${2:-"sqlite mariadb pgsql"}
APP=$(cd "$(dirname "$0")/../.." && pwd)
RESULTS=()
for v in $VERSIONS; do for db in $DBS; do
  n=ngbm-$v-$db; net=$n-net
  docker network create $net >/dev/null
  env=(-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin-matrix-pw -e NEXTCLOUD_TRUSTED_DOMAINS=localhost)
  case $db in
    sqlite) env+=(-e SQLITE_DATABASE=nextcloud) ;;
    mariadb) docker run -d --name $n-db --network $net -e MARIADB_ROOT_PASSWORD=r -e MARIADB_DATABASE=nextcloud -e MARIADB_USER=nc -e MARIADB_PASSWORD=nc mariadb:11 --transaction-isolation=READ-COMMITTED --binlog-format=ROW >/dev/null
             env+=(-e MYSQL_HOST=$n-db -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=nc -e MYSQL_PASSWORD=nc) ;;
    pgsql) docker run -d --name $n-db --network $net -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nc -e POSTGRES_PASSWORD=nc postgres:16-alpine >/dev/null
           env+=(-e POSTGRES_HOST=$n-db -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nc -e POSTGRES_PASSWORD=nc) ;;
  esac
  [ "$db" != sqlite ] && sleep 8
  docker run -d --name $n --network $net "${env[@]}" -v "$APP:/var/www/html/custom_apps/ng_backup:ro" nextcloud:$v-apache >/dev/null
  for i in $(seq 1 90); do docker exec -u www-data $n php occ status 2>/dev/null | grep -q "installed: true" && break; sleep 4; done
  docker exec $n chown www-data:www-data /var/www/html/custom_apps
  echo "=== NC $v / $db: $(docker exec -u www-data $n php occ -V 2>/dev/null)"
  if bash ${NGB_TRACE:+-x} "$APP/tests/integration/roundtrip.sh" "docker exec -u www-data $n" /var/www/html/data /var/www/html/custom_apps; then
    RESULTS+=("NC $v / $db: PASS")
  else
    RESULTS+=("NC $v / $db: FAIL"); docker exec $n tail -c 2000 /var/www/html/data/nextcloud.log | tr ',' '\n' | grep -E '"message"' | tail -3
  fi
  # -v removes only these containers' own anonymous volumes (never a global prune)
  docker rm -fv $n $n-db >/dev/null 2>&1; docker network rm $net >/dev/null
done; done
printf '%s\n' "${RESULTS[@]}"
# Non-zero exit when any combination failed (CI).
printf '%s\n' "${RESULTS[@]}" | grep -q FAIL && exit 1
exit 0
