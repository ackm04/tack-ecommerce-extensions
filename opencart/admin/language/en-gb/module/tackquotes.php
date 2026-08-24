<?php
// Heading
$_['heading_title'] = 'TackQuote';

// Text
$_['text_extension'] = 'Extensions';
$_['text_home'] = 'Dashboard';
$_['text_success'] = 'Success: You have modified TackQuote settings!';
$_['text_edit'] = 'Edit TackQuote';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_test_success'] = 'Connected to TackQuote successfully.';

// Entry
$_['entry_status'] = 'Status';
$_['entry_api_url'] = 'TackQuote API URL';
$_['entry_api_url_help'] = 'Default is https://api.tackquote.com/v1. Change only if TackQuote support gives you a custom or staging API base URL (include the /v1 path, no trailing slash).';
$_['entry_api_key'] = 'TackQuote API Key';
$_['entry_api_key_help'] = 'Create an API key in TackQuote under Settings > Developer > API Keys — the same kind of key the WooCommerce and PrestaShop plugins use.';
$_['entry_api_key_saved'] = 'Saved key: %s. Leave unchanged to keep it, or paste a new key to replace it.';
$_['entry_connector_token'] = 'Catalog / order feed token';
$_['entry_connector_token_help'] = 'Optional. Paste a long random secret here and the SAME secret into TackQuote under Settings > Integrations > OpenCart ("API key"). It lets TackQuote read your catalog and orders and place quote-accepted orders. Leave empty to keep that feed switched off.';
$_['entry_connector_token_saved'] = 'Saved token: %s. Leave unchanged to keep it, paste a new token to replace it, or enter a single dash (-) to switch the feed off.';
$_['entry_button_label'] = 'Button label';
$_['entry_button_label_help'] = 'Text shown on the storefront button, e.g. "Request a Quote" or "Get a B2B quote".';
// Was: "After saving, add the TackQuote module to a Product-page layout position…". That was
// the only way to show the button until 1.2.0, and it is now the FALLBACK — leaving it as the
// instruction would have merchants placing a second, duplicate button under the description.
$_['entry_button_help'] = 'Saving is enough: with "Show beside Add to Cart" enabled the quote buttons appear on every product page automatically. Assign the "TackQuote" module to a layout position under Design > Layouts only if your theme has renamed the core Add to Cart button, or if you want the buttons somewhere else on the page.';

// Button
$_['button_test'] = 'Test connection';
$_['button_save'] = 'Save';
$_['button_cancel'] = 'Cancel';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify TackQuote!';
$_['error_api_url'] = 'Please enter a valid TackQuote API URL (e.g. https://api.tackquote.com/v1).';
$_['error_api_key'] = 'Enter or save a TackQuote API key before testing the connection.';
$_['error_test_connection'] = 'Could not connect to TackQuote: %s';
// Rendered by the Test connection button when the admin AJAX call itself fails
// (network error, session expired, endpoint 500) rather than returning JSON. This
// was the one hard-coded English string left in the template; OpenCart's own
// guidance is "Internationalization: use language files for all text"
// (<https://docs.opencart.com/developer-guide/extensions> § Best Practices).
$_['error_ajax'] = 'Could not reach the admin ajax endpoint.';

// Storefront placement (1.2.0)
$_['entry_add_label'] = 'Add-to-Quote label';
$_['entry_add_label_help'] = 'Text on the button that adds a product to the quote list, e.g. "Add to Quote".';
$_['entry_inline_button'] = 'Show beside Add to Cart';
$_['entry_inline_button_help'] = 'Places the quote buttons directly after the Add to Cart button on product pages. Uses a storefront view event, so no theme files are edited. If your theme has renamed the core Add to Cart button (id="button-cart"), nothing is injected and the page is left untouched — assign the TackQuote module to a layout position under Design > Layouts instead.';
$_['entry_quote_list'] = 'Multi-product quote list';
$_['entry_quote_list_help'] = 'Lets a buyer collect several products and request one quote for all of them, like a cart. The list is held in the browser and never touches the OpenCart cart. With this off, each request covers a single product.';
$_['entry_listing_button'] = 'Add-to-Quote on category tiles';
$_['entry_listing_button_help'] = 'Adds a compact add-to-quote button beside the cart/wishlist/compare buttons on category and search results. Requires the quote list to be enabled.';

// ── Quote-only / B2B catalog mode (1.3.0) ────────────────────────────────────────────────
$_['entry_quote_only'] = 'Quote-only store (B2B catalog)';
$_['entry_quote_only_help'] = 'Turns the whole storefront into a B2B catalog: Add to Cart and checkout are REFUSED BY THE SERVER, and shoppers request a quote instead. This is enforced in PHP, not by hiding buttons, so a crafted request cannot get around it. Existing carts are not emptied — they simply cannot grow or be checked out — and admin API order creation (Sales > Orders > Add Order, and TackQuote converting an accepted quote) keeps working. While you are logged into this admin panel your own storefront session keeps its cart, so you can compare both.';
$_['entry_quote_only_scope'] = 'Applies to';
$_['entry_quote_only_scope_help'] = 'Who sees the quote-only storefront. "Guests only" is the usual B2B setup: the public gets a catalog, and approved customers who have logged in keep a normal cart and checkout.';
$_['text_scope_all'] = 'Everyone';
$_['text_scope_guests'] = 'Guests only (logged-in customers keep the cart)';
$_['text_scope_groups'] = 'Selected customer groups';
$_['entry_quote_only_groups'] = 'Customer groups';
$_['entry_quote_only_groups_help'] = 'Used only when "Applies to" is set to selected customer groups. Visitors who are not logged in are matched against the store default customer group (System > Settings > Option > Customer Group), which is the group OpenCart already prices them in.';

// Error
$_['error_quote_only_groups'] = 'Choose at least one customer group, or set "Applies to" back to Everyone or Guests only.';
$_['error_quote_only_api_key'] = 'Save a TackQuote API key before switching on quote-only mode. Without a key no quote button is rendered, so enforcing quote-only would leave your storefront with no way to buy AND no way to request a quote.';
