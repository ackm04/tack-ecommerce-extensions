<?php
/**
 * TackQuote for Zen Cart — storefront AJAX endpoint.
 *
 * URL (once copied into the storefront root): /ajax_tack_quote_request.php
 *
 * Zen Cart has no MVC front-controller layer (unlike PrestaShop's
 * ModuleFrontController), so — following the same pattern Zen Cart itself
 * uses for its own `ajax.php`-style endpoints — this is a plain top-level
 * PHP script that bootstraps the storefront (`includes/application_top.php`)
 * for DB access + configuration constants, then handles one POST action and
 * exits with a JSON body. It never touches the cart or checkout.
 *
 * Called by includes/templates/template_default/jscript/tack_quote_button.js
 * from includes/templates/template_default/templates/tpl_tack_quote_button.php.
 */

require('includes/application_top.php');

header('Content-Type: application/json');

/**
 * @param string $message
 * @param int    $httpCode
 */
function tack_json_error($message, $httpCode = 400)
{
    http_response_code($httpCode);
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}

/**
 * @param array $data
 */
function tack_json_success($data)
{
    echo json_encode(array_merge(array('success' => true), $data));
    exit;
}

if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    tack_json_error('Invalid request method.', 405);
}

$email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$note = isset($_POST['note']) ? (string) $_POST['note'] : '';
$productId = isset($_POST['products_id']) ? (int) $_POST['products_id'] : 0;
$quantity = isset($_POST['quantity']) ? max(1, (int) $_POST['quantity']) : 1;

if (!$email || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
    tack_json_error('A valid email address is required.', 400);
}

if (!$productId) {
    tack_json_error('No product to quote.', 400);
}

// Load the product being quoted using Zen Cart's own products table + query
// pattern (TABLE_PRODUCTS / TABLE_PRODUCTS_DESCRIPTION constants and the
// bootstrapped $db object are both provided by application_top.php).
$productQuery = $db->Execute(
    "SELECT p.products_model, p.products_price, pd.products_name
     FROM " . TABLE_PRODUCTS . " p
     LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd
       ON pd.products_id = p.products_id AND pd.language_id = " . (int) $_SESSION['languages_id'] . "
     WHERE p.products_id = " . (int) $productId
);

if ($productQuery->EOF) {
    tack_json_error('Product not found.', 404);
}

$apiUrl = defined('TACK_API_URL') ? TACK_API_URL : '';
$apiKey = defined('TACK_API_KEY') ? TACK_API_KEY : '';

if (!$apiUrl || !$apiKey) {
    tack_json_error('TackQuote is not configured on this store yet.', 503);
}

require_once(DIR_WS_CLASSES . 'tack_api_client.php');

$client = new TackApiClient($apiUrl, $apiKey);

$payload = array(
    'buyerEmail' => $email,
    'note' => $note,
    'source' => 'zencart',
    'lineItems' => array(
        array(
            'sku' => $productQuery->fields['products_model'],
            'name' => $productQuery->fields['products_name'],
            'quantity' => $quantity,
            'unitPrice' => (float) $productQuery->fields['products_price'],
            'externalProductId' => (string) $productId,
        ),
    ),
);

// products_price is stored in the store's DEFAULT currency, not the session currency, so
// that is the currency this quote is denominated in — sending the session currency here
// would label an unconverted amount with the wrong code, which is worse than the original
// bug. Without any currency Tack fell back to a hardcoded 'USD'.
//
// `DEFAULT_CURRENCY` is the store's configured default; this repo's own Zen Cart connector
// already treats it as such (see tack-connector/index.php, tack_connector_currency()).
// Sent only when it looks like ISO 4217 alpha-3, so a misconfigured store falls back to
// the tenant's configured currency rather than receiving junk.
$currency = defined('DEFAULT_CURRENCY') ? strtoupper(trim((string) DEFAULT_CURRENCY)) : '';

if (preg_match('/^[A-Z]{3}$/', $currency)) {
    $payload['currency'] = $currency;
}

$result = $client->createQuoteRequest($payload);

if (is_string($result)) {
    // Surface the real error to the shopper rather than pretending it worked —
    // this is a 502 today unless a companion API deployment with the
    // /integrations/zencart routes is reachable at TACK_API_URL.
    tack_json_error($result, 502);
}

tack_json_success(array(
    'quoteId' => isset($result['id']) ? $result['id'] : null,
    'portalUrl' => isset($result['portalUrl']) ? $result['portalUrl'] : '',
));
