#!/bin/sh
# Zips the in-game addon into addon/dist/ (git-ignored), the file scripts/publish-curseforge.sh uploads.
#   scripts/build-addon.sh
#
# The folder is at the top of the zip so unzipping into AddOns just works. Fixed file times and
# modes and a sorted file list keep the zip reproducible.
set -eu

cd "$(dirname "$0")/.."
. scripts/docker.sh
. scripts/addon-version.sh

OUT=addon/dist
rm -rf "$OUT"
mkdir -p "$OUT"

alpine_run '
    set -eu
    apk add --no-cache zip >/dev/null
    rm -rf /tmp/addon && mkdir /tmp/addon && cp -r addon/WowFlipLedger /tmp/addon/
    find /tmp/addon -type f -exec chmod 644 {} +
    find /tmp/addon -exec touch -d "2020-01-01 00:00:00" {} +
    cd /tmp/addon && find WowFlipLedger -type f | sort | zip -X -q "/repo/$0" -@
' "$OUT/$ADDON_ZIP"
echo "Built $OUT/$ADDON_ZIP"
