# TackQuote B2B Quoting — Official Storefront Extensions & Addons

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Downloadable Addons](https://img.shields.io/badge/Downloadable%20addons-8-emerald)](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest)

Official downloadable plugins, modules and code-snippet addons for embedding
**TackQuote B2B Wholesale Quoting & CPQ** into popular eCommerce platforms —
plus the source for the Shopify theme app extension, which ships as part of
the TackQuote Shopify app rather than as one of these release assets.

📖 **[Browse the platforms, install steps and verification evidence →](https://ackm04.github.io/tack-ecommerce-extensions/)**

## Policy: this repository is the home for plugins, extensions and releases

Decided by the project owner: **`tack-ecommerce-extensions` is where storefront
addons live and where they are released.** The private `tack` monorepo is the
TackQuote application (API, seller portal, buyer portal) and should not carry
plugin releases of its own.

Every extension here is edited **here**. There is no second copy to keep in
step: WordPress.org publishes the WooCommerce plugin from this repository, and
every release artifact is built from it by `scripts/package-all.sh`.

The extensions used to live in the TackQuote application monorepo as well, and
the two copies diverged silently for weeks — the published WooCommerce plugin
ran a 270-line order sync while the monorepo had a 626-line one, and the
published Shopware plugin was missing the method that gave its API-key field a
consumer, so merchants pasted a credential the plugin never read. Nothing
detected it: no build broke, no test failed, the wrong artifact simply shipped.

The monorepo copies have therefore been removed (`ackm04/tack` issue #340). If
you are about to add an extension copy to another repository, don't — that is
the exact failure this arrangement exists to prevent.

Do not direct merchants to download files from a TackQuote application
checkout; point them at the releases here.

---

## Supported eCommerce Platforms & Connection Mechanics

**Eight of these are downloadable public addon source trees, released from this
repository.** Shopify and BigCommerce are native/service integrations — code
that runs as part of the TackQuote application or a Shopify app, not a
merchant-installed package built and released from here — and are marked
accordingly.

| # | Platform | Distribution | Artifact type | How It Connects & Operates | Download |
|---|---|---|---|---|---|
| 1 | **Shopify** | Source lives here; **not a release asset**. Deployed by the TackQuote team as part of the TackQuote Shopify app (`shopify app deploy`) — a merchant installs the app, not a file from this page. | Theme App Extension (source only) | Six storefront app blocks (Add to Quote, Request a Quote, Wholesale Price, Quantity Breaks, Buyer Group Badge, Wholesale Application), added by the merchant in the theme editor once the TackQuote app is installed. Requires an Online Store 2.0 theme. Verified so far only by a 24-check local schema/asset validator (`node --test shopify/validate-theme-extension.mjs`) — **not yet installed on any real store**; nothing has rendered in a theme editor or received a live App Proxy request. | Not applicable — see `shopify/README.md` |
| 2 | **WooCommerce** | Release asset, installer | WordPress plugin (`tackquote`, v1.7.1) | Posts to the TackQuote API — never the WooCommerce REST API. Hooks `woocommerce_after_add_to_cart_button` for the buttons and `wp_footer` for the quote drawer; order sync (opt-in, off by default) is queued through Action Scheduler so a slow TackQuote can't add latency to checkout. `wp plugin check` has been run clean against this exact version, and the WooCommerce-specific features (B2B pricing, order limits, payment/shipping restriction by buyer group) were rendered and exercised in a local WordPress 7.1 + WooCommerce 11.1.0 devstore — **not a live merchant store.** | **[`tackquote.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tackquote.zip)** — built by `wordpress/tackquote/bin/build.sh` (excludes `bin/`, `tests/`, `*.md`); install this one. `tack-opencart-source.zip` and similar `-source` assets elsewhere in this table are source-only, not installers. |
| 3 | **Magento 2** | Release asset, manual install | Native module (`TackQuote_Quotes`, setup_version 1.4.0) | Connects via Magento's own Admin REST API with a Bearer token. Registers layout XML (`catalog_product_view.xml`) and posts quote requests to `POST /v1/integrations/magento/quote-requests` — there is no shared cross-platform endpoint. | **[`tack-magento2.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-magento2.zip)** — unzip into `app/code/` (the archive already carries the `TackQuote/Quotes` nesting). |
| 4 | **BigCommerce** | **Not in this repository.** Native/service integration hosted by the TackQuote application itself — there is no source tree, plugin or release asset here for it. | Storefront Script API (service) | Connects via BigCommerce's Script Manager API & REST v3 from TackQuote's backend. Configured entirely from the TackQuote seller portal. | Not applicable — configure at `/settings/integrations/bigcommerce` in the TackQuote app |
| 5 | **OpenCart** | Release asset, installer + separate source archive | OpenCart 4.x extension (`.ocmod.zip`, install.json v1.3.1) | Two halves. Storefront: quote button posts to Tack. **Connector**: this extension itself serves `extension/tack/api/product.list`, `order.list` **and `order.add`** — OpenCart core has no such endpoints, so the extension is mandatory, and `order.add` is what lets TackQuote place a quote-accepted order directly in this store (see "Order handling" below). Bearer-authenticated, fails closed. | **[`tack.ocmod.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack.ocmod.zip)** — install via Extensions › Installer; **do not rename it** (OpenCart derives the extension code from the filename; a rename installs cleanly then 404s every route). [`tack-opencart-source.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-opencart-source.zip) is source-only, for review/local builds — not installable. |
| 6 | **PrestaShop** | Release asset, installer | PrestaShop 1.7 / 8.x module (`tackquotes`, v1.3.1) | Connects via PrestaShop's FrontController hooks — specifically **`displayProductActions`** (not `displayProductButtons`). This module only pushes quote requests out to Tack; it has no order-sync hook of its own. Product/order sync for this platform, where it exists, runs from TackQuote's side against PrestaShop's own core Webservice API, independent of this module. | **[`tack-prestashop.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-prestashop.zip)** |
| 7 | **Shopware** | Two separate release assets — self-hosted plugin and Cloud app | Self-hosted plugin `TackQuote` (composer v0.3.0) **and** a separate Cloud-compatible app manifest `TackQuoteApp` (v1.0.0) | The self-hosted plugin extends Storefront Twig templates and pushes order status to Tack via `POST /integrations/shopware/order-sync` (like WooCommerce, one direction only). The Cloud app is manifest + webhooks only, read-only permissions, no storefront UI of its own yet — see `shopware/TackQuoteApp/README.md` "Not implemented yet". | **[`tack-shopware.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-shopware.zip)** (self-hosted plugin) / **[`tack-shopware-app.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-shopware-app.zip)** (Cloud app manifest; requires `SHOPWARE_APP_SECRET` injected at package time — see `shopware/TackQuoteApp/bin/build-zip.sh`, not used by the CI-built release asset) |
| 8 | **Zen Cart** | Release asset, manual file-copy + SQL patch | File overlay + `tack-connector/` API (no plugin manifest or version file — Zen Cart has no package-installer format this ships against) | Two halves. Storefront: injects a quote button and handles requests via `ajax_tack_quote_request.php`. **Connector**: `tack-connector/` serves `GET /products`, `GET /orders`, `GET /orders/{id}` **and `POST /orders`** — Zen Cart core has no JSON API, so this is mandatory, and `POST /orders` is what lets TackQuote place a quote-accepted order directly (see "Order handling" below). Bearer-authenticated, fails closed. | **[`tack-zencart.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-zencart.zip)** — copy the **contents** of `store-root/` only, then run the SQL patch. |
| 9 | **Squarespace** | Release asset, code snippet | Code Injection embed (no plugin version — this is a README plus a snippet, not an installable package) | Merchant pastes a Code Injection snippet; no webhook subscriptions (Squarespace's Webhook Subscriptions API is OAuth-only and this integration is API-key based), so sync is pull-based. Order creation, where used, goes through Squarespace's own Commerce "Create Order" endpoint from TackQuote's side. | **[`tack-squarespace.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-squarespace.zip)** |
| 10 | **Wix** | Release asset, code snippet | Wix Velo code module (no plugin version, same reasoning as Squarespace) | Merchant adds a Velo backend module; sync is pull-based (manual "Sync Now" or scheduled), same limitation as PrestaShop/OpenCart. Order creation, where used, goes through Wix's own Stores/Orders API (read/write) from TackQuote's side. | **[`tack-wix.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/latest/download/tack-wix.zip)** |

nopCommerce has no TackQuote release asset. Its connector targets a separately obtained community
REST API plugin; TackQuote does not claim a nopCommerce Marketplace listing.

All download links above point at `releases/latest/download/<exact-asset-name>`,
never a version-pinned tag — this repository cuts one repo-wide `v*` tag per
release covering every platform, so a link pinned to one tag goes stale the
next time *any other* platform ships (this is exactly what happened to the
`v1.1.0`-pinned links this section used to carry). `scripts/check-release-claims.sh`
fails CI if a version-pinned link like `releases/download/vX.Y.Z/...` reappears
in any README in this repository.

---

## Order handling: it is connector-specific, not a universal webhook

There is no such thing as a generic `v1/webhooks` endpoint, and no single mechanism by which "Tack
triggers a webhook to create an order" across every platform in this table —
that claim was fabricated and this section used to assert it. What actually
happens, verified against each connector's own code and README:

- **Push-only (storefront → TackQuote), no path back:** WooCommerce, the
  self-hosted Shopware plugin, and (once wired) PrestaShop push order data to
  TackQuote; none of them expose a way for TackQuote to create an order in the
  store. WooCommerce's push is opt-in and off by default, and queued through
  Action Scheduler.
- **A real inbound order-creation route exists in this repo's code:**
  OpenCart's connector exposes `POST …/api/order.add`, and Zen Cart's connector
  exposes `POST /tack-connector/orders`. TackQuote's backend can call either to
  place a quote-accepted order directly — but the extension itself never
  initiates this; it only serves the request when TackQuote's backend makes
  one.
- **Handled entirely by the platform's own native API, no extension code
  involved:** Magento order placement uses Magento's own Admin REST API and
  webapi_rest, authenticated with an integration access token — nothing in
  this repository's Magento module implements it. Squarespace and Wix are
  similar: order creation, where used, goes through each platform's own
  Commerce/Stores API from TackQuote's side.
- **Not implemented at all yet:** the Shopware Cloud app (`TackQuoteApp`) is
  read-only by design and states this explicitly in its own README.

Even on a platform where the mechanism above exists, whether an accepted quote
actually propagates depends on TackQuote having an **active `sync_rule`** for
that tenant and entity type — connecting a store, or toggling a "Sync Orders"
switch in the TackQuote UI, does not create that rule by itself
(`ackm04/tack` issue #336). Don't promise a merchant automatic order creation
without confirming that rule is active for their tenant.

---

## Cutting a release

1. Every platform's version lives in its own manifest (`tackquote.php` /
   `readme.txt` Stable tag for WooCommerce, `module.xml` `setup_version` for
   Magento, `install.json` for OpenCart, the module's own `$this->version` for
   PrestaShop, `composer.json` for the Shopware plugin, `manifest.xml` for the
   Shopware app). Bump whichever changed.
2. Tag the commit `vX.Y.Z` and push the tag. This repo cuts **one tag for every
   platform**, even if only one platform changed — there is no per-platform
   tag scheme.
3. `.github/workflows/release.yml` (`Release plugin ZIP`) runs on the tag push:
   it PHP-lints every `*.php` file, runs `scripts/package-all.sh dist` to build
   all ten artifacts, and attaches every `dist/*.zip` to a new GitHub Release
   for that tag via `softprops/action-gh-release`. This is not hypothetical —
   the v1.7.1 tag triggered exactly this and produced the release now
   published; GitHub Actions is not billing-blocked on this (public) repo.
4. If a platform's artifact looks wrong after the run, the bug is almost always
   in `scripts/package-all.sh`'s staging step for that platform (top-level
   directory naming is load-bearing on nearly every one of these platforms —
   see the comments in that script) rather than in the workflow itself.
5. Nothing here publishes to WordPress.org, the Shopware Store, or any other
   marketplace automatically — those remain manual steps, recorded in the
   corresponding release notes when done (see the v1.5.1 WooCommerce release
   for an example).

`scripts/check-release-claims.sh` runs against every `README.md`/`readme.txt`
in the repository and fails if it finds a version-pinned release URL or either
of the fabricated endpoints this section used to describe (`v1/webhooks`,
`v1/widget/quotes`). It runs in CI on every push to `main` and every pull
request touching a README (`.github/workflows/docs-contract.yml`), separately
from the release workflow so it also catches a doc regression on a branch that
never tags a release. Run it locally (`bash scripts/check-release-claims.sh`)
before committing a README change.

---

## License

This repository and all included extensions are licensed under the [MIT License](LICENSE).
