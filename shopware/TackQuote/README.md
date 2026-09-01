# TackQuote for Shopware 6

Store-side companion plugin. Lives at `integrations/shopware/TackQuote/`.
Distribution authority: the public GitHub release asset is
[`tack-shopware.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.1.0/tack-shopware.zip).
This monorepo directory is build/source only. No Shopware Store listing is claimed.

> ⚠️ **UNVERIFIED:** the release-asset URL above has not been checked from this
> environment. It also predates the fixes recorded below, so if the asset does exist it
> ships the broken version. Rebuild the zip from this directory before pointing anyone at it.

## Tested version

Verified working end-to-end on **Shopware 6.6.10.22** (PHP-FPM + MySQL dev stack,
`shopware/core`, `shopware/administration` and `shopware/storefront` all at
`v6.6.10.22`; 81 demo products).

`composer.json` declares `shopware/core: ~6.5.0 || ~6.6.0 || ~6.7.0`. Only the 6.6 line
has actually been exercised — **6.5 and 6.7 compatibility is UNVERIFIED**, and the
storefront template this plugin extends is a version-sensitive path (see below), so treat
those constraints as aspirational until someone runs them.

**Composer will not let you install 6.6.10.0 – 6.6.10.6.** Shopware's own guard package
`shopware/conflicts` (0.5.1) requires `shopware/core: >=6.6.10.7 <6.7.0.0 || >=6.7.1.0`,
so the whole 6.6.10.0–6.6.10.6 range is unreachable — Shopware cut 6.6.10.7 as the
security-fixed floor of that line. Pin 6.6.10.7 or newer; this plugin was tested on
6.6.10.22.

## What's real vs. what's a gap

**Real / working today:**

- Standard Shopware 6 plugin skeleton (`composer.json` with
  `type: shopware-platform-plugin`, `extra.shopware-plugin-class`, PSR-4
  autoload), main plugin class `TackQuote\TackQuote\TackQuote` extending
  Shopware's `Plugin` base class, DI wiring via `services.xml`.
- **The plugin installs.** `plugin:refresh` and `plugin:install --activate TackQuote`
  both complete, and the plugin reports `Installed: Yes / Active: Yes`.
  > This was **broken until recently**: `src/Resources/config/config.xml` put `<config>`
  > in a default XML namespace with `xsi:schemaLocation`, but Shopware's schema
  > (`vendor/shopware/core/System/SystemConfig/Schema/config.xsd`) declares **no**
  > `targetNamespace`. libxml therefore reported "No matching global declaration
  > available for the validation root" and `plugin:install` aborted every single time —
  > the plugin could never be installed by anyone. The file now uses
  > `xsi:noNamespaceSchemaLocation` and no default `xmlns`; do not "tidy" that back.
- **A storefront route exists.** `src/Resources/config/routes.xml` imports
  `../../Storefront/Controller/*Controller.php` as `type="attribute"`.
  > Also **broken until recently**: this file did not exist at all. Shopware does not
  > scan plugin `Controller/` directories automatically, so no route was ever
  > registered — `path('frontend.tackquote.quote-request')` threw in the template and
  > the endpoint 404'd. Every plugin shipping a controller needs this file.
- Administration → Extensions → TackQuote → Configure screen
  (`src/Resources/config/config.xml`): **eight fields across three cards** —
  *TackQuote connection* (**API base URL**, **tenant slug**, **API key**),
  *Request a Quote button* (**enableButton**, **buttonLabel**) and
  *B2B quote-only mode* (**quoteOnlyMode**, **quoteOnlyScope**,
  **quoteOnlyCustomerGroupIds**).
- A storefront **"Request a Quote"** button on the product detail page, from
  `src/Resources/views/storefront/component/buy-widget/buy-widget.html.twig`, which
  `sw_extends` `@Storefront/storefront/component/buy-widget/buy-widget.html.twig` and
  overrides block **`buy_widget`**.
  > **The template path and block matter and were previously wrong.** The plugin used to
  > extend `page/product-detail/index.html.twig` / block `page_product_detail_buy`.
  > Shopware 6.6 product pages are **CMS-driven**: `cms-element-buy-box.html.twig`
  > `sw_include`s the *component* buy-widget, and the legacy product-detail buy-widget
  > template — while still present in core — is **not in the render path**. Extending it
  > rendered nothing on every product page. In this component the product variable is
  > **`product`**, not `page.product`.
- Clicking the button opens a small modal (name/email/company/quantity/message) and
  submits via `fetch()` to a plugin-owned storefront route:
  `POST /tackquote/quote-request` → `QuoteRequestController`
  (`src/Storefront/Controller/QuoteRequestController.php`).
  > **There is no CSRF token, and that is correct for 6.5+.** Shopware **removed CSRF
  > protection in 6.5** in favour of same-site cookies; `sw_csrf()` no longer exists and
  > calling it threw "Unknown function" as soon as the block rendered. Neither
  > `sw_csrf` nor `csrf_protected` appears anywhere in `shopware/core` or
  > `shopware/storefront` on 6.6.10.22. The `'csrf_protected' => true` entry still
  > present in the route's `defaults` is therefore **inert** — harmless, but do not read
  > it as protection that exists.
- That controller calls `TackQuoteApiClient::submitQuoteRequest()`
  (`src/Service/TackQuoteApiClient.php`), which POSTs to TackQuote's
  **existing, public** endpoint:

  ```
  POST {apiUrl}/widget/quote-request
  Body: { tenantSlug, firstName, lastName, email, company, phone, message, currency?, items[] }
  ```

  This is the endpoint defined in
  `apps/api/src/modules/quotes/widget.controller.ts` (`@Public()`, no auth,
  throttled to **5 requests / 60s**, resolves the tenant by `tenantSlug`). It creates a
  real draft **Buyer + Quote + line items** in TackQuote and (best-effort) emails the
  tenant's notification address — the same code path the generic `tack-widget.js`
  snippet uses on any storefront.
  > **This endpoint used to fail for every caller.** The widget controller mapped line
  > items to `description` and never set `name`, but `quote_line_items.name` is
  > `NOT NULL`, so every insert failed and `AllExceptionsFilter` surfaced a generic 400
  > "A required field is missing." That affected the generic `tack-widget.js` embeds too,
  > not just this plugin. Fixed; verified by a live submission creating `TK-2026-001035`.
- **The quote is denominated in the sales channel's own currency.** The controller reads
  `$context->getCurrency()->getIsoCode()` and `TackQuoteApiClient` sends it as
  `currency` when it is a plausible ISO 4217 alpha-3 code.
  > Previously the plugin sent no currency and the widget endpoint hardcoded
  > `currency: 'USD'`, so a EUR store produced USD quotes with no error anywhere. The
  > API-side field is optional: what the request sends wins, then the TackQuote tenant's
  > own configured currency (Settings → Company), then USD. Storefronts that send no
  > currency are therefore unaffected, and simply inherit whatever the seller configured.
  > Verified live on this store: an EUR sales channel produced quote `TK-2026-001047` in
  > EUR even though that tenant is configured USD, because the sales channel's currency
  > takes precedence.
- **The browser supplies only `productId`.** Name, SKU, unit price and product URL are
  all resolved server-side from that id via the **sales-channel-scoped** product
  repository (`sales_channel.product.repository`), so a product not published to the
  storefront cannot be quoted at all. Quantity is clamped to the product's own
  `minPurchase` / `maxPurchase` / `purchaseSteps`.
  > This endpoint is unauthenticated. It used to take the product's name, SKU, **unit
  > price** and URL straight from the POST body, because that is what the Twig template
  > put in `data-` attributes — so anyone could `curl` it and create a quote for any
  > product at any price, or point `productUrl` anywhere. Nothing downstream re-checked
  > the price. Covered by `tests/Storefront/Controller/QuoteRequestControllerLogicTest.php`.
- **Advanced (quantity-tiered) prices are honoured.** The unit price is taken from the
  tier covering the requested quantity, not the single-unit price.
  > On the demo store this was a real mispricing, not just a hardening detail: the
  > "Enormous Copper Car" has a 1–10 tier at €1233.27 and an 11+ tier at €900.29, while
  > its base price is €966.27. A 40-unit request was quoted at the base rate — above the
  > merchant's own bulk price. Core ships no helper for this lookup; the tier semantics
  > (`quantityEnd ?? quantityStart`, ascending) are read off
  > `ProductPriceCalculator::calculateAdvancePrices()` and pinned by the tests.
- **All storefront copy comes from snippets**, in `src/Resources/snippet/{en_GB,de_DE}/`
  (auto-discovered by `SnippetFileLoader` — no `SnippetFile` class needed). The inline JS
  receives its messages as already-translated `data-` attributes rather than containing
  English of its own.
- **B2B quote-only mode (catalog mode)** — turns the storefront into a B2B catalog with the
  cart disabled server-side. See the dedicated section below.

## B2B quote-only mode

Turns the whole storefront into a B2B catalog: no "Add to cart", customers request a
quote instead. Off by default.

### Where enforcement actually happens

`src/Core/Checkout/Cart/QuoteOnlyCartItemAddRoute.php:60` — a decorator around
`Shopware\Core\Checkout\Cart\SalesChannel\CartItemAddRoute` that throws
`QuoteOnlyModeException` (HTTP **403**, error code `TACKQUOTE__QUOTE_ONLY_MODE_ACTIVE`)
**before** delegating, so no line item is ever built, priced, persisted or announced by
`BeforeLineItemAddedEvent`.

Hiding the button in Twig is cosmetic and is treated as such here. A cached page, a
bookmarked form post, a `curl` against `/store-api/checkout/cart/line-item` or any
headless client bypasses the template entirely. Decorating the route covers all of them
at once, verified against Shopware **6.6.10.22** source:

| Entry point | Reaches the guard because |
|---|---|
| `POST /store-api/checkout/cart/line-item` | is this route (`core/Checkout/Cart/SalesChannel/CartItemAddRoute.php:47`) |
| `POST /checkout/line-item/add` | `CartLineItemController::addLineItems()` → `CartService::add()` (`storefront/Controller/CartLineItemController.php:314`) |
| `POST /checkout/product/add-by-number` | `CartLineItemController::addProductByNumber()` → `CartService::add()` (same file, line 245) |
| any plugin calling `CartService::add()` | `CartService` is wired to the decorated service id (`core/Checkout/DependencyInjection/cart.xml:70` → `:116`); it delegates at `core/Checkout/Cart/SalesChannel/CartService.php:90` |

A tree-wide grep for `AbstractCartItemAddRoute` across `shopware/core` + `shopware/storefront`
returns only the abstract, the concrete route and that one `CartService` constructor
argument — there is no fourth way to write a line item into a persisted cart.

### Existing carts and the checkout route

Blocking the add route does nothing about a basket that was already full when the merchant
flipped the switch. So `src/Core/Checkout/Cart/QuoteOnlyCartValidator.php` (tagged
`shopware.cart.validator`) puts a **blocking** `QuoteOnlyModeError` on any non-empty cart
while quote-only mode applies.

The checkout route is deliberately **not** decorated. Core already enforces blocking errors
at the point of no return: `OrderPersister::persist()` throws on
`$cart->getErrors()->blockOrder()` before writing the order row
(`core/Checkout/Cart/Order/OrderPersister.php:38`), every cart calculation runs the tagged
validators (`core/Checkout/Cart/Processor.php:62-63`), and `CartOrderRoute::order()`
recalculates immediately before persisting (`core/Checkout/Cart/SalesChannel/CartOrderRoute.php:75`
then `:87`). One validator therefore covers the storefront checkout, the Store API order
route and anything else that persists a cart — a decorated controller would have covered
only the first.

Item **update** and **remove** are intentionally left working, so a shopper can still empty
a stranded cart. Blocking them would prevent nothing, since that cart can no longer be
ordered anyway.

### Who it applies to

| `quoteOnlyScope` | Effect |
|---|---|
| `everyone` (default) | Nobody can add to cart. |
| `guests` | Anonymous visitors and guest-checkout customers must request a quote; a registered, logged-in B2B customer keeps a working cart. |
| `groups` | Only the customer groups selected in `quoteOnlyCustomerGroupIds`. |

An unrecognised scope value falls back to `everyone`, not to "off" — the master switch is
on, so a typo must not silently put the buy button back on every product page.

### Administrators are always exempt

`QuoteOnlyModeService::isExemptOperator()` — so the merchant can still test their own store
and support can still place an order for a customer who phoned in. Two signals, both read
off core rather than guessed:

- `SalesChannelContext::getImitatingUserId() !== null` — the Administration's "log in as
  customer". Core reads exactly this at
  `core/System/SalesChannel/Context/CartRestorer.php:166` and
  `core/Framework/Routing/SalesChannelRequestContextResolver.php:73`.
- context source is `AdminSalesChannelApiSource` / `AdminApiSource` / `SystemSource` — the
  admin order module builds carts under the first of these
  (`core/System/SalesChannel/Context/BaseContextFactory.php:248`). An ordinary shopper gets
  `SalesChannelApiSource` (same file, line 250), which is **not** exempt; since
  `AdminSalesChannelApiSource extends SalesChannelApiSource`, checking the parent would have
  exempted every visitor on the site, and a test pins that direction.

### The storefront never ends up with nothing to click

The failure mode this feature has to avoid is a store where the customer can neither buy
nor ask. Both templates are written and tested against it:

- **Product page** — `buy-widget.html.twig` suppresses core's `buy_widget_buy_form`
  (which owns the `<form>`, the quantity selector, the hidden `lineItems[…]` inputs and the
  buy button in one place). The TackQuote CTA is appended inside `buy_widget` after
  `{{ parent() }}`, i.e. a **sibling** of that form, not a child — so removing the form
  cannot remove the CTA. In quote-only mode the CTA renders even when the `enableButton`
  opt-out is off, because it is then the only remaining way to transact.
- **Listing / cross-selling cards** — `component/product/card/action.html.twig` **replaces**
  the buy form with a link to the product detail page rather than rendering nothing. Core's
  template is an `if/else`; an override that emitted nothing would leave the card with an
  empty action area, because the `else` branch (the "Details" link) never runs.

`tests/Resources/QuoteCtaRendersWithCartDisabledTest.php` renders both real templates
against the real core templates through Shopware's real `sw_extends` parser, with the flag
forced both ways, and asserts the buy form disappears **and** the quote CTA survives.

That test is the reason this note is not just a claim: mutating the CTA condition to
`and not tackquote_quote_only()` — i.e. reproducing the WooCommerce coupling exactly — kills
two of its cases. So does removing the guard in `QuoteOnlyCartItemAddRoute::add()`, flipping
`QuoteOnlyModeError::blockOrder()` to `false`, inverting the `guests` scope, or making
`isExemptOperator()` always true.

### Reverse-proxy cache

Core's HTTP cache hash covers rule ids, version, currency, tax state and a coarse
logged-in / not-logged-in flag — but **not** the customer group
(`core/Framework/Adapter/Cache/Http/CacheResponseSubscriber.php:238-246`). With
`quoteOnlyScope = groups` that would let two logged-in customers in different groups share
one cached page. `src/Framework/Adapter/Cache/QuoteOnlyCacheCookieSubscriber.php` adds the
group to the hash, but only for that scope, so the other scopes do not fragment the cache.
This is a display-correctness fix only; the server-side guard reads the live context and was
never affected.

> **UNVERIFIED:** which Shopware minor first shipped `HttpCacheCookieEvent`. It exists on
> 6.6.10.22. `TackQuote::build()` removes the subscriber when the class is absent, because a
> subscriber naming a missing class is a container-compile fatal — the shop would fail to
> boot rather than lose one cache dimension.

## Tests

```bash
# from the Shopware project root, with the plugin at custom/plugins/TackQuote
vendor/bin/phpunit -c custom/plugins/TackQuote/phpunit.xml.dist
```

65 tests / 116 assertions. No kernel, database or network — mocks plus
`MockHttpClient`, so the suite runs in ~0.03s. `failOnWarning`, `failOnNotice`,
`failOnRisky` and `failOnDeprecation` are all enabled. The API-client tests assert on
the **outgoing request** (method, URL, JSON body), not on a canned response — per
`CLAUDE.md`, tests that only check a mocked response are how 20+ connectors in this
repo came to encode the same wrong assumptions as their code.

**Gap — needs a new Nest endpoint to fully match the WooCommerce plugin's
integration depth:**

The WooCommerce plugin (`integrations/wordpress/tackquote/`) authenticates
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
API today** (verified: nothing matching `*shopware*plugin*` under `apps/api/src`).
This plugin's `apiKey` config field is captured and stored in Shopware's system config
for that future work, but nothing sends it anywhere yet — the storefront button
intentionally uses the public tenant-slug endpoint instead of inventing a fake API-key
call. To close this gap, add a `ShopwarePluginController` (mirroring the WooCommerce one)
with:

- `GET  /integrations/shopware/ping` — API-key connectivity check
- `POST /integrations/shopware/quote-requests` — richer quote creation (catalog upsert + tagging)
- `POST /integrations/shopware/order-sync` — inbound order sync from the storefront

...then update `TackQuoteApiClient` to call those with the `apiKey` (via
`X-Api-Key` header) instead of/in addition to the public widget endpoint.

## Configure

Shopware Admin → **Extensions → My extensions → TackQuote → Configure**:

| Setting | Purpose |
|---|---|
| API base URL | Default `https://api.tackquote.com/v1`. Use `http://localhost:3001/v1` for local dev (or the API's container hostname when Shopware runs in Docker). |
| Tenant slug | Your TackQuote workspace slug (e.g. `demo`). **Required** — with no slug the client throws and the button cannot submit. |
| API key | From TackQuote → Settings → Developer → API Keys. Sent as `X-API-Key` on the connection test (`GET /integrations/shopware/ping`), and NOT on quote submission — see the note below. |
| Show "Request a Quote" button | Toggles the storefront button. Default on. |
| Button label | Default `Request a Quote`. |
| Run this storefront as a quote-only B2B catalog | Master switch for quote-only mode. Default **off**. |
| Applies to | `everyone` / `guests` / `groups`. Default `everyone`. |
| Customer groups | Only read when *Applies to* is `groups`. Empty + `groups` means quote-only matches nobody. |

## Install (public release, or Composer path for maintainers)

Download [`tack-shopware.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.1.0/tack-shopware.zip)
and follow the included README, or copy the extracted plugin into
`custom/plugins/TackQuote`. **The directory name must be exactly `TackQuote`** —
Shopware resolves a plugin by matching the directory to the plugin class
(`TackQuote\TackQuote\TackQuote`).

The Composer path repository below is for maintainers working from this monorepo:

```bash
composer config repositories.tackquote-shopware path /path/to/tack/integrations/shopware/TackQuote
composer require tackquote/shopware-tack-quote:@dev
bin/console plugin:refresh
bin/console plugin:install --activate TackQuote
bin/console cache:clear
```

Or copy this plugin directory to `custom/plugins/TackQuote` and run the same
`plugin:refresh` / `plugin:install --activate TackQuote` steps.

`cache:clear` is what makes the Twig override and the new route visible —
**`theme:compile` is not required**, because this plugin ships no SCSS or JS assets
(`src/Resources/app/storefront` does not exist; the storefront JS is inline in the
template). Run it only if your own theme needs it for unrelated reasons.

## Connect catalog / order sync in TackQuote (unrelated to this plugin)

Product and order sync for Shopware runs from **TackQuote's side** against
the Shopware Admin API (OAuth client-credentials), configured in the seller
portal — **not** through this plugin:

1. Shopware Admin → Settings → System → Integrations → create an integration, copy Client ID/Secret.
2. TackQuote seller portal → Connections → Shopware 6 → paste Store URL, Client ID, Client Secret.
3. Sync products/orders from that page.

This plugin only adds the storefront quote button and (for now, unused)
config storage for a future tighter integration.

## Limitations

- No Shopware Store listing.
- Storefront JS is a plain inline `<script>` block, not a webpack-built
  Shopware storefront plugin (`src/Resources/app/storefront`) — simplest
  path to a working button without requiring the theme asset pipeline. A
  follow-up could migrate it to a proper `PluginBaseClass`-based JS module.
- `apiKey` is used by the connection test only. `TackQuoteApiClient::ping()` sends it as
  `X-API-Key` (`src/Service/TackQuoteApiClient.php:111`), but quote submission posts to
  `POST {apiUrl}/widget/quote-request` (`:198`) — the unauthenticated widget endpoint,
  which resolves the tenant from `tenantSlug` in the body rather than from the signed key.

  This is a real difference from the other five storefront plugins, not a documentation
  detail. They post to `POST /integrations/<platform>/quote-requests`, which is API-key
  authenticated and derives the tenant from the key. Shopware quotes therefore carry no
  plugin tagging, no catalog product upsert, and no API-key tenant binding. There is no
  `integrations/shopware/quote-requests` route on the API to point at yet — the Shopware
  plugin controller serves `ping` and nothing else.
- The public widget endpoint is throttled to **5 requests / 60s**, which is shared
  across all callers of that route — fine for a product page, but it will reject bursts.
- Only Shopware 6.6 has been tested (see *Tested version*).
- Quote-only mode has been verified against **6.6.10.22 source and by unit test only** —
  it has not been exercised against a running storefront in this environment. The route
  decoration, validator tag, config schema and Twig block names are all read off that
  version's source; 6.5 and 6.7 remain UNVERIFIED, as for the rest of the plugin.
- In quote-only mode the listing card links to the product detail page rather than opening
  a quote modal in place. The quote form needs a quantity clamped to the product's own
  `minPurchase`/`purchaseSteps` and a unit price from the advanced-price tier for that
  quantity, both resolved server-side per product; duplicating that across a 24-item
  listing buys nothing the detail page does not already do correctly.
- Do not claim Marketplace-ready.

## Related

- Seller UI: `apps/web/src/app/(dashboard)/connections/shopware/page.tsx`
- API connector (Admin API, seller-side sync): `apps/api/src/modules/integrations/shopware/shopware.service.ts`
- Public widget endpoint this plugin calls: `apps/api/src/modules/quotes/widget.controller.ts`
- Widget request/response contract: `apps/api/src/modules/quotes/dto/widget-quote.dto.ts`
- Pattern mirrored (WooCommerce plugin): `integrations/wordpress/tackquote/`
