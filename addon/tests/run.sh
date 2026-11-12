#!/bin/sh
# Renders a sample feed with the uploader's own serializer, then loads the addon against it under
# Lua 5.1 with stubbed WoW APIs, and drives the recorder with simulated game events. Needs Docker
# only. Checks logic and load errors, not the UI or real client behaviour in game.
# Also writes addon/tests/out/WowFlipLedger.lua, the saved variables the recorder produced.
set -eu

cd "$(dirname "$0")/../.."
. scripts/docker.sh
mkdir -p addon/tests/out

docker run --rm -v "$HERE:/repo" -v wfl-gocache:/root/.cache/go-build -w /repo/uploader \
    -e CGO_ENABLED=0 -e WFL_SAMPLE_OUT=/repo/addon/tests/out/Data.lua \
    golang:1.24-alpine go test ./internal/addon -run TestWriteSampleForLuaHarness -count=1

alpine_run '
    apk add --no-cache lua5.1 >/dev/null
    for f in addon/WowFlipLedger/*.lua addon/tests/out/Data.lua; do luac5.1 -p "$f" || exit 1; done
    echo "syntax ok"
    lua5.1 addon/tests/run.lua addon/tests/out/Data.lua addon/WowFlipLedger
    lua5.1 addon/tests/recorder.lua addon/WowFlipLedger addon/tests/out/WowFlipLedger.lua
'
