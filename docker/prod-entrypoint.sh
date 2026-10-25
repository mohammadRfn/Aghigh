#!/bin/sh
# Prod image only: warm framework caches at container start, then run the normal entrypoint.
set -eu

php artisan config:cache
php artisan event:cache
# Closure routes cannot be cached; tolerate that until every route uses a controller.
php artisan route:cache || echo "route:cache skipped (closure routes present)" >&2

exec docker-php-entrypoint "$@"
