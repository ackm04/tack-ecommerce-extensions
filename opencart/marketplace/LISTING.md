# OpenCart Marketplace submission — TackQuote 1.1.1

Everything needed to submit this extension to <https://www.opencart.com>. Assembled and
verified against a real **OpenCart 4.1.0.4** store (the `tack-devstores/opencart` stack),
not from reading the code.

> **UNVERIFIED: the marketplace's own image dimension rules.** opencart.com answers
> automated requests with HTTP 403, and the partner upload form is behind a login, so the
> required/recommended sizes for the listing image and additional images could not be read
> from the vendor. The sizes below are chosen from OpenCart's *documented* image guidance
> (`docs.opencart.com/admin-interface/overview/manufacturers`: "Ensure logo is 200x200
> pixels for optimal display. Use PNG format for transparent background"). **Check the
> upload form before submitting** and re-export from `apps/web/public/logo-mark.png`
> (493×493, transparent) if it asks for something else.

## Files in this bundle

| File | What it is |
|---|---|
| `tack.ocmod.zip` | **The installable extension.** Upload this at Extensions > Installer. The filename is load-bearing: OpenCart derives the extension code from `basename($file, '.ocmod.zip')`, so renaming it to anything other than `tack` breaks the `extension/tack/api/*` feed routes. |
| `logo-200x200.png` | Listing logo. PNG, transparent, LANCZOS downscale of the 493×493 brand mark. |
| `screenshot-01-storefront-button.png` | Product page — "Add to Quote" and "Request a Quote" directly beneath Add to Cart. |
| `screenshot-02-category-tiles.png` | Category listing — an add-to-quote button on every product tile, and the floating quote launcher. |
| `screenshot-03-quote-list.png` | The quote list: three products collected across pages, editable quantities. |
| `screenshot-04-form-details.png` | Step 2 of the request form — email, name, company, phone, note. |
| `screenshot-05-quote-sent.png` | Step 3 — the quote reference returned by TackQuote (TK-2026-001075). |
| `screenshot-06-admin-settings.png` | Admin settings: connection, labels, and each placement toggle. |

## Listing copy

**Name:** TackQuote — B2B Quoting & Request a Quote

**Version:** 1.2.0 · **Compatible:** OpenCart 4.0.x.x, 4.1.x.x · **Licence:** MIT

**Short description**

> Add "Request a Quote" and "Add to Quote" buttons beside Add to Cart, let buyers collect
> several products into one quote request, and manage the quotes in TackQuote. Also exposes a
> secure catalog/order feed so TackQuote can sync products, import orders and place
> quote-accepted orders back into your store.

**Long description**

> TackQuote turns product pages into a B2B quoting channel. Shoppers who need volume
> pricing, terms or a negotiated price request a quote instead of adding to cart; the request
> lands in TackQuote as a draft quote your team prices and sends back.
>
> The extension has two independent halves, each with its own secret:
>
> * **Quote button (store → TackQuote).** A modal on the product page posts to
>   `POST /v1/integrations/opencart/quote-requests`, authenticated with your TackQuote API
>   key. The key never reaches the browser — the request is made server-side from PHP. The
>   store's active currency is sent with the prices, so a store trading in EUR gets EUR
>   quotes.
> * **Catalog / order feed (TackQuote → store).** `extension/tack/api/product.list`,
>   `order.list` and `order.add`, authenticated with a feed token you generate in the admin.
>   OpenCart core has no such endpoint, so this half is what makes catalog sync, order
>   history and quote-accepted order placement possible. It **fails closed**: with no token
>   configured every request is refused with 503, so a fresh install never leaks catalog or
>   order data.
>
> Bearer tokens are compared with `hash_equals()`. The two secrets are deliberately separate
> and are not usable in each other's direction.

**Requirements:** OpenCart 4.0.x/4.1.x · PHP 8.0+ with `curl`, `ZipArchive` · a TackQuote
account and API key.

## Install steps (as shown to merchants)

1. **Extensions > Installer** → upload `tack.ocmod.zip` → click **Install**.
2. **Extensions > Extensions** → choose **Modules** → find **TackQuote** → click **+** to install.
3. Click **edit** on TackQuote → set **Status = Enabled**, paste your **TackQuote API key**,
   leave the API URL at its default → **Save** → **Test connection**.
4. That is it — the quote buttons appear on every product page, and the add-to-quote button on
   category and search tiles. Each placement has its own on/off switch on the same screen.
5. *(Optional)* **Design > Layouts** → add the **TackQuote** module to a layout position, only
   if your theme has renamed core's Add to Cart button (`id="button-cart"`) or you want the
   buttons elsewhere on the page.
6. *(Optional, for catalog/order sync)* generate a long random secret, paste it into
   **Catalog / order feed token**, and paste the same secret into TackQuote under
   Settings > Integrations > OpenCart.

## Verification evidence (2026-08-19, OpenCart 4.1.0.4)

* Installed through OpenCart's own **Extensions > Installer** from `tack.ocmod.zip` — 32
  files extracted into `extension/tack/`, no manual file copying.
* Module registered as `oc_extension (tack, module, tackquotes)`, layout row
  `tack.tackquotes @ content_bottom` on the Product layout.
* **Two real quotes created from the storefront**: `TK-2026-001073` (Canon EOS 5D, 12 ×
  $100.00 = $1,200.00) and `TK-2026-001074` (MacBook, 25 × $500.00 = $12,500.00). Both
  landed as `draft`, denominated **USD** from the store's own currency, with the product
  name and OpenCart model carried through as name/SKU.
* **Test connection** verified to make a real call, not a config check:
  `GET /v1/integrations/opencart/ping 200` appears in the TackQuote API log at the moment
  of the click.

## What 1.2.0 added, and how each claim was checked

| capability | verified by |
|---|---|
| buttons beside Add to Cart (view event, no theme edits) | rendered HTML: controls on the Add to Cart line, description tabs below |
| add-to-quote on every category tile | 5 tiles each gained a button; product pages gained none |
| multi-product quote list, separate from the cart | 3 products collected across pages, quantities 25/10/1 |
| one quote holding many lines | **TK-2026-001075** — 3 lines, $46,000.00, USD |
| prices re-resolved server-side | tile items had no client-side SKU yet stored `Product 17/18/19`; displayed incl-tax prices stored as ex-tax catalog prices |
| price tampering refused | a request forging `unitPrice=0.01`, SKU and name stored 2000.00 with the real values (**TK-2026-001076**) |
| submission throttle | 5 requests succeeded on one session, the 6th was refused |

## Two defects this pass found and fixed

Both were invisible from the code and only appeared once the extension was installed in a
real store — this extension had never been run in one before.

1. **The storefront button could not be placed at all (fixed in 1.1.1).** The settings group
   was `module_tackquote_*` while the module code is `tackquotes`. OpenCart lists a
   single-instance module in Design > Layouts only when a setting named
   `module_<code>_status` exists (`admin/controller/design/layout.php:262`), so TackQuote was
   absent from every position picker while the admin reported everything as installed,
   enabled and saved.
2. **"Test connection" could never succeed on a saved configuration (fixed in 1.1.1).** The
   API-key input is rendered empty on purpose (the stored secret is only shown masked), and
   `test()` read only the posted value — so it answered "please enter an API key" forever
   after the first save. It now falls back to the stored credentials, exactly as the save
   path's `keepOrReplace()` does.
