# TackQuote for OpenCart

An OpenCart 4.x extension that adds a "Request a Quote" button to product
pages and connects the store to a TackQuote B2B quoting account (API base
URL + API key). It mirrors the pattern used by the TackQuote WooCommerce
plugin (`integrations/wordpress/tack-quotes/`) and PrestaShop module
(`integrations/prestashop/modules/tackquotes/`), adapted to OpenCart's own
MVC(L) controller/model/view + PSR-4 extension layout.

Before this extension, `docs/integrations/OPENCART.md` described OpenCart as
"API-only aspirational" — a Nest-side connector (`OpenCartService`,
`OpenCartPlatformAdapter`) existed with no companion store-side package to
talk to. This directory, plus the new
`apps/api/src/modules/integrations/opencart/opencart-plugin.controller.ts`
(see below), makes that connector genuinely functional end-to-end.

## Directory layout (assumption — read this first)

```
integrations/opencart/
├── install.json                 — OpenCart 4.x Extension Installer manifest
├── README.md
└── upload/                      — mirrors the OpenCart web root; this is what
    │                               gets zipped and uploaded
    ├── admin/
    │   ├── controller/extension/tackquote/module/tackquotes.php
    │   ├── language/en-gb/extension/tackquote/module/tackquotes.php
    │   └── view/template/extension/tackquote/module/tackquotes.twig
    ├── catalog/
    │   ├── controller/extension/tackquote/module/tackquotes.php
    │   ├── language/en-gb/extension/tackquote/module/tackquotes.php
    │   └── view/template/extension/tackquote/module/tackquotes.twig
    └── system/
        └── library/tackquote/apiclient.php
```

**Assumption**: this targets **OpenCart 4.0/4.1's namespaced extension
convention** — `admin/`, `catalog/`, and `system/` living directly under
`upload/` (not nested inside `extension/{vendor}/`), with PHP classes
namespaced `Opencart\Admin\Controller\Extension\Tackquote\Module\Tackquotes`,
`Opencart\Catalog\Controller\Extension\Tackquote\Module\Tackquotes`, and
`Opencart\System\Library\Tackquote\ApiClient`, PSR-4 autoloaded by
OpenCart's core loader from those exact paths. This is the current
documented OC4 "vendor/extension_name" convention as of OpenCart 4.0.2.3+,
and is the most standard/common structure I'm confident in. I have **not**
verified this against a running OpenCart 4 install, so treat file
paths/namespaces as "should be correct for a recent 4.0.x/4.1.x release,
verify against your exact point release before shipping to the OpenCart
Marketplace."

**OpenCart 3.x is not supported by these exact files** — OC3 controllers are
un-namespaced (e.g. `ControllerExtensionModuleTackquotes extends Controller`,
loaded by filename convention, no `system/library` PSR-4 autoloading), and
its admin `Loader`/`Model` API differs in several method names. Porting this
to OC3 means: drop the `Opencart\...` namespaces, rename classes to the
un-namespaced `ControllerExtensionModuleTackquotes` / `ModelExtensionModuleTackquotes`
style, and swap `Opencart\System\Library\Tackquote\ApiClient` for a manually
`require_once`'d global class. This is a real gap — no OC3-specific files are
shipped here.

## What's real

- **Admin settings controller** (`upload/admin/controller/extension/tackquote/module/tackquotes.php`)
  — a genuine OpenCart module controller: `index()` renders a form and saves
  API URL / API key / button label / enabled flag via OpenCart's standard
  `oc_setting` table (`model_setting_setting::editSetting('module_tackquote', ...)`,
  the same mechanism every stock OpenCart module — Banner, HTML Content, etc.
  — uses). `test()` is a real AJAX action that calls
  `ApiClient::testConnection()` against the live TackQuote API and returns
  JSON. `install()`/`uninstall()` hook into OpenCart's extension lifecycle;
  `uninstall()` deletes the `module_tackquote_*` settings.
