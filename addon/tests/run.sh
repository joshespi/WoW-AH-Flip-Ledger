#!/bin/sh
# Loads the addon under Lua 5.1 with stubbed WoW APIs, against the sample feed in fixtures/Data.lua,
# and drives the recorder with simulated game events. Needs Docker only. Checks logic and load
# errors, not the UI or real client behaviour in game.
# Also writes addon/tests/out/WowFlipLedger.lua, the saved variables the recorder produced.
set -eu

cd "$(dirname "$0")/../.."
. scripts/docker.sh
mkdir -p addon/tests/out

alpine_run '
    apk add --no-cache lua5.1 >/dev/null
    for f in addon/WowFlipLedger/*.lua addon/tests/fixtures/Data.lua; do luac5.1 -p "$f" || exit 1; done
    echo "syntax ok"
    lua5.1 addon/tests/run.lua addon/tests/fixtures/Data.lua addon/WowFlipLedger
    lua5.1 addon/tests/recorder.lua addon/WowFlipLedger addon/tests/out/WowFlipLedger.lua
    lua5.1 addon/tests/prices.lua addon/WowFlipLedger
'
