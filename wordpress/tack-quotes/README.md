# TackQuote for WooCommerce

> **Status: Partial** — companion plugin lives in this monorepo at
> `integrations/wordpress/tack-quotes/`. Packaging / WordPress.org distribution
> polish may still be incomplete; the plugin is **not** Missing.

Add a **Request a Quote** button to your WooCommerce store and sync orders with your [TackQuote](https://tackquote.com) B2B quoting account.

- 🧾 "Request a Quote" button on product pages and the cart
- 🔁 Automatic order sync to TackQuote (on creation and status change)
- 🔑 Simple setup: paste your TackQuote API key
- 🛡️ HPOS-compatible, nonce + capability protected, no data left behind on uninstall

## Installation

1. Download the latest `tack-quotes.zip` from the [Releases page](https://github.com/tackquote/tack-woocommerce/releases), or build locally with `bash bin/build.sh`.
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
# Lint
composer install && ./vendor/bin/phpcs --standard=WordPress .

# Build a distributable zip
bash bin/build.sh   # produces dist/tack-quotes.zip
```

Releases are built and attached automatically by the GitHub Actions workflow in `.github/workflows/release.yml` when a `v*` tag is pushed.

## License

GPL-2.0-or-later
