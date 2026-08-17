# TackQuote for Magento 2

A Magento 2 Composer module (`tackquote/module-quotes`, module name `TackQuote_Quotes`)
that adds B2B quoting to the storefront: a **"Request a Quote"** button on product pages, a
multi-product **quote list** with its own drawer, and an admin dashboard with a connection
test. Submissions create real quote requests — and, where the seller's policy allows it,
real buyer companies — in TackQuote. It is the Magento counterpart to the TackQuote
WooCommerce plugin (`integrations/wordpress/tack-quotes/`).

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
  their type, required flag, select options and help text). Fetched by
  `Model/RegistrationConfigProvider.php` and cached for 15 minutes; the response is
  visitor-independent, so it is safe to render into full-page-cached HTML.
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

## Known limitations

- **No `Idempotency-Key` header is sent.** TackQuote accepts one, but
  `apps/api/src/modules/idempotency/idempotency.service.ts` writes to
  `api_idempotency_keys` — a FORCE-RLS table — without establishing tenant RLS context, so
  every request carrying the header fails with "new row violates row-level security policy".
  Duplicate suppression is therefore done store-side in `Model/IdempotencyGuard.php`. The
  header line is commented out in `Model/Api/Client.php` and should be restored once the
  API is fixed.
- **No cart-page quote button.** Quoting runs off the module's own browser-side list, not
  Magento's cart, so there is no entry point on the cart page.
- **The registration policy is cached for 15 minutes** (60 seconds on failure). A policy
  change in TackQuote can take that long to appear on the storefront.
- **A TackQuote outage degrades rather than breaks the form** — the policy fetch returns
  null and the form falls back to contact fields only, which still produces a usable quote
  request.
- No Magento Marketplace listing or Packagist publication (see Installation).

## Requirements

- Magento Open Source / Adobe Commerce **2.4.5 – 2.4.8** (`composer.json` pins the module
  package series Magento itself uses: `magento/framework: 103.0.*`,
  `magento/module-catalog: 104.0.*`, and so on).
- PHP **8.1 – 8.4** — the union of what those Magento releases support (2.4.5 still allows
  8.1; 2.4.8 allows 8.4).

## Installation

Distribution authority: the public GitHub release asset is
[`tack-magento2.zip`](https://github.com/ackm04/tack-ecommerce-extensions/releases/download/v1.0.0/tack-magento2.zip).
This monorepo directory is build/source only. No Magento Marketplace or Packagist listing
is claimed.

Download that zip, extract it into `app/code` as directed by the release README, then:

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
├── i18n/en_US.csv                              Translation source strings
├── etc/module.xml                              Module declaration + sequence
├── etc/acl.xml                                 Admin ACL: dashboard tree + config resource
├── etc/adminhtml/menu.xml                      TackQuote sidebar menu (Dashboard, Settings)
├── etc/adminhtml/routes.xml                    Admin route tackquote/*
├── etc/adminhtml/system.xml                    Stores > Configuration > TackQuote fields
├── etc/frontend/routes.xml                     Storefront route tackquote/*
├── Model/Config.php                            Reads scoped config (enable/url/key/labels)
├── Model/Api/Client.php                        HTTP client for /v1/integrations/magento/*
├── Model/RegistrationConfigProvider.php        Fetches + caches the seller's policy
├── Model/ProductQuoteResolver.php              Server-side SKU -> priced line item
├── Model/ProductOptionRequirement.php          "Does this product need a selection first?"
├── Model/SubmissionThrottle.php                Per-IP rate limit on the public endpoint
├── Model/IdempotencyGuard.php                  Collapses double-submits into one quote
├── Block/RequestQuote.php                      Product-page trigger view model
├── Block/ListingButton.php                     Category/search tile "Add to Quote"
├── Block/QuoteList.php                         Quote-list widget + shared form view model
├── Block/Adminhtml/Dashboard.php               Dashboard status + setup checklist
├── Block/Adminhtml/System/Config/TestConnection.php   Inline config-screen test button
├── Controller/Quote/Submit.php                 Storefront quote-request POST handler
├── Controller/Adminhtml/Dashboard/Index.php    Admin dashboard page
├── Controller/Adminhtml/Connection/Test.php    Admin "Test connection" AJAX endpoint
├── view/frontend/
│   ├── layout/catalog_product_view.xml         Product-page triggers
│   ├── layout/catalog_category_view.xml        Listing-tile "Add to Quote"
│   ├── layout/default.xml                      Site-wide quote list + shared form + CSS
│   ├── templates/button.phtml                  Product-page triggers
│   ├── templates/listing-button.phtml          Listing-tile trigger
│   ├── templates/quote-list.phtml              Drawer + multi-step form markup
│   ├── web/js/quote-app.js                     RequireJS component: list, drawer, form
│   └── web/css/request-quote.css               Storefront styles
└── view/adminhtml/
    ├── layout/tackquote_dashboard_index.xml    Dashboard layout
    ├── templates/dashboard.phtml               Dashboard markup + test-connection JS
    ├── templates/system/config/test-connection.phtml   Config-screen button markup
    └── web/css/dashboard.css                   Admin dashboard styles
```
