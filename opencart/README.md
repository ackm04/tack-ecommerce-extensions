# TackQuote for OpenCart

An OpenCart 4.x extension with **two independent halves**:

| Half | Direction | What it is |
|------|-----------|------------|
| **Quote button** | store → TackQuote | A "Request a Quote" button on product pages that posts to `POST /v1/integrations/opencart/quote-requests` on the TackQuote API, authenticated with a **TackQuote API key**. |
| **Catalog / order feed** | TackQuote → store | JSON routes `index.php?route=extension/tack/api/{product.list,order.list,order.add}`, authenticated with a **feed token you generate in this store**. This is what `OpenCartService` in the TackQuote API calls to sync products, import orders and place quote-accepted orders. |

The two halves use **different secrets on purpose**. The API key lets this
store talk to TackQuote; the feed token lets TackQuote talk to this store.
Neither is usable in the other direction.

Distribution authority: merchants install the public
[`tack.ocmod.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack.ocmod.zip)
release asset directly. Keep that exact filename. The optional
[`tack-opencart-source.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-opencart-source.zip)
asset is source-only for review and local builds; do not upload it to OpenCart.

Both links resolve to the newest GitHub release rather than a pinned tag: this repository
cuts one repo-wide `v*` tag covering every platform, so a tag pinned in this file goes
stale the next time any *other* extension ships. `scripts/package-all.sh` is what emits
both assets, under exactly these names.

