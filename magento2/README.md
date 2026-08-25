# TackQuote for Magento 2

A Magento 2 Composer module (`tackquote/module-quotes`, module name `TackQuote_Quotes`)
that adds B2B quoting to the storefront: a **"Request a Quote"** button on product pages, a
multi-product **quote list** with its own drawer, and an admin dashboard with a connection
test. Submissions create real quote requests — and, where the seller's policy allows it,
real buyer companies — in TackQuote. It is the Magento counterpart to the TackQuote
WooCommerce plugin (`integrations/wordpress/tackquote/`).

Magento's product catalog sync, inventory pull, quote-to-checkout and order import are
handled entirely outside this module, by
`apps/api/src/modules/integrations/magento/{magento.controller,magento.service}.ts` against
the Magento Admin REST API, configured under Settings → Integrations → Magento in the
seller portal. This module does not touch any of that. It exists to add the storefront
entry points, which a REST-API-only connector cannot do because nothing calls out from the
storefront to TackQuote without a module installed on the Magento side.

Order sync **from** Magento to TackQuote also needs no module component:
`CommercePullCronService` (`apps/api/src/modules/integrations/commerce-pull-cron.service.ts`)
pulls Magento orders on a schedule via the Admin REST API. It explicitly bypasses its
"skip platforms that have webhooks" rule for Magento (`row.type !== 'magento'`) because
Magento has no outbound payment webhooks, so Tack always pulls for this platform — unlike
WooCommerce, which needs the plugin to push.

## What this module does

### Storefront — single-product requests

- A "Request a Quote" button renders under the add-to-cart area on the product view page
  (`view/frontend/layout/catalog_product_view.xml`, `Block/RequestQuote.php`,
  `view/frontend/templates/button.phtml`).
- Clicking it opens a multi-step modal (built on `Magento_Ui/js/modal/modal`, so it
  inherits the active theme) seeded with just that product.
- Products with required options — configurable, bundle, grouped, or anything with required
  custom options (`Model/ProductOptionRequirement.php`) — must have their options chosen
  first. Quoting a bare parent SKU would name something the seller cannot fulfil.

### Storefront — multi-product quote list

- An "Add to Quote" control appears on the product page and, optionally, inside each
  product tile on category and search listings (`Block/ListingButton.php`, attached to
  Magento's own `category.product.addto` container — the extension point Magento_Wishlist
  uses — so no core file is edited and no catalog template is overridden).
- The list lives in the browser under the localStorage key **`tack_quote_list`**, never in
  the Magento cart. Quoting must not touch stock, cart totals or checkout, and Magento's
  own cart is internally called a "quote" (Magento_Quote), so building on it would collide
  with that name.
- A floating widget with an item count opens a drawer where rows can have their quantity
  changed or be removed (`Block/QuoteList.php`, `view/frontend/templates/quote-list.phtml`,
  `view/frontend/web/js/quote-app.js`). The drawer's submit button opens the same form,
  seeded with the whole list.
- On a listing tile there is no option UI, so products requiring a selection link through
  to their product page instead of being added.
- The list is capped at 50 items in the browser and again at 50 server-side, matching the
  API's own `MAX_ITEMS`.

**Prices are always re-resolved server-side.** The browser stores and sends only a SKU, a
display name and a quantity — never a price. `Controller/Quote/Submit.php` looks every SKU
up through `ProductRepositoryInterface` and prices it via `Model/ProductQuoteResolver.php`,
which reads the real customer-visible price through the price-info pipeline (`final_price`,
falling back to `getFinalPrice()` then `getPrice()`), so special prices, catalog price rules
and tier prices are honoured. localStorage is fully editable by the shopper, so a posted
price would let anyone quote themselves any amount. For a configurable, the chosen variant
is resolved to the child SKU actually being quoted, and the selection is folded into the
quote note as human-readable text ("Size: M, Color: Blue").

### Storefront — company registration

