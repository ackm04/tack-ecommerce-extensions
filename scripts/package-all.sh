#!/usr/bin/env bash
#
# Build every installable storefront-extension artifact, reproducibly.
#
# Why this exists: until now the release zips were built by hand, and they drifted
# from the repository. The published v1.1.0 Magento asset had a `magento2/`
# top-level directory instead of `Vendor/Module/` — so Magento could never
# discover the module and the extension was NEVER installable — and it shipped
# eight files that exist nowhere in this repository's history. The WooCommerce
# release workflow, meanwhile, sat in a subdirectory where GitHub never read it.
#
# Every layout rule below was checked against the VENDOR's own documentation, not
# against a sibling directory. The per-platform notes name the rule, because the
# top-level directory name is load-bearing on almost every one of these platforms
# and getting it wrong fails silently — the extension installs and then does
# nothing.
#
# Usage: scripts/package-all.sh [outdir]     (default: dist)

set -Eeuo pipefail

OUT="${1:-dist}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
rm -rf "$OUT" && mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Excluded from every artifact. `-x` patterns are matched by zip against the
# paths as it stores them, so they are relative to the staged tree.
COMMON_EX=( -x '*/.git/*' -x '*/.gitignore' -x '*/.DS_Store' -x '*/__MACOSX/*' -x '*/._*' )

say() { printf '  %s\n' "$*"; }

# stage <dest-top-level-dir> <source-dir> — copy a source tree under the exact
# top-level directory name the platform requires.
stage() {
  local top="$1" src="$2" d="$STAGE/$1"
  rm -rf "$d"; mkdir -p "$(dirname "$d")"
  cp -R "$ROOT/$src" "$d"
  find "$d" -name '.DS_Store' -delete 2>/dev/null || true
}

pack() { # pack <zipname> <top-level-dir> [extra zip -x args...]
  local name="$1" top="$2"; shift 2
  ( cd "$STAGE" && zip -q -r -X "$OUT/$name" "$top" "${COMMON_EX[@]}" "$@" )
  say "$name  $(wc -c < "$OUT/$name" | tr -d ' ') bytes"
}

# ── WooCommerce ─────────────────────────────────────────────────────────────
# Top-level dir MUST be the WordPress.org slug: WP derives the plugin folder from
# it and a mismatch breaks updates. Delegates to the plugin's own builder so the
# two cannot drift.
say "woocommerce"
bash wordpress/tackquote-for-woocommerce/bin/build.sh >/dev/null
cp wordpress/tackquote-for-woocommerce/dist/tackquote-for-woocommerce.zip "$OUT/"
say "tackquote-for-woocommerce.zip  $(wc -c < "$OUT/tackquote-for-woocommerce.zip" | tr -d ' ') bytes"

# ── PrestaShop ──────────────────────────────────────────────────────────────
# "The `name` attribute ... MUST be the same as the module's folder and main
# class file" (Creating your first module). So: `tackquotes/`, not
# `modules/tackquotes/`. index.php stubs are documented and kept; composer.json
# and the cs-fixer config are build-time only (no runtime deps) and are dropped.
say "prestashop"
stage tackquotes prestashop/modules/tackquotes
pack tack-prestashop.zip tackquotes \
  -x 'tackquotes/.github/*' -x 'tackquotes/.php-cs-fixer.dist.php' \
  -x 'tackquotes/composer.json' -x 'tackquotes/composer.lock' \
  -x 'tackquotes/vendor/*' -x 'tackquotes/tests/*' -x 'tackquotes/_dev/*'

# ── Shopware: App (Cloud) ───────────────────────────────────────────────────
# <meta><name> "must equal the name of the folder your app is contained in"
# (Manifest Reference). That name is ALSO concatenated into the registration
# `proof` HMAC, so a mismatch breaks registration silently. bin/ is a dev-only
# validator and is not shipped.
say "shopware app"
python3 shopware/TackQuoteApp/bin/validate-manifest.py >/dev/null
stage TackQuoteApp shopware/TackQuoteApp
pack tack-shopware-app.zip TackQuoteApp -x 'TackQuoteApp/bin/*'

