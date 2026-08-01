# TackQuote B2B Quoting — Official Storefront Extensions & Addons

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Platform Support](https://img.shields.io/badge/Platforms-10%20Supported-emerald)](https://github.com/ackm04/tack-ecommerce-extensions)

Official downloadable plugins and extensions for embedding **TackQuote B2B Wholesale Quoting & CPQ** into popular eCommerce platforms.

---

## Supported eCommerce Platforms & Connection Mechanics

| # | Platform | Integration Method | How It Connects & Operates | Download / Setup |
|---|---|---|---|---|
| 1 | **Shopify** | Native App / Theme App Extension & Hydrogen SDK | Connects via GraphQL Admin API & Webhooks (`orders/create`, `products/update`). Injects quote buttons via Theme App Extensions or the framework-agnostic `@tack/hydrogen-sdk` for headless React stores. | Embedded App / Hydrogen SDK |
| 2 | **WooCommerce** | Standalone WordPress Plugin | Connects via WooCommerce REST API v3. The plugin (`tack-quotes`) hooks into `woocommerce_after_add_to_cart_button`, adding a "Request Quote" drawer and auto-syncing orders. | [Download WooCommerce Plugin](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-woocommerce.zip) |
| 3 | **Magento 2** | Native Magento 2 Extension | Connects via Magento REST Bearer API. The extension (`Tack_Quote`) registers custom XML layout blocks (`catalog_product_view.xml`) and posts quote requests to `/v1/widget/quotes`. | [Download Magento 2 Extension](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-magento2.zip) |
| 4 | **BigCommerce** | Storefront Script API | Connects via BigCommerce Script Manager API & REST V3. Automatically injects the storefront widget and listens for `store/order/created` webhooks to trigger B2B order conversion. | `/settings/integrations/bigcommerce` |
| 5 | **OpenCart** | OpenCart 3.0 & 4.0 Module | Connects via OpenCart Extension System. The module (`tackquotes`) overrides Twig product templates, providing a custom admin settings panel and cart API client. | [Download OpenCart Module](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-opencart.zip) |
| 6 | **PrestaShop** | PrestaShop 1.7 & 8.0 Module | Connects via PrestaShop FrontController Hooks. Hooks into `displayProductButtons`, serving a modal quote cart and syncing contacts via the `TackApiClient` class. | [Download PrestaShop Module](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-prestashop.zip) |
| 7 | **Shopware** | Shopware 6.4/6.5 Plugin | Connects via Shopware Storefront Controller. Extends Shopware Twig templates (`product-detail/index.html.twig`) and posts to Tack's secure quote ingest endpoints. | [Download Shopware Plugin](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-shopware.zip) |
| 8 | **ZenCart** | ZenCart Addon Module | Connects via ZenCart Template Hooks & AJAX Handler. Injects `tpl_tack_quote_button.php` into store templates and handles quote requests through `ajax_tack_quote_request.php`. | [Download ZenCart Addon](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-zencart.zip) |
| 9 | **Squarespace** | Code Injection Embed Script | Connects via Squarespace Code Injection. Inserts the minified embed snippet (`tack-widget.js`) in store headers, enabling automatic product button injection. | [Download Squarespace Guide](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-squarespace.zip) |
| 10 | **Wix** | Wix Velo Custom Application | Connects via Wix Velo / Custom Apps. Embeds an interactive HTML component or Velo backend web module posting to Tack quote API endpoints. | [Download Wix App Code](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-wix.zip) |

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