- The form renders the **seller's own registration policy**, fetched from TackQuote rather
  than hardcoded: whether companies and/or individuals are accepted (`mode`,
  `allowCompany`), which built-in company fields the seller marked required
  (`requiredCompanyFields`), and the seller's custom questions (`customFields`, including
  their type, required flag, select options and help text). Cached by
  `Model/RegistrationConfigProvider.php`; the response is visitor-independent, so it is
  safe to render into full-page-cached HTML.
- **The policy is never fetched while a shopper waits.** `Block/QuoteList.php` renders on
  every storefront page and reads cache only (`getCached()`), so a cold cache costs nothing;
  `Cron/WarmRegistrationConfig.php` does the fetching every 5 minutes
  (`etc/crontab.xml`). **Magento cron must be running** or the policy is never warmed and
  the form permanently degrades to contact fields only — see Known limitations.
- Submitting registers a real buyer and, where the policy allows, a real company plus
  membership — TackQuote runs the identical sequence as its own buyer-portal registration
  route (`PluginRegistrationService.register` → `BuyersService::registerWithCompany`).
- **Approval is honoured.** When the seller requires company approval, the API returns
  `awaitingApproval: true` and the storefront says "Request received — your account needs
  approval" instead of a plain success, so the buyer is not left waiting for access nobody
  granted. The quote is still created either way.
- A signed-in shopper's first/last name are prefilled client-side from Magento's
  `customer-data` (private-content) section rather than server-rendered, because these
  blocks are cacheable. Server-side, `Submit.php` overrides the posted email with the
  authenticated customer's own address, so a quote is always attributed to the signed-in
  identity.

### Storefront — the submit endpoint

- Everything posts to this module's own controller, `POST /tackquote/quote/submit`
  (`Controller/Quote/Submit.php`), so the TackQuote API key stays server-side and never
  reaches the browser — the same principle as the WooCommerce plugin's `admin-ajax.php`
  handler.
- It is CSRF-validated against the session form key. The key is **not** server-rendered:
  pages are full-page-cached, so a baked-in key would be shared by every visitor and fail
  for all of them. The template emits an empty `input[name="form_key"]`, which
  Magento_PageCache's `form-key-provider.js` fills from the `form_key` cookie; the JS also
  reads that cookie directly as a fallback.
- From the internet's point of view the endpoint is unauthenticated (the API key
  authenticates the store, not the visitor), so it is also IP-rate-limited
  (`Model/SubmissionThrottle.php`) and duplicate-suppressed
  (`Model/IdempotencyGuard.php`) so a double-click or a browser retry collapses into one
  quote.
- Only an allow-list of company fields is forwarded, so a crafted form cannot smuggle
  arbitrary keys into the company record.

### Admin

- A top-level **TackQuote** entry in the admin sidebar (`etc/adminhtml/menu.xml`) with
  **Dashboard** and **Settings** items.
- The **Dashboard** (`Controller/Adminhtml/Dashboard/Index.php`,
  `Block/Adminhtml/Dashboard.php`, `view/adminhtml/templates/dashboard.phtml`) shows a
  live/not-live status banner, a **setup checklist** naming exactly what is missing
  (TackQuote enabled → API key set → quote button turned on, each with the fix), the
  configured API base URL, whether a key is set, the current button label, and a **Test
  connection** button.
- **Test connection** exists in two places, both calling
  `Model\Api\Client::testConnection()` through the admin controller
  `Controller/Adminhtml/Connection/Test.php` (`POST tackquote/connection/test`):
  the dashboard button, and an inline button in **Stores → Configuration → TackQuote**
  (`Block/Adminhtml/System/Config/TestConnection.php`,
  `view/adminhtml/templates/system/config/test-connection.phtml`). The configuration screen
  is where an admin actually pastes a key, so testing without leaving the page is what stops
  stores sitting misconfigured.
  The controller distinguishes *disabled*, *no key set* and *configured but rejected*,
  because those need completely different fixes.
- Two separate ACL resources on purpose (`etc/acl.xml`): `TackQuote_Quotes::dashboard`
  (under a top-level `TackQuote_Quotes::tackquote`) backs the menu and connection test, so
  day-to-day TackQuote access can be granted **without** store-configuration rights;
  `TackQuote_Quotes::config` sits under `Magento_Config::config` because that is where
  Magento resolves a `system.xml` section's `<resource>`.

