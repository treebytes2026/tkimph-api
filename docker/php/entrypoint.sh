#!/usr/bin/env sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ "${RUN_LARAVEL_SETUP:-false}" = "true" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        php artisan key:generate --force
    fi

    php artisan migrate --force
    php artisan storage:link || true
fi

exec "$@"
