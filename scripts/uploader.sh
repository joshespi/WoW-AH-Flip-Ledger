#!/bin/sh
# Runs a command in the Go toolchain image against ./uploader (no local Go install).
#   scripts/uploader.sh go test ./...
#   scripts/uploader.sh go vet ./...
#   scripts/uploader.sh gofmt -l .
set -eu

cd "$(dirname "$0")/.."

# Git Bash on Windows needs a Windows-style path for docker -v and must not rewrite /src.
HERE=$(pwd -W 2>/dev/null || pwd)
export MSYS_NO_PATHCONV=1

exec docker run --rm \
    -v "$HERE/uploader:/src" \
    -v wfl-gocache:/root/.cache/go-build \
    -w /src -e CGO_ENABLED=0 \
    golang:1.24-alpine "$@"
