#!/bin/sh
# Cross-compiles the uploader in Docker and writes uploader/dist/ with a SHA256SUMS file.
#   scripts/build-uploader.sh            # version from internal/version/version.go
#   scripts/build-uploader.sh 0.2.0
#
# Targets: windows/amd64, darwin/amd64, darwin/arm64, and linux/amd64 (for Wine/Proton setups and
# testing; start-at-login is not supported there). Pure Go, no cgo, so no toolchain on the
# target. -trimpath keeps builds reproducible and free of local paths.
set -eu

cd "$(dirname "$0")/.."

VERSION="${1:-$(grep 'Version = ' uploader/internal/version/version.go | cut -d'"' -f2)}"

rm -rf uploader/dist

# uploader.sh owns the Docker invocation (image, mounts, Windows path handling); the version
# reaches the inner script as $1.
scripts/uploader.sh sh -c '
    set -eu
    VERSION=$1
    go vet ./... && go test -count=1 ./... >/dev/null
    mkdir -p dist
    for target in windows/amd64 darwin/amd64 darwin/arm64 linux/amd64; do
        os=${target%/*}; arch=${target#*/}; ext=""
        [ "$os" = windows ] && ext=".exe"
        GOOS=$os GOARCH=$arch go build -trimpath \
            -ldflags "-s -w -X wowflipledger/uploader/internal/version.Version=$VERSION" \
            -o "dist/wowflipledger-uploader-$VERSION-$os-$arch$ext" .
    done
    cd dist && sha256sum wowflipledger-uploader-* > SHA256SUMS && cat SHA256SUMS && ls -l
' build "$VERSION"

# Publish for the app's /setup page: it serves only what is listed in SHA256SUMS from here.
# (Production deploys run this script too; storage/app is git-ignored.)
PUBLISH=src/storage/app/uploader
rm -rf "$PUBLISH"
mkdir -p "$PUBLISH"
cp uploader/dist/* "$PUBLISH"/

# The in-game addon, zipped with its folder at the top so unzipping into AddOns just works. Fixed
# file times and a sorted file list keep the zip reproducible. Its hash joins the published
# SHA256SUMS, which is where the app lists it from.
. scripts/addon-version.sh
HERE=$(pwd -W 2>/dev/null || pwd)
MSYS_NO_PATHCONV=1 docker run --rm -v "$HERE:/repo" -w /repo alpine:3.20 sh -c '
    set -eu
    apk add --no-cache zip >/dev/null
    rm -rf /tmp/addon && mkdir /tmp/addon && cp -r addon/WowFlipLedger /tmp/addon/
    find /tmp/addon -exec touch -d "2020-01-01 00:00:00" {} +
    cd /tmp/addon && find WowFlipLedger -type f | sort | zip -X -q "/repo/$0" -@
' "$PUBLISH/$ADDON_ZIP"
(cd "$PUBLISH" && sha256sum "$ADDON_ZIP" >> SHA256SUMS)
echo "Published to $PUBLISH"