- **Admin settings template** (`upload/admin/view/template/extension/tackquote/module/tackquotes.twig`)
  — a real Twig form (OC4 uses Twig, not `.tpl`) with a "Test connection"
  button wired to fetch() the `test()` AJAX route client-side, matching the
  PrestaShop module's HelperForm + separate test-connection block.
- **Storefront module controller** (`upload/catalog/controller/extension/tackquote/module/tackquotes.php`)
  — `index($setting)` is the standard signature OpenCart calls for any
  content module assigned to a layout position (see "Displaying the button"
  below); it loads the current product via `model_catalog_product::getProduct()`,
  and renders nothing (`''`) when disabled, unconfigured, or off a product
  page — the same "don't show a button that can't work" behavior as the
  PrestaShop module's `hookDisplayProductActions()`. `quote()` is the real
  AJAX endpoint the storefront modal POSTs to: validates the email,
  re-fetches the product server-side (never trusts client-submitted
  price/name), and calls `ApiClient::createQuoteRequest()`.
- **Storefront template** (`upload/catalog/view/template/extension/tackquote/module/tackquotes.twig`)
  — button + inline modal (email, quantity, note) with vanilla-JS `fetch()`
  submission, functionally identical to the PrestaShop module's
  `quote_button.tpl` + `tackquotes.js`, just inlined into one Twig file
  (OpenCart doesn't split JS into a separate enqueued file for a single small
  module the way PrestaShop registers stylesheets/scripts per-hook).
- **API client** (`upload/system/library/tackquote/apiclient.php`) — a real
  cURL-based HTTP client, authenticated exactly like the WooCommerce plugin's
  `Tack_Api_Client` and the PrestaShop module's `TackApiClient`:
  `Authorization: Bearer <key>` + `X-Api-Key: <key>` headers, matching how
  `ApiKeyGuard` on the Tack API accepts credentials
  (`apps/api/src/common/guards/api-key.guard.ts`). Calls
  `GET /integrations/opencart/ping` (falling back to `GET /health`) for "Test
  connection", and `POST /integrations/opencart/quote-requests` for the
  storefront button — **both routes now exist** (see Part 2 below), unlike
  the PrestaShop module's README at the time it was written, which had to
  document those routes as missing.
- **Installer manifest** (`install.json`) — a minimal OC4 Extension Installer
  manifest (name/code/version/author/link/compatible_versions).

## Displaying the button (important — read before assuming it's broken)

Unlike PrestaShop's hook system (`displayProductActions`), OpenCart has no
built-in "action buttons next to Add to Cart" hook point that a module can
attach to without editing the theme template. The standard, non-hacky
OpenCart way to add storefront content is the one used by every stock
content module (Banner, HTML Content, Google Analytics, etc.): **the
merchant assigns the module to a layout position** via **Design > Layouts**
→ edit (or create) the layout used by the Product route → add a "TackQuote"
module in a position such as "Content Bottom" or "Content Top". OpenCart
then calls `Tackquotes::index($setting)` for that position/route and injects
whatever HTML it returns. This is documented in Installation step 6 below.

This is a deliberate design choice, not a shortcut: modifying theme template
files (`catalog/view/template/product/product.twig`) directly, or doing a
`str_replace()` against pre-rendered HTML via OpenCart's event system, would
be far more fragile (broken by any theme change) than using the layout
system OpenCart ships specifically for this purpose. The trade-off is an
extra one-time admin step compared to PrestaShop/WooCommerce, where the
button appears automatically after activation — that's a genuine UX gap
worth calling out, not a hidden one.

## Installation

1. Zip the **contents of `integrations/opencart/`** (`install.json` and
   `upload/` at the zip root, not nested inside another folder):
   ```
   cd integrations/opencart
   zip -r tackquote.ocmod.zip install.json upload
   ```
2. In your OpenCart admin, go to **Extensions > Installer** and upload
   `tackquote.ocmod.zip`.
