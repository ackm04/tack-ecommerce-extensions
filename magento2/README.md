# TackQuote for Magento 2

A Magento 2 Composer module (`tackquote/module-quotes`, module name `TackQuote_Quotes`)
that adds a storefront **"Request a Quote"** button to product pages, which creates a
real quote request in TackQuote. It's the Magento counterpart to the TackQuote
WooCommerce plugin (`integrations/wordpress/tack-quotes/`), scoped to the same
basic-but-real MVP: connect, show a button, create a quote.

Magento's product catalog sync, inventory pull, quote-to-checkout, and order import were
**already real and working** before this module existed — they run entirely through
`apps/api/src/modules/integrations/magento/{magento.controller,magento.service}.ts`
against the Magento Admin REST API, configured under Settings → Integrations → Magento in
the seller portal. This module does not touch any of that. It exists solely to add a
storefront quote-request entry point, which the REST-API-only connector cannot do because
nothing calls out from the storefront to TackQuote without a module installed on the
Magento side.

## What this module does

- Adds a "Request a Quote" button under the Add to Cart area on the product view page
  (`view/frontend/layout/catalog_product_view.xml`, `Block/RequestQuote.php`,
  `view/frontend/templates/button.phtml`).
- Clicking it opens a small modal (email, quantity, optional note) and posts to this
  module's own controller, `POST /tackquote/quote/submit`
  (`Controller/Quote/Submit.php`) — the TackQuote API key stays server-side, never in the
  browser, same principle as the WooCommerce plugin's `admin-ajax.php` handler.
- The controller loads the product by SKU via `ProductRepositoryInterface`, builds a
  single-line-item payload, and calls the TackQuote API
  (`Model/Api/Client.php`) using the base URL + API key configured under **Stores →
  Configuration → TackQuote → TackQuote Settings** (`etc/adminhtml/system.xml`,
  `Model/Config.php`). The API key is stored encrypted
  (`Magento\Config\Model\Config\Backend\Encrypted`), mirroring how the WordPress plugin
  masks its stored key.
- On success, the shopper sees a link to the quote in the TackQuote buyer portal.

This is real end-to-end: submitting the form on a configured store creates an actual
draft `Quote` (with a buyer and line item) in TackQuote's database, the same as the
WooCommerce plugin's button does.

## What's real vs. what's a gap

**Real and working today:**

- Module installs, enables, and shows a config section (`Stores → Configuration →
  TackQuote`) with API URL / API key / button toggle / button label, scoped per website.
- The storefront button renders on product pages when enabled, and submitting it creates
  a genuine TackQuote quote (buyer auto-created if new, quote + line item persisted).
- Magento catalog sync, inventory, quote-to-checkout, and order import — unrelated to this
  module, already shipped in `apps/api`.
- Order sync **from** Magento to TackQuote needs no module component: `CommercePullCronService`
  (`apps/api/src/modules/integrations/commerce-pull-cron.service.ts`) already pulls
  Magento orders on a schedule via the Admin REST API (Magento has no outbound payment
  webhooks, so Tack always pulls for this platform, unlike WooCommerce which needs the
  plugin to push).

**Known gap — inbound endpoint reuse:**

As of this version, `apps/api` only ships an **API-key-authenticated** plugin controller
for WooCommerce:
`apps/api/src/modules/integrations/woocommerce/woocommerce-plugin.controller.ts`, exposing
`GET /v1/integrations/woocommerce/ping` and `POST
/v1/integrations/woocommerce/quote-requests`. There is **no Magento-specific equivalent**
(no `magento-plugin.controller.ts`). The existing Magento controller
(`magento.controller.ts`) is entirely JWT-guarded, seller-only — a public Magento
storefront visitor's browser (or this module's server-side request, authenticated with a
tenant API key rather than a seller session) cannot call it.

