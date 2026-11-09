#!/usr/bin/env bash
#
# Builds dist/plugin-settings-finder.zip: the folder plugin-settings-finder/
# with runtime files only (no tests, vendor, CI or dev config). This is the
# file to attach to a GitHub release; the README's install links expect
# exactly this name.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="plugin-settings-finder"
FILES=(plugin-settings-finder.php uninstall.php includes assets languages extras readme.txt README.md LICENSE)

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$SLUG" "$ROOT/dist"

for path in "${FILES[@]}"; do
	[ -e "$ROOT/$path" ] || { echo "Missing $path" >&2; exit 1; }
	cp -R "$ROOT/$path" "$STAGE/$SLUG/"
done
find "$STAGE" -name '.DS_Store' -delete

VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/plugin-settings-finder.php")"
rm -f "$ROOT/dist/$SLUG.zip"
(cd "$STAGE" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG")
echo "✓ dist/$SLUG.zip (version $VERSION, $(unzip -l "$ROOT/dist/$SLUG.zip" | tail -n 1 | awk '{print $2}') files)"
