# TackQuote for Squarespace

Squarespace doesn't support installable third-party admin plugins or apps —
there is no App Store/Marketplace model like Shopify or BigCommerce, and no
theme file access like WooCommerce/Magento/PrestaShop. Extensibility is
limited to two supported mechanisms: the official **Commerce APIs** (REST) and
**Code Injection** (paste custom HTML/JS site-wide or per page). TackQuote uses
both, matching what Squarespace's own docs recommend for this kind of
integration — there is no first-party "app" to build here.

## 1. Connect TackQuote to your Squarespace site

Requires a Squarespace plan that can generate Commerce API keys: **Core, Plus,
Advanced, or legacy Commerce Advanced**. Squarespace's help centre lists the
Orders, Inventory and Transactions API keys as available on "the Core, Plus,
Advanced, and Commerce Advanced plan" — **Commerce Basic cannot generate them**.
(This file previously said "Commerce Advanced" only, which was too narrow, and
the product catalog said "Commerce Basic or Advanced", which was too broad.)

1. In Squarespace: **Settings → Advanced → Developer API Keys → Generate Key**.
   Check the **Products**, **Orders** and **Inventory** permissions. The key is
   shown once and cannot be retrieved afterwards.
2. In TackQuote: **Settings → Integrations → Squarespace** → paste the key →
   **Connect**.
3. Use **Sync Now** to pull your catalog, or wait for the scheduled sync.

> ⚠️ **A Squarespace OAuth app will not work here.** An OAuth app gives you a
> client id, a client secret and an app id, and none of those is a bearer
> credential — the Commerce APIs reject them with a 401 that reads exactly like
> a mistyped API key. Squarespace only issues an access token through the
> authorization-code flow, which requires the app to be published as a
> Squarespace Extension, and those tokens live 30 minutes (refresh tokens, 7
> days). TackQuote does not implement that flow for Squarespace. Use the
> per-site Developer API key.

Backend implementation:
`apps/api/src/modules/integrations/squarespace/squarespace.service.ts` and
`apps/api/src/modules/integrations/platform/adapters/squarespace-platform.adapter.ts`.
Squarespace versions each Commerce API independently
(`https://api.squarespace.com/{api-version}/{resource-path}`), so the service
holds a **per-API version map**, not one shared prefix: Products `1.0`
(deliberately not the current `v2`, which does not embed variants), Orders
`1.0`, Inventory `1.0`, Websites `1.0`. See
`docs/integrations/SQUARESPACE.md` in the main repo for the full contract,
the vendor citations, and the live probe results behind each claim.

## 2. Add the "Request a Quote" button to your storefront

> ⚠️ **Not live yet.** The hosted widget script
> (`https://cdn.tackquote.com/widget/v1/squarespace.js`) is **not published** —
> the URL currently answers HTTP 404, so pasting the snippet injects a script
> that loads nothing and no button appears. The steps below are the shape of
> the install; wait for the widget release before editing a live storefront.

**Site-wide (all product pages):**

1. Squarespace dashboard → **Settings → Advanced → Code Injection**.
2. Paste the embed snippet from TackQuote's **Settings → Integrations →
   Squarespace** page into the **Footer** box.
3. Save. The widget script detects product pages automatically and renders
   the button near the Add to Cart control.

**Single page only:**

1. Edit the page → add a **Code Block** where you want the button to appear.
2. Paste the same snippet directly into that block instead of Code Injection.

## Limitations vs. a native admin plugin

- No "Apps" install flow — merchants connect via a per-site API key. OAuth
  exists but is reserved for published Squarespace Extensions (see the warning
  above).
- No dashboard panel inside Squarespace itself — sync settings live in the
  TackQuote dashboard (Settings → Integrations → Squarespace) instead.
- No webhook subscriptions. Squarespace's Webhook Subscriptions API is
  **OAuth-only** and cannot be used with an API key at all, so sync is
  pull-based (manual "Sync Now" or the scheduled sync job) — the same
  limitation as Wix/PrestaShop/nopCommerce/OpenCart.
- No hosted checkout handoff. Squarespace's Create Order endpoint "creates an
  order using information from a third-party sales channel" and returns no
  payment page, so buyers pay in the TackQuote portal and the order is recorded
  in Squarespace afterwards.
- Catalog sync covers **physical products only**. Service, gift-card and
  download product types are served by the v2 Products API, which this sync
  does not use yet.
- Create Order is rate-limited to **100 requests per hour per website** when an
  API key is used for authentication.
- Code Injection requires a paid plan; check your tier before relying on the
  storefront button.

This is the correct end-state for Squarespace, not a stopgap — Squarespace
does not offer a richer extensibility model for third parties to build
against today.
