#!/bin/sh
# Uploads the addon zip that scripts/build-addon.sh produced to CurseForge.
#   CURSEFORGE_TOKEN=... scripts/publish-curseforge.sh [release|beta|alpha]
#
# Needs CURSEFORGE_TOKEN (an API token, never commit it); CURSEFORGE_PROJECT_ID defaults to ours (1783753).
# Game version ids are looked up by name, so GAME_VERSIONS must match names CurseForge lists
# (see https://wow.curseforge.com/api/game/versions with your token).
#   GAME_VERSIONS="12.1.5,1.60.1"   comma separated, defaults to that
set -eu

cd "$(dirname "$0")/.."

: "${CURSEFORGE_TOKEN:?set CURSEFORGE_TOKEN}"
CURSEFORGE_PROJECT_ID="${CURSEFORGE_PROJECT_ID:-1783753}"
TYPE="${1:-release}"
GAME_VERSIONS="${GAME_VERSIONS:-12.1.5,1.60.1}"

. scripts/addon-version.sh
VERSION=$ADDON_VERSION
CHANGELOG=$ADDON_CHANGELOG
ZIP="addon/dist/$ADDON_ZIP"
[ -f "$ZIP" ] || { echo "missing $ZIP: run scripts/build-addon.sh first" >&2; exit 1; }

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
# --form-string, not -F: -F reads ';' in a value as a parameter and cuts the JSON there.
RESP=$(mktemp)
trap 'rm -f "$RESP"' EXIT
CODE=$(curl -sS -o "$RESP" -w '%{http_code}' -H "X-Api-Token: $CURSEFORGE_TOKEN" \
    --form-string "metadata=$META" -F "file=@$ZIP" \
    "$API/projects/$CURSEFORGE_PROJECT_ID/upload-file")
cat "$RESP"
echo
case "$CODE" in
    2??) ;;
    *) echo "CurseForge answered HTTP $CODE" >&2; exit 1 ;;
esac