## Configuration

**Stores → Configuration → TackQuote → TackQuote Settings** (`etc/adminhtml/system.xml`,
read through `Model/Config.php`). The section is scoped to default/website — not per store
view.

| Group | Field | Notes |
| --- | --- | --- |
| Connection | **Enable TackQuote** | Master switch. |
| Connection | **TackQuote API Base URL** | Default `https://api.tackquote.com/v1`. Include `/v1`, no trailing slash. |
| Connection | **TackQuote API Key** | Stored encrypted (`Magento\Config\Model\Config\Backend\Encrypted`) and decrypted on read. Needs the `quotes:write` scope. |
| Connection | **Verify** | Inline "Test connection" button. |
| Request a Quote button | **Show quote button on product pages** | Single-product request button. |
| Request a Quote button | **Button label** | |
| Request a Quote button | **Show "Add to Quote" button** | Turns on the multi-product quote list. |
| Request a Quote button | **"Add to Quote" label** | |
| Request a Quote button | **Show on category and search results** | Adds "Add to Quote" to listing tiles. |
| Request a Quote button | **Quote list submit label** | Label on the drawer's submit button. |

Every storefront control is additionally gated on the module being enabled *and* an API key
being present — offering a control that cannot submit would only produce a dead end.

## The TackQuote endpoints this module calls

`Model/Api/Client.php` calls TackQuote's dedicated Magento plugin routes
(`apps/api/src/modules/integrations/magento/magento-plugin.controller.ts`,
`MagentoPluginController`), authenticated by the store's TackQuote API key rather than a
seller JWT:

| Route | Scope | Used for |
| --- | --- | --- |
| `GET /v1/integrations/magento/ping` | none | Connection test. Deliberately unscoped so a *wrong key* is distinguishable from a *wrong base URL*. |
| `GET /v1/integrations/magento/registration-config` | none | The seller's registration policy, to render the form. Exposes form shape only. |
| `POST /v1/integrations/magento/quote-requests` | `quotes:write` | Register the buyer/company and create the quote. |

That controller is separate from `MagentoController`, which is JWT-guarded and drives the
opposite direction (Tack → the store: connect, sync, checkout). Quotes are tagged with the
`source` this module sends (`magento`) plus `plugin-request`, and the store's currency is
forwarded rather than assumed.

## Quote-only mode (B2B catalog)

**Stores > Configuration > TackQuote > TackQuote Settings > Quote-Only Store (B2B Catalog).**
Turns the whole storefront into a B2B catalog: Add to Cart and checkout are refused and
shoppers request a quote instead. Off by default.

### It is refused by the server, not hidden by CSS

| where | what |
|---|---|
| `Plugin/Quote/QuoteOnlyCartGuard.php` → `beforeAddProduct()` | refuses `Magento\Quote\Model\Quote::addProduct()` |
| `Plugin/Checkout/QuoteOnlyExistingCartGuard.php` | refuses quantity increases, reconfigure and reorder on a pre-existing cart |
| `Plugin/Checkout/QuoteOnlyCheckoutGuard.php` → `aroundExecute()` | refuses `Magento\Checkout\Controller\Index\Index`, redirecting to the cart with a reason |

`curl -d 'product=42&qty=1' https://store/checkout/cart/add/` gets the same refusal as the
browser: a `before` plugin that throws aborts the intercepted call, so core's cart code never
runs.

