#!/usr/bin/env bash
# Builds the release zip into dist/ from the committed files:
#
#   bmlt-minutes-archive-importer-<version>.zip   folder bmlt-minutes-archive-importer/   (Plugins → Add New → Upload Plugin)
#
# The zip contains build.json (version, commit, build time) and checksums.sha256 (every file), and gets a
# <zip>.sha256 next to it. Files are taken with git archive, so nothing uncommitted or ignored can slip in;
# file times are the commit's, so a build of the same commit is byte-for-byte reproducible.
#
# Usage: bin/build.sh [--dev] [--allow-dirty]
#   --dev          mark build.json "development": true (CI builds of commits that are not a release tag)
#   --allow-dirty  build although the working tree has uncommitted changes (still from the last commit)
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DEV=0
DIRTY=0
for arg in "$@"; do
	case "$arg" in
		--dev) DEV=1 ;;
		--allow-dirty) DIRTY=1 ;;
		*) sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
	esac
done
cd "$ROOT"
fail() { echo "build: $*" >&2; exit 1; }
P=bmlt-minutes-archive-importer

# One version everywhere.
V=$(sed -n 's/^ \* Version: *//p' "$P/$P.php")
[ -n "$V" ] || fail "no Version in the plugin header"
check() { [ "$2" = "$V" ] || fail "$1 says $2, the plugin header says $V"; }
check "VERSION constant" "$(sed -n "s/^ *public const VERSION *= '\(.*\)';/\1/p" "$P/$P.php")"
check "readme.txt Stable tag" "$(sed -n 's/^Stable tag: *//p' "$P/readme.txt" | tr -d '[:space:]')"
grep -q "^## $V " CHANGELOG.md || fail "CHANGELOG.md has no entry for $V"
grep -q "^= $V =" "$P/readme.txt" || fail "readme.txt has no changelog entry for $V"
# Every PHP file carries its version ("@version x.y.z").
OTHER=$(grep -rlE "@version [0-9]+\.[0-9]+\.[0-9]+" "$P" | xargs grep -LE "@version $V" || true)
[ -z "$OTHER" ] || fail "files with another version: $OTHER"

if [ -n "$(git status --porcelain)" ] && [ "$DIRTY" = 0 ]; then
	fail "uncommitted changes (commit them, or use --allow-dirty)"
fi
COMMIT=$(git rev-parse HEAD)
STAMP=$(git log -1 --format=%ct)
if [ "$DEV" = 0 ] && git rev-parse -q --verify "refs/tags/v$V" >/dev/null && [ "$(git rev-parse "v$V^{commit}")" != "$COMMIT" ]; then
	fail "v$V is already tagged on another commit; bump the version (or use --dev for a test build)"
fi

DIST="$ROOT/dist"
mkdir -p "$DIST"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
ZIP="$P-$V.zip"
STAGE="$WORK/$P"
mkdir -p "$WORK/x" "$STAGE"
echo "Building $V from ${COMMIT:0:12}$([ "$DEV" = 1 ] && echo ' (development)')"
git archive --format=tar "$COMMIT" "$P" | tar -x -C "$WORK/x"
cp -a "$WORK/x/$P/." "$STAGE/"
FILES=$(cd "$STAGE" && find . -type f | wc -l)
printf '{\n\t"name": "%s",\n\t"version": "%s",\n\t"commit": "%s",\n\t"built": "%s",\n\t"files": %d%s\n}\n' \
	"$P" "$V" "$COMMIT" "$(date -u -d "@$STAMP" +%Y-%m-%dT%H:%M:%SZ)" "$((FILES + 2))" "$([ "$DEV" = 1 ] && printf ',\n\t"development": true')" > "$STAGE/build.json"
(cd "$STAGE" && find . -type f ! -name checksums.sha256 | LC_ALL=C sort | sed 's#^\./##' | xargs -d '\n' sha256sum > checksums.sha256)
# Same permissions on every machine whatever its umask, so builds are identical everywhere.
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
find "$STAGE" -exec touch -h -d "@$STAMP" {} +
rm -f "$DIST/$ZIP"
(cd "$WORK" && find "$P" -print | LC_ALL=C sort | TZ=UTC zip -q -X "$DIST/$ZIP" -@)
(cd "$DIST" && sha256sum "$ZIP" > "$ZIP.sha256")
echo "  $ZIP  $(cut -c1-16 < "$DIST/$ZIP.sha256")…  $((FILES + 2)) files, $(du -h "$DIST/$ZIP" | cut -f1)"
