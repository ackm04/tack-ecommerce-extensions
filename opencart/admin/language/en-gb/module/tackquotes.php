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
$_['entry_button_help'] = 'After saving, add the "TackQuote" module to a Product-page layout position under Design > Layouts to show the storefront button (OpenCart modules render only where a layout assigns them — see README.md).';

// Button
$_['button_test'] = 'Test connection';
$_['button_save'] = 'Save';
$_['button_cancel'] = 'Cancel';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify TackQuote!';
$_['error_api_url'] = 'Please enter a valid TackQuote API URL (e.g. https://api.tackquote.com/v1).';
$_['error_api_key'] = 'Please enter a TackQuote API key before testing the connection.';
$_['error_test_connection'] = 'Could not connect to TackQuote: %s';
