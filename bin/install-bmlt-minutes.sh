#!/usr/bin/env bash
# Downloads BMLT Minutes (the plugin this importer imports into) from wordpress.org for the tests, checked against a
# pinned checksum, into tests/dependencies/bmlt-minutes/.
#
# Usage: bin/install-bmlt-minutes.sh [version sha256]
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
V=${1:-1.1.0}
SUM=${2:-74624c5326b88fd777daf80cc8ad911eba34020ac2f70b766343e83fa5678660}
DEST="$ROOT/tests/dependencies"
if [ -f "$DEST/bmlt-minutes/.version" ] && [ "$(cat "$DEST/bmlt-minutes/.version")" = "$V" ]; then
	echo "BMLT Minutes $V already in $DEST/bmlt-minutes"
	exit 0
fi
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
curl -fsSL --retry 5 -o "$TMP/m.zip" "https://downloads.wordpress.org/plugin/bmlt-minutes.$V.zip"
echo "$SUM  $TMP/m.zip" | sha256sum -c - >/dev/null || { echo "bmlt-minutes.$V.zip: checksum mismatch" >&2; exit 1; }
mkdir -p "$DEST"
rm -rf "$DEST/bmlt-minutes"
unzip -q "$TMP/m.zip" -d "$DEST"
echo "$V" > "$DEST/bmlt-minutes/.version"
cp "$TMP/m.zip" "$DEST/bmlt-minutes.zip"
echo "BMLT Minutes $V installed in $DEST/bmlt-minutes"
