#!/usr/bin/env bash
# Build a distributable zip of the Tack Quotes plugin.
set -euo pipefail

PLUGIN_SLUG="tackquote"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
STAGE="$DIST/$PLUGIN_SLUG"

rm -rf "$DIST"
mkdir -p "$STAGE"

# Copy runtime files only (exclude dev/build artifacts).
rsync -a --delete \
	--exclude 'dist' \
	--exclude 'bin' \
	--exclude '.git*' \
	--exclude 'vendor' \
	--exclude 'node_modules' \
	--exclude 'tests' \
	`# WordPress.org listing assets — banners, icons and screenshots. They`\
	`# belong to the DIRECTORY PAGE, not the plugin: wordpress.org reads them`\
	`# from the SVN assets/ folder, and every byte shipped here is downloaded`\
	`# by every user on every install and update for nothing. Leaving them in`\
	`# took the zip from 84KB to 625KB.`\
	--exclude '.wordpress-org' \
	--exclude '*.dist' \
	--exclude '.DS_Store' \
	--exclude '*.md' \
	"$ROOT/" "$STAGE/"

cd "$DIST"
zip -r -q "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
rm -rf "$STAGE"

# ── The zip must contain the PLUGIN and nothing else ────────────────────────
#
# An exclusion that silently stops matching is invisible: the build still
# succeeds, the plugin still works, and the only symptom is a zip that quietly
# grew. That already happened once — WordPress.org listing assets landed in
# .wordpress-org/, `--exclude '.git*'` did not cover them, and the zip went from
# 84KB to 625KB of images downloaded by every user on every update.
#
# So assert the outcome rather than trusting the exclusion list.
ENTRIES=$(unzip -l "$DIST/$PLUGIN_SLUG.zip" | grep -E '^[[:space:]]+[0-9]+[[:space:]]')
LEAKED=$(printf '%s\n' "$ENTRIES" | grep -cE '\.(png|jpg|jpeg|gif|zip)$' || true)
if [ "$LEAKED" -gt 0 ]; then
	echo "BUILD FAILED: $LEAKED image/archive file(s) are inside the plugin zip." >&2
	echo "Listing assets belong in .wordpress-org/ and must be excluded above." >&2
	printf '%s\n' "$ENTRIES" | grep -E '\.(png|jpg|jpeg|gif|zip)$' >&2
	exit 1
fi

echo "Built $DIST/$PLUGIN_SLUG.zip"
