=== TackQuote for WooCommerce ===
Contributors: tackquote
Tags: woocommerce, request a quote, b2b, quotes, wholesale, quoting, rfq
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
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
* Optional automatic **order sync** (create + status change) pushed one-way to TackQuote.
* Simple setup: paste your TackQuote API key (and optionally a custom API URL).
* Compatible with WooCommerce **High-Performance Order Storage (HPOS)**.

= What this plugin does not do =

* It does not replace WooCommerce checkout or turn the cart into a full CPQ workspace.
* Order sync is **outbound only** — it does not import TackQuote quotes as WooCommerce orders, sync your product catalog, or update inventory.
* Failed syncs are logged (WooCommerce → Status → Logs, source `tack-quotes`) and never block checkout.

This plugin is distributed via GitHub Releases. A WordPress.org listing may use this readme for discovery around “request a quote”, B2B, and wholesale quoting keywords.

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