> ## ⚠️ The package MUST be named `tack.ocmod.zip`
>
> OpenCart derives the extension code from the **zip filename** — "a folder will be
> created into the `extension/` directory based on the name of your file"
> ([developer guide](https://docs.opencart.com/developer-guide/extensions)). **Nothing
> inside the package pins it.** `install.json`'s `"code": "tack"` is not one of the
> documented keys and drives nothing.
>
> Every namespace in this extension hard-codes `…\Extension\Tack\…`, and the event
> actions registered at install time are `extension/tack/event/quote.productPage` and
> `…quote.footer`. So a zip named anything else — `tackquote.ocmod.zip`,
> `tack-opencart-source.zip`, `tack.ocmod (1).zip` from a browser re-download — **installs
> cleanly, reports success, and then 404s on every single route**: no quote button, no
> settings screen, no catalog feed, and nothing in the error log pointing at the cause.
>
> The packaging check in `tests/run.php` reads the required code out of the admin
> controller's own namespace and fails if it no longer matches the shipped
> `tack.ocmod.zip`, so this cannot drift silently. (The monorepo guarded the same
> invariant from `scripts/package-integrations.sh`; that script is not part of this
> repository — `scripts/package-all.sh` builds the artifacts here.)

> **New in 1.3.1 — documentation only, no code change.** Reconciled against the
> TackQuote monorepo copy of this extension before that copy was retired. The
> distribution contract above was stale: it named `tack-opencart.zip` at `v1.1.0` and
> told merchants to build the installer themselves, when `scripts/package-all.sh` has
> emitted a ready-to-install `tack.ocmod.zip` (plus an optional
> `tack-opencart-source.zip`) since repo tag `v1.2.0`. The Build, Tests and Layout
> sections also pointed at monorepo paths (`scripts/package-integrations.sh`,
> `dist/extensions/`, `integrations/opencart/`) that do not exist in this repository.
> No PHP, Twig or JavaScript changed; the suite is unchanged at 67 checks.

> **New in 1.2.0 — the button is where buyers look for it, and one quote can hold many
> products.** Three changes, matching what the WooCommerce and Magento extensions already do:
>
> 1. **Beside Add to Cart, not under the description.** A `catalog/view/product/product/after`
>    view event injects the controls immediately after core's Add to Cart button
>    (`id="button-cart"`). No theme file is edited and no OCMOD patch is applied. If a theme
>    has renamed that button the event injects nothing and leaves the page untouched — the
>    layout-module placement remains as a fallback.
> 2. **A multi-product quote list.** "Add to Quote" collects products — from product pages and
>    from category/search tiles — into a list held in `localStorage`, shown in a floating
>    launcher and a three-step panel (items → your details → done). The list **never touches
>    the OpenCart cart**: quoting is not buying, and a buyer pricing twelve items must not have
>    their cart, shipping estimate or abandoned-cart email disturbed.
> 3. **Prices come from the catalog, never the browser.** The panel posts only product ids and
>    quantities; `Tackquotes::quoteList()` re-reads every name, model and price server-side. A
>    request forging `unitPrice=0.01`, a SKU and a product name was stored at the real
>    2000.00 with the real SKU (verified against a live store, quote TK-2026-001076).
>
> Also in 1.2.0: a session-scoped submission throttle (5 per 10 minutes — the sixth request is
> refused), a 50-line cap per quote, and four new settings covering the labels and each
> placement.

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
opencart/
├── install.json
├── README.md
├── admin/
│   ├── controller/module/tackquotes.php          settings screen + Test connection
│   ├── language/en-gb/module/tackquotes.php
│   └── view/template/module/tackquotes.twig
├── catalog/
│   ├── controller/module/tackquotes.php          storefront button + quote AJAX
│   ├── controller/quotemode.php                  quote-only mode ENFORCEMENT + blocked page
│   ├── controller/event/quote.php                product-page / footer view events
│   ├── controller/api/product.php                GET  …route=extension/tack/api/product.list
│   ├── controller/api/order.php                  GET  …route=extension/tack/api/order.list
│   │                                             POST …route=extension/tack/api/order.add
│   ├── language/en-gb/module/tackquotes.php
│   └── view/template/
│       ├── module/tackquotes.twig
│       └── quote/{controls,drawer,blocked}.twig
└── system/library/
    ├── api_client.php                            store → TackQuote HTTP client
    ├── api_guard.php                             TackQuote → store auth/paging/JSON
    └── quote_only.php                            who is quote-only, in one place
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

## Quote-only mode (B2B catalog)

**Extensions > Modules > TackQuote > Quote-only store.** Turns the whole storefront
into a B2B catalog: Add to Cart and checkout are refused and shoppers request a
quote instead. Off by default.

### It is refused by the server, not hidden by CSS

The refusal is in PHP, before core's cart controller is constructed. Two event
handlers registered against `catalog/controller/checkout/…/before` rewrite the
route the framework is about to dispatch:

| file | what it does |
|---|---|
| `catalog/controller/quotemode.php` → `guardCart()` | rewrites `checkout/cart.add` and `checkout/cart.edit` to `extension/tack/quotemode.blocked` |
| `catalog/controller/quotemode.php` → `guardCheckout()` | rewrites `checkout/checkout`, `checkout/confirm` and `checkout/confirm.confirm` to `extension/tack/quotemode.notice` |

Why a route rewrite is a refusal and not a suggestion, from OpenCart 4.1.0.4
source rather than from documentation:

```php
system/framework.php:214   $action = '';
system/framework.php:262   $event->trigger('controller/' . $trigger . '/before', [&$route, &$args]);
system/framework.php:268   if (!$action) {
system/framework.php:269       $action = new \Opencart\System\Engine\Action($route);
system/framework.php:275   $output = $action->execute($registry, $args);
```

`$route` reaches the handler **by reference** and the Action is then built from
whatever the handler left in it, so core `checkout/cart.add` is never
constructed and nothing reaches `$this->cart->add()`
(`catalog/controller/checkout/cart.php:286`). The identical event fires from
`system/engine/loader.php:73` for internal `$this->load->controller(…)` calls,
which is what covers `checkout/confirm` being loaded as a sub-controller of the
checkout page. `curl -d 'product_id=42&quantity=1'` gets the JSON refusal, same
as the browser.

The event rows are written by `install()` and are visible under **Extensions >
Events** as `tackquotes_guard_*`. Disabling them there disables enforcement —
that is OpenCart's design, not a bypass this extension can close.

### Who it applies to

`Applies to` — **Everyone** (default), **Guests only** (logged-in customers keep
a normal cart and checkout — the usual B2B setup), or **Selected customer
groups**. Guests are matched against the store default customer group, which is
the group OpenCart already prices them in; their raw `customer_group_id` is `0`,
a sentinel rather than a group (`system/library/cart/customer.php:36`).

An **admin logged into the same browser is exempt**, so a merchant can compare
the real cart and checkout before and after flipping the switch. This mirrors
core maintenance mode, the only other feature that switches the storefront off
for the public but not for staff (`catalog/controller/startup/maintenance.php:29-31`).

`api/*` is never guarded. Admin **Sales > Orders > Add Order** drives
`catalog/controller/api/cart.php`, and TackQuote's own `extension/tack/api/order.add`
places quote-accepted orders — blocking those would stop phone orders and stop
the conversion of the very quotes this mode exists to collect. A test asserts
that no `catalog/controller/api/…` trigger is ever registered.

### The store is never left unable to transact

This is the failure the WooCommerce build nearly shipped: there, the quote button
hung off a hook that only fires *inside* the add-to-cart form, so removing the
cart button would have silently removed the quote button too. OpenCart has the
same coupling in a different shape — `id="button-cart"` is the **only** anchor
the product-page injection has. So:

- The quote controls are injected **first**, and the Add to Cart button is
  removed **second**, re-finding its own anchor in the new string
  (`catalog/controller/event/quote.php`). The two cannot half-happen.
- Quote-only mode **overrides** the "Show beside Add to Cart" placement toggle.
  Honouring it would leave a product page with a dead cart button and no quote
  button.
- If a theme has renamed `id="button-cart"`, the controls are **appended to the
  end of the product page** instead of the usual "inject nothing". The theme's
  own button is left untouched and the POST is refused anyway.
- Enforcement and the CTA are switched on by **one condition**. With no API key
  saved, `isActive()` renders no quote button — so with no API key the cart is
  **not** blocked either, and the settings screen refuses to save quote-only
  mode until a key exists.
- The blocked-checkout page (`catalog/view/template/quote/blocked.twig`) carries
  a "Request a quote" button of its own, plus a link back to the existing basket.

Category and search tiles get their cart button hidden by
`catalog/view/javascript/tack/quote-app.js` and an add-to-quote button in its
place. That is cosmetic only — the server refuses the POST whether or not that
script ran.

### Existing carts and the checkout route

Turning the mode on **does not empty anybody's cart**. A session cart is the
shopper's data, and OpenCart persists carts in `oc_cart` across sessions for
logged-in customers, so "silently deleted" could mean weeks later. `checkout/cart`
and `checkout/cart.remove` stay open so a shopper can still see and clear what
they had.

What the cart cannot do while the mode applies is **grow or convert**: `.add` and
`.edit` are refused (`.edit` is not decoration — without it a one-line cart from
before the switch could be edited to quantity 10 000), and `checkout/confirm`,
the only storefront path to `addOrder()` (`catalog/controller/checkout/confirm.php:280`),
is refused. A pre-existing cart is inert rather than destroyed, and becomes live
again untouched the moment the mode is switched off.

**UNVERIFIED / known limit:** a third-party payment extension that calls
`$this->model_checkout_order->addOrder()` itself instead of going through
`checkout/confirm` would not be intercepted. Core has no such path — the only
other callers in 4.1.0.4 are `catalog/controller/api/order.php` (exempt by
design) and `catalog/controller/cron/subscription.php` (recurring billing for
subscriptions taken out before the switch). The third-party ecosystem cannot be
enumerated. The invariant that holds unconditionally is the one the feature is
sold on: nothing new can enter the cart.

## Build

```
bash scripts/package-all.sh
```

produces two artifacts in `dist/` (pass a directory to override):

| File | What it is |
|------|------------|
| **`tack.ocmod.zip`** | What a merchant installs. `install.json` + `admin/` + `catalog/` + `system/` at the zip root. **The filename sets the extension code — do not rename it.** |
| `tack-opencart-source.zip` | Optional source-only archive for GitHub Releases (adds `README.md`, wrapped in an `opencart/` folder, `marketplace/` excluded). **Not installable** by OpenCart's installer. |

To build just the installable one by hand:

```
cd opencart
zip -r ../dist/tack.ocmod.zip install.json admin catalog system
```

## Tests

```
php opencart/tests/run.php
```

No composer, no phpunit, no database, no store — OpenCart is not a composer
package and this extension ships no dependency manifest, so the runner is
self-contained and stubs only the slice of OpenCart 4's engine the admin
controller touches (`Controller::__get()` resolving out of a Registry).

It covers the **save path**, because that is where the 1.2.1 defects lived and
every one of them was invisible to manual clicking: the setting persisted
correctly each time, and the bug was in what came *back*. So the assertions are
about the response and about whether anything was written at all — a permission
denial must produce `error.warning` and write nothing, a bad URL must produce
`error.api_url` and write nothing, a good save must return `success` as JSON and
not a redirect. It also guards the invariants that are easy to undo by accident:
no `innerHTML` sink in the admin template, no stored secret reaching the view,
the `module_tackquotes` setting group, and the namespace-derived extension code
still matching `tack.ocmod.zip`.

Checked against the pre-fix tree: 13 of the 15 fail on it. A suite that passes
both before and after is not testing anything.

**Quote-only mode** adds 38 more (67 total). They assert the two claims the
feature actually makes, and both were mutation-tested — 10 deliberate breaks,
all 10 compiled and all 10 were caught:

| break | caught by |
|---|---|
| `guardCart()` stops rewriting the route | *a crafted add-to-cart POST never reaches core cart controller* |
| scope "guests" inverted | *scope "guests" lets approved B2B customers keep the cart* |
| the API-key condition dropped from the rule | *WITHOUT AN API KEY the cart is NOT blocked* |
| the no-anchor fallback removed | *a theme that renamed the cart button still gets a quote CTA* |
| the placement toggle can silence the CTA again | *quote-only overrides the placement toggle* |
| the admin-preview exemption forced off | *an admin logged into this browser keeps cart and checkout* |
| the `cart.add` guard row never registered | *install() registers the guard rows* |
| the CTA renders as nothing while the button is removed | *removes Add to Cart AND leaves the quote CTA in its place* |
| the Add to Cart button never removed | same test, other direction |
| the guard pointed at `api/cart.add` | *no guard is ever registered against an api/ route* |

The dispatch test does not mock the framework's contract, it reproduces it: the
value it asserts on is literally the route OpenCart would construct and execute
(`system/framework.php:262-269`).

## Install

1. **Extensions > Installer** → upload `tack.ocmod.zip`, then click Install.
2. **Extensions > Extensions**, filter by **Modules**, find **TackQuote**, click
   the **+** (install), then the **pencil** (edit).
3. Fill in:
   - **TackQuote API URL** — `https://api.tackquote.com/v1`.
   - **TackQuote API Key** — TackQuote → Settings → Developer → API Keys. Used by
     the storefront button. Click **Test connection**.

     > **The key must carry the `quotes:write` scope.** *Test connection* uses the unscoped `ping` route, so a key without it passes the test and then fails every real quote submission with a 403.
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
  "total": 1, "page": 1, "limit": 0,
  "truncated": false, "next_page": null, "next_limit": null }
```

- **Unpaginated by default, and still complete.** TackQuote's `syncProducts()`
  makes one call with no paging, so a default page size here would silently
  import the first page and report success. `page`/`limit` are honoured if sent.
- **Bounded internally.** The default path reads that complete catalog in
  250-row chunks rather than one unbounded statement, so neither the buffered
  result set nor the correlated `special` subquery is evaluated against the whole
  table at once. It stops early only past 5,000 products, and then says so:
  `truncated: true` with `next_page`/`next_limit` giving the exact request that
  resumes the walk, and `total` giving what remains. `truncated` is `false` on
  every normal response — a caller never has to compare `products.length`
  against `total` to find out it was handed a partial catalog.
- `next_page` is always paired with `next_limit`, because a page number is
  meaningless without the size it is counted in.
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
- **Quote-only mode and third-party payment extensions.** See the UNVERIFIED note
  under "Existing carts and the checkout route" above.
- **Quote-only mode needs `install()` to have run since 1.3.0.** The guard event
  rows are added by `install()`, which OpenCart calls when the extension is
  installed or the module re-enabled. A merchant who upgrades the files in place
  without re-installing gets the settings but no enforcement. Re-install from
  Extensions > Installer, or check Extensions > Events for five
  `tackquotes_guard_*` rows.
- **Enter-to-submit on the product page.** In quote-only mode the Add to Cart
  button is removed but core's `<form id="form-product">` remains, so pressing
  Enter in the quantity field still fires its submit handler. The POST is refused
  server-side; the stock inline handler has no `#error-warning` element, so the
  refusal is not drawn on that page. Server-side correct, cosmetically silent.
- Not verified against a running OpenCart install. Every structural claim above
  is cited to 4.0.2.3 source, and the PHP is syntax-checked in CI-equivalent
  fashion (`php -l`), but there is no integration test against a live store.
