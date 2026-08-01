#!/usr/bin/env bash
# Build a distributable zip of the Tack Quotes plugin.
set -euo pipefail

PLUGIN_SLUG="tack-quotes"
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
	--exclude '*.md' \
	"$ROOT/" "$STAGE/"

cd "$DIST"
zip -r -q "$PLUGIN_SLUG.zip" "$PLUGIN_SLUG"
rm -rf "$STAGE"

echo "Built $DIST/$PLUGIN_SLUG.zip"