# ── Shopware: plugin (self-hosted) ──────────────────────────────────────────
# Top-level dir must match the plugin bundle class and the composer psr-4 root,
# i.e. `TackQuote/` (Creating Plugins — plugin structure).
say "shopware plugin"
stage TackQuote shopware/TackQuote
pack tack-shopware.zip TackQuote \
  -x 'TackQuote/tests/*' -x 'TackQuote/phpunit.xml.dist' -x 'TackQuote/.phpunit*'

# ── OpenCart 4.x ────────────────────────────────────────────────────────────
# The developer guide is explicit: "you must not zip the folder `Test module/`
# but the inside files directly (so when you open your zip file you will see
# install.json, admin/, catalog/)". There is NO `upload/` wrapper in 4.x — that
# is the 3.x/core-distribution convention and is not stripped by the 4.x
# installer.
#
# The FILENAME is load-bearing: "a folder will be created into the extension/
# directory based on the name of your file". Every namespace and event action
# hard-codes `tack`, so this MUST stay `tack.ocmod.zip` — any other name installs
# cleanly and then 404s every route with nothing in the log.
#
# marketplace/ is a listing kit for opencart.com; no doc says the installer reads
# it, and extension/ is web-served, so 1.5 MB of PNGs are not shipped.
say "opencart"
rm -rf "$STAGE/oc" && mkdir -p "$STAGE/oc"
for p in admin catalog system install.json; do cp -R "$ROOT/opencart/$p" "$STAGE/oc/"; done
find "$STAGE/oc" -name '.DS_Store' -delete 2>/dev/null || true
( cd "$STAGE/oc" && zip -q -r -X "$OUT/tack.ocmod.zip" . -x '.DS_Store' -x '__MACOSX/*' )
say "tack.ocmod.zip  $(wc -c < "$OUT/tack.ocmod.zip" | tr -d ' ') bytes"

# Source archive only — CANNOT be installed (the guide requires the name to end
# in .ocmod.zip). Named `-source` so it cannot be mistaken for the installer,
# which matters precisely because a wrongly-named zip fails silently.
stage opencart opencart
pack tack-opencart-source.zip opencart -x 'opencart/marketplace/*'

# ── Magento 2 ───────────────────────────────────────────────────────────────
# Manual-install path is app/code/<Vendor>/<Module>/, so the artifact carries the
# Vendor/Module nesting itself and the user cannot lose it:
#   unzip tack-magento2.zip -d <magento-root>/app/code
say "magento2"
rm -rf "$STAGE/TackQuote_m2" && mkdir -p "$STAGE/TackQuote_m2/TackQuote"
cp -R "$ROOT/magento2" "$STAGE/TackQuote_m2/TackQuote/Quotes"
find "$STAGE/TackQuote_m2" -name '.DS_Store' -delete 2>/dev/null || true
( cd "$STAGE/TackQuote_m2" && zip -q -r -X "$OUT/tack-magento2.zip" TackQuote \
    -x 'TackQuote/Quotes/Test/*' -x 'TackQuote/Quotes/phpunit*' -x '*/.DS_Store' )
say "tack-magento2.zip  $(wc -c < "$OUT/tack-magento2.zip" | tr -d ' ') bytes"

# ── Zen Cart ────────────────────────────────────────────────────────────────
# No package installer: the admin copies a file tree over the store root and runs
# the SQL through Admin > Tools > Install SQL Patches. The wrapper dir keeps the
# SQL findable and stops the tree from being dumped loose into a download folder;
# what the admin copies is the contents of store-root/.
say "zencart"
rm -rf "$STAGE/tack-zencart" && mkdir -p "$STAGE/tack-zencart/store-root"
for p in tack-connector includes ajax_tack_quote_request.php; do
  [ -e "$ROOT/zencart/$p" ] && cp -R "$ROOT/zencart/$p" "$STAGE/tack-zencart/store-root/"
done
cp -R "$ROOT/zencart/zc_install" "$STAGE/tack-zencart/"
cp "$ROOT/zencart/README.md" "$STAGE/tack-zencart/"
find "$STAGE/tack-zencart" -name '.DS_Store' -delete 2>/dev/null || true
pack tack-zencart.zip tack-zencart

# ── Squarespace / Wix ───────────────────────────────────────────────────────
# Code-snippet integrations: a README is the whole deliverable.
for p in squarespace wix; do
  say "$p"
  stage "tack-$p" "$p"
  pack "tack-$p.zip" "tack-$p"
done

echo
echo "artifacts in $OUT:"
ls -1 "$OUT"