**Why `Magento\Quote\Model\Quote::addProduct()` and not a controller plugin.** It is
`@api` (`vendor/magento/module-quote/Model/Quote.php:34`), which is Magento's stability
guarantee, and it is the single model every add path funnels through — the product page,
related products, wishlist "Add to Cart", `checkout/cart/addgroup`, and
`Magento\Checkout\Model\Cart::addProduct()`, which is itself only a caller
(`vendor/magento/module-checkout/Model/Cart.php:392` is literally
`$result = $this->getQuote()->addProduct($product, $request);`). A plugin on
`Magento\Checkout\Controller\Cart\Add` would guard one route and miss the rest;
`Magento\Checkout\Model\Cart` carries `@deprecated 100.1.0 Use \Magento\Quote\Model\Quote
instead` in its own docblock. `LocalizedException` is thrown rather than a generic one
because `Magento\Checkout\Controller\Cart\Add::execute()` catches that type at
`Add.php:170` and renders the message to the shopper instead of producing a 500.

### Admin and integrations stay exempt — structurally

Everything is declared in **`etc/frontend/di.xml`**, so Magento generates the interceptors
for the frontend area only. Admin order creation (Sales > Orders > Create New Order) drives
the very same `Quote::addProduct()`, and so does TackQuote turning an accepted quote into a
real order; both keep working because adminhtml never loads that file. Area-scoped `di.xml`
is Magento's own mechanism for this — core scopes
`Magento\Customer\Model\App\Action\ContextPlugin` the same way in
`vendor/magento/module-customer/etc/frontend/di.xml:23-26`. There is deliberately no runtime
area check: a check can be edited out, a file adminhtml never loads cannot be.
`Test/Unit/Layout/QuoteOnlyLayoutTest.php` fails if any guard moves into a global `etc/di.xml`.

### Who it applies to

