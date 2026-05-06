#!/bin/sh
# Container entrypoint:
#   1. Generate APP_KEY at runtime if the deployer didn't supply one (dev convenience).
#   2. Optionally run migrations when RUN_MIGRATIONS=true.
#   3. Hand off to whatever CMD was passed (default: supervisord).
set -eu

cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

if [ -z "${APP_KEY:-}" ] && [ ! -f .env ]; then
  echo "[entrypoint] APP_KEY not set and no .env present; generating an ephemeral key (set APP_KEY in your env for stability)."
  php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;" > /tmp/app.key
  export APP_KEY="$(cat /tmp/app.key)"
fi

php artisan config:clear --quiet || true
php artisan route:clear  --quiet || true
php artisan view:clear   --quiet || true

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "[entrypoint] Running database migrations..."
  php artisan migrate --force
fi

exec "$@"
