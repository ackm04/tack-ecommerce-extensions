# TackQuote for PrestaShop

A PrestaShop 1.7 / 8.x module that adds a "Request a Quote" button to product
pages and connects the store to a TackQuote B2B quoting account (API base URL
+ API key). It mirrors the pattern used by the TackQuote WooCommerce plugin at
`wordpress/tackquote/`.

This module is **separate** from `apps/api/src/modules/integrations/prestashop/prestashop.service.ts`.
That service is Tack acting as a client of *your* store's PrestaShop
Webservice API (pulling products/orders, pushing orders) using a PrestaShop
API key stored in Tack. This module is the reverse direction: your
PrestaShop store acting as a client of the **Tack API**, using a Tack API
key, the same way the WooCommerce plugin does.

## Installation

Distribution authority: the public GitHub release asset is
[`tack-prestashop.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-prestashop.zip),
built by `scripts/package-all.sh`. The link resolves to the newest release rather than a
pinned tag, because this repository cuts one repo-wide `v*` tag covering every platform.
This directory is source only. No PrestaShop Addons listing is claimed.

1. Download `tack-prestashop.zip` and follow the included README, or zip the
   `tackquotes/` directory itself (not its parent) if you are packaging from this
   checkout:
   ```
   cd prestashop/modules
   zip -r tackquotes.zip tackquotes
   ```
2. In your PrestaShop back office, go to **Modules > Module Manager > Upload a module**
   and upload `tackquotes.zip`.
3. Once installed, click **Configure** on the TackQuote module.
4. Enter your **TackQuote API URL** (defaults to `https://api.tackquote.com/v1`)
   and paste your **TackQuote API key** (create one in TackQuote under
   Settings > Developer > API Keys — the same kind of key the WooCommerce
   plugin uses).

   > **The key must carry the `quotes:write` scope.** *Test connection* uses the unscoped `ping` route, so a key without it passes the test and then fails every real quote submission with a 403.
5. Click **Save**, then **Test TackQuote connection** to confirm connectivity.
6. Toggle "Show 'Request a Quote' button on product pages" and set the button
   label as desired.

## What's real today

- **Module scaffold** (`tackquotes.php`, `config.xml`) — a standard installable
  PrestaShop module: `install()`/`uninstall()` register/remove config values
  and hooks; metadata (name, version, author "TackQuote") is set correctly for
  the Module Manager.
- **Settings page** (`getContent()` / `views/templates/admin/configure.tpl`) —
  a real `HelperForm`-based admin form that persists the API URL, API key,
  button-enabled flag, and button label via `Configuration::updateValue()`,
  plus a "Test connection" action. This is a genuine settings UI; PrestaShop
  had none before this module (there was no seller-facing PrestaShop settings
  page anywhere in the repo, `apps/web` included).
- **Storefront button** (`hookDisplayProductActions` in `tackquotes.php` +
  `views/templates/hook/quote_button.tpl` + `views/js/tackquotes.js`) — a real
  hook implementation. `displayProductActions` is the current PrestaShop 1.7/8
  hook for action buttons rendered next to "Add to cart" on the product page.
  The button opens a small modal (email, quantity, note) and POSTs to this
  module's own front controller.
- **Front controller** (`controllers/front/quoterequest.php`) — a real
  `ModuleFrontController` that validates the submitted email, loads the
  `Product` by ID, builds a line item (SKU, name, quantity, unit price,
  external product ID), and calls `TackApiClient::createQuoteRequest()`.
- **API client** (`classes/TackApiClient.php`) — a real cURL-based HTTP client
  authenticated the same way the WooCommerce plugin's `Tack_Api_Client` is:
  `Authorization: Bearer <key>` + `X-Api-Key: <key>` headers, matching how
  `ApiKeyGuard` on the Tack API accepts credentials
  (`apps/api/src/common/guards/api-key.guard.ts`).

## The Tack API endpoints this module calls

`PrestaShopPluginController`
(`apps/api/src/modules/integrations/prestashop/prestashop-plugin.controller.ts`) serves all
of them, behind `ApiKeyGuard` — the tenant is resolved from the API key, not a seller JWT.

This section previously said "**Neither route exists yet**" and listed the controller as
work still to do. That was stale: the controller shipped, and "Test connection" and the
storefront button both work against a current Tack API.

- `GET /integrations/prestashop/ping` → `{ ok: true, tenantId }`. Used by "Test
  connection", which falls back to `GET /health` only for a Tack deployment older than that
  route (a `/health` success proves the URL is reachable, not that the key is valid for a
  tenant).
- `POST /integrations/prestashop/quote-requests` → upserts the referenced products, then
  registers the buyer and creates a draft quote through the canonical pipeline.

  Request:
  `{ buyerEmail, note, source: "prestashop", currency?, firstName?, lastName?, phone?,
  companyName?, lineItems: [{ sku, name, quantity, unitPrice, externalProductId }] }`

  Response: `{ id, quoteNumber, portalUrl, company, awaitingApproval }`
- `POST /integrations/prestashop/order-sync` → exists, but this module does not push orders
  (no order hook is wired up, unlike the WooCommerce plugin's `Tack_Order_Sync`), so it is
  only relevant if order-push-from-storefront is wanted later.

### Buyer identity

`firstName` and `lastName` are collected by the product-page modal and sent as fields.

They did not exist before, and the endpoint — knowing only an email address — wrote its
local part into `buyers.first_name`. A shopper at `ps-probe@example.com` became a buyer
literally named **"ps-probe"**, which a seller could not tell apart from a name somebody
typed. Nothing failed: the request answered `201`. Confirmed against the live API, quote
`TK-2026-001085`.

- **Both fields are optional and stay empty when blank.** Every already-installed copy of
  this module posts email/note/quantity/product_id and nothing else, and those submissions
  still answer `201` — the buyer is created with no name rather than an invented one. A
  blank input is omitted from the payload, never sent as `""`.
- **Validated with `Validate::isName()`**, not `Validate::isCustomerName()`: the latter is
  what PrestaShop uses for `customers.firstname`/`lastname` but only exists from 1.7.6, and
  this module declares `ps_versions_compliancy` min `1.7.0.0`.
- **Prefilled from the signed-in customer** (`$this->context->customer`, only once
  `isLogged()`), the same source the OpenCart drawer uses. Guests get empty inputs; nothing
  is guessed.
- `companyName` and `phone` are accepted by the endpoint but **not collected by this
  module**. Adding a company input is a separate decision, because a company name resolves
  to a real company record and applies the seller's TackQuote registration policy — a
  tenant set to `company_only`, or requiring a business email or particular company
  details, would then refuse submissions this modal cannot collect the answers for.
  `GET /v1/integrations/woocommerce/registration-config` is the endpoint that exists to
  drive that rendering.

## Quote-only / B2B catalog mode

Configure > **Quote-only (B2B catalog) mode**. Off by default; installing the module
never changes what a storefront is allowed to do.

When it is on, an in-scope shopper gets a storefront where "Add to cart" and checkout
are **refused by the server** and the "Request a Quote" button takes their place.

### Where enforcement happens

`TackQuotes::hookActionFrontControllerInitBefore()` in `tackquotes.php`. It runs from
the first statement of `FrontController::init()`
(`classes/controller/FrontController.php:264-269`, PrestaShop 8.2), i.e. before any
controller does anything, and it does two things:

1. **Refuses the request.** A cart-mutating request (`add` or `update`, in `$_GET` or
   `$_POST`) is rejected outright — 403 + PrestaShop's own ajax error envelope for
   XHR, redirect back to the product otherwise. `CartController::updateCart()` never
   runs. This is what answers a crafted POST; no CSS or JS is involved anywhere in
   this module's enforcement.
2. **Engages PrestaShop's own catalog mode for this request only**, which inherits
   core's whole enforcement surface: `Cart::updateQty()` (`classes/Cart.php:1563-1569`),
   `Cart::checkQuantities()` (`classes/Cart.php:4120`), the cart page and its two ajax
   endpoints (`controllers/front/CartController.php:99`, `:126`, `:166`) and checkout
   (`controllers/front/OrderController.php:246`).

### Why the native `PS_CATALOG_MODE` setting is *complemented*, not *driven*

The persistent setting the merchant edits in **Shop Parameters > Product Settings >
Catalog mode** is **never written** by this module. `Configuration::updateValue()` is
never called for it, and `uninstall()` deliberately does not touch it.

What the module does call is `Configuration::set()`, whose own docblock reads *"Set
TEMPORARY a single configuration value"* (`classes/Configuration.php:369-376`) and
whose body writes only the in-memory caches — there is no `Db` call anywhere in
`classes/Configuration.php:377-406`. Nothing is persisted; the flag lasts exactly one
request.

Driving the real setting was rejected for three reasons:

- **It is global.** `PS_CATALOG_MODE` has no notion of a customer group, so
  "guests only, approved B2B buyers keep the cart" would be impossible. (Core *does*
  have a per-group catalog mode via `Group::show_prices` — `Configuration::isCatalogMode()`
  at `classes/Configuration.php:697-705` — but it works by hiding prices for that
  group, which is a different feature.)
- **The merchant also controls it.** Two writers on one setting means our uninstall
  either clobbers a value the merchant set by hand, or leaves the shop stuck in
  catalog mode after the module is gone. Both are worse than not writing it.
- **It is not reversible per shopper.** Employee preview and post-purchase pages both
  need the mode *off* within the same shop, in the same minute.

If the merchant turns the native setting on themselves, nothing here fights them:
`Configuration::isCatalogMode()` is already true and the module simply behaves as the
theme does.

### The quote button must survive the cart being removed

In the classic theme, `{hook h='displayProductActions'}` sits at
`themes/classic/templates/catalog/_partials/product-add-to-cart.tpl:64`, and line 26
of that same file wraps everything from there down in `{if !$configuration.is_catalog}`.
**Disabling the cart therefore stops the theme calling that hook at all** — the quote
button would have vanished along with "Add to cart". This is the same shape of bug as
the WooCommerce plugin hanging its button off a hook that only fires inside the
add-to-cart form.

So the CTA is published on three hooks and exactly one renders per page:

| hook | used when | why it survives |
| --- | --- | --- |
| `displayProductActions` | normal storefront | current placement, next to "Add to cart" |
| `displayProductAdditionalInfo` | quote-only mode | `product-additional-info.tpl:26` is included by `product.tpl:126-128` **outside** the `{if !$configuration.is_catalog}` guard |
| `displayFooter` | neither of the above rendered | last-resort net; deliberately conspicuous, because it means the theme needs a template tweak |

All three are template-emitted hooks, so a sufficiently unusual theme could still
call none of them — which is what the footer net is for.

On top of that, quote-only **refuses to engage at all** unless the CTA can actually
render: it requires a saved API key *and* the storefront button switched on, because
`renderQuoteWidget()` returns `''` without either. A store is never left unable to
both buy and ask. `TackQuoteOnlyMode::applies()` enforces this (`ctaReady`), the
settings form refuses the combination at save time, and the Modules page shows a
warning if it is ever reached.

### Scope

| Setting | Behaviour |
| --- | --- |
| **Everyone** (default) | every visitor gets the quote-only storefront |
| **Guests only** | signed-in customers keep a working cart; everyone else must request a quote |
| **Specific customer groups** | a visitor in any ticked group gets quote-only; a group scope with nothing ticked does not engage |

Groups come from `Customer::getGroupsStatic()`, which answers with
`PS_UNIDENTIFIED_GROUP` for anonymous traffic (`classes/Customer.php:1098-1100`), so
there is no separate anonymous branch.

### Employee preview stays exempt

A back-office preview (`?preview=1&adtoken=…&id_employee=…`) sees the ordinary
storefront, cart included. The check reproduces core's own
(`controllers/front/ProductController.php:136-142`) rather than calling
`ProductController::isPreview()`, because that flag is set inside `init()` and this
guard runs before `init()`. **Limit:** the exemption covers page *rendering*. The
theme's add-to-cart form does not carry `adtoken`, so an employee clicking "Add to
cart" from a preview page is still refused unless the request itself carries the
preview credentials. Native catalog mode has no preview exemption at all, so this is
strictly better, not worse.

### Existing carts, and the order route

- **Existing carts are not deleted.** Toggling quote-only on leaves every cart row
  untouched, so toggling it off restores them intact. Destroying customer data on a
  settings change is not reversible and was not asked for.
- **Removing products from an existing cart is still allowed.** `delete` is
  deliberately excluded from the blocked operations (`TackQuoteOnlyMode::CART_ADD_KEYS`):
  a shopper who had a cart when the switch was flipped must be able to empty it rather
  than be stranded with items they can no longer check out.
- **Checkout is blocked** by core, at `controllers/front/OrderController.php:246`.
- **Post-purchase pages are explicitly left alone** — `history`, `order-detail`,
  `order-follow`, `order-slip`, `order-return`. Core's catalog mode redirects all five
  away (`HistoryController.php:48`, `OrderDetailController.php:157`,
  `OrderFollowController.php:105`, `OrderSlipController.php:44`,
  `OrderReturnController.php:87`), which would retroactively hide the invoices and
  order history of customers who bought while the shop was still selling. The
  request-scoped flag is simply not set on those pages
  (`TackQuotes::POST_PURCHASE_PAGES`).
- **Prices**: PrestaShop hides prices in catalog mode unless "with prices" is also on
  (`src/Core/Product/ProductPresentationSettings.php:46`), so the module exposes a
  "Keep prices visible in quote-only mode" switch, default on.

### Tests

`tests/QuoteOnlyModeTest.php` loads the real `TackQuotes` class against small
PrestaShop stubs and calls the real guard — it asserts behaviour, not source text.

```
docker run --rm -v "$PWD":/p -w /p php:8.3-cli \
  php prestashop/modules/tackquotes/tests/QuoteOnlyModeTest.php
```

`tests/` is excluded from the release zip by `scripts/package-all.sh`.

### Not verified

- Nothing here has been exercised in a running PrestaShop. The behaviour above is
  derived from PrestaShop 8.2 source read out of the `prestashop/prestashop:8.2-apache`
  image, and from the PrestaShop docs MCP for hook names; the admin form has not been
  rendered in a live back office.
- **UNVERIFIED: interaction with Smarty template caching (`PS_SMARTY_CACHE`).**
  Quote-only makes the product page differ per visitor. `Module::getCacheId()` does
  include the visitor's groups (`classes/module/Module.php:2225-2228`), which is the
  right shape, but this was not confirmed against a running shop with caching on.
  Treat quote-only + full-page caching as unverified.
- Themes other than `classic` were not inspected.

## Not implemented (out of scope for this scaffold)

- Order sync (PrestaShop order -> TackQuote). The WooCommerce plugin has
  `Tack_Order_Sync` hooking `woocommerce_order_status_changed`; this module has
  no equivalent yet. `PrestaShopService::syncOrdersInbound()` already exists
  server-side (pull-based, via the seller-portal-authenticated connector), so
  this may not even be needed for parity — PrestaShop orders can be pulled
  instead of pushed.
- A logo/icon asset for the Module Manager listing.
- Translation files (`translations/`) — user-facing strings use `$this->l()`
  / `{l}` so they're translation-ready, but no `.php` translation catalogs are
  included.
- A cart-level "Request a Quote" button (only the single-product page button
  is implemented, matching the task's storefront button requirement).

## Changelog

### 1.3.1 — documentation and manifest guards only, no runtime change

Reconciled against the TackQuote monorepo copy of this module before that copy was
retired. Nothing in `tackquotes.php`, `classes/`, `controllers/` or `views/` changed.

Carried across from the monorepo:

- The distribution link above pointed at release `v1.1.0`. It now resolves to the newest
  release, which is what `scripts/package-all.sh` publishes `tack-prestashop.zip` to.

Corrected in this copy rather than taken from the monorepo:

- The packaging step said `cd integrations/prestashop/modules` and the intro pointed at
  `integrations/wordpress/tackquote/`. Both carry the monorepo's directory prefix, which
  does not exist in this repository. (The monorepo copy additionally spelled the
  WooCommerce plugin `tack-quotes/`; that directory does not exist in either repository,
  so that change was **not** taken.)

Newly pinned by `tests/QuoteOnlyModeTest.php`, because all three of these shipped once
and nothing could see them:

- `config.xml` `<version>` must equal `$this->version`. They disagreed through two
  releases, and PrestaShop keys upgrades off that value — merchants were never offered
  an upgrade.
- `config.xml` `need_instance` must be `1`, or the module's own "no API key is set"
  warning is never constructed and never shown.
- The module description must not advertise order sync. It does not do order sync — see
  *Not implemented* above — and the monorepo copy still claims it does.
