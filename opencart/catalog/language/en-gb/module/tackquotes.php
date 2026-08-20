<?php
// Storefront strings. Every string the JS shows is passed in from here through the drawer
// template's config attribute — the app file contains no English of its own, so a merchant
// translating this file translates the whole buyer-facing flow. (The one exception is the
// network-failure fallback, which fires when the page cannot reach the store at all.)

// Buttons
$_['button_default_label'] = 'Request a Quote';
$_['button_add_to_quote']  = 'Add to Quote';
$_['button_send']          = 'Send request';
$_['button_next']          = 'Continue';
$_['button_back']          = 'Back';
$_['button_close']         = 'Close';
$_['button_view_quote']    = 'View your quote';

// Panel
$_['text_drawer_title']    = 'Your quote';
$_['text_drawer_empty']    = 'Your quote list is empty. Use “Add to Quote” on a product to start one.';
$_['text_step_items']      = 'Items';
$_['text_step_details']    = 'Your details';
$_['text_step_done']       = 'Done';
$_['text_review_hint']     = 'Prices are confirmed by the seller — the quote you receive is the priced one.';
$_['text_items_count']     = 'items';
$_['text_price_on_request'] = 'Price on request';
$_['text_added']           = 'Added to your quote list.';
$_['text_remove']          = 'Remove';

// Form fields
$_['text_email']           = 'Email';
$_['text_first_name']      = 'First name';
$_['text_last_name']       = 'Last name';
$_['text_company']         = 'Company';
$_['text_telephone']       = 'Phone';
$_['text_quantity']        = 'Quantity';
$_['text_note']            = 'Note (optional)';
$_['text_success']         = 'Quote request sent. Check your email for a link to the quote.';

// Errors
$_['error_email']          = 'A valid email address is required.';
$_['error_product']        = 'No product to quote.';
$_['error_empty_list']     = 'Your quote list is empty.';
$_['error_too_many']       = 'A quote can hold up to 50 different products. Please split this into more than one request.';
$_['error_throttled']      = 'Too many quote requests from this browser. Please wait a few minutes and try again.';
$_['error_not_configured'] = 'TackQuote is not configured on this store yet.';
