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

1. Zip the `tackquotes/` directory itself (not its parent), e.g.:
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

## What still needs a new Nest endpoint to fully complete

The Tack API has **no PrestaShop equivalent of the WooCommerce plugin
controller**. Compare:

- WooCommerce: `apps/api/src/modules/integrations/woocommerce/woocommerce-plugin.controller.ts`
  exposes `GET /integrations/woocommerce/ping`, `POST /integrations/woocommerce/quote-requests`,
  and `POST /integrations/woocommerce/order-sync`, all behind `ApiKeyGuard` (tenant
  resolved from the API key, not a seller JWT) — this is exactly what the
  WooCommerce plugin's `Tack_Api_Client` calls.
- PrestaShop: **no such controller exists.** The only PrestaShop-related code
  server-side is `PrestaShopService` / `PrestaShopPlatformAdapter`, which are
  JWT-authenticated (seller-portal-driven) and call *out* to the store's own
  PrestaShop Webservice API — they don't accept inbound calls from a
  storefront module at all.

Concretely, this module's `TackApiClient` calls:

- `GET /integrations/prestashop/ping` (falls back to `GET /health`) for the
  "Test connection" button.
- `POST /integrations/prestashop/quote-requests` for the storefront quote
  button, with the same JSON contract the WooCommerce plugin's endpoint uses:
  `{ buyerEmail, note, source: "prestashop", lineItems: [{ sku, name, quantity, unitPrice, externalProductId }] }`,
  expecting back `{ id, quoteNumber, portalUrl }`.

**Neither route exists yet.** Until they're added, "Test connection" will
report an error (after falling back to `/health`, which will succeed if the
key/URL are otherwise valid but doesn't prove PrestaShop-specific wiring) and
the storefront "Request a Quote" button will fail with a `502` surfaced from
`TackApiClient::createQuoteRequest()` (the JSON error message is shown to the
shopper, not swallowed).

To finish the integration, add a `PrestaShopPluginController` under
`apps/api/src/modules/integrations/prestashop/` mirroring
`woocommerce-plugin.controller.ts` line for line:
- `@UseGuards(ApiKeyGuard, PlanGuard)` on `@Controller('integrations/prestashop')`
- `GET ping` → `{ ok: true, tenantId }`
- `POST quote-requests` → upsert referenced products via
  `IntegrationsService.upsertProduct(tenantId, 'prestashop', ...)`, then create
  a draft quote via the same canonical pipeline `WooCommerceService.createQuoteFromPluginRequest`
  uses (a `PrestaShopService.createQuoteFromPluginRequest` equivalent, or a
  shared helper), returning `{ id, quoteNumber, portalUrl }`.
- `POST order-sync` → optional; this module does not push orders today (no
  order-sync hook is wired up, unlike the WooCommerce plugin's
  `Tack_Order_Sync`), so it isn't required for the button to work, only if
  order-push-from-storefront is wanted later.

No other changes to this module should be needed once that controller exists
— the request/response shapes already match.

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
