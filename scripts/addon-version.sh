# Sourced from the repo root. Reads the shared release version and its addon changelog.
ADDON_VERSION=$(tr -d '\r' < src/VERSION)
if ! printf '%s\n' "$ADDON_VERSION" | awk 'END { exit !(NR == 1 && $0 ~ /^[0-9]+\.[0-9]+\.[0-9]+$/) }'; then
    echo 'src/VERSION must contain an x.y.z release number' >&2
    exit 1
fi
ADDON_ZIP="wowflipledger-addon-$ADDON_VERSION.zip"
ADDON_CHANGELOG=$(awk -v v="## $ADDON_VERSION" '$0 == v {on=1; next} /^## / {on=0} on' addon/CHANGELOG.md)
[ -n "$ADDON_CHANGELOG" ] || { echo "addon/CHANGELOG.md has no '## $ADDON_VERSION' section" >&2; exit 1; }
