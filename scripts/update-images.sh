#!/bin/sh
# Refreshes every upstream image the project uses. `docker compose pull` alone only covers
# services with a plain `image:` (nginx, mariadb); the app/scheduler image is built from
# docker/php/Dockerfile, whose bases (php, node, composer) only `build --pull` refreshes.
# Usage: scripts/update-images.sh [--no-restart]
set -eu

cd "$(dirname "$0")/.."

echo "==> docker compose pull (image-only services)"
docker compose pull --ignore-buildable

echo "==> docker compose build --pull (Dockerfile bases: php, node, composer)"
docker compose build --pull

# Helper images run through `docker run` by scripts, which compose never sees.
echo "==> helper images"
for img in $(grep -rhoE '(alpine):[A-Za-z0-9._-]+' scripts/*.sh | sort -u); do
    docker pull "$img"
done

if [ "${1:-}" != "--no-restart" ]; then
    echo "==> docker compose up -d"
    docker compose up -d
fi
