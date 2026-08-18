# TackQuote for OpenCart

An OpenCart 4.x extension with **two independent halves**:

| Half | Direction | What it is |
|------|-----------|------------|
| **Quote button** | store → TackQuote | A "Request a Quote" button on product pages that posts to `POST /v1/integrations/opencart/quote-requests` on the TackQuote API, authenticated with a **TackQuote API key**. |
| **Catalog / order feed** | TackQuote → store | JSON routes `index.php?route=extension/tack/api/{product.list,order.list,order.add}`, authenticated with a **feed token you generate in this store**. This is what `OpenCartService` in the TackQuote API calls to sync products, import orders and place quote-accepted orders. |

The two halves use **different secrets on purpose**. The API key lets this
store talk to TackQuote; the feed token lets TackQuote talk to this store.
Neither is usable in the other direction.

Distribution authority: the public GitHub release asset is
[`tack-opencart.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-opencart.zip).
It is a source archive. Extract it and follow the build instructions below to create the
load-bearing `tack.ocmod.zip` installer; do not upload `tack-opencart.zip` to OpenCart.

> **Fixed in 1.1.1 — the storefront button could not be placed at all.** The settings
> group was `module_tackquote_*` while the module code is `tackquotes`. OpenCart's
> Design > Layouts form lists a single-instance module only when a setting named
> `module_<code>_status` exists (`admin/controller/design/layout.php:262`), so TackQuote
> was missing from every layout position picker. Nothing in the admin hinted at it: the
> extension installed, the module installed, the settings screen saved successfully and
> `Status = Enabled` persisted. Only the storefront half was affected; the catalog/order
> feed routes were reachable throughout. Found by installing the extension into a real
> OpenCart 4.1.0.4 store rather than by reading the code.
>
> If you installed 1.1.0, re-save the settings screen after upgrading — the old
> `module_tackquote_*` rows are ignored, and 1.1.1 writes the correctly named ones.

> **New in 1.1.0.** The feed half did not exist before. `OpenCartService` had
> always called `extension/tack/api/*`, but TackQuote published nothing that
> answered there, so every catalog/order sync 404'd and the docs told merchants
> to write the endpoints themselves. They now ship here.
>
> 1.1.0 also **relaid out the package**. See "Layout" below — the 1.0.0 tree
> could not be installed by either supported method.

## Layout

```
integrations/opencart/
├── install.json
├── README.md
├── admin/
│   ├── controller/module/tackquotes.php          settings screen + Test connection
│   ├── language/en-gb/module/tackquotes.php
│   └── view/template/module/tackquotes.twig
├── catalog/
│   ├── controller/module/tackquotes.php          storefront button + quote AJAX
│   ├── controller/api/product.php                GET  …route=extension/tack/api/product.list
│   ├── controller/api/order.php                  GET  …route=extension/tack/api/order.list
│   │                                             POST …route=extension/tack/api/order.add
│   ├── language/en-gb/module/tackquotes.php
│   └── view/template/module/tackquotes.twig
└── system/library/
    ├── api_client.php                            store → TackQuote HTTP client
    └── api_guard.php                             TackQuote → store auth/paging/JSON
```

This is OpenCart 4's real extension layout, confirmed against **OpenCart's own
developer guide** — <https://docs.opencart.com/developer-guide/extensions> — and
cross-checked against the 4.0.2.3 source rather than assumed:

- The Extension Installer extracts the **zip root** into `extension/<code>/`,
  with no `upload/` folder stripping and no `extension/<code>/` prefix of its
  own — so the zip must contain `install.json`, `admin/`, `catalog/`, `system/`
  at its root. Core's own bundled extension has exactly this shape
  ([`upload/extension/opencart/`](https://github.com/opencart/opencart/tree/4.0.2.3/upload/extension/opencart)).
  [installer.php](https://github.com/opencart/opencart/blob/4.0.2.3/upload/admin/controller/marketplace/installer.php) ·
  [developer guide](https://github.com/opencart/opencart/blob/master/docs/developer-guide/extensions.md)
- `<code>` comes from the **zip filename** (`basename($filename, '.ocmod.zip')`),
  not from `install.json`. The archive must therefore be named
  **`tack.ocmod.zip`** — that is what makes the routes resolve as
  `extension/tack/api/*`, which is the contract `OpenCartService` calls.
- `catalog/controller/startup/extension.php` registers
  `Opencart\Catalog\Controller\Extension\Tack` → `extension/tack/catalog/controller/`,
  and `system/engine/autoloader.php` maps the rest of the class name to a file
  with `strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', …))` — which
  is why the client library is `api_client.php`, not `apiclient.php`.
  [startup/extension.php](https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/controller/startup/extension.php) ·
  [autoloader.php](https://github.com/opencart/opencart/blob/4.0.2.3/upload/system/engine/autoloader.php)
- `index.php?route=extension/tack/api/product.list` is split at the **last dot**
  into route `extension/tack/api/product` + method `list`.
  [action.php](https://github.com/opencart/opencart/blob/4.0.2.3/upload/system/engine/action.php)
- An extension's catalog controllers are **not confined to `module/`**. The
  vendor guide's own worked example puts one at `catalog/controller/events.php`
  (namespace `…\Extension\TestModule`, invoked as
  `extension/test_module/events.onCartAddBefore`), which is exactly the shape
  the `api/` directory here uses.
- JSON is emitted the way the vendor guide emits it:
  `$this->response->addHeader('Content-Type: application/json')` +
  `setOutput(json_encode($json))`.

### Two places the vendor guide is not a reliable source

Recorded so a future reader does not "fix" working code to match a doc bug:

- The guide's directory listing says the storefront template lives at
  `catalog/view/theme/default/template/module/…`. **No released OpenCart does
  that.** 4.0.2.3, 4.1.0.3 and master all register
  `DIR_EXTENSION . <code> . '/catalog/view/template/'` in
  `catalog/controller/startup/extension.php`, and core's own bundled extension
  keeps its templates at `extension/opencart/catalog/view/template/module/`.
  This package follows the source.
- Template **extension**: `.twig` on 4.0.x and 4.1.x, `.html` on master
  (unreleased). This package ships `.twig` and declares 4.0.x/4.1.x
  compatibility accordingly; a future 4.2 will need the storefront/admin twig
  files renamed.

### Not covered by the vendor docs at all — UNVERIFIED

- **Authenticating a custom extension route.** OpenCart publishes nothing on
  this. Its only API page,
  <https://docs.opencart.com/admin-interface/system/users/api>, documents the
  admin-managed API user for core's *session cart/checkout* API, which an
  extension controller cannot reuse. The Bearer + `hash_equals()` scheme here is
  TackQuote's own, chosen to match what the connector already sends. That page
  does recommend IP-restricting API credentials — worth applying to these routes
  at the web-server level.
- **SQL escaping.** The Coding Standard page
  (<https://docs.opencart.com/developer-guide/coding-standard>) covers naming
  and formatting only; no escaping guidance exists anywhere in the docs, and
  `$this->db` has no prepared-statement API. The `(int)`-cast /
  `$this->db->escape()` idiom used here is taken from core's own models.

**What 1.0.0 got wrong** (recorded so it is not reintroduced): it shipped
`upload/admin/controller/extension/tackquote/module/tackquotes.php` — both an
`upload/` wrapper the installer does not strip *and* a second
`extension/<code>/` segment. Installed through the Extension Installer the files
landed at `extension/tackquote/upload/admin/…`, where nothing autoloads; copied
into the web root by FTP the module never appeared in Extensions > Modules,
because that screen lists only paths the installer recorded
([extension/module.php](https://github.com/opencart/opencart/blob/4.0.2.3/upload/admin/controller/extension/module.php)).
`system/library/tackquote/apiclient.php` could not have loaded under either
method. None of this is a behaviour change for any working install — there could
not have been one.

**OpenCart 3.x is not supported by these files.** OC3 controllers are
un-namespaced (`ControllerExtensionModuleTackquotes extends Controller`, loaded
by filename), there is no `extension/` directory and no PSR-4-ish autoloading,
and `Action`/`Factory` resolve routes differently — the API routes above cannot
exist on OC3 in this form. Porting means an OC3-specific tree, which is not
shipped.

## Build

```
bash scripts/package-integrations.sh
```

produces two artifacts in `dist/extensions/`:

| File | What it is |
|------|------------|
| **`tack.ocmod.zip`** | What a merchant installs. `install.json` + `admin/` + `catalog/` + `system/` at the zip root. **The filename sets the extension code — do not rename it.** |
| `tack-opencart.zip` | Source archive for GitHub Releases (adds `README.md`, wrapped in an `opencart/` folder). **Not installable** by OpenCart's installer. |

To build just the installable one by hand:

```
cd integrations/opencart
zip -r ../../dist/extensions/tack.ocmod.zip install.json admin catalog system
```

## Install

1. **Extensions > Installer** → upload `tack.ocmod.zip`, then click Install.
2. **Extensions > Extensions**, filter by **Modules**, find **TackQuote**, click
   the **+** (install), then the **pencil** (edit).
3. Fill in:
   - **TackQuote API URL** — `https://api.tackquote.com/v1`.
   - **TackQuote API Key** — TackQuote → Settings → Developer → API Keys. Used by
     the storefront button. Click **Test connection**.
   - **Catalog / order feed token** — *optional; only needed for catalog/order
     sync.* Generate a long random URL-safe string (`openssl rand -hex 32`),
     paste it here **and** into TackQuote → Settings → Integrations → OpenCart.
     Leave it empty and the feed routes answer `503 feed_disabled`.
   - **Status** → Enabled. Save.
4. **Design > Layouts** → the layout used by the **Product** route → add the
   **TackQuote** module to a position such as "Content Bottom". OpenCart has no
   built-in hook next to Add to Cart, so layout assignment is the standard
   (and theme-update-safe) way to place storefront module output; that is a
   genuine extra step compared with WooCommerce/PrestaShop.

To switch the feed off later, save the token field with a single dash (`-`).

## The feed routes

All three require `Authorization: Bearer <feed token>` and fail **closed**: with
no token configured they answer `503`, never an open feed. The token is compared
with `hash_equals()`.

### `GET index.php?route=extension/tack/api/product.list`

```json
{ "products": [ { "product_id": 42, "model": "MDL-42", "sku": "SKU-42",
                  "name": "Widget", "description": "…", "price": "100.0000",
                  "special": "80.0000", "image": "catalog/demo/widget.jpg",
                  "status": "1", "quantity": 7 } ],
  "total": 1, "page": 1, "limit": 0 }
```

- **Unpaginated by default.** TackQuote's `syncProducts()` makes one call with
  no paging, so a default page size here would silently import the first page
  and report success. `page`/`limit` are honoured if sent. Very large catalogs
  may need a higher PHP `memory_limit`.
- Scoped to the connected store via `product_to_store`.
- **Disabled products are included** with `status: "0"` so TackQuote deactivates
  its copy instead of leaving a stale product live.
- `special` is the live special price for the store's default customer group
  (same subquery core's catalog model uses), or `false` — never `0`, which would
  read as a free product.

### `GET index.php?route=extension/tack/api/order.list&page=1&limit=50`

```json
{ "orders": [ { "order_id": 51, "order_status": "Complete", "total": "99.5000",
                "currency_code": "EUR", "date_added": "2026-02-02T10:00:00+00:00",
                "comment": "…",
                "products": [ { "product_id": 42, "name": "Widget",
                                "model": "MDL-42", "quantity": 2,
                                "price": "49.7500" } ] } ],
  "total": 51, "page": 1, "limit": 50 }
```

- `limit` defaults to 50, caps at 250. Ordered by `order_id` **ASC** so orders
  placed mid-walk cannot shift the window and hide a row.
- Excludes `order_status_id = 0` — OpenCart's "missing order" state (an
  abandoned confirm step), not revenue.
- `total` and line `price` are **converted into the order's own currency**
  (`× currency_value`), because OpenCart stores them in the store's default
  currency and reporting the raw number next to `currency_code` would label a
  EUR order with a USD amount.
- `date_added` is ISO-8601 with an offset, not a bare MySQL `DATETIME`.

### `POST index.php?route=extension/tack/api/order.add`

Body is `application/x-www-form-urlencoded`:
`firstname, lastname, email, comment, currency_code, products[i][product_id], products[i][quantity]`.

- Written through OpenCart's own `checkout/order` model (`addOrder()` +
  `addHistory()`), not hand-rolled INSERTs, so order/product/total rows and
  stock handling stay consistent with the rest of OpenCart.
- **Prices always come from this store's catalog.** The request carries none and
  none would be honoured — an endpoint that let the caller name its own price
  would be a discount oracle for anyone who obtained the token.
- Unknown or disabled product ids **reject the whole order** rather than placing
  a short one.
- An unknown/disabled `currency_code` is refused rather than defaulted.
- The order lands on the store's configured default order status
  (`config_order_status_id`) with `notify = false`, so the merchant sees a real
  order and the buyer gets no OpenCart email — TackQuote owns that conversation.
- The order is attached to an existing customer account when the email matches
  exactly; **no account is created** (guest order, `customer_id = 0`, otherwise).
- **No tax is calculated.** There is no shipping/payment address to resolve a
  tax zone from, so OpenCart's tax rules cannot be applied honestly; TackQuote
  is the system of record for tax on a quote. Recorded, not guessed.

## Known gaps

- **`checkoutUrl`.** `OpenCartService.createOrder` hands back
  `index.php?route=checkout/checkout`. That route exists, but it renders the
  *visitor's own session cart* and bounces when that cart is empty, so a buyer
  following the link only lands somewhere useful if their session already has
  the items. Seeding a stranger's session from a server-to-server call is not
  something OpenCart supports; **unchanged and still unverified**.
- **Order options/variants.** `order.add` places simple product lines only —
  product options, subscriptions and vouchers are not carried.
- **Multi-store.** The feed is scoped to the store OpenCart resolves for the
  request host. A second storefront needs its own TackQuote connection.
- **No OpenCart Marketplace listing** and no OC3 package.
- **`order-sync`** (`POST /v1/integrations/opencart/order-sync` on the TackQuote
  side) still has no caller in this extension — order import happens through
  `order.list` above instead.
- Not verified against a running OpenCart install. Every structural claim above
  is cited to 4.0.2.3 source, and the PHP is syntax-checked in CI-equivalent
  fashion (`php -l`), but there is no integration test against a live store.
