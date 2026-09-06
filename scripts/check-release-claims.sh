#!/usr/bin/env bash
#
# README/release contract — fails CI if a merchant-facing README regresses on
# either of the two defects that shipped in ackm04/tack-ecommerce-extensions#20:
#
#   1. A version-pinned release asset URL (`releases/download/vX.Y.Z/...`).
#      These go stale the moment any OTHER platform ships a release, because
#      this repo cuts one repo-wide `v*` tag covering every platform — the
#      v1.1.0 links in the pre-fix README kept returning 200 while serving a
#      build four releases out of date. The only safe merchant-facing link is
#      `releases/latest/download/<exact-asset-name>`.
#
#   2. Fabricated API surface. `/v1/webhooks` and `/v1/widget/quotes` do not
#      exist anywhere in this repository's connector code — they were invented
#      for a "Tack triggers webhook events to create official orders/invoices"
#      claim that was false for every platform this repo ships (see the
#      per-platform READMEs: WooCommerce/PrestaShop/Shopware push orders OUT,
#      one direction only; OpenCart/Zen Cart expose a real inbound
#      order-creation route, but it is `order.add` / `POST /orders`, not a
#      generic webhook; Magento's is its own core Admin REST API). If either
#      string reappears in a README, someone is re-describing an endpoint that
#      was never real instead of citing the platform-specific one.
#
# Usage: scripts/check-release-claims.sh [file ...]   (default: every README*.md)

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail=0
count=0

check_one() {
  local f="$1"
  [ -f "$f" ] || return 0
  count=$((count + 1))

  # 1. Version-pinned release asset URLs.
  if grep -nE 'releases/(download|tag)/v[0-9]+\.[0-9]+\.[0-9]+' "$f" >/dev/null; then
    echo "FAIL: $f pins a release asset to a version tag. Use releases/latest/download/<asset> instead:"
    grep -nE 'releases/(download|tag)/v[0-9]+\.[0-9]+\.[0-9]+' "$f" | sed 's/^/    /'
    fail=1
  fi

  # 2. Fabricated endpoints that were never real.
  if grep -nF '/v1/webhooks' "$f" >/dev/null; then
    echo "FAIL: $f references /v1/webhooks, which no connector in this repo calls or implements:"
    grep -nF '/v1/webhooks' "$f" | sed 's/^/    /'
    fail=1
  fi
  if grep -nF '/v1/widget/quotes' "$f" >/dev/null; then
    echo "FAIL: $f references /v1/widget/quotes, which no connector in this repo calls:"
    grep -nF '/v1/widget/quotes' "$f" | sed 's/^/    /'
    fail=1
  fi
}

if [ "$#" -gt 0 ]; then
  for f in "$@"; do check_one "$f"; done
else
  # Every README in the repo except node_modules/vendor/dist trees, if any exist locally.
  while IFS= read -r f; do
    check_one "$f"
  done < <(find . \( -iname 'README.md' -o -iname 'readme.txt' \) \
    -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*/dist/*')
fi

if [ "$fail" -ne 0 ]; then
  echo
  echo "See ackm04/tack-ecommerce-extensions#20 for why these are load-bearing checks."
  exit 1
fi

echo "OK: no version-pinned release URLs or fabricated endpoints found in ${count} file(s)."
