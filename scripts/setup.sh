#!/bin/sh
# First-run setup: creates .env from .env.example, fills the blank secrets, starts the stack.
# Reruns are safe: only blank values are filled, so a live secret is never rotated.
# Usage: scripts/setup.sh [--no-start]
set -eu

cd "$(dirname "$0")/.."

[ -f .env ] || cp .env.example .env

rand() { head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

fill() {
    key="$1"
    val="$2"
    grep -q "^${key}=$" .env || return 0
    awk -v k="$key" -v v="$val" 'BEGIN { FS = OFS = "=" } $1 == k && NF == 2 && $2 == "" { print k "=" v; next } { print }' .env > .env.tmp
    cat .env.tmp > .env
    rm .env.tmp
}

fill APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
fill DB_PASSWORD "$(rand 24)"
fill DB_ROOT_PASSWORD "$(rand 24)"

if [ "${1:-}" = "--no-start" ]; then
    echo ".env ready."
    exit 0
fi

docker compose up -d --build
echo "Up at $(grep -m1 '^APP_URL=' .env | cut -d= -f2-) (first start installs dependencies and builds assets; watch: docker compose logs -f app)"
