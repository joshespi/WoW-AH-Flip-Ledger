# Sourced from the repo root. Sets ADDON_VERSION (the .toc version), ADDON_ZIP (the zip name) and
# ADDON_CHANGELOG (that version's CHANGELOG section); exits when the section is missing.
ADDON_VERSION=$(grep '^## Version:' addon/WowFlipLedger/WowFlipLedger.toc | sed 's/^## Version: *//' | tr -d '\r')
ADDON_ZIP="wowflipledger-addon-$ADDON_VERSION.zip"
ADDON_CHANGELOG=$(awk -v v="## $ADDON_VERSION" '$0 == v {on=1; next} /^## / {on=0} on' addon/CHANGELOG.md)
[ -n "$ADDON_CHANGELOG" ] || { echo "addon/CHANGELOG.md has no '## $ADDON_VERSION' section" >&2; exit 1; }
