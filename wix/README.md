# TackQuote for Wix

Wix doesn't support installable third-party admin plugins the way Shopify or
WooCommerce do — a fully-fledged "Wix App" (OAuth install, App Market
listing, a hosted dashboard panel built with Wix Blocks) is a much larger
undertaking that requires a Wix Developer account, an approved app listing,
and infrastructure this repo doesn't run. Instead, TackQuote connects to Wix
the way Wix's own docs recommend for site-to-site integrations: a scoped
**API key** calling the [Wix REST APIs](https://dev.wix.com/docs/rest) (Stores
Catalog + Orders) directly, with the "Request a Quote" button added to the
storefront as a **Custom Code / embedded HTML element** — no app install
required.

## 1. Connect TackQuote to your Wix site

1. In your Wix dashboard, go to **Settings → API Keys** and create a key
   scoped to **Stores (read)** and **Orders (read/write)**.
2. Copy your **Site ID** (also under Settings) and the API key.
3. In TackQuote: **Settings → Integrations → Wix** → paste both → **Connect**.
4. Use **Sync Now** to pull your catalog, or wait for the scheduled sync.

Backend implementation: `apps/api/src/modules/integrations/wix/wix.service.ts`
(GraphQL/REST calls against Wix Stores Catalog v1/v3) and
`apps/api/src/modules/integrations/platform/adapters/wix-platform.adapter.ts`.

## 2. Add the "Request a Quote" button to your storefront

Wix product pages can't run arbitrary third-party JS the way a WooCommerce
theme file can, but the Wix Editor supports embedding custom HTML directly.

**Option A — Wix Editor (no code):**

1. Open the Wix Editor on the product page template.
2. **Add → Embed → Custom Code** (or **Embed a Widget → Custom Element**
   depending on your Editor version).
3. Paste the snippet from TackQuote's **Settings → Integrations → Wix** page
   (shown there once connected) — it loads `tackquote.com`'s hosted widget
   script scoped to your Site ID.
4. Publish the site.

**Option B — Velo (site code), if you want the button wired to page state**
(e.g. showing the current product's SKU/price without a page reload):

```js
// Velo site code — Product Page code panel
import wixWindow from 'wix-window';

$w.onReady(function () {
  const productId = $w('#productPage').getProduct().then((product) => {
    $w('#tackQuoteButton').onClick(() => {
      wixWindow.openLightbox('TackQuoteRequest', {
        productId: product._id,
        sku: product.sku,
        name: product.name,
        price: product.price,
      });
    });
  });
});
```

Pair this with a Wix Lightbox (`TackQuoteRequest`) containing an embedded
HTML element that loads the same widget script — the Lightbox receives the
product context via `wixWindow.lightbox.getContext()` instead of relying on
page DOM scraping.

## Limitations vs. a real Wix App

- No native "Apps" tab install — merchants connect via API key, not OAuth.
- No Wix dashboard panel inside the Wix Business Manager — sync settings
  live in the TackQuote dashboard (Settings → Integrations → Wix) instead.
- No webhook subscriptions from Wix — sync is pull-based (manual "Sync Now"
  or the scheduled sync job), same limitation as PrestaShop/nopCommerce/OpenCart.

If TackQuote later builds a certified Wix App (OAuth + Wix Blocks dashboard +
App Market listing), it would replace this API-key connector as the primary
path — see `docs/integrations/` for the equivalent Shopify/BigCommerce
embedded-app pattern this would follow.
