#!/bin/sh
# Uploads the addon zip that scripts/build-uploader.sh produced to CurseForge.
#   CURSEFORGE_TOKEN=... scripts/publish-curseforge.sh [release|beta|alpha]
#
# NOT YET RUN: it follows CurseForge's upload API as documented but has never been used against a
# real project. Needs CURSEFORGE_TOKEN (an API token, never commit it) and CURSEFORGE_PROJECT_ID.
# Game version ids are looked up by name, so GAME_VERSIONS must match names CurseForge lists
# (see https://wow.curseforge.com/api/game/versions with your token).
#   GAME_VERSIONS="12.1.5"          comma separated, defaults to that
set -eu

cd "$(dirname "$0")/.."

: "${CURSEFORGE_TOKEN:?set CURSEFORGE_TOKEN}"
: "${CURSEFORGE_PROJECT_ID:?set CURSEFORGE_PROJECT_ID (the number on the project page)}"
TYPE="${1:-release}"
GAME_VERSIONS="${GAME_VERSIONS:-12.1.5}"

. scripts/addon-version.sh
VERSION=$ADDON_VERSION
CHANGELOG=$ADDON_CHANGELOG
ZIP="src/storage/app/uploader/$ADDON_ZIP"
[ -f "$ZIP" ] || { echo "missing $ZIP: run scripts/build-uploader.sh first" >&2; exit 1; }

API="https://wow.curseforge.com/api"

# Names to ids.
ALL=$(curl -fsS -H "X-Api-Token: $CURSEFORGE_TOKEN" "$API/game/versions")
IDS=""
for name in $(echo "$GAME_VERSIONS" | tr ',' ' '); do
    id=$(echo "$ALL" | tr '{' '\n' | grep "\"name\":\"$name\"" | head -1 | sed 's/.*"id":\([0-9]*\).*/\1/')
    [ -n "$id" ] || { echo "CurseForge has no game version named '$name'" >&2; exit 1; }
    IDS="$IDS${IDS:+,}$id"
done

# JSON-escape the changelog.
ESC=$(printf '%s' "$CHANGELOG" | sed ':a;N;$!ba;s/\\/\\\\/g;s/"/\\"/g;s/\n/\\n/g')
META="{\"changelog\":\"$ESC\",\"changelogType\":\"markdown\",\"displayName\":\"WoW Flip Ledger $VERSION\",\"gameVersions\":[$IDS],\"releaseType\":\"$TYPE\"}"

echo "Uploading $ZIP to project $CURSEFORGE_PROJECT_ID as $TYPE ($GAME_VERSIONS)"
curl -fsS -H "X-Api-Token: $CURSEFORGE_TOKEN" \
    -F "metadata=$META" -F "file=@$ZIP" \
    "$API/projects/$CURSEFORGE_PROJECT_ID/upload-file"
echo
