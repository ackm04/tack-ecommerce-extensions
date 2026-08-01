# Tack B2B Quoting — Official Storefront Extensions & Addons

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Platform Support](https://img.shields.io/badge/Platforms-8%20Supported-emerald)](https://github.com/tackquote/tack-ecommerce-extensions)

Official downloadable plugins and extensions for embedding **Tack B2B Wholesale Quoting & CPQ** into popular eCommerce platforms.

---

## Supported eCommerce Platforms

| Platform | Directory | Plugin Name | Compatibility | Installation Guide |
|---|---|---|---|---|
| **WooCommerce** | `wordpress/` | `tack-quotes` | WordPress 6.0+, WooCommerce 7.0+ | [WooCommerce Guide](wordpress/tack-quotes/README.md) |
| **Magento 2** | `magento2/` | `Tack_Quote` | Magento 2.4+ (Adobe Commerce) | [Magento 2 Guide](magento2/README.md) |
| **OpenCart** | `opencart/` | `tack_quotes` | OpenCart 3.0 & 4.0 | [OpenCart Guide](opencart/README.md) |
| **PrestaShop** | `prestashop/` | `tackquotes` | PrestaShop 1.7 & 8.0+ | [PrestaShop Guide](prestashop/README.md) |
| **Shopware** | `shopware/` | `TackQuote` | Shopware 6.4 & 6.5+ | [Shopware Guide](shopware/README.md) |
| **ZenCart** | `zencart/` | `tack_quotes` | ZenCart 1.5+ | [ZenCart Guide](zencart/README.md) |
| **Squarespace** | `squarespace/` | `tack-embed` | Squarespace 7.1 (Code Injection) | [Squarespace Guide](squarespace/README.md) |
| **Wix** | `wix/` | `tack-wix-app` | Wix Velo / Custom Apps | [Wix Guide](wix/README.md) |

---

## Quick Start & Setup

### 1. Download Extension
Select your platform from the table above or download the pre-packaged zip archive from the [Releases](https://github.com/tackquote/tack-ecommerce-extensions/releases) tab.

### 2. Configure API Credentials
In your store admin panel under **Tack Settings**:
1. Enter your Tack API Endpoint URL (e.g. `https://api.yourcompany.com/v1`).
2. Enter your **Tenant ID** (found under **Settings → API Keys** in your Tack Seller Portal).
3. Set your preferred **Button Label** (e.g., `"Request B2B Quote"`).

### 3. Webhook Listener
Enable webhook notifications in your Tack dashboard under **Settings → Webhooks** pointing to your store's inbound webhook URL (e.g., `https://yourstore.com/api/tack/webhook`) for automatic order status updates.

---

## Features

- **Automated "Add to Quote" Buttons**: Injects quote request buttons next to standard "Add to Cart" buttons on product pages and collection grids.
- **Cart & Line Item Sync**: Syncs cart items, custom product attributes, quantities, and target prices to Tack quotes.
- **Honeypot Anti-Spam Protection**: Prevents bot submissions without captchas or friction for real B2B buyers.
- **Bi-Directional Order Sync**: Converts approved Tack quotes into official store orders and invoices automatically.

---

## License

This repository and all included extensions are licensed under the [MIT License](LICENSE).
