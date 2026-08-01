# TackQuote for Squarespace

Squarespace doesn't support installable third-party admin plugins or apps —
there is no App Store/Marketplace model like Shopify or BigCommerce, and no
theme file access like WooCommerce/Magento/PrestaShop. Extensibility is
limited to two supported mechanisms: the official **Commerce APIs** (REST,
API-key auth) and **Code Injection** (paste custom HTML/JS site-wide or per
page). TackQuote uses both, matching what Squarespace's own docs recommend
for this kind of integration — there is no first-party "app" to build here.

## 1. Connect TackQuote to your Squarespace site

Requires a **Commerce Advanced** plan (API access is not available on lower
Commerce tiers).

1. In Squarespace: **Settings → Advanced → API Keys** → create a key.
2. In TackQuote: **Settings → Integrations → Squarespace** → paste the key →
   **Connect**.
3. Use **Sync Now** to pull your catalog, or wait for the scheduled sync.

Backend implementation:
`apps/api/src/modules/integrations/squarespace/squarespace.service.ts` (calls
`api.squarespace.com/1.0/commerce/*`) and
`apps/api/src/modules/integrations/platform/adapters/squarespace-platform.adapter.ts`.

## 2. Add the "Request a Quote" button to your storefront

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

- No "Apps" install flow — merchants connect via API key, not OAuth.
- No dashboard panel inside Squarespace itself — sync settings live in the
  TackQuote dashboard (Settings → Integrations → Squarespace) instead.
- No webhook subscriptions — sync is pull-based (manual "Sync Now" or the
  scheduled sync job), same limitation as Wix/PrestaShop/nopCommerce/OpenCart.
- Requires Commerce Advanced for both the API and Code Injection on
  storefront (not available on Personal/Business plans).

This is the correct end-state for Squarespace, not a stopgap — Squarespace
does not offer a richer extensibility model for third parties to build
against today.