Because those two WooCommerce routes are payload-generic — `ping` just returns
`{ok, tenantId}` and `quote-requests` accepts `{buyerEmail, note, source, lineItems}` with
no WooCommerce-specific validation — `Model/Api/Client.php` calls them as a stand-in so
this module is genuinely functional today rather than a stub. `createQuoteRequest()` sends
`"source": "magento"`, and **the backend now honors that field**:
`WooCommerceService.createQuoteFromPluginRequest` (in
`apps/api/src/modules/integrations/woocommerce/woocommerce.service.ts`) reads
`payload.source` (defaulting to `'woocommerce'` for backward compatibility with the real
WooCommerce plugin, which doesn't send it) and tags the created quote with
`[source, 'plugin-request']`. A quote created from this Magento module is now correctly
tagged `magento` in TackQuote instead of `woocommerce`.

**Still open** (small, additive backend change — no changes needed in this module):

1. Add `apps/api/src/modules/integrations/magento/magento-plugin.controller.ts`, mirroring
   `woocommerce-plugin.controller.ts` — same `ApiKeyGuard` + `PlanGuard`, routes under
   `/integrations/magento/ping` and `/integrations/magento/quote-requests`.
2. Update `Model\Api\Client::PATH_PING` / `PATH_QUOTE_REQUESTS` in this module to the new
   `/integrations/magento/*` paths and bump the module version.

**Not built (intentionally out of scope for this MVP):**

- No "cart" quote button (WooCommerce's plugin also quotes the whole cart from the cart
  page) — only the single-product page button. Adding a cart-page block later is
  straightforward (same pattern as `Tack_Widget::render_cart_button()` in the WooCommerce
  plugin) once cart line items are read from Magento's `Magento\Checkout\Model\Session`.
- No admin "Test connection" button in `system.xml` (the WooCommerce plugin has one). The
  HTTP client (`Model\Api\Client::testConnection()`) exists and works — it's just not
  wired to an admin UI button yet. Verify connectivity by clicking the storefront button on
  a test product, or by curling the configured API URL/key directly.
- No Magento Marketplace listing or Packagist publication (see Installation below).

## Installation

This module is **not yet published to Packagist or the Magento Marketplace**. Until it is,
install it from source:

### Option A — path repository (recommended for local/staging)

In your Magento store's root `composer.json`:

```json
{
  "repositories": {
    "tackquote-quotes": {
      "type": "path",
      "url": "../path/to/tack/integrations/magento2"
    }
  }
}
```

```bash
composer require tackquote/module-quotes:*
bin/magento module:enable TackQuote_Quotes
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Option B — copy into app/code

```bash
mkdir -p app/code/TackQuote/Quotes
cp -r /path/to/tack/integrations/magento2/* app/code/TackQuote/Quotes/
bin/magento module:enable TackQuote_Quotes
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Configure

1. Go to **Stores → Configuration → TackQuote → TackQuote Settings**.
2. Set **Enable TackQuote** to Yes.
3. Set **TackQuote API Base URL** (default `https://api.tackquote.com/v1`).
4. Paste a TackQuote **API Key**, generated in TackQuote under Settings → Developer → API
   Keys (or the "Inbound API key" section of Settings → Integrations → Magento in the
   seller portal).
5. Under **Request a Quote button**, enable **Show quote button on product pages** and
   optionally change the label.
6. Save config, then visit any product page on the storefront to confirm the button
   appears and submits successfully.

## File map

```
integrations/magento2/
├── registration.php                          Module registration
├── composer.json                             Package metadata (tackquote/module-quotes)
├── etc/module.xml                            Module declaration + sequence
├── etc/acl.xml                               Admin ACL resource for the config section
├── etc/adminhtml/system.xml                  Stores > Configuration > TackQuote fields
├── etc/frontend/routes.xml                   Registers the tackquote/* frontend route
├── Model/Config.php                          Reads scoped config (enabled/url/key/label)
├── Model/Api/Client.php                      HTTP client to the TackQuote API (cURL)
├── Block/RequestQuote.php                    Product-page button view model
├── Controller/Quote/Submit.php               Server-side quote-request POST handler
└── view/frontend/
    ├── layout/catalog_product_view.xml       Injects the button block on product pages
    ├── templates/button.phtml                Button + modal markup
    └── web/js/request-quote.js, web/css/…    Vanilla JS/CSS for the modal (no RequireJS)
```
