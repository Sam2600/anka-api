#!/usr/bin/env bash
#
# Container entrypoint for anka-api.
#
# Same image is used by three services (web, queue, scheduler). Only the web
# service should run migrations + cache configs — the other two start AFTER
# web is healthy. We gate on the $ROLE env var the compose file sets.
#
# ROLE values:
#   web        — runs config/route/event cache then exec php-fpm
#   queue      — exec php artisan queue:work
#   scheduler  — exec php artisan schedule:work
#   migrate    — one-shot: run migrations, then exit 0

set -euo pipefail

ROLE="${ROLE:-web}"

# Fail fast if APP_KEY isn't set — laravel will throw cryptic errors otherwise.
if [[ -z "${APP_KEY:-}" ]]; then
    echo "FATAL: APP_KEY is empty. Generate one with: docker compose run --rm app php artisan key:generate --show" >&2
    exit 1
fi

# Wait for Postgres before any artisan command tries to hit it. The DB is on
# Supabase, not in compose, so we just check TCP reachability via PHP.
wait_for_db() {
    local max_attempts=30
    local attempt=1
    while [[ $attempt -le $max_attempts ]]; do
        if php -r "
            try {
                new PDO(
                    'pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE').';sslmode='.(getenv('DB_SSLMODE') ?: 'require'),
                    getenv('DB_USERNAME'),
                    getenv('DB_PASSWORD'),
                    [PDO::ATTR_TIMEOUT => 5]
                );
                exit(0);
            } catch (Throwable \$e) {
                exit(1);
            }
        " 2>/dev/null; then
            return 0
        fi
        echo "Waiting for Postgres at ${DB_HOST}:${DB_PORT} (attempt ${attempt}/${max_attempts})..."
        sleep 2
        attempt=$((attempt + 1))
    done
    echo "FATAL: could not connect to Postgres after ${max_attempts} attempts" >&2
    return 1
}

case "$ROLE" in
    web)
        wait_for_db

        # Run migrations on web boot. --force because production env. This is
        # idempotent: only new migrations run.
        php artisan migrate --force

        # Cache config/routes/events for production performance. Done at run
        # time (not build time) so env values are baked into the compiled
        # config cache.
        php artisan config:cache
        php artisan route:cache
        php artisan event:cache
        # NOTE: view:cache is not run — we have no Blade views in API mode.

        # Storage symlink: only matters if FILESYSTEM_DISK=public somewhere.
        # Idempotent — safe to run on every boot.
        php artisan storage:link || true

        exec "$@"
        ;;

    queue)
        wait_for_db
        # --tries=3 because some queued jobs hit Anthropic which can flake.
        # --timeout=120 matches php.ini max_execution_time.
        exec php artisan queue:work --tries=3 --timeout=120 --sleep=3
        ;;

    scheduler)
        wait_for_db
        # schedule:work is a long-running daemon — internally ticks every
        # minute and runs whatever's due per routes/console.php.
        exec php artisan schedule:work
        ;;

    migrate)
        wait_for_db
        php artisan migrate --force
        echo "Migrations complete."
        ;;

    *)
        echo "Unknown ROLE=${ROLE}. Expected: web|queue|scheduler|migrate" >&2
        exit 1
        ;;
esac
