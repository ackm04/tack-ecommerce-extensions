# TackQuote for Zen Cart

A Zen Cart storefront + admin companion that adds a "Request a Quote" button
to product info pages and connects the store to a TackQuote B2B quoting
account (API base URL + API key). It mirrors the pattern used by the
TackQuote WooCommerce and PrestaShop companions
(`integrations/wordpress/tackquote-for-woocommerce/`, `integrations/prestashop/modules/tackquotes/`),
adapted to Zen Cart's classic PHP architecture — no DI container and (unlike
PrestaShop's `displayProductActions` hook) no plugin hook point on the default
product info template, so this ships as a set of files you copy into your Zen
Cart install plus one SQL patch and one manual template edit. Zen Cart *does*
have a self-installing package format — "encapsulated plugins" under
`zc_plugins/`, admin-only from 1.5.7 and extended to the storefront through
2.1.0 (<https://docs.zen-cart.com/dev/plugins/encapsulated/>) — but this module
is **not** packaged as one; it is a plain file overlay plus a SQL patch, which
is what the rest of this README describes.

This directory holds **two independent halves**:

| Half | Direction | What it is |
|------|-----------|------------|
| **Quote button** | store → TackQuote | `ajax_tack_quote_request.php` + the template partial. Posts to `POST /v1/integrations/zencart/quote-requests` on the TackQuote API with a **TackQuote API key** (`TACK_API_KEY`). |
| **Connector** | TackQuote → store | `tack-connector/` — JSON at `{store}/tack-connector/{products,orders}`, authenticated with a **token you generate in this store** (`TACK_CONNECTOR_TOKEN`). This is what `ZenCartService` in the TackQuote API calls to sync products, import orders and place quote-accepted orders. |

The two halves use **different secrets on purpose**. `TACK_API_KEY` lets this
store talk to TackQuote; `TACK_CONNECTOR_TOKEN` lets TackQuote talk to this
store. Neither is usable in the other direction.

Distribution authority: the public GitHub release asset is
[`tack-zencart.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.2.0/tack-zencart.zip).
This monorepo directory is build/source only. No Zen Cart plugin-directory listing
is claimed.

> **New in 1.1.0.** The connector half did not exist before. `ZenCartService`
> had always called `{baseUrl}/tack-connector/*`, but TackQuote published
> nothing that answered there, so every catalog/order sync 404'd and this README
> described the token as "a Bearer token you generate on your side" for a
> companion the merchant was expected to write. It now ships here.

## What's in the release zip

`tack-zencart.zip` unpacks to a single top-level folder. **Only what is inside
`store-root/` belongs on your web server** — the SQL patches and this README
are deliberately kept outside it so they cannot end up publicly downloadable
under your docroot:

```
tack-zencart/
├── README.md                            ← this file. Do NOT copy to the server.
├── zc_install/                          ← run these in admin. Do NOT copy to the server.
│   ├── install.sql                      Adds the TackQuote settings group (see below)
│   ├── uninstall.sql                    Removes them
│   └── upgrade_connector.sql            Adds ONLY the new connector token, for
│                                        stores that ran install.sql before 1.1.0
└── store-root/                          ← copy the CONTENTS of this folder into
    │                                      your Zen Cart docroot (not the folder itself)
    ├── ajax_tack_quote_request.php                             Storefront AJAX endpoint
    ├── tack-connector/index.php          Inbound catalog/order connector
    ├── tack-connector/.htaccess          Sub-path rewrite + Authorization passthrough
    └── includes/
        ├── classes/tack_api_client.php                         API client (cURL)
        └── templates/template_default/
            ├── templates/tpl_tack_quote_button.php  Button + modal template partial
            ├── css/tack_quote_button.css
            └── jscript/tack_quote_button.js
```

Every path under `store-root/` is named and laid out exactly as it should sit
inside your Zen Cart storefront root, so the copy is a straight merge into your
existing `includes/` tree — do not overwrite unrelated files, and do not create
a `store-root` directory on the server.

In this monorepo the same files sit flat under `integrations/zencart/`
(`zc_install/`, `README.md`, and the store files side by side); the
`store-root/` wrapper is created by the release packaging step.

## Installation

1. **Copy files.** From `store-root/` in the unpacked zip, copy:
   - `ajax_tack_quote_request.php` → your store root.
   - `tack-connector/` (the whole folder, **including the hidden `.htaccess`**)
     → your store root, so it sits at `{store}/tack-connector/`. Only needed if
     you want catalog/order sync; skip it for the quote button alone.
   - `includes/classes/tack_api_client.php` → your store's `includes/classes/`.
   - `includes/templates/template_default/templates/tpl_tack_quote_button.php`,
     `.../css/tack_quote_button.css`, `.../jscript/tack_quote_button.js` → the
     matching folders under your store's active template (`template_default`
     if you haven't customized templates; otherwise your custom template's
     equivalent folders).
2. **Run the settings SQL.** Import `zc_install/install.sql` into your Zen
   Cart database. The supported route is Zen Cart admin ▸ **Tools ▸ Install SQL
   Patches**, which takes either pasted SQL or an uploaded `.sql` file and
   applies your store's database table prefix for you
   (<https://docs.zen-cart.com/user/admin_pages/tools/install_sql_patches/>).
   phpMyAdmin or `mysql your_db < zc_install/install.sql` also work, but there
   you must add the table prefix yourself if your store uses one. This adds a
   "TackQuote" configuration group.

   **Already installed a pre-1.1.0 version?** Run
   `zc_install/upgrade_connector.sql` instead — it adds only the new
   `TACK_CONNECTOR_TOKEN` setting, is guarded by a `NOT EXISTS` on the key, and
   is safe to re-run.

   **Do not re-run `install.sql` on a store that already has the group.** It is
   not written to be idempotent: the `configuration_group` row inserts again
   (creating a **second**, empty "TackQuote" entry in the admin Configuration
   menu), and the very next statement then aborts on Zen Cart's
   `unq_config_key_zen` unique index with `ERROR 1062 Duplicate entry
   'TACK_API_URL'`. Nothing is lost, but you are left with an orphan group to
   clean up:
   `DELETE FROM configuration_group WHERE configuration_group_title = 'TackQuote' AND configuration_group_id NOT IN (SELECT configuration_group_id FROM configuration);`
   (Verified on the Zen Cart v1.5.8a `configuration` / `configuration_group`
   schema; `uninstall.sql` and `upgrade_connector.sql` are both re-runnable.)
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
   - **TackQuote connector token** — *optional; only needed for catalog/order
     sync.* Generate a long random URL-safe string (`openssl rand -hex 32`),
     paste it here **and** into TackQuote → Settings → Integrations → Zen Cart.
     Leave it empty and `{store}/tack-connector/*` answers `503 feed_disabled`.
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
  exposes `GET /integrations/zencart/ping`,
  `POST /integrations/zencart/quote-requests`, and
  `POST /integrations/zencart/order-sync`, all behind `ApiKeyGuard`, mirroring
  `PrestaShopPluginController`/`WooCommercePluginController` exactly. It IS
  registered in `integrations.module.ts` (an earlier revision of this file said
  the registration was still outstanding; it is not).
  `createQuoteRequest()` genuinely creates a draft quote (buyer
  lookup/creation + quote + line items) tagged `['zencart', 'plugin-request']`
  via the same canonical pipeline `PrestaShopService`/`WooCommerceService`
  use, and `order-sync` upserts into `b2b_orders` and can mark a quote paid.
- **Inbound connector** (`tack-connector/`) — see "The connector routes" below.

## The connector routes

All routes require `Authorization: Bearer <TACK_CONNECTOR_TOKEN>` and fail
**closed**: with no token configured they answer `503`, never an open feed. The
token is compared with `hash_equals()`.

`{store}/tack-connector/products` is not a real file, so the shipped
`.htaccess` rewrites everything under the directory to its `index.php` with the
sub-path in `?tack_path=`. Requests that DO resolve to a real file or directory
are passed straight through, so `{store}/tack-connector/index.php` and
`{store}/tack-connector/` still work; the `.htaccess` itself is protected by
Apache's stock global `.ht*` deny, not by anything in this file. It needs `AllowOverride FileInfo` (or `All`), which
Zen Cart already requires for its own `.htaccess` files. **nginx** has no
`.htaccess`; add to the server block instead:

```nginx
location /tack-connector/ {
    try_files $uri /tack-connector/index.php?tack_path=$uri&$args;
}
# and, in the php-fpm location:
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

`GET {store}/tack-connector/` (authenticated, no sub-path) returns a small
discovery document — the quickest way to check the rewrite works.

### `GET /tack-connector/products`

```json
{ "products": [ { "id": 7, "model": "MDL-7", "name": "Widget",
                  "description": "…", "price": "19.9900",
                  "image": "widget.jpg", "active": true, "quantity": 12 } ],
  "page": 1, "limit": 0 }
```

Unpaginated by default: TackQuote's `syncProducts()` makes one call with no
paging, so a default page size here would silently import the first page and
report success. `page`/`limit` are honoured if sent. Disabled products are
included with `active: false` so TackQuote deactivates its copy rather than
leaving a stale product live.

### `GET /tack-connector/orders?page=1&limit=50` · `GET /tack-connector/orders/{id}`

```json
{ "orders": [ { "id": 51, "orderNumber": "51", "status": "Processing",
                "total": "250.0000", "currency": "EUR",
                "orderedAt": "2026-02-02T10:00:00+00:00",
                "note": "Created from TackQuote.",
                "lineItems": [ { "productId": 7, "name": "Widget",
                                 "sku": "MDL-7", "quantity": 2,
                                 "price": 125 } ] } ],
  "page": 1, "limit": 50 }
```

- `limit` defaults to 50, caps at 250. Ordered by `orders_id` **ASC** so orders
  placed mid-walk cannot shift the window and hide a row.
- Excludes `orders_status = 0`.
- `total` and line `price` are **converted into the order's own currency**
  (`x currency_value`), because Zen Cart stores them in the store's default
  currency and reporting the raw number next to `currency` would label a EUR
  order with a USD amount.
- `note` is the first status-history comment, if any.
- `orderedAt` is ISO-8601 with an offset, not a bare MySQL `DATETIME`.

### `POST /tack-connector/orders`

```json
{ "customer": { "email": "buyer@acme.com", "firstName": "Bea", "lastName": "Buyer" },
  "lineItems": [ { "productId": 7, "quantity": 3, "price": 12.5 } ],
  "currency": "EUR", "note": "Quote TK-2026-000001" }
```

Zen Cart's own `order` class is checkout-session driven and cannot place an
order from arbitrary data, so this writes the four tables Zen Cart's checkout
writes — `orders`, `orders_products`, `orders_total`, `orders_status_history` —
using `$db->bindVars()` for every non-integer value and `(int)` casts elsewhere.

- **`price` is honoured** when supplied, because it is the whole point of a
  quote: the negotiated unit price. It is stored as `final_price` with the
  catalog price kept in `products_price`, which is exactly how Zen Cart records
  a discounted line, so the discount stays visible in admin. A negative price is
  rejected.
- Unknown or disabled product ids **reject the whole order** rather than placing
  a short one; an unknown currency is refused rather than defaulted.
- The order lands on `DEFAULT_ORDERS_STATUS_ID` with `customer_notified = 0`.
- It is attached to an existing customer account when the email matches exactly;
  **no account is created** (guest order, `customers_id = 0`, otherwise).
- **No tax and no shipping** are calculated: there is no address to resolve a
  tax zone or a shipping quote from, so `order_tax` is 0 and no `ot_shipping`
  row is written. TackQuote is the system of record for tax on a quote.
  Recorded, not guessed.

## Known gaps / what is not automatic

- **No module-zip install.** Zen Cart has no equivalent of PrestaShop's Module
  Manager upload flow. Files are copied manually and the SQL patch run manually,
  which is how most third-party Zen Cart add-ons are distributed.
- **No auto-injecting hook** for the storefront button — step 4 above is a real
  one-line template edit.
- **A custom template needs two more edits.**
  `tpl_tack_quote_button.php` references its own CSS/JS with the literal paths
  `includes/templates/template_default/css/tack_quote_button.css` and
  `.../jscript/tack_quote_button.js`. If you copy the partial into a template
  other than `template_default`, edit those two `href`/`src` values to your
  template's directory as well, or the button renders unstyled and does
  nothing when clicked.
- **No "Test connection" button in admin.** This module uses Zen Cart's native
  configuration-group rendering rather than a custom admin page. Verify the
  quote button from a product page, and the connector with
  `curl -H "Authorization: Bearer <token>" {store}/tack-connector/` or
  TackQuote's own Test connection.
- **Order options/attributes** are not carried by `POST /orders`; simple product
  lines only. Nothing is written to `orders_products_attributes`.
- **No outbound webhook.** `ZenCartService.importPluginOrder` /
  `POST /v1/integrations/zencart/order-sync` exist on the TackQuote side for
  parity with the WooCommerce plugin, but nothing in this module calls them —
  order import happens by pull, through `GET /tack-connector/orders`.
- **Translations.** User-facing strings in the template partial, the JavaScript
  and the connector's JSON errors are plain English, not run through
  `includes/languages/`.
- **`checkoutUrl`** (`index.php?main_page=checkout_shipping`) renders the
  visitor's own session cart, so a buyer following it only lands somewhere
  useful if their session already holds the items. Seeding a stranger's session
  from a server-to-server call is not something Zen Cart supports; unchanged and
  still unverified.
- Not verified against a running Zen Cart install. Table and column names are
  taken from the v1.5.8a schema and `includes/database_tables.php`, and the PHP
  is syntax-checked (`php -l`), but there is no integration test against a live
  store.
