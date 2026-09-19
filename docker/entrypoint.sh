#!/bin/sh
set -eu

mkdir -p \
  bootstrap/cache \
  storage/app/private \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data bootstrap/cache storage
fi

exec "$@"