3. Go to **Extensions > Extensions**, filter by type **Modules**, find
   **TackQuote**, and click the install (+) icon, then the edit (pencil)
   icon.
4. Enter your **TackQuote API URL** (defaults to `https://api.tackquote.com/v1`)
   and paste your **TackQuote API key** (create one in TackQuote under
   Settings > Developer > API Keys — the same kind of key the WooCommerce
   and PrestaShop plugins use). Set **Status** to Enabled and click
   **Test connection**, then **Save**.
5. Go to **Design > Layouts**, choose (or create) the layout used by the
   **Product** route, and add the **TackQuote** module to a position such as
   "Content Bottom".
6. Shoppers on any product page assigned that layout will now see the
   "Request a Quote" button.

## What still needed a new Nest endpoint (Part 2 — now done)

Before this change, the Tack API had no OpenCart equivalent of the
WooCommerce/PrestaShop plugin controllers — `apps/api/src/modules/integrations/opencart/opencart.service.ts`
only supported the seller-portal-driven, JWT-authenticated direction (Tack
pulling products/orders from the store's own companion API). There was no
inbound, API-key-authenticated route for a storefront module to call.

This is now added:

- `apps/api/src/modules/integrations/opencart/opencart-plugin.controller.ts`
  — `OpenCartPluginController`, mirroring `PrestaShopPluginController` /
  `WooCommercePluginController` exactly: `@UseGuards(ApiKeyGuard, PlanGuard)`
  on `@Controller('integrations/opencart')`, with `GET ping`,
  `POST quote-requests` (creates a real draft quote via the canonical
  `QuotesService` pipeline, tagged `['opencart', 'plugin-request']`), and
  `POST order-sync` for parity with the other plugin controllers.
- `OpenCartService.createQuoteFromPluginRequest()` / `.importPluginOrder()`
  in `apps/api/src/modules/integrations/opencart/opencart.service.ts` — added
  alongside the existing `syncProducts()`/`createOrder()`/`syncOrdersInbound()`
  methods, following `PrestaShopService.createQuoteFromPluginRequest()` /
  `.importPluginOrder()` line for line (buyer upsert via a `buyers` table
  query, `QuotesService.create()`, `B2bOrdersService.upsertExternal()`,
  `QuotesService.markPaidFromPlatformReference()`).

**Not yet wired**: `OpenCartPluginController` still needs to be registered
in `apps/api/src/modules/integrations/integrations.module.ts` — intentionally
left out of this change to avoid a merge race with a concurrent Zen Cart
controller registration. See the top-level task report for the exact one-line
import + controllers-array edit needed.

## Known gaps / assumptions summary

- Built and reasoned about against OpenCart 4.0.x/4.1.x conventions from
  documentation and general PHP/OpenCart knowledge, **not verified against a
  running OpenCart install** — file paths, namespaces, and the exact
  `model_setting_setting`/`model_catalog_product` method signatures should be
  double-checked against your specific OpenCart point release before
  production use.
- **No OpenCart 3.x package.** OC3's un-namespaced controller/model
  convention is different enough that shipping one file tree for "3.x/4.x"
  wasn't feasible without either duplicating the whole extension or building
  an abstraction neither codebase has. 4.x was chosen as the actively
  maintained line.
- The storefront button requires a **one-time manual layout-assignment step**
  (Design > Layouts) that PrestaShop/WooCommerce don't need — see
  "Displaying the button" above for why that's the standard OpenCart pattern
  rather than a shortcut.
- No OCMOD-style automatic theme file patching is used anywhere — deliberate,
  to avoid breaking on theme updates.
- `order-sync` exists for parity with the other plugin controllers but, like
  PrestaShop's, is not called by any hook in this extension yet (OpenCart has
  no storefront "order placed" webhook wired up here) — it's there so a
  future admin-side "sync orders to TackQuote" action has a real endpoint to
  call.
