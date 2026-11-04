#!/bin/sh
set -eu

# Open the HTTP listening port promptly so Vercel does not time out while
# PostgreSQL/Neon migrations or startup locks are still being prepared.
# Maintenance mode prevents handling business requests on an unmigrated schema.
php artisan down --retry=5 --no-interaction > /dev/null

php artisan serve --host=0.0.0.0 --port="${PORT:-6467}" &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT HUP INT TERM

if ! php artisan nexa:migrate-on-start --no-interaction; then
    echo "Nexa database preparation failed. API remains unavailable." >&2
    exit 1
fi

php artisan up --no-interaction > /dev/null
wait "$server_pid"
