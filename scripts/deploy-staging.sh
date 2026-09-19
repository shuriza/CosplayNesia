#!/bin/sh
set -eu

root=${DEPLOY_ROOT:-/opt/cosplaynesia}
release_sha=${RELEASE_SHA:?RELEASE_SHA is required}
release_dir="$root/releases/$release_sha"
current_link="$root/current"
previous_dir=''
backup=''

if [ -L "$current_link" ]; then
  previous_dir=$(readlink -f "$current_link")
fi

compose() {
  release="$1"
  shift
  docker compose --project-name cosplaynesia-staging --env-file "$release/.env.staging" --file "$release/compose.staging.yml" "$@"
}

mkdir -p "$root/backups"
chmod 700 "$root" "$root/backups" "$root/releases" "$release_dir"
chmod 600 "$release_dir/.env.staging"

if [ -n "$previous_dir" ] && [ -f "$previous_dir/.env.staging" ]; then
  backup="$root/backups/pre-$release_sha.dump"
  compose "$previous_dir" exec -T postgres pg_dump \
    --username=cosplaynesia \
    --dbname=cosplaynesia \
    --format=custom > "$backup"
  chmod 600 "$backup"
fi

rollback() {
  set +e
  compose "$release_dir" stop proxy scheduler worker web migrate >/dev/null 2>&1
  set -e

  if [ -z "$previous_dir" ] || [ ! -f "$previous_dir/.env.staging" ] || [ ! -s "$backup" ]; then
    echo 'Rollback is impossible: no verified previous release and pre-deploy backup exist.' >&2
    return 1
  fi

  compose "$release_dir" exec -T postgres dropdb \
    --username=cosplaynesia \
    --maintenance-db=postgres \
    --force \
    --if-exists \
    cosplaynesia
  compose "$release_dir" exec -T postgres createdb \
    --username=cosplaynesia \
    --maintenance-db=postgres \
    --owner=cosplaynesia \
    cosplaynesia
  compose "$release_dir" exec -T postgres pg_restore \
    --username=cosplaynesia \
    --dbname=cosplaynesia \
    --exit-on-error < "$backup"

  compose "$previous_dir" up --detach --remove-orphans --wait --wait-timeout 180
  curl --fail --silent --show-error --retry 12 --retry-all-errors --retry-delay 5 \
    "https://$STAGING_HOST/ready" >/dev/null
  ln -sfn "$previous_dir" "$current_link"
}

trap rollback INT TERM HUP

if ! compose "$release_dir" config --quiet \
  || ! compose "$release_dir" build --pull \
  || ! compose "$release_dir" up --detach --remove-orphans --wait --wait-timeout 180; then
  rollback || true
  exit 1
fi

if ! curl --fail --silent --show-error --retry 12 --retry-all-errors --retry-delay 5 \
  "https://$STAGING_HOST/ready" >/dev/null; then
  rollback || true
  exit 1
fi

ln -sfn "$release_dir" "$current_link"

# Keep the five newest releases and backups. Images remain content-addressed by commit;
# remove only unreferenced layers after the rollback window has been retained.
find "$root/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
  | sort -nr \
  | awk 'NR > 5 { sub(/^[^ ]+ /, ""); print }' \
  | xargs -r rm -rf
find "$root/backups" -mindepth 1 -maxdepth 1 -type f -printf '%T@ %p\n' \
  | sort -nr \
  | awk 'NR > 5 { sub(/^[^ ]+ /, ""); print }' \
  | xargs -r rm -f
docker image prune --force >/dev/null
