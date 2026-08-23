#!/usr/bin/env bash
#
# Build the installable TackQuoteApp zip, with the app secret injected.
#
# WHY THIS SCRIPT EXISTS
#
# Two things were wrong with the hand-built v1.2.0 asset, and both made the
# install fail with "No manifest.xml found" on a Shopware Cloud shop:
#
#  1. NO <secret>. Shopware's docs: "If you are developing a private app not
#     published in the Shopware Store, you must provide the <secret> in case of
#     an external app server." That is this app. The repository cannot carry the
#     secret (it is public), so it is injected here at package time.
#
#  2. DIRECTORY ENTRIES. Shopware's own packaging tool never emits them —
#     shopware-cli's internal/archiver/zip.go recurses directories and only ever
#     adds FILES. So the canonical artifact has index 0 = TackQuoteApp/manifest.xml,
#     while `zip -r` produces a bare `TackQuoteApp/` entry at index 0. Core's
#     PluginZipDetector::isApp() tolerates that, but Shopware Cloud runs a
#     different, non-public codebase and the directory entry was the ONLY
#     structural difference from what official tooling produces. `-D` omits them.
#
# Usage:
#   SHOPWARE_APP_SECRET=<64-char secret> bin/build-zip.sh [outdir]
#
# The secret MUST equal the API's SHOPWARE_APP_SECRET env var — that is what
# ShopwareAppService.requireAppSecret() verifies the registration signature
# against. Read it from the running API rather than inventing a new one:
#
#   docker inspect tack-api-1 --format \
#     '{{range .Config.Env}}{{println .}}{{end}}' | grep '^SHOPWARE_APP_SECRET='

set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$HERE")"          # .../shopware/TackQuoteApp
APP_NAME="$(basename "$APP_DIR")"     # TackQuoteApp — must equal <meta><name>
OUT="${1:-$APP_DIR/dist}"

if [ -z "${SHOPWARE_APP_SECRET:-}" ]; then
  echo "ERROR: SHOPWARE_APP_SECRET is not set." >&2
  echo "Without it the app uploads but registration can never succeed — for an" >&2
  echo "unpublished private app there is nothing else to authenticate with." >&2
  exit 2
fi
# Fail loudly on a placeholder rather than shipping an app that installs and
# then silently cannot register.
case "$SHOPWARE_APP_SECRET" in
  *CHANGE*|*changeme*|*xxx*|*TODO*)
    echo "ERROR: SHOPWARE_APP_SECRET looks like a placeholder: $SHOPWARE_APP_SECRET" >&2; exit 2 ;;
esac
if [ "${#SHOPWARE_APP_SECRET}" -lt 32 ]; then
  echo "ERROR: SHOPWARE_APP_SECRET is ${#SHOPWARE_APP_SECRET} chars; expected >= 32." >&2
  exit 2
fi

python3 "$HERE/validate-manifest.py" >/dev/null

STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$APP_NAME" "$OUT"

# Only what the app needs. bin/ is dev tooling and is not shipped.
cp "$APP_DIR/manifest.xml" "$APP_DIR/README.md" "$STAGE/$APP_NAME/"

# Inject <secret> immediately after </registrationUrl>, which is where the XSD
# expects it (verified against manifest-2.0.xsd and manifest-3.0.xsd — 6.6 uses
# 2.0, 6.7/trunk uses 3.0, and it validates against both).
python3 - "$STAGE/$APP_NAME/manifest.xml" "$SHOPWARE_APP_SECRET" <<'PY'
import re, sys, html
path, secret = sys.argv[1], sys.argv[2]
s = open(path).read()

# Strip XML comments BEFORE deciding anything. The manifest's own comment
# explains the <secret> requirement and therefore contains the literal string
# "<secret>" in prose -- checking the raw text made this script refuse to inject
# into a manifest that has no actual secret element. Matching explanatory prose
# instead of markup is a recurring defect class in this project; strip first.
code = re.sub(r'<!--.*?-->', '', s, flags=re.S)
if '<secret>' in code:
    sys.exit("refusing to inject: manifest already contains a real <secret> element")

# Anchor on the LAST </registrationUrl> outside comments. Find it in the
# stripped text, then map back by matching the same unique tag in the original.
if '</registrationUrl>' not in code:
    sys.exit("no </registrationUrl> element found -- cannot place <secret>")
m = re.search(r'([ \t]*)</registrationUrl>', s)
if not m:
    sys.exit("no </registrationUrl> found — cannot place <secret>")
indent = m.group(1)
s = s[:m.end()] + f"\n{indent}<secret>{html.escape(secret, quote=False)}</secret>" + s[m.end():]
open(path, 'w').write(s)
PY

ZIP="$OUT/$APP_NAME.zip"
rm -f "$ZIP"
# -D: no directory entries (match shopware-cli). -X: no extra platform attrs.
( cd "$STAGE" && zip -q -r -D -X "$ZIP" "$APP_NAME" -x '*.DS_Store' -x '__MACOSX/*' )

# Postconditions — assert the two things that actually broke the install, plus
# that the secret really landed. A build that quietly produces the old shape is
# worse than one that fails.
first="$(unzip -Z1 "$ZIP" | head -1)"
[ "$first" = "$APP_NAME/manifest.xml" ] || {
  echo "POSTCONDITION FAILED: first zip entry is '$first', expected '$APP_NAME/manifest.xml'" >&2; exit 1; }
unzip -Z1 "$ZIP" | grep -q '/$' && {
  echo "POSTCONDITION FAILED: zip still contains directory entries" >&2; exit 1; }
unzip -p "$ZIP" "$APP_NAME/manifest.xml" | grep -q '<secret>' || {
  echo "POSTCONDITION FAILED: <secret> was not injected" >&2; exit 1; }
unzip -p "$ZIP" "$APP_NAME/manifest.xml" | grep -q "<name>$APP_NAME</name>" || {
  echo "POSTCONDITION FAILED: <meta><name> does not match the directory '$APP_NAME'" >&2; exit 1; }

echo "built $ZIP ($(wc -c < "$ZIP" | tr -d ' ') bytes)"
unzip -Z1 "$ZIP" | sed 's/^/  /'
echo
echo "NOTE: this zip contains a live credential. Do not commit it or attach it"
echo "to a public release. Upload it to your shop and then delete it."
