# TackQuote for Zen Cart

A Zen Cart storefront + admin companion that adds a "Request a Quote" button
to product info pages and connects the store to a TackQuote B2B quoting
account (API base URL + API key). It mirrors the pattern used by the
TackQuote WooCommerce and PrestaShop companions
(`integrations/wordpress/tack-quotes/`, `integrations/prestashop/modules/tackquotes/`),
adapted to Zen Cart's classic PHP architecture — Zen Cart has no module-zip
system, no DI container, and (unlike PrestaShop's `displayProductActions`
hook) no plugin hook point on the default product info template, so this
ships as a set of files you copy into your Zen Cart install plus one SQL
patch and one manual template edit.

This replaces the "coming soon" status previously documented in
`docs/integrations/ZENCART.md`: `{baseUrl}/tack-connector/*` is still the
contract used by the **outbound** connector (`ZenCartService` in the Tack
API, pulling products/orders from *your* store via a Bearer token you
generate on your side). This module is the reverse direction — your Zen Cart
store acting as a client of the **Tack API** — and is unrelated to
`{baseUrl}/tack-connector/*`; it authenticates with a **Tack** API key, the
same way the WooCommerce/PrestaShop plugins do.

## What's in this directory

```
integrations/zencart/
├── zc_install/install.sql              Adds the TackQuote settings group (see below)
├── zc_install/uninstall.sql            Removes them
├── includes/classes/tack_api_client.php                        API client (cURL)
├── ajax_tack_quote_request.php                                 Storefront AJAX endpoint
└── includes/templates/template_default/
    ├── templates/tpl_tack_quote_button.php  Button + modal template partial
    ├── css/tack_quote_button.css
    └── jscript/tack_quote_button.js
```

Every path under `includes/` and `ajax_tack_quote_request.php` is named and
laid out exactly as it should sit inside your Zen Cart storefront root — copy
the contents of this directory into your store's docroot (merging into your
existing `includes/` tree; do not overwrite unrelated files).

## Installation

1. **Copy files.** From this directory, copy:
   - `ajax_tack_quote_request.php` → your store root.
   - `includes/classes/tack_api_client.php` → your store's `includes/classes/`.
   - `includes/templates/template_default/templates/tpl_tack_quote_button.php`,
     `.../css/tack_quote_button.css`, `.../jscript/tack_quote_button.js` → the
     matching folders under your store's active template (`template_default`
     if you haven't customized templates; otherwise your custom template's
     equivalent folders).
