# TackQuote for WooCommerce

Add a **Request a Quote** button to your WooCommerce store and sync orders with your [TackQuote](https://tackquote.com) B2B quoting account.

- 🧾 "Add to Quote" and "Request a Quote" buttons on product pages, plus a floating quote list with "Checkout as Quote"
- 🔁 Optional one-way order sync to TackQuote (on creation and status change), queued through Action Scheduler so it never runs inside checkout
- 🔑 Simple setup: paste your TackQuote API key
- 🛡️ HPOS- and Cart/Checkout-blocks-compatible; nonce, capability and rate-limit protected; removes its own options and transients on uninstall

See the `== External services ==` and `== Privacy ==` sections of [`readme.txt`](readme.txt) for exactly which fields are sent to TackQuote, when, and what the plugin stores locally.

## Installation

1. Download the latest `tackquote.zip` from the [Releases page](https://github.com/ackm04/tack-ecommerce-extensions/releases), or build locally with `bash bin/build.sh`.
2. In WP Admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.
4. Go to **TackQuote** in the admin menu and paste your **TackQuote API key** (found in TackQuote under **Settings → Developer → API Keys**). Click **Test TackQuote connection** to verify.

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
