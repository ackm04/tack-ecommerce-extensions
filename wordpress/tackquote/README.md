# TackQuote for WooCommerce

Add a **Request a Quote** button to your WooCommerce store and sync orders with your [TackQuote](https://tackquote.com) B2B quoting account.

- 🧾 "Add to Quote" and "Request a Quote" buttons on product pages, plus a floating quote list with "Checkout as Quote"
- 💷 **B2B pricing** — signed-in trade customers priced from their TackQuote price book, buyer group and quantity breaks, with an optional volume-pricing table
- 📦 **Order limits** — minimum/maximum order quantities shown on the product page and enforced at the cart and checkout
- 🏷️ **Buyer group badge** — tells a customer which pricing group they are on, so a discounted price does not read as an error
- 🔁 Optional one-way order sync to TackQuote (on creation and status change), queued through Action Scheduler so it never runs inside checkout
- 🔑 Simple setup: paste your TackQuote API key
- 🛡️ HPOS- and Cart/Checkout-blocks-compatible; nonce, capability and rate-limit protected; removes its own options and transients on uninstall

See the `== External services ==` and `== Privacy ==` sections of [`readme.txt`](readme.txt) for exactly which fields are sent to TackQuote, when, and what the plugin stores locally.
TackQuote's [Terms of Service](https://tackquote.com/terms) and
[Privacy Policy](https://tackquote.com/privacy) govern use of the service.

## Installation

1. Download the latest `tackquote.zip` from the [Releases page](https://github.com/ackm04/tack-ecommerce-extensions/releases), or build locally with `bash bin/build.sh`.
2. In WP Admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.
4. Go to **TackQuote** in the admin menu and paste your **TackQuote API key** (found in TackQuote under **Settings → Developer → API Keys**). Click **Test TackQuote connection** to verify.

   > **The key must carry the `quotes:write` scope.** *Test connection* uses the unscoped `ping` route, so a key without it passes the test and then fails every real quote submission with a 403. Order sync additionally needs `orders:write`.

## Requirements

- WordPress 6.0+
- WooCommerce 6.0+
- PHP 7.4+

## What it calls

The plugin talks to your TackQuote account over HTTPS using your API key (Bearer + `X-Api-Key`). Configure the API URL in settings (default `https://api.tackquote.com/v1`). Endpoints used:

| Purpose | Method & path |
|---|---|
| Connection test | `GET /integrations/woocommerce/ping` (falls back to `/health`) |
| Quote request from product/cart | `POST /integrations/woocommerce/quote-requests` |
| Order sync | `POST /integrations/woocommerce/order-sync` |
| B2B pricing (per buyer, per quantity) | `POST /storefront-pricing/resolve` |
| Order limits | `GET /storefront-b2b/order-limits` |
| Buyer group | `GET /storefront-b2b/buyer-group` |

### What happens when TackQuote cannot be reached

Every one of the B2B lookups **fails open**, and that is a deliberate design
decision rather than an oversight:

| Lookup | On failure |
|---|---|
| B2B pricing | the store's own price is used — no product is ever unpriced or zeroed |
| Order limits | **nothing is blocked** — the cart and checkout behave as they always did |
| Buyer group | no badge |

The reasoning for order limits is the one worth stating: a checkout that stops
working because a supplier's API is slow costs the day's revenue, while an
unenforced minimum costs a phone call. Failures are written to
**WooCommerce → Status → Logs** (source `tackquote`), so the merchant can see
that B2B pricing is not being applied even though the shop still works.

### Plan gating

The B2B endpoints are gated **server-side** by your TackQuote plan — the plugin
does not check your plan, because a client-side plan check is not a check. On a
plan without B2B pricing the API declines and, by the table above, your store
simply keeps its own prices.

## Development

```bash
# Lint (PHP syntax)
find . -name '*.php' -print0 | xargs -0 -n1 php -l

# Lint (WordPress Coding Standards)
composer install && ./vendor/bin/phpcs --standard=WordPress .

# Build a distributable zip
bash bin/build.sh   # produces dist/tackquote.zip
```

Releases are built and attached by the GitHub Actions workflow in the repository root `.github/workflows/release.yml` when a `v*` tag is pushed. That workflow calls `scripts/package-all.sh`, which delegates to `bin/build.sh` here, so the two cannot drift.

## License

GPL-2.0-or-later