2. **Run the settings SQL.** Import `zc_install/install.sql` into your Zen
   Cart database (phpMyAdmin, `mysql your_db < zc_install/install.sql`, or
   Zen Cart admin's SQL patch tool if you have one enabled). This adds a
   "TackQuote" configuration group.
3. **Configure in admin.** In Zen Cart admin, go to **Configuration ▸
   TackQuote** (it appears automatically once the SQL patch has run — Zen
   Cart's admin configuration screen renders a form for any group in
   `configuration_group`/`configuration`, same as every built-in setting).
   Set:
   - **TackQuote API URL** — defaults to `https://api.tackquote.com/v1`.
   - **TackQuote API Key** — create one in TackQuote under Settings ▸
     Developer ▸ API Keys.
   - **Show "Request a Quote" button** — `true`/`false`.
   - **Button label** — e.g. "Request a Quote" or "Get a B2B quote".
4. **Add one line to your product template.** Zen Cart's default product
   info template has no hook point at the "Add to Cart" button the way
   PrestaShop's module system does, so wiring in the button requires a
   one-line manual edit. In your active template's
   `tpl_product_info_display.php` (commonly
   `includes/templates/template_default/templates/tpl_product_info_display.php`),
   add, near the add-to-cart button markup:
   ```php
   <?php require(DIR_WS_TEMPLATE . 'templates/tpl_tack_quote_button.php'); ?>
   ```
   (Adjust the path if you copied the partial into a custom template
   directory rather than `template_default`.)
5. Visit a product page — you should see the button. Submitting it does not
   touch the cart or checkout; it only posts a quote request to TackQuote.

## What's real today

- **Admin settings screen** — a genuine, working Zen Cart admin configuration
  page using Zen Cart's own `configuration_group`/`configuration` tables and
  built-in `admin/configuration.php` renderer (`zc_install/install.sql`).
  This is the standard, documented Zen Cart extension pattern for
  simple key/value settings — no custom admin controller code needed, and
  every setting (API URL, API key, button visibility toggle, button label)
  is genuinely persisted and editable there. Zen Cart's bootstrap also
  auto-`define()`s every `configuration_key` as a PHP constant
  (`TACK_API_URL`, `TACK_API_KEY`, `TACK_ENABLE_WIDGET`, `TACK_BUTTON_LABEL`),
  so the storefront files below read real, live config with no extra
  wiring.
- **Storefront button + modal** (`tpl_tack_quote_button.php` +
  `tack_quote_button.css` + `tack_quote_button.js`) — a real template partial
  that renders a button and a small modal (email, quantity, note) for the
  product identified by `$_GET['products_id']`, matching the WooCommerce and
  PrestaShop widgets' UX. It is off by default until `TACK_ENABLE_WIDGET` is
  `true` and an API key is set.
- **AJAX endpoint** (`ajax_tack_quote_request.php`) — a real, working
  top-level PHP script (Zen Cart's own equivalent of a "front controller",
  since Zen Cart has no MVC front-controller layer). It bootstraps
  `includes/application_top.php`, validates the posted email, loads the
  product via a real query against `TABLE_PRODUCTS` /
  `TABLE_PRODUCTS_DESCRIPTION`, builds a single-product line item (model as
  SKU, description-table name, quantity, price, product ID), and calls
  `TackApiClient::createQuoteRequest()`.
- **API client** (`includes/classes/tack_api_client.php`) — a real cURL-based
  HTTP client authenticated the same way the WooCommerce/PrestaShop plugins'
  clients are: `Authorization: Bearer <key>` + `X-Api-Key: <key>` headers,
  matching how `ApiKeyGuard` on the Tack API accepts credentials
  (`apps/api/src/common/guards/api-key.guard.ts`).
- **Backend endpoint** —
  `apps/api/src/modules/integrations/zencart/zencart-plugin.controller.ts`
  (new in this change) exposes `GET /integrations/zencart/ping`,
  `POST /integrations/zencart/quote-requests`, and
  `POST /integrations/zencart/order-sync`, all behind `ApiKeyGuard`, mirroring
  `PrestaShopPluginController`/`WooCommercePluginController` exactly. Once
  registered in `integrations.module.ts` (see "Wiring" below — deliberately
  left undone here to avoid a merge race with a concurrently-built OpenCart
  controller), `createQuoteRequest()` genuinely creates a draft quote (buyer
  lookup/creation + quote + line items) tagged `['zencart', 'plugin-request']`
  via the same canonical pipeline `PrestaShopService`/`WooCommerceService`
  use, and `order-sync` upserts into `b2b_orders` and can mark a quote paid.

## Known gaps / what's not automatic

- **No module-zip install.** Zen Cart has no equivalent of PrestaShop's
  Module Manager upload flow. Files must be copied into the storefront
  manually (step 1 above), and the SQL patch run manually (step 2). This
  matches how most third-party Zen Cart add-ons are distributed.
- **No auto-injecting hook.** Unlike PrestaShop's `displayProductActions`,
  Zen Cart's default template has no documented, stable hook point for
  inserting markup next to "Add to Cart" without editing a template file.
  Step 4 (one `require()` line) is a genuine manual edit, not automated by
  this module. If your store uses a heavily customized template, the include
  path in that line may need adjusting.
- **No cart-level button** — only the single-product page button is
  implemented, matching the WooCommerce/PrestaShop widgets and this task's
  storefront button requirement.
- **No order-push hook from the storefront.** `order-sync` exists
  server-side (`ZenCartPluginController.orderSync` /
  `ZenCartService.importPluginOrder`) for parity with the WooCommerce
  plugin's `Tack_Order_Sync`, but nothing in this Zen Cart module calls it —
  there's no `zen_cart order status changed`-style hook wired up here.
  `ZenCartService.syncOrdersInbound()` already exists server-side
  (pull-based, via the separate `{baseUrl}/tack-connector/*` connector), so
  this may not be needed for most stores.
- **Translations.** User-facing strings in the template partial and
  JavaScript are plain English, not run through Zen Cart's
  `includes/languages/` translation system.
- **"Test connection" in admin.** Because this module uses Zen Cart's native
  configuration-group rendering rather than a custom admin controller, there
  is no "Test connection" button in the admin screen itself (unlike the
  WooCommerce/PrestaShop plugins' custom admin pages). To verify
  connectivity, use the storefront button on any product page — a failed
  connection surfaces the real error message from `TackApiClient`
  (`502` with the upstream message, not swallowed).

## Wiring needed on the Tack API side (not done in this change, by design)

`apps/api/src/modules/integrations/zencart/zencart-plugin.controller.ts` and
the new methods on `ZenCartService`
(`createQuoteFromPluginRequest`, `importPluginOrder`) are complete and
type-check, but the controller is **not yet registered** in
`apps/api/src/modules/integrations/integrations.module.ts` — a concurrent
change is adding the OpenCart equivalent controller and both registrations
are meant to land together to avoid a merge conflict. To finish wiring, add:

```ts
import { ZenCartPluginController } from './zencart/zencart-plugin.controller'
```

to the imports, and add `ZenCartPluginController` to the `controllers: [...]`
array (the `ZenCartService` provider is already registered — no change
needed there). No other file needs touching; `ZenCartService`'s constructor
already receives `DataSource` and `QuotesService` exactly as
`PrestaShopService` does.
