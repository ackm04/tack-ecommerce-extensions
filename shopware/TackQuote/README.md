# TackQuote for Shopware 6

Store-side companion plugin. Lives at `integrations/shopware/TackQuote/`.
**Not** published on the Shopware Store — install via a Composer path
repository, or by copying this directory into `custom/plugins/TackQuote`.

## What's real vs. what's a gap

**Real / working today:**

- Standard Shopware 6 plugin skeleton (`composer.json` with
  `type: shopware-platform-plugin`, `extra.shopware-plugin-class`, PSR-4
  autoload), main plugin class `TackQuote\TackQuote\TackQuote` extending
  Shopware's `Plugin` base class, DI wiring via `services.xml`.
- Administration → Extensions → TackQuote → Configure screen
  (`src/Resources/config/config.xml`) with three fields: **API base URL**,
  **tenant slug**, and **API key** — mirroring the WordPress/WooCommerce
  plugin's settings pattern (`integrations/wordpress/tack-quotes/includes/class-tack-settings.php`,
  which stores `tack_quotes_api_url` / `tack_quotes_api_key`).
- A storefront **"Request a Quote"** button on the product detail page
  (`src/Resources/views/storefront/page/product-detail/index.html.twig`,
  overriding block `page_product_detail_buy`). Clicking it opens a small
  modal (name/email/company/quantity/message) and submits via `fetch()` to
  a plugin-owned, CSRF-protected storefront route:
  `POST /tackquote/quote-request` → `QuoteRequestController`
  (`src/Storefront/Controller/QuoteRequestController.php`).
- That controller calls `TackQuoteApiClient::submitQuoteRequest()`
  (`src/Service/TackQuoteApiClient.php`), which POSTs to TackQuote's
  **existing, public** endpoint:

  ```
  POST {apiUrl}/widget/quote-request
  Body: { tenantSlug, firstName, lastName, email, company, phone, message, items[] }
  ```

  This is the exact endpoint defined in
  `apps/api/src/modules/quotes/widget.controller.ts` (`@Public()`, no auth,
  rate-limited, resolves the tenant by `tenantSlug`). It creates a real
  draft **Buyer + Quote + line items** in TackQuote and (best-effort) emails
  the tenant's notification address — the same code path the generic
  `tack-widget.js` snippet uses on any storefront. So the button is genuinely
  functional against a running TackQuote API today, no new backend work
  required.

**Gap — needs a new Nest endpoint to fully match the WooCommerce plugin's
integration depth:**

The WooCommerce plugin (`integrations/wordpress/tack-quotes/`) authenticates
with an **API key** against a dedicated, WooCommerce-specific controller —
`apps/api/src/modules/integrations/woocommerce/woocommerce-plugin.controller.ts`
(`ApiKeyGuard`-protected `POST /integrations/woocommerce/quote-requests` and
`POST /integrations/woocommerce/order-sync`). That flow additionally:

- upserts referenced catalog products into TackQuote before creating the quote,
- tags the quote `['woocommerce', 'plugin-request']` and routes it through
  `QuotesService.create` with a resolved/created `Buyer` row (not the
  lighter widget-controller buyer upsert),
- has a matching outbound `order-sync` endpoint so store order status can
  flow back into TackQuote's `b2b_orders` table.

**No equivalent `integrations/shopware/*-plugin.controller.ts` exists in the
API today.** This plugin's `apiKey` config field is captured and stored in
Shopware's system config for that future work, but nothing sends it
anywhere yet — the storefront button intentionally uses the public
tenant-slug endpoint instead of inventing a fake API-key call. To close this
gap, add a `ShopwarePluginController` (mirroring the WooCommerce one) with:

- `GET  /integrations/shopware/ping` — API-key connectivity check
- `POST /integrations/shopware/quote-requests` — richer quote creation (catalog upsert + tagging)
- `POST /integrations/shopware/order-sync` — inbound order sync from the storefront

...then update `TackQuoteApiClient` to call those with the `apiKey` (via
`X-Api-Key` header) instead of/in addition to the public widget endpoint.

## Configure

Shopware Admin → **Extensions → My extensions → TackQuote → Configure**:

| Setting | Purpose |
|---|---|
| API base URL | Default `https://api.tackquote.com/v1`. Use `http://localhost:3001/v1` for local dev. |
| Tenant slug | Your TackQuote workspace slug (e.g. `demo`). **Required** for the quote button to work. |
| API key | From TackQuote → Settings → Developer → API Keys. Stored for future use; not sent by this version of the plugin (see gap above). |

## Install (Composer path repository — development)

From your Shopware project root:

```bash
composer config repositories.tackquote-shopware path /path/to/tack/integrations/shopware/TackQuote
composer require tackquote/shopware-tack-quote:@dev
bin/console plugin:refresh
bin/console plugin:install --activate TackQuote
bin/console cache:clear
```

Or copy this plugin directory to `custom/plugins/TackQuote` and run the same
`plugin:refresh` / `plugin:install --activate TackQuote` steps.

Then rebuild storefront assets if your Shopware install requires it for
template overrides to appear:

```bash
bin/console theme:compile
```

## Connect catalog / order sync in TackQuote (unrelated to this plugin)

Product and order sync for Shopware runs from **TackQuote's side** against
the Shopware Admin API (OAuth client-credentials), configured in the seller
portal — **not** through this plugin:

1. Shopware Admin → Settings → System → Integrations → create an integration, copy Client ID/Secret.
2. TackQuote seller portal → Settings → Integrations → Shopware 6 → paste Store URL, Client ID, Client Secret.
3. Sync products/orders from that page.

This plugin only adds the storefront quote button and (for now, unused)
config storage for a future tighter integration.

## Limitations

- No Shopware Store listing.
- Storefront JS is a plain inline `<script>` block, not a webpack-built
  Shopware storefront plugin (`src/Resources/app/storefront`) — simplest
  path to a working button without requiring the theme asset pipeline. A
  follow-up could migrate it to a proper `PluginBaseClass`-based JS module.
- `apiKey` config field is not yet used by any request (see gap above).
- Do not claim Marketplace-ready.

## Related

- Seller UI: `apps/web/src/app/(dashboard)/settings/integrations/shopware/page.tsx`
- API connector (Admin API, seller-side sync): `apps/api/src/modules/integrations/shopware/shopware.service.ts`
- Public widget endpoint this plugin calls: `apps/api/src/modules/quotes/widget.controller.ts`
- Pattern mirrored (WooCommerce plugin): `integrations/wordpress/tack-quotes/`
