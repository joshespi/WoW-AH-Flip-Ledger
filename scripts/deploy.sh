#!/bin/sh
# Pulls the repo and brings the running stack up to date: rebuilds images only when Docker files
# changed, then composer, npm, build, migrate, addon zip and caches.
# Usage: scripts/deploy.sh [--no-pull] [--rebuild]
set -eu

cd "$(dirname "$0")/.."

pull=1
force_build=0
for arg in "$@"; do
    case "$arg" in
        --no-pull) pull=0 ;;
        --rebuild) force_build=1 ;;
        *) echo "Usage: $0 [--no-pull] [--rebuild]" >&2; exit 2 ;;
    esac
done

if [ ! -f .env ]; then
    echo "No .env found in $(pwd). Run scripts/setup.sh first." >&2
    exit 1
fi

# Paths whose change means the images or service definitions need rebuilding.
BUILD_PATHS="docker docker-compose.yml"

before=$(git rev-parse HEAD)
if [ "$pull" = 1 ]; then
    echo "==> git pull"
    git pull --ff-only
fi
after=$(git rev-parse HEAD)

rebuild=$force_build
# shellcheck disable=SC2086
if [ "$before" != "$after" ] && ! git diff --quiet "$before" "$after" -- $BUILD_PATHS; then
    echo "==> Docker files changed since $(git rev-parse --short "$before")"
    rebuild=1
fi

if [ "$rebuild" = 1 ]; then
    echo "==> docker compose up -d --build"
    docker compose up -d --build
else
    echo "==> docker compose up -d"
    docker compose up -d
fi

# The app entrypoint touches this when it has finished its own composer/npm/migrate pass.
echo "==> waiting for app entrypoint"
tries=0
until docker compose exec -T app test -f storage/framework/cache/.ready; do
    tries=$((tries + 1))
    if [ "$tries" -gt 100 ]; then
        echo "app did not become ready; check: docker compose logs app" >&2
        exit 1
    fi
    sleep 3
done

app() { docker compose exec -T app "$@"; }

app_env=$(grep -m1 '^APP_ENV=' .env | cut -d= -f2- | tr -d '"' || true)

echo "==> composer install"
if [ "$app_env" = "production" ]; then
    app composer install --no-dev --optimize-autoloader --no-interaction
else
    app composer install --optimize-autoloader --no-interaction
fi

echo "==> npm ci"
app npm ci --no-audit --no-fund

echo "==> npm run build"
app npm run build

echo "==> migrate"
app php artisan migrate --force

echo "==> addon:package"
app php artisan addon:package || echo "addon:package refused; the published addon zip is unchanged" >&2

echo "==> optimize:clear"
app php artisan optimize:clear
if [ "$app_env" = "production" ]; then
    echo "==> optimize"
    app php artisan optimize
fi

# Keep php-fpm workers (www-data) able to write what the root-run steps created.
app sh -c 'chown -R www-data:www-data storage bootstrap/cache' || true

# schedule:work keeps the old code loaded.
echo "==> restart scheduler"
docker compose restart scheduler

echo "Deployed $(git rev-parse --short HEAD)."
