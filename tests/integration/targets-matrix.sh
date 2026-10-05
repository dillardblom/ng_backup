#!/bin/bash
# Round trip of NG Backup against real S3 (s3mock) and SFTP backends, through files_external's own
# backend/auth classes, same as a production location would use. Backend correctness does not
# depend on the Nextcloud version or database, so this runs once (not matrixed across NC/DB),
# on a recent Nextcloud with sqlite. Each backend gets its own fresh Nextcloud container, since
# NG Backup's key can only be initialised once per instance.
# Usage: tests/integration/targets-matrix.sh [nextcloud-version]
set -euo pipefail
V=${1:-35}
APP=$(cd "$(dirname "$0")/../.." && pwd)
NET=ngb-targets-net
RESULTS=()

cleanup() { docker rm -fv ngb-targets-nc-s3 ngb-targets-nc-sftp ngb-targets-s3 ngb-targets-sftp >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; }
trap cleanup EXIT

wait_tcp() { # host port
  docker run --rm --network "$NET" alpine:3 sh -c "for i in \$(seq 1 30); do nc -z $1 $2 2>/dev/null && exit 0; sleep 1; done; exit 1" \
    || { echo "FAIL: $1:$2 never came up"; exit 1; }
}

start_nc() { # container-name
  docker run -d --name "$1" --network "$NET" \
    -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin-targets-pw -e NEXTCLOUD_TRUSTED_DOMAINS=localhost \
    -e SQLITE_DATABASE=nextcloud \
    -v "$APP:/var/www/html/custom_apps/ng_backup:ro" nextcloud:$V-apache >/dev/null
  for i in $(seq 1 90); do docker exec -u www-data "$1" php occ status 2>/dev/null | grep -q "installed: true" && break; sleep 4; done
  docker exec "$1" chown www-data:www-data /var/www/html/custom_apps
  echo "=== NC $V / sqlite ($1): $(docker exec -u www-data "$1" php occ -V 2>/dev/null)"
}

run() { # label, nc-container, target-args
  echo "=== $1"
  if bash ${NGB_TRACE:+-x} "$APP/tests/integration/roundtrip.sh" "docker exec -u www-data $2" /var/www/html/data /var/www/html/custom_apps "$3"; then
    RESULTS+=("$1: PASS")
  else
    RESULTS+=("$1: FAIL")
    docker exec "$2" tail -c 2000 /var/www/html/data/nextcloud.log | tr ',' '\n' | grep -E '"message"' | tail -3
  fi
}

docker network create "$NET" >/dev/null

# adobe/s3mock: an S3-compatible test server (MinIO's Docker Hub image now needs a paid login;
# this one is freely pullable and pre-creates a bucket via INITIAL_BUCKETS, no auth to configure).
docker run -d --name ngb-targets-s3 --network "$NET" \
  -e INITIAL_BUCKETS=ngbackups \
  adobe/s3mock >/dev/null

# atmoz/sftp: "user:pass:::dir" creates /home/user/dir as the upload root.
docker run -d --name ngb-targets-sftp --network "$NET" \
  atmoz/sftp ngbackup:ngbackuppw:::upload >/dev/null

wait_tcp ngb-targets-s3 9090
wait_tcp ngb-targets-sftp 22

start_nc ngb-targets-nc-s3
run "S3 (s3mock)" ngb-targets-nc-s3 "amazons3 -a amazons3::accesskey -o bucket=ngbackups -o hostname=ngb-targets-s3 -o port=9090 -o region=us-east-1 -o use_ssl=false -o use_path_style=true -o key=test -o secret=test"

start_nc ngb-targets-nc-sftp
run "SFTP" ngb-targets-nc-sftp "sftp -a password::password -o host=ngb-targets-sftp -o root=/upload -o user=ngbackup -o password=ngbackuppw"

printf '%s\n' "${RESULTS[@]}"
printf '%s\n' "${RESULTS[@]}" | grep -q FAIL && exit 1
exit 0
