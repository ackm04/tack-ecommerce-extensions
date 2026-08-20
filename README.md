# TackQuote B2B Quoting — Official Storefront Extensions & Addons

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Platform Support](https://img.shields.io/badge/Platforms-10%20Supported-emerald)](https://github.com/ackm04/tack-ecommerce-extensions)

Official downloadable plugins and extensions for embedding **TackQuote B2B Wholesale Quoting & CPQ** into popular eCommerce platforms.

📖 **[Browse the platforms, install steps and verification evidence →](https://ackm04.github.io/tack-ecommerce-extensions/)**

The public [`ackm04/tack-ecommerce-extensions`](https://github.com/ackm04/tack-ecommerce-extensions)
release is the distribution authority. This monorepo directory is build/source material only; do
not direct merchants to download files from a TackQuote application checkout.

---

## Supported eCommerce Platforms & Connection Mechanics

| # | Platform | Integration Method | How It Connects & Operates | Download / Setup |
|---|---|---|---|---|
| 1 | **Shopify** | Native App / Theme App Extension & Hydrogen SDK | Connects via GraphQL Admin API & Webhooks (`orders/create`, `products/update`). Injects quote buttons via Theme App Extensions or the framework-agnostic `@tack/hydrogen-sdk` for headless React stores. | Embedded App / Hydrogen SDK |
| 2 | **WooCommerce** | Standalone WordPress Plugin | Posts to the TackQuote API — not the WooCommerce REST API, which it never calls. The plugin (`tack-quotes`, v1.3.1) hooks `woocommerce_after_add_to_cart_button` for the buttons and `wp_footer` for the quote drawer, and hands order sync to **Action Scheduler** so a slow TackQuote can never sit inside a shopper's checkout. Verified end to end on WordPress 7.1 + WooCommerce 11.0.1; passes `wp plugin check` and `phpcs --standard=WordPress` clean. | [Download WooCommerce Plugin](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-woocommerce.zip) — **release asset is v1.0.0 and stale; build from `wordpress/tack-quotes/bin/build.sh` for 1.3.1** |
| 3 | **Magento 2** | Native Magento 2 Extension | Connects via Magento REST Bearer API. The extension (`Tack_Quote`) registers custom XML layout blocks (`catalog_product_view.xml`) and posts quote requests to `/v1/widget/quotes`. | [Download Magento 2 Extension](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-magento2.zip) |
| 4 | **BigCommerce** | Storefront Script API | Connects via BigCommerce Script Manager API & REST V3. Automatically injects the storefront widget and listens for `store/order/created` webhooks to trigger B2B order conversion. | `/settings/integrations/bigcommerce` |
| 5 | **OpenCart** | OpenCart 4.x Extension (`.ocmod.zip`) | Two halves. Storefront: injects the quote button and posts to Tack. **Catalog/order API**: serves `extension/tack/api/product.list` and `…/order.list`, which is what TackQuote pulls products and order history from — OpenCart core has no such endpoint, so this extension is **mandatory, not optional**. Bearer-authenticated, fails closed. | [Download OpenCart source release](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-opencart.zip), then follow its README to build the required `tack.ocmod.zip` installer. |
| 6 | **PrestaShop** | PrestaShop 1.7 & 8.0 Module | Connects via PrestaShop FrontController Hooks. Hooks into `displayProductButtons`, serving a modal quote cart and syncing contacts via the `TackApiClient` class. | [Download PrestaShop Module](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-prestashop.zip) |
| 7 | **Shopware** | Shopware 6.4/6.5 Plugin | Connects via Shopware Storefront Controller. Extends Shopware Twig templates (`product-detail/index.html.twig`) and posts to Tack's secure quote ingest endpoints. | [Download Shopware Plugin](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-shopware.zip) |
| 8 | **ZenCart** | ZenCart Addon + `tack-connector` API | Two halves. Storefront: injects `tpl_tack_quote_button.php` and handles requests via `ajax_tack_quote_request.php`. **Catalog/order API**: `tack-connector/` serves `GET /products`, `GET /orders`, `GET /orders/{id}` and `POST /orders` — Zen Cart core has no JSON API, so this is **mandatory, not optional**. Bearer-authenticated, fails closed. | [Download ZenCart Addon](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-zencart.zip) |
| 9 | **Squarespace** | Code Injection Embed Script | Connects via Squarespace Code Injection. Inserts the minified embed snippet (`tack-widget.js`) in store headers, enabling automatic product button injection. | [Download Squarespace Guide](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-squarespace.zip) |
| 10 | **Wix** | Wix Velo Custom Application | Connects via Wix Velo / Custom Apps. Embeds an interactive HTML component or Velo backend web module posting to Tack quote API endpoints. | [Download Wix App Code](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-wix.zip) |

nopCommerce has no TackQuote release asset. Its connector targets a separately obtained community
REST API plugin; TackQuote does not claim a nopCommerce Marketplace listing.

---

## Universal Connection Architecture

Every platform uses the same core ingestion pipeline:
- **Button Injection**: Injects "Add to Quote" buttons next to default purchase buttons.
- **Quote Cart Drawer**: Renders a glassmorphism drawer for buyers to specify quantities and notes.
- **Honeypot Ingest**: Posts data securely to `POST /v1/widget/quotes` with bot prevention.
- **Order Conversion**: When a quote is approved, Tack triggers webhook events (`POST /v1/webhooks`) to create official orders/invoices in the merchant's store and connected ERP/accounting systems.

---

## License

This repository and all included extensions are licensed under the [MIT License](LICENSE).
