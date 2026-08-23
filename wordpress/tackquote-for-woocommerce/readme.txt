=== TackQuote for WooCommerce ===
Contributors: tackquote
Tags: woocommerce, request a quote, b2b, wholesale, rfq
Requires at least: 6.0
Requires Plugins: woocommerce
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Request a quote from WooCommerce for B2B wholesale quoting — sync orders to your TackQuote account with one API key.

== Description ==

**TackQuote for WooCommerce** adds storefront RFQ buttons — a configurable mix of **Add to Quote** and **Request a Quote** on product pages, plus a floating **quote list** with **Checkout as Quote** — and optional one-way order sync, so B2B and wholesale merchants can capture quote demand without leaving WooCommerce.

= What you get =

* **Add to Quote** on product pages — adds the product to a separate quote list (never the WooCommerce cart), so shoppers can add several products before requesting one quote without affecting stock or checkout.
* **Request a Quote** on product pages — submits a quote for just that product immediately, without adding it anywhere.
* Show either button, both, or neither — per-store, from Settings → TackQuote.
* A floating **quote list** (bottom-right, site-wide) with **Checkout as Quote** — submits everything currently in the list as a single quote request.
* Shopper quote requests sent securely to your TackQuote B2B quoting account.
* Optional automatic **order sync** (create + status change) pushed one-way to TackQuote — **off by default**, and queued through Action Scheduler so it never runs inside checkout.
* Simple setup: paste your TackQuote API key (and optionally a custom API URL).
* Compatible with WooCommerce **High-Performance Order Storage (HPOS)**.

= What this plugin does not do =

* It does not replace WooCommerce checkout or turn the cart into a full CPQ workspace.
* Order sync is **outbound only** — it does not import TackQuote quotes as WooCommerce orders, sync your product catalog, or update inventory.
* Failed syncs are logged (WooCommerce → Status → Logs, source `tack-quotes`) and never block checkout — each push runs on a background request, not the customer's.

This plugin is distributed via GitHub Releases.

== Installation ==

= New install =