**Everyone** (default), **Guests only** (signed-in customers keep a normal cart and
checkout — the usual B2B setup), or **Selected customer groups**. Magento group 0 is
`NOT LOGGED IN`, a real row in the customer-group grid, so it can be selected on its own and
is equivalent to "Guests only". (The OpenCart build of this feature deliberately *drops*
group 0, because OpenCart uses it as a "no group" sentinel; copying that rule here would
silently delete a merchant's selection.)

Full-page cache is safe without a custom vary dimension: the decision reads only store config
plus signed-in state and customer group, and Magento already puts both into the HTTP context
that produces the vary hash (`Magento\Customer\Model\App\Action\ContextPlugin` lines 50
and 55). **Adding any other input to `Model/QuoteOnlyMode.php` — a cookie, a time of day, the
customer id — would serve one visitor's storefront to another** and needs its own
`HttpContext::setValue()` first.

### The store is never left unable to transact

The same feature nearly shipped a dead storefront twice on other platforms: in WooCommerce
the quote button hung off a hook that only fires *inside* the add-to-cart form, and in
PrestaShop the theme wraps the CTA hook in `{if !$configuration.is_catalog}`. Magento's
equivalent coupling was checked against 2.4.8 source **before** anything was removed:

- The CTA block `tackquote.request.quote` is a child of the container `product.info.main`.
  The blocks removed are children of *different* parents — `product.info.addtocart` of
  `product.info.form.content`
  (`vendor/magento/module-catalog/view/frontend/layout/catalog_product_view.xml:72`) and
  `product.info.addtocart.additional` of `product.info.options.wrapper.bottom` (line 86) —
  so `remove="true"` cannot reach it.
- `Test/Unit/Layout/QuoteOnlyLayoutTest.php` pins that parentage, so a later "put it next to
  Add to Cart" edit that recreates the coupling fails the suite instead of shipping.
- **Both** add-to-cart blocks are removed. They render the same template; removing only the
  first leaves a working-looking button on every product with custom options.
- Quote-only mode **overrides** the `show_button` and `show_on_listing` preferences
  (`Block/RequestQuote.php::isEnabled()`, `Block/ListingButton.php::isEnabled()`). Honouring
  them would leave a product page with no cart button *and* no quote button.
- Enforcement and the CTA are switched on by **one condition**: both require
  `Config::isConfigured()`. With no API key saved, no quote button renders — and the cart is
  therefore **not** blocked either.
- The cart page gets its own notice with a working CTA (`Block/QuoteOnlyNotice.php`), which
  is where a shopper refused at checkout lands.

An ordering bug was fixed along the way: this module's `after="product.info.addtocart"` was
inert, because Magento's `after` only orders *siblings* and returns without moving anything
when the parents differ (`Structure.php:122-130`). It is now `after="product.info"`, a real
sibling, so the quote controls finally render where the file always claimed — directly under
the add-to-cart form rather than below the social links.

Category and search tiles keep their cart button in the DOM and hidden by CSS
(`view/frontend/web/css/request-quote.css`), because that button is hard-coded in
`Magento_Catalog::product/list.phtml:108` rather than rendered as a removable block, and
overriding that template would clobber every theme. **That hiding is cosmetic only** — the
server refuses the POST regardless.

### Existing carts and the checkout route

Turning the mode on **does not empty anybody's cart**. Magento persists carts server-side in
`quote`/`quote_item` for signed-in customers, so "silently deleted" could mean weeks later on
another device; it would also make the switch destructive and effectively one-way. The cart
page stays reachable, and **lowering a quantity, setting it to 0, and removing a line all
stay allowed** — refusing the whole of `updateItems()` would trap a shopper with a basket
they can see, cannot use, and cannot clear.

What a cart cannot do is **grow or convert**: adds are refused, quantity *increases* are
refused (without that, a one-line cart from before the switch could be edited to 10 000 and
checked out), and the checkout route is refused. A pre-existing cart is **inert rather than
destroyed**, and works normally again the moment the mode is switched off.

### Verification

51 unit tests were added (85 → 136, 255 assertions), run against a real Magento 2.4.8-p5
bootstrap. The guard was mutation-tested with **12 deliberate breaks: 12 caught, 0 survived,
0 rejected as non-compiling.**

| break | caught by |
|---|---|
| the add-to-cart refusal never fires | *a crafted add-to-cart is REFUSED when the mode applies* |
| scope "guests" inverted | *guests-only refuses the public and spares signed-in customers* |
| dead-storefront guard removed (enforce with no API key) | *with no API key the mode stays INACTIVE* |
| CTA override dropped | *the CTA renders EVEN with show_button turned off* |
| only one of the two add-to-cart blocks removed | *both core Add to Cart blocks are removed* |
| CTA moved into a container holding a removed block | *the CTA does not live inside anything being removed* |
| the refusal declared globally | *the refusal is declared for the FRONTEND AREA ONLY* |
| existing-cart guard refuses every update | *lowering the quantity / removing a line is ALLOWED* |
| product-page removals applied on every page | *an ordinary page gets only the global handle* |
| group 0 dropped (the OpenCart rule) | *group 0 is a real group in Magento and can be selected* |
| checkout guard falls through | *checkout is refused and the shopper is sent back to their cart* |
| cart CTA reverts to the `$root`-scoped JS hook | *the cart notice uses its own JS hook* |

```
# from a Magento install with the module in app/code/TackQuote/Quotes
php vendor/bin/phpunit -c app/code/TackQuote/Quotes/Test/Unit/phpunit.xml
```

## Known limitations

- **No cart-page quote button in normal mode.** Quoting runs off the module's own
  browser-side list, not Magento's cart, so there is no entry point on the cart page unless
  quote-only mode is on (which adds one — see above).
- **Duplicate suppression is belt-and-braces.** `Model/Api/Client.php` sends an
  `Idempotency-Key` header AND `Model/IdempotencyGuard.php` collapses double-submits
  store-side. The two catch different things: the guard stops a double-click before it
  leaves the store, the header catches retries the guard cannot see (a second Magento node,
  or a request after a cache flush). Earlier revisions of this list claimed the header was
  commented out because of an upstream RLS defect; that was fixed upstream and the header
  has been sent since — see the comment in `Model/Api/Client.php`.
- **Quote-only mode does not cover the Web API or GraphQL areas.** `UNVERIFIED against a
  running store.` The guards are declared in `etc/frontend/di.xml`, so `webapi_rest`,
  `webapi_soap` and `graphql` are not intercepted. Two consequences: adding items to a guest
  cart via `POST /rest/V1/guest-carts/{cartId}/items` — an anonymous endpoint — would still
  succeed, and Magento's own Luma checkout places its order over `webapi_rest`, so a crafted
  REST call against a cart that predates the switch could still place an order. Those areas
  are left open deliberately: the same endpoints carry the merchant's own integrations,
  including TackQuote placing quote-accepted orders, and telling an admin/integration token
  from an anonymous storefront caller needs `UserContextInterface`, which is only bound in
  the webapi areas — so one plugin cannot serve both, and guessing wrong would break quote
  conversion. A merchant who needs that surface closed should disable the anonymous
  guest-cart endpoints in `webapi.xml`, which is a store-wide decision. The invariant that
  holds unconditionally is the one the feature is sold on: **nothing new can enter a cart
  from the storefront.**
- **Quote-only mode and multishipping.** `Magento_Multishipping` (`multishipping/checkout`,
  off by default) has its own checkout controller and is not guarded. UNVERIFIED.
- **The registration policy can be up to 5 minutes stale**, bounded by the cron schedule in
  `etc/crontab.xml` rather than by the 1-hour cache TTL. A policy change in TackQuote takes
  that long to reach the storefront. **If Magento cron is not running the policy is never
  warmed at all** and the form shows contact fields only, forever — the same degraded state
  as an outage, but permanent, so check `bin/magento cron:run` on a new install.
- **The form degrades rather than breaks with no policy** — a TackQuote outage, or a cache
  flush that cron has not yet caught up with, drops the company step and the seller's custom
  questions and leaves contact fields only. That still produces a usable quote request, and
  TackQuote re-checks its own policy server-side on submit, so a required company detail is
  refused there rather than silently accepted.
- **The product-page triggers sit directly after the add-to-cart form**, ordered
  `after="product.info"` within `product.info.main`. They are deliberately NOT inside
  `product.info.form.content`, which is what keeps quote-only mode able to remove
  `product.info.addtocart` without taking the quote CTA with it. See the comment in
  `view/frontend/layout/catalog_product_view.xml`.
- **Marketplace-grade CSP needs Magento to catch up first.** Every script and style this
  module emits goes through `SecureHtmlRenderer`, so it is nonce/hash eligible and works
  with `Magento_Csp` in restrict mode — verified with `'unsafe-inline'` removed from
  `script-src`. On 2.4.8-p5, however, removing `'unsafe-inline'` blocks 13 of Magento's OWN
  inline blocks on a Luma product page, including the RequireJS base-URL config, which takes
  the whole storefront's JavaScript down. That is a core limitation, not this module's, but
  it means a merchant cannot yet run the storefront with inline scripts fully disabled.
- No Magento Marketplace listing or Packagist publication (see Installation).

## Requirements

- Magento Open Source / Adobe Commerce **2.4.5 – 2.4.8** (`composer.json` pins the module
  package series Magento itself uses: `magento/framework: 103.0.*`,
  `magento/module-catalog: 104.0.*`, and so on).
- PHP **8.1 – 8.4** — the union of what those Magento releases support (2.4.5 still allows
  8.1; 2.4.8 allows 8.4).
- Magento **cron** must be running (`etc/crontab.xml` warms the registration policy).

## Licence

GPL-2.0-or-later. The full text ships in the package as `LICENSE.txt`, matching the SPDX
identifier in `composer.json` — the identifier alone is not enough for Adobe Commerce
Marketplace technical review, and every Magento core module ships its licence text the same
way.

## Development tooling

`composer.json` declares the tools in `require-dev` so a reviewer can reproduce the checks
without guessing versions, and `autoload-dev` is what makes `Test/` loadable:

```bash
composer install                     # inside magento2/ (this directory)
vendor/bin/phpcs                     # picks up ./phpcs.xml -> the Magento2 standard
vendor/bin/phpunit -c Test/Unit/phpunit.xml
```

`Test/` is deliberately absent from the PRODUCTION `autoload` block. That block used to map
the module root (`"TackQuote\\Quotes\\": ""`), which made
`TackQuote\Quotes\Test\Unit\Controller\Quote\SubmitTest` — and therefore PHPUnit, an
undeclared dependency — resolvable on a live store. The production map now names only the
six namespaces that ship code (`Block\`, `Controller\`, `Cron\`, `Model\`, `Observer\`,
`Plugin\`), and `Test\` lives in `autoload-dev`. **Adding a new top-level source directory
means adding it to `autoload.psr-4`**; that is the cost of not mapping the root.

That cost is not theoretical. `Plugin\` — which is the whole of quote-only enforcement — was
missing from the map at one point, and nothing catches it locally: an `app/code` install
resolves the classes anyway through the PSR-0 `""` -> `app/code/` fallback in Magento's own
root `composer.json`, so the suite and a dev store both stay green while a Marketplace
(`vendor/`) install silently loses all three guards. Check a change to this map by resolving
against the generated `vendor/composer/autoload_psr4.php`, not by running the tests.

`Test/Unit/phpunit.xml` bootstraps from Magento's own `dev/tests/unit/framework/bootstrap.php`,
so the suite has to be run from inside a Magento installation, not from this directory alone.

## Installation

Distribution authority: the public GitHub release asset is
[`tack-magento2.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.2.0/tack-magento2.zip).
This monorepo directory is build/source only. No Magento Marketplace or Packagist listing
is claimed.

The zip's top-level directory is `TackQuote/Quotes/`, so unzip it **into `app/code`**
(not into `app/code/TackQuote/Quotes`) — that produces
`app/code/TackQuote/Quotes/registration.php`:

```bash
unzip tack-magento2.zip -d <magento-root>/app/code
```

Then, from the Magento root:

```bash
bin/magento module:enable TackQuote_Quotes
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The path-repository and copy-from-checkout options below are for maintainers working
from this monorepo, not for merchant distribution.

This module is **not published to Packagist or the Magento Marketplace**.

### Option A — path repository (maintainers / local staging)

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
   seller portal). The key needs the `quotes:write` scope — that is the only scope this
   module's endpoints enforce, so do not grant it more.
5. Click **Test connection** on that same screen (or on TackQuote → Dashboard) to confirm
   the key and base URL.
6. Under **Request a Quote button**, enable **Show quote button on product pages** and/or
   **Show "Add to Quote" button**, and optionally change the labels.
7. Save config, then visit a product page on the storefront to confirm the controls appear
   and submit successfully. TackQuote → Dashboard shows a checklist if anything is missing.

> **Connecting the other direction (TackQuote → this store) on Magento 2.4.4+.**
> The steps above cover store → TackQuote, which uses a TackQuote API key and is
> unaffected. If you also connect *this store* to TackQuote so it can read the catalog
> and create orders, TackQuote authenticates with the integration access token as a
> **Bearer** token — and Adobe disabled that by default in 2.4.4, so it will be refused
> with a 401 until you opt in:
>
> ```bash
> bin/magento config:set oauth/consumer/enable_integration_as_bearer 1
> bin/magento cache:flush
> ```
>
> Or set **Stores → Configuration → Services → OAuth → Consumer Settings → Allow OAuth
> Access Tokens to be used as standalone Bearer tokens** to **Yes**. Adobe turned this
> off because integration tokens never expire; enable it knowingly. Of the four values
> on Magento's integration screen, TackQuote uses only the **Access Token**.
> <https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-token>

## File map

```
integrations/magento2/
├── registration.php                            Module registration
├── composer.json                               Package metadata (tackquote/module-quotes)
├── LICENSE.txt                                 GPL-2.0 text (required in the package)
├── phpcs.xml                                   Magento2 coding standard for a bare `phpcs`
├── i18n/en_US.csv                              Translation source strings
│                                               (regenerate with i18n:collect-phrases)
├── etc/module.xml                              Module declaration + sequence
├── etc/acl.xml                                 Admin ACL: dashboard tree + config resource
├── etc/crontab.xml                             Warms the registration policy every 5 min
├── etc/adminhtml/events.xml                    Flush the policy when settings are saved
├── etc/adminhtml/menu.xml                      TackQuote sidebar menu (Dashboard, Settings)
├── etc/adminhtml/routes.xml                    Admin route tackquote/*
├── etc/adminhtml/system.xml                    Stores > Configuration > TackQuote fields
├── etc/frontend/routes.xml                     Storefront route tackquote/*
├── etc/frontend/di.xml                         QUOTE-ONLY GUARDS — frontend area only,
│                                               which is what keeps the admin exempt
├── etc/frontend/events.xml                     layout_load_before -> quote-only handles
├── Model/Config.php                            Reads scoped config (enable/url/key/labels)
├── Model/Api/Client.php                        HTTP client for /v1/integrations/magento/*
├── Model/RegistrationConfigProvider.php        Fetches + caches the seller's policy
├── Model/ProductQuoteResolver.php              Server-side SKU -> priced line item
├── Model/ProductOptionRequirement.php          "Does this product need a selection first?"
├── Model/SubmissionThrottle.php                Per-IP rate limit on the public endpoint
├── Model/IdempotencyGuard.php                  Collapses double-submits into one quote
├── Model/QuoteOnlyRules.php                    Quote-only scoping rule, pure functions
├── Model/QuoteOnlyMode.php                     "Is this visitor quote-only right now?"
├── Model/Config/Source/QuoteOnlyScope.php      "Applies to" dropdown options
├── Plugin/Quote/QuoteOnlyCartGuard.php         THE REFUSAL: Quote::addProduct()
├── Plugin/Checkout/QuoteOnlyExistingCartGuard.php  Pre-existing carts: no growth
├── Plugin/Checkout/QuoteOnlyCheckoutGuard.php  Refuses the checkout route
├── Observer/QuoteOnlyLayoutHandle.php          Adds the quote-only layout handles
├── Block/QuoteOnlyNotice.php                   Cart-page "this store works by quote" panel
├── Block/RequestQuote.php                      Product-page trigger view model
├── Block/ListingButton.php                     Category/search tile "Add to Quote"
├── Block/QuoteList.php                         Quote-list widget + shared form view model
├── Block/Adminhtml/Dashboard.php               Dashboard status + setup checklist
├── Block/Adminhtml/System/Config/TestConnection.php   Inline config-screen test button
├── Controller/Quote/Submit.php                 Storefront quote-request POST handler
├── Controller/Adminhtml/Dashboard/Index.php    Admin dashboard page
├── Controller/Adminhtml/Connection/Test.php    Admin "Test connection" AJAX endpoint
├── Cron/WarmRegistrationConfig.php             Keeps the policy out of the render path
├── Observer/FlushRegistrationConfig.php        Invalidates the policy on a settings save
├── view/frontend/
│   ├── layout/catalog_product_view.xml         Product-page triggers
│   ├── layout/catalog_category_view.xml        Listing-tile "Add to Quote"
│   ├── layout/default.xml                      Site-wide quote list + shared form + CSS
│   ├── layout/tackquote_quote_only.xml         Quote-only: body class (global)
│   ├── layout/tackquote_quote_only_product.xml Quote-only: removes BOTH addtocart blocks
│   ├── layout/tackquote_quote_only_cart.xml    Quote-only: cart-page notice
│   ├── templates/quote-only-notice.phtml       Cart-page notice + CTA
│   ├── templates/button.phtml                  Product-page triggers
│   ├── templates/listing-button.phtml          Listing-tile trigger
│   ├── templates/quote-list.phtml              Drawer + multi-step form markup
│   ├── web/js/quote-app.js                     RequireJS component: list, drawer, form
│   └── web/css/request-quote.css               Storefront styles
└── view/adminhtml/
    ├── layout/tackquote_dashboard_index.xml    Dashboard layout
    ├── layout/adminhtml_system_config_edit.xml Loads the config-screen control stylesheet
    ├── templates/dashboard.phtml               Dashboard markup + test-connection JS
    ├── templates/system/config/test-connection.phtml   Config-screen button markup
    ├── web/css/dashboard.css                   Admin dashboard styles
    └── web/css/config-test-connection.css      "Verify" result pill (was an inline <style>)
```
