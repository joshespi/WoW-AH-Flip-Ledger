#!/bin/bash
set -e

cd /var/www/html

if [ ! -f composer.json ]; then
    echo "[entrypoint] no Laravel app in src/ (composer.json missing)" >&2
    exit 1
fi

# The scheduler waits on this marker; clear it so a restart re-waits for migrations.
rm -f storage/framework/cache/.ready

# Production gets a lean install. Anywhere else keeps dev deps so PHPUnit/Pint survive restarts.
if [ "${APP_ENV}" = "production" ]; then
    echo "[entrypoint] composer install (no dev deps)"
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
else
    echo "[entrypoint] composer install (dev deps included, APP_ENV=${APP_ENV:-unset})"
    composer install --optimize-autoloader --no-interaction --quiet
fi

# npm ci only when node_modules is missing or package-lock.json changed.
if [ ! -d node_modules ] || [ package-lock.json -nt node_modules/.package-lock.json ]; then
    echo "[entrypoint] npm ci"
    npm ci --no-audit --no-fund
fi

echo "[entrypoint] npm run build"
npm run build

echo "[entrypoint] migrate"
php artisan migrate --force

# optimize caches config; prod only. It would freeze .env and could cache the real DB
# connection over phpunit's forced sqlite one.
if [ "${APP_ENV}" = "production" ]; then
    echo "[entrypoint] optimize"
    php artisan optimize
else
    php artisan optimize:clear
fi

# After every root-run artisan step: migrate can create laravel.log and optimize writes compiled
# views, and php-fpm workers (www-data) must still be able to write both.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

touch storage/framework/cache/.ready

echo "[entrypoint] starting: $*"
exec "$@"