1. Download the latest `tack-quotes.zip` from the [GitHub Releases](https://github.com/tackquote/tack-woocommerce/releases) page (or build with `bash bin/build.sh`).
2. In WP Admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate **TackQuote for WooCommerce**.
4. Open **TackQuote** in the admin menu.
5. Paste your **TackQuote API Key** (TackQuote → Settings → Developer → API Keys). Leave the API URL as the default unless support gives you another base URL.
6. Enable or disable the quote button and order sync as needed, then click **Save TackQuote settings**.
7. Click **Test TackQuote connection** to verify.

= Update from a previous ZIP =

1. Download the newer `tack-quotes.zip` from Releases.
2. Deactivate the old plugin (optional but recommended), then upload the new ZIP via **Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded**.
3. Activate, open **TackQuote**, confirm settings, and run **Test TackQuote connection**.
4. Your API key and toggles are stored as WordPress options and are preserved across updates.

== Privacy ==

This plugin sends data to TackQuote, a third-party service, over HTTPS. It sends nothing anywhere else, and it never sends payment card data.

= Where data is sent =

To the TackQuote API base URL configured under **TackQuote → TackQuote API URL** — `https://api.tackquote.com/v1` unless your TackQuote support contact gave you another one. Endpoints used:

* `GET /integrations/woocommerce/ping` (and `GET /health`) — connection test. Sends no store or customer data.
* `GET /integrations/woocommerce/registration-config` — fetches which fields the quote form should ask for. Sends no store or customer data.
* `POST /integrations/woocommerce/quote-requests` — a shopper's quote request.
* `POST /integrations/woocommerce/order-sync` — order sync. **Only when the merchant has switched order sync on. It is off by default.**

= What a quote request sends =

Sent when a shopper submits the quote form, using only what they typed into it:

* Email address
* First name, last name
* Phone number (if provided)
* Company name, and any company fields the seller's registration policy requires (for example legal name, tax/VAT ID, registration number, address, city, state, postal code, country, company phone, industry, employee count)
* The free-text note, if written (capped at 2,000 characters)
* The requested products: name, SKU, quantity, unit price excluding tax, and the WooCommerce product ID
* The store's currency code

= What order sync sends (off by default) =

Sent for each order when it is created and when its status changes, if the merchant has enabled **Sync orders to TackQuote**:

* WooCommerce order ID and order number
* Order status, currency, and order total
* Billing email address
* Billing first and last name
* Billing company name
* Line items: product name, SKU, quantity, line total
* Order created date
* An idempotency key, so a repeated delivery of the same order state can be discarded

= What is stored on your own site =

* Plugin settings, as WordPress options: the TackQuote API key, API URL, button labels, and the feature toggles.
* `tack_quotes_registration_config` — a transient caching the quote-form field policy for 15 minutes.
* `tack_qr_*` — short-lived transients counting quote requests per visitor for rate limiting. They hold a salted hash of the visitor's IP address, never the address itself, and expire after 5 minutes.
* `_tack_quotes_sync_key` — order meta recording which order state was last accepted by TackQuote, so the same state is not sent twice.

Deleting the plugin removes every option above and the `tack_quotes_registration_config` transient, on every site of a multisite network. The `tack_qr_*` rate-limit counters are left to expire on their own (they last five minutes and are keyed on a hash, so there is no name to delete). The `_tack_quotes_sync_key` order meta is deliberately left in place: orders are financial records and an uninstall routine should not rewrite every one of them.

= Suggested privacy policy text =

The plugin adds suggested wording to **Settings → Privacy → Policy guide** in WordPress, listing the same fields. Edit it to match how your store actually uses the plugin.

== Frequently Asked Questions ==

= Where do I get an API key? =

In TackQuote, go to **Settings → Developer → API Keys**.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. The plugin declares HPOS (`custom_order_tables`) compatibility via WooCommerce `FeaturesUtil` and uses the WooCommerce order CRUD APIs (`wc_get_order`, order status hooks) rather than direct `wp_posts` order queries. You can run with HPOS enabled.

= Is order sync bidirectional? =

No. Sync is one-way: WooCommerce → TackQuote on order create and status change. Turning the toggle off stops new pushes; it does not delete data already in TackQuote.

= Do the quote buttons replace checkout, or touch the WooCommerce cart? =

No, and no. "Add to Quote" adds the product to a separate, browser-side quote list — it never touches the WooCommerce cart, stock, or totals. "Checkout as Quote" creates a quote request in TackQuote from that list's contents. Customers can still shop and check out through WooCommerce completely normally, at the same time, with no interaction between the two.

= Why doesn't "Add to Quote" send a quote request immediately? =

So shoppers can add multiple products before requesting one combined quote. Use the floating "Quote list" button (bottom-right) once you've added everything you want quoted, then click "Checkout as Quote".

== Changelog ==

= 1.3.2 =
* Packaging: the distributed ZIP now unpacks to `tackquote-for-woocommerce/`, matching the plugin slug, and is rebuilt from the current source. The previously published download still contained pre-1.2.0 code, so stores installing it got the old buttons and none of the 1.3.1 security fixes.
* No functional changes to the plugin itself beyond the version bump.

= 1.3.1 =
* Security: company field names supplied by the API are now escaped and allowlisted before being rendered into the quote form, closing a cross-site scripting hole.
* Security: the TackQuote API key is no longer rendered into the settings page HTML. Leave the field blank to keep the saved key; a new "Remove saved API key" button clears it.
* Security: the settings page now requires the administrator capability, which is also the capability WordPress requires to save it — a shop manager previously saw the page but could not save it.
* Quote requests from the storefront are rate limited per visitor, the note field is length-capped, and the request timeout is 5s instead of 20s.
* Order sync is now queued and sent on a background request through Action Scheduler, so it no longer runs inside checkout, and each push carries an idempotency key so the same order state is never sent twice.
* Order sync now defaults to OFF on new installs, and readme.txt documents exactly which fields are sent where. Existing stores keep their current setting.
* An expired or cache-stale security token now says so and offers a reload, instead of showing a generic error that could never be resolved.
* Quantity and variation are read from the clicked product's own form, fixing wrong values on grouped products, product archives, related-product rows and sticky add-to-cart bars.
* Declares compatibility with the Cart and Checkout blocks, and adds the `WC tested up to` header that WooCommerce needs before it will surface any compatibility declaration.
* Uninstall now removes every option and transient the plugin creates.

= 1.3.0 =
* "Add to Quote" no longer adds the product to the WooCommerce cart. It now adds to a separate, browser-side "quote list" that never touches stock, cart totals, or checkout. A floating "Quote list" button (bottom-right, site-wide) appears once at least one product is added, showing what's in it and a "Checkout as Quote" button that submits the whole list as one TackQuote request. The WooCommerce cart page no longer has a quote button — quoting and purchasing are now fully separate paths.

= 1.2.0 =
* Restored "Request a Quote" as an independent, configurable product-page button alongside "Add to Quote" — Settings → TackQuote now has checkboxes to show "Add to Quote", "Request a Quote", both, or neither on product pages (the cart page's "Checkout as Quote" is unaffected). Both default to on for existing and new installs.

= 1.1.0 =
* Split the single "Request a Quote" button into two: "Add to Quote" on product pages (adds the product to the cart, same as Add to Cart) and "Checkout as Quote" on the cart page (submits everything in the cart as one quote request). Previously the product-page button submitted a quote for that one product immediately, with no way to accumulate multiple products into a single request without using the whole-cart button on every add.

= 1.0.1 =
* Replaced the browser `prompt()`/`alert()` quote-request flow with a real modal dialog (email + optional note fields, inline validation, loading/success/error states). Email is pre-filled for logged-in customers.

= 1.0.0 =
* Initial release: Request a Quote button, one-way order sync, TackQuote settings, HPOS declaration.
