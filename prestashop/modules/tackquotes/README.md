# TackQuote for PrestaShop

A PrestaShop 1.7 / 8.x module that adds a "Request a Quote" button to product
pages and connects the store to a TackQuote B2B quoting account (API base URL
+ API key). It mirrors the pattern used by the TackQuote WooCommerce plugin at
`integrations/wordpress/tack-quotes/`.

This module is **separate** from `apps/api/src/modules/integrations/prestashop/prestashop.service.ts`.
That service is Tack acting as a client of *your* store's PrestaShop
Webservice API (pulling products/orders, pushing orders) using a PrestaShop
API key stored in Tack. This module is the reverse direction: your
PrestaShop store acting as a client of the **Tack API**, using a Tack API
key, the same way the WooCommerce plugin does.

## Installation

Distribution authority: the public GitHub release asset is
[`tack-prestashop.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-prestashop.zip).
This monorepo directory is build/source only. No PrestaShop Addons listing is claimed.

1. Download `tack-prestashop.zip` and follow the included README, or zip the
   `tackquotes/` directory itself (not its parent) if you are packaging from this
   checkout:
   ```
   cd integrations/prestashop/modules
   zip -r tackquotes.zip tackquotes
   ```
2. In your PrestaShop back office, go to **Modules > Module Manager > Upload a module**
   and upload `tackquotes.zip`.
3. Once installed, click **Configure** on the TackQuote module.
4. Enter your **TackQuote API URL** (defaults to `https://api.tackquote.com/v1`)
   and paste your **TackQuote API key** (create one in TackQuote under
   Settings > Developer > API Keys — the same kind of key the WooCommerce
   plugin uses).
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
