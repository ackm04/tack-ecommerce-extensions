<?php
/**
 * TackQuote for Zen Cart — inbound catalog / order connector.
 *
 * URL (once copied into the storefront root): {store}/tack-connector/...
 *
 *   GET  /tack-connector/products              catalog feed
 *   GET  /tack-connector/orders?page=&limit=   order history, paged
 *   GET  /tack-connector/orders/{id}           one order
 *   POST /tack-connector/orders                place a quote-accepted order
 *
 * This is the store side of the contract that
 * `apps/api/src/modules/integrations/zencart/zencart.service.ts` has always
 * called. Until this file existed those calls 404'd: Zen Cart ships no core
 * REST API, so nothing served `{baseUrl}/tack-connector/*` and the merchant was
 * told to write it themselves.
 *
 * It is the OPPOSITE direction from ajax_tack_quote_request.php, which is this
 * store calling TackQuote with a TackQuote API key. This file is TackQuote
 * calling this store, authenticated with the store's own TACK_CONNECTOR_TOKEN.
 * Two directions, two secrets — see README.md.
 *
 * Bootstrap follows the same pattern as ajax_tack_quote_request.php (Zen Cart
 * has no MVC front controller, so a top-level script bootstraps
 * includes/application_top.php and exits with a JSON body). The one difference
 * is the chdir(): application_top.php resolves `includes/configure.php` with a
 * RELATIVE include, so it only works with the store root as the working
 * directory, and this script lives one level down.
 *   https://github.com/zencart/zencart/blob/v1.5.8a/includes/application_top.php
 *
 * Routing: /tack-connector/products is not a real file, so it needs the
 * rewrite in the .htaccess shipped alongside this file (or the nginx
 * equivalent in README.md). The rewrite passes the sub-path as ?tack_path=;
 * PATH_INFO and REQUEST_URI are both accepted as fallbacks so a hand-rolled
 * server config has more than one way to work.
 */

// Captured BEFORE the bootstrap: Zen Cart's application_top.php sanitises $_GET
// and starts a session, and the sub-path must be read from the untouched
// request. Deliberately long, unique variable names — application_top.php runs
// in this same scope and defines plenty of short ones.
$tackConnectorRequestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
$tackConnectorScriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
$tackConnectorPathInfo = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
$tackConnectorQueryPath = isset($_GET['tack_path']) ? (string) $_GET['tack_path'] : '';
$tackConnectorMethod = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$tackConnectorRawBody = $tackConnectorMethod === 'POST' ? (string) file_get_contents('php://input') : '';
$tackConnectorAuthHeader = tack_connector_auth_header();

chdir(dirname(__DIR__));
require 'includes/application_top.php';

/**
 * Read the Authorization header.
 *
 * `Authorization` does not always reach PHP: mod_php exposes HTTP_AUTHORIZATION,
 * CGI/FastCGI commonly drops it unless the vhost forwards it (usually arriving
 * as REDIRECT_HTTP_AUTHORIZATION), and getallheaders() covers the rest. All
 * three are checked, because a stripped header reads to the merchant as "wrong
 * token" — the hardest failure to diagnose.
 *
 * @return string
 */
function tack_connector_auth_header()
{
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
        if (!empty($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
    }

    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                return (string) $value;
            }
        }
    }

    return '';
}

/**
 * @param int   $status
 * @param array $payload
 */
function tack_connector_respond($status, $payload)
{
    http_response_code($status);
    header('Content-Type: application/json');
    // Authenticated, per-store data — never cacheable by an intermediary.
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

/**
 * @param int    $status
 * @param string $code
 * @param string $message
 */
function tack_connector_fail($status, $code, $message)
{
    tack_connector_respond($status, array('error' => $message, 'code' => $code));
}

/**
 * Language id for name/description/status lookups. Prefers the session
 * language (set by application_top.php), falling back to the store's
 * configured default rather than assuming id 1.
 *
 * @return int
 */
function tack_connector_language_id()
{
    global $db;

    if (!empty($_SESSION['languages_id'])) {
        return (int) $_SESSION['languages_id'];
    }

    if (defined('DEFAULT_LANGUAGE')) {
        $sql = "SELECT languages_id FROM " . TABLE_LANGUAGES . " WHERE code = :code: LIMIT 1";
        $sql = $db->bindVars($sql, ':code:', DEFAULT_LANGUAGE, 'string');
        $result = $db->Execute($sql);

        if (!$result->EOF) {
            return (int) $result->fields['languages_id'];
        }
    }

    return 1;
}

/**
 * Resolve a currency code against the store's own `currencies` table.
 * An unknown code is refused rather than defaulted — booking a EUR quote as
 * USD at rate 1.0 misstates the order value.
 *
 * @param string $code
 *
 * @return array|null {code, value}
 */
function tack_connector_currency($code)
{
    global $db;

    $code = strtoupper(trim((string) $code));

    if ($code === '') {
        $code = defined('DEFAULT_CURRENCY') ? strtoupper(DEFAULT_CURRENCY) : '';
    }

    if ($code === '') {
        return null;
    }

    $sql = "SELECT code, value FROM " . TABLE_CURRENCIES . " WHERE code = :code: LIMIT 1";
    $sql = $db->bindVars($sql, ':code:', $code, 'string');
    $result = $db->Execute($sql);

    if ($result->EOF) {
        return null;
    }

    return array(
        'code'  => (string) $result->fields['code'],
        'value' => (float) $result->fields['value'] > 0 ? (float) $result->fields['value'] : 1.0,
    );
}

/** MySQL DATETIME -> ISO-8601 with offset; '' when unparseable. */
function tack_connector_iso8601($dateTime)
{
    $dateTime = (string) $dateTime;

    if ($dateTime === '' || strpos($dateTime, '0001-01-01') === 0 || strpos($dateTime, '0000-00-00') === 0) {
        return '';
    }

    $timestamp = strtotime($dateTime);

    return $timestamp !== false ? date('c', $timestamp) : '';
}

// ---------------------------------------------------------------------------
// Auth. Fails CLOSED: with no token configured the connector does not exist at
// all (503), so a store that has merely copied the files in never serves
// catalog or order data to an unauthenticated caller.
// ---------------------------------------------------------------------------

$tackConnectorExpectedToken = defined('TACK_CONNECTOR_TOKEN') ? trim((string) TACK_CONNECTOR_TOKEN) : '';

if ($tackConnectorExpectedToken === '') {
    tack_connector_fail(
        503,
        'feed_disabled',
        'The TackQuote connector is switched off. Set "TackQuote connector token" in Zen Cart admin under Configuration > TackQuote (run zc_install/upgrade_connector.sql first if that setting is missing).'
    );
}

if (stripos($tackConnectorAuthHeader, 'Bearer ') !== 0) {
    tack_connector_fail(401, 'missing_token', 'Missing Authorization: Bearer <token> header.');
}

// hash_equals, not ===: this is a bearer secret compared on every request.
if (!hash_equals($tackConnectorExpectedToken, trim(substr($tackConnectorAuthHeader, 7)))) {
    tack_connector_fail(401, 'invalid_token', 'The presented token does not match this store\'s TackQuote connector token.');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

$tackConnectorPath = '';

if ($tackConnectorQueryPath !== '') {
    $tackConnectorPath = $tackConnectorQueryPath;
} elseif ($tackConnectorPathInfo !== '') {
    $tackConnectorPath = $tackConnectorPathInfo;
} elseif ($tackConnectorRequestUri !== '' && $tackConnectorScriptName !== '') {
    $uriPath = (string) parse_url($tackConnectorRequestUri, PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname($tackConnectorScriptName)), '/');

    if ($base !== '' && strpos($uriPath, $base) === 0) {
        $tackConnectorPath = substr($uriPath, strlen($base));
    } else {
        $tackConnectorPath = $uriPath;
    }
}

$tackConnectorPath = '/' . trim((string) $tackConnectorPath, '/');
$tackConnectorSegments = $tackConnectorPath === '/' ? array() : explode('/', trim($tackConnectorPath, '/'));
$tackConnectorResource = isset($tackConnectorSegments[0]) ? $tackConnectorSegments[0] : '';

$languageId = tack_connector_language_id();

if ($tackConnectorResource === '' ) {
    // Discovery document. Useful for "is the rewrite working?" without leaking
    // any store data — the caller is already authenticated at this point.
    tack_connector_respond(200, array(
        'connector' => 'tackquote-zencart',
        'version'   => '1.1.0',
        'routes'    => array('GET /products', 'GET /orders', 'GET /orders/{id}', 'POST /orders'),
    ));
}

if ($tackConnectorResource === 'products') {
    if ($tackConnectorMethod !== 'GET') {
        tack_connector_fail(405, 'method_not_allowed', 'GET only.');
    }

    // TackQuote's syncProducts() issues ONE unpaginated call, so `limit`
    // defaults to "everything". Defaulting to a page size here would silently
    // drop the rest of the catalog; a loud memory error on a very large
    // catalog is preferable to a sync that quietly imports 50 products and
    // reports success. `page`/`limit` are honoured if sent.
    $limit = isset($_GET['limit']) ? max(0, (int) $_GET['limit']) : 0;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

    $sql = "SELECT p.products_id, p.products_model, p.products_price, p.products_quantity,
                   p.products_image, p.products_status,
                   pd.products_name, pd.products_description
            FROM " . TABLE_PRODUCTS . " p
            LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd
              ON pd.products_id = p.products_id AND pd.language_id = " . (int) $languageId . "
            ORDER BY p.products_id ASC";

    // Zen Cart's queryFactory has no prepared statements; bindVars() with an
    // explicit type is its sanitiser of record. Integers are (int)-cast, which
    // is what Zen Cart core does for LIMIT clauses.
    if ($limit > 0) {
        $sql .= " LIMIT " . (int) (($page - 1) * $limit) . ", " . (int) $limit;
    }

    $result = $db->Execute($sql);

    $products = array();

    while (!$result->EOF) {
        $products[] = array(
            'id'          => (int) $result->fields['products_id'],
            'model'       => (string) $result->fields['products_model'],
            'name'        => (string) $result->fields['products_name'],
            'description' => (string) $result->fields['products_description'],
            // DECIMAL(15,4) as a string: TackQuote parses it with parseFloat,
            // and json_encode of a float would round-trip through binary.
            'price'       => number_format((float) $result->fields['products_price'], 4, '.', ''),
            // Raw column value — TackQuote builds {store}/images/{image}.
            'image'       => (string) $result->fields['products_image'],
            // Disabled products are reported with active=false rather than
            // omitted, so TackQuote deactivates its copy instead of leaving a
            // stale product live.
            'active'      => ((int) $result->fields['products_status']) === 1,
            'quantity'    => (float) $result->fields['products_quantity'],
        );

        $result->MoveNext();
    }

    tack_connector_respond(200, array(
        'products' => $products,
        'page'     => $page,
        'limit'    => $limit,
    ));
}

if ($tackConnectorResource === 'orders') {
    $orderId = isset($tackConnectorSegments[1]) ? (int) $tackConnectorSegments[1] : 0;

    if ($tackConnectorMethod === 'GET') {
        tack_connector_orders_get($orderId, $languageId);
    }

    if ($tackConnectorMethod === 'POST') {
        if ($orderId > 0) {
            tack_connector_fail(405, 'method_not_allowed', 'POST /orders takes no order id.');
        }

        tack_connector_orders_post($tackConnectorRawBody, $languageId);
    }

    tack_connector_fail(405, 'method_not_allowed', 'GET or POST only.');
}

tack_connector_fail(404, 'unknown_route', 'No such connector route: ' . $tackConnectorPath);

/**
 * GET /orders[/{id}]
 *
 * Ordered by orders_id ASC. Ascending is deliberate: TackQuote walks pages
 * until one yields no order id it has not already seen, and with DESC every
 * order placed mid-walk would shift the window and hide a row.
 *
 * @param int $orderId 0 = list.
 * @param int $languageId
 */
function tack_connector_orders_get($orderId, $languageId)
{
    global $db;

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 250) {
        $limit = 250;
    }
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

    // orders_status = 0 is not a real Zen Cart order state for a placed order;
    // guarding on > 0 keeps any half-written row out of the revenue feed.
    $where = " WHERE o.orders_status > 0";

    if ($orderId > 0) {
        $where = " WHERE o.orders_id = " . (int) $orderId;
    }

    $sql = "SELECT o.orders_id, o.orders_status, o.order_total, o.currency, o.currency_value,
                   o.date_purchased, os.orders_status_name
            FROM " . TABLE_ORDERS . " o
            LEFT JOIN " . TABLE_ORDERS_STATUS . " os
              ON os.orders_status_id = o.orders_status AND os.language_id = " . (int) $languageId
        . $where
        . " ORDER BY o.orders_id ASC";

    if ($orderId === 0) {
        $sql .= " LIMIT " . (int) (($page - 1) * $limit) . ", " . (int) $limit;
    }

    $result = $db->Execute($sql);

    $orders = array();
    $rates = array();
    $ids = array();

    while (!$result->EOF) {
        $id = (int) $result->fields['orders_id'];
        $ids[] = $id;

        // Zen Cart stores order_total in the STORE's default currency and
        // currency_value as the rate into the currency the buyer paid in; its
        // own admin formats with $currencies->format($total, true, $currency,
        // $currency_value), which multiplies. Reporting the raw total next to
        // `currency` would label a EUR order with a USD amount.
        $rate = (float) $result->fields['currency_value'] > 0
            ? (float) $result->fields['currency_value']
            : 1.0;
        $rates[$id] = $rate;

        $orders[$id] = array(
            'id'          => $id,
            'orderNumber' => (string) $id,
            'status'      => (string) $result->fields['orders_status_name'],
            'total'       => number_format((float) $result->fields['order_total'] * $rate, 4, '.', ''),
            'currency'    => (string) $result->fields['currency'],
            'orderedAt'   => tack_connector_iso8601($result->fields['date_purchased']),
            'note'        => '',
            'lineItems'   => array(),
        );

        $result->MoveNext();
    }

    if ($orderId > 0 && !$orders) {
        tack_connector_fail(404, 'order_not_found', 'No such order: ' . (int) $orderId . '.');
    }

    if ($ids) {
        // One query for the page's line items rather than N. Every id is an
        // (int) cast of a value just read from the DB, so the IN list carries
        // no request-supplied string.
        $idList = implode(',', array_map('intval', $ids));

        $lineResult = $db->Execute(
            "SELECT orders_id, products_id, products_model, products_name, products_quantity, final_price
             FROM " . TABLE_ORDERS_PRODUCTS . "
             WHERE orders_id IN (" . $idList . ")
             ORDER BY orders_products_id ASC"
        );

        while (!$lineResult->EOF) {
            $id = (int) $lineResult->fields['orders_id'];

            if (isset($orders[$id])) {
                $rate = isset($rates[$id]) ? $rates[$id] : 1.0;

                $orders[$id]['lineItems'][] = array(
                    'productId' => (int) $lineResult->fields['products_id'],
                    'name'      => (string) $lineResult->fields['products_name'],
                    'sku'       => (string) $lineResult->fields['products_model'],
                    'quantity'  => (float) $lineResult->fields['products_quantity'],
                    'price'     => round((float) $lineResult->fields['final_price'] * $rate, 4),
                );
            }

            $lineResult->MoveNext();
        }

        // First status-history comment doubles as the order note; TackQuote
        // falls back to its own text when this is empty.
        $noteResult = $db->Execute(
            "SELECT orders_id, comments
             FROM " . TABLE_ORDERS_STATUS_HISTORY . "
             WHERE orders_id IN (" . $idList . ")
             ORDER BY orders_status_history_id ASC"
        );

        while (!$noteResult->EOF) {
            $id = (int) $noteResult->fields['orders_id'];
            $comment = trim((string) $noteResult->fields['comments']);

            if (isset($orders[$id]) && $orders[$id]['note'] === '' && $comment !== '') {
                $orders[$id]['note'] = $comment;
            }

            $noteResult->MoveNext();
        }
    }

    if ($orderId > 0) {
        tack_connector_respond(200, $orders[$orderId]);
    }

    tack_connector_respond(200, array(
        'orders' => array_values($orders),
        'page'   => $page,
        'limit'  => $limit,
    ));
}

/**
 * POST /orders — place a quote-accepted order.
 *
 * Body (exactly what ZenCartService.createOrder sends):
 *   { customer: { email, firstName, lastName },
 *     lineItems: [ { productId, quantity, price? } ],
 *     currency, note }
 *
 * Zen Cart's own `order` class is checkout-session driven and cannot place an
 * order from arbitrary data, so this writes the four tables Zen Cart's checkout
 * writes — orders, orders_products, orders_total, orders_status_history — using
 * bindVars() for every non-integer value.
 *
 * `price` is honoured when supplied because it is the whole point of a quote:
 * the negotiated unit price. It is recorded as `final_price` with the catalog
 * price kept in `products_price`, which is exactly how Zen Cart records a
 * discounted line, so the discount stays visible in the merchant's admin.
 *
 * @param string $rawBody
 * @param int    $languageId
 */
function tack_connector_orders_post($rawBody, $languageId)
{
    global $db;

    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        tack_connector_fail(400, 'invalid_body', 'Body must be a JSON object.');
    }

    $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : array();
    $email = isset($customer['email']) ? trim((string) $customer['email']) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        tack_connector_fail(400, 'invalid_email', 'A valid customer.email is required.');
    }

    $firstName = isset($customer['firstName']) ? trim((string) $customer['firstName']) : '';
    $lastName = isset($customer['lastName']) ? trim((string) $customer['lastName']) : '';
    $firstName = $firstName !== '' ? $firstName : 'Guest';
    $lastName = $lastName !== '' ? $lastName : 'Buyer';

    $lineItems = isset($payload['lineItems']) && is_array($payload['lineItems']) ? $payload['lineItems'] : array();

    if (!$lineItems) {
        tack_connector_fail(400, 'no_line_items', 'At least one line item is required.');
    }

    $requested = array();

    foreach ($lineItems as $line) {
        if (!is_array($line)) {
            continue;
        }

        $productId = isset($line['productId']) ? (int) $line['productId'] : 0;
        $quantity = isset($line['quantity']) ? (float) $line['quantity'] : 0;

        if ($productId <= 0 || $quantity <= 0) {
            tack_connector_fail(400, 'invalid_line', 'Every line needs a positive productId and quantity.');
        }

        $price = isset($line['price']) && $line['price'] !== null ? (float) $line['price'] : null;

        if ($price !== null && $price < 0) {
            tack_connector_fail(400, 'invalid_line', 'A negative price is not accepted for product ' . $productId . '.');
        }

        if (isset($requested[$productId])) {
            $requested[$productId]['quantity'] += $quantity;
        } else {
            $requested[$productId] = array('quantity' => $quantity, 'price' => $price);
        }
    }

    if (!$requested) {
        tack_connector_fail(400, 'no_line_items', 'At least one line item is required.');
    }

    $idList = implode(',', array_map('intval', array_keys($requested)));

    $catalogResult = $db->Execute(
        "SELECT p.products_id, p.products_model, p.products_price, p.products_status,
                p.products_tax_class_id, pd.products_name
         FROM " . TABLE_PRODUCTS . " p
         LEFT JOIN " . TABLE_PRODUCTS_DESCRIPTION . " pd
           ON pd.products_id = p.products_id AND pd.language_id = " . (int) $languageId . "
         WHERE p.products_id IN (" . $idList . ")"
    );

    $catalog = array();

    while (!$catalogResult->EOF) {
        $catalog[(int) $catalogResult->fields['products_id']] = $catalogResult->fields;
        $catalogResult->MoveNext();
    }

    $missing = array_values(array_diff(array_keys($requested), array_keys($catalog)));

    if ($missing) {
        // Refuse the whole order rather than placing a partial one — a silently
        // short order is worse than a failed one.
        tack_connector_fail(400, 'unknown_product', 'No such product id(s): ' . implode(', ', $missing) . '.');
    }

    $disabled = array();

    foreach ($catalog as $productId => $row) {
        if ((int) $row['products_status'] !== 1) {
            $disabled[] = $productId;
        }
    }

    if ($disabled) {
        tack_connector_fail(409, 'product_disabled', 'Disabled in this store: product id(s) ' . implode(', ', $disabled) . '.');
    }

    $currency = tack_connector_currency(isset($payload['currency']) ? $payload['currency'] : '');

    if ($currency === null) {
        tack_connector_fail(
            400,
            'unknown_currency',
            'Currency "' . (string) (isset($payload['currency']) ? $payload['currency'] : '') . '" is not configured in this store.'
        );
    }

    $note = isset($payload['note']) ? (string) $payload['note'] : '';

    // Existing customer account when the email matches exactly, so the order
    // shows in that customer's history. No account is created: registering
    // customers from a background sync is a side effect the merchant did not
    // ask for. 0 = guest order, a first-class Zen Cart state.
    $customerSql = $db->bindVars(
        "SELECT customers_id FROM " . TABLE_CUSTOMERS . " WHERE LOWER(customers_email_address) = :email: LIMIT 1",
        ':email:',
        strtolower($email),
        'string'
    );
    $customerResult = $db->Execute($customerSql);
    $customerId = $customerResult->EOF ? 0 : (int) $customerResult->fields['customers_id'];

    $statusId = defined('DEFAULT_ORDERS_STATUS_ID') ? (int) DEFAULT_ORDERS_STATUS_ID : 1;
    if ($statusId < 1) {
        $statusId = 1;
    }

    // Totals are stored in the STORE's default currency, matching how Zen Cart
    // records every order; `currency_value` carries the rate into the buyer's
    // currency.
    $subTotal = 0.0;
    $lines = array();

    foreach ($requested as $productId => $line) {
        $row = $catalog[$productId];
        $catalogPrice = (float) $row['products_price'];
        $finalPrice = $line['price'] !== null ? (float) $line['price'] : $catalogPrice;
        $subTotal += $finalPrice * (float) $line['quantity'];

        $lines[] = array(
            'products_id'       => $productId,
            'products_model'    => (string) $row['products_model'],
            'products_name'     => (string) $row['products_name'],
            'products_price'    => $catalogPrice,
            'final_price'       => $finalPrice,
            'products_quantity' => (float) $line['quantity'],
        );
    }

    $insertOrder = "INSERT INTO " . TABLE_ORDERS . "
        (customers_id, customers_name, customers_email_address,
         delivery_name, billing_name,
         payment_method, payment_module_code, shipping_method, shipping_module_code,
         orders_status, date_purchased, last_modified,
         currency, currency_value, order_total, order_tax,
         ip_address, language_code)
        VALUES
        (" . (int) $customerId . ", :name:, :email:,
         :name:, :name:,
         :payment_method:, '', '', '',
         " . (int) $statusId . ", now(), now(),
         :currency:, :currency_value:, :order_total:, 0,
         :ip:, :language_code:)";

    $insertOrder = $db->bindVars($insertOrder, ':name:', $firstName . ' ' . $lastName, 'string');
    $insertOrder = $db->bindVars($insertOrder, ':email:', $email, 'string');
    $insertOrder = $db->bindVars($insertOrder, ':payment_method:', 'TackQuote', 'string');
    $insertOrder = $db->bindVars($insertOrder, ':currency:', $currency['code'], 'string');
    $insertOrder = $db->bindVars($insertOrder, ':currency_value:', $currency['value'], 'float');
    $insertOrder = $db->bindVars($insertOrder, ':order_total:', $subTotal, 'float');
    $insertOrder = $db->bindVars($insertOrder, ':ip:', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', 'string');
    $insertOrder = $db->bindVars(
        $insertOrder,
        ':language_code:',
        defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en',
        'string'
    );

    $db->Execute($insertOrder);

    $newOrderId = (int) $db->insert_ID();

    if ($newOrderId <= 0) {
        tack_connector_fail(500, 'order_not_created', 'Zen Cart did not return an order id.');
    }

    foreach ($lines as $line) {
        $insertLine = "INSERT INTO " . TABLE_ORDERS_PRODUCTS . "
            (orders_id, products_id, products_model, products_name,
             products_price, final_price, products_tax, products_quantity, products_prid)
            VALUES
            (" . (int) $newOrderId . ", " . (int) $line['products_id'] . ", :model:, :name:,
             :price:, :final_price:, 0, :quantity:, :prid:)";

        $insertLine = $db->bindVars($insertLine, ':model:', $line['products_model'], 'string');
        $insertLine = $db->bindVars($insertLine, ':name:', $line['products_name'], 'string');
        $insertLine = $db->bindVars($insertLine, ':price:', $line['products_price'], 'float');
        $insertLine = $db->bindVars($insertLine, ':final_price:', $line['final_price'], 'float');
        $insertLine = $db->bindVars($insertLine, ':quantity:', $line['products_quantity'], 'float');
        $insertLine = $db->bindVars($insertLine, ':prid:', (string) $line['products_id'], 'string');

        $db->Execute($insertLine);
    }

    // Zen Cart renders an order's money lines from orders_total, not from
    // orders.order_total, so an order without these rows shows a blank total in
    // admin. ot_subtotal / ot_total are core's own class names.
    $formatted = number_format($subTotal * $currency['value'], 2, '.', ',') . ' ' . $currency['code'];

    foreach (array(
        array('title' => 'Sub-Total:', 'class' => 'ot_subtotal', 'sort_order' => 1),
        array('title' => 'Total:', 'class' => 'ot_total', 'sort_order' => 999),
    ) as $totalRow) {
        $insertTotal = "INSERT INTO " . TABLE_ORDERS_TOTAL . "
            (orders_id, title, text, value, class, sort_order)
            VALUES (" . (int) $newOrderId . ", :title:, :text:, :value:, :class:, " . (int) $totalRow['sort_order'] . ")";

        $insertTotal = $db->bindVars($insertTotal, ':title:', $totalRow['title'], 'string');
        $insertTotal = $db->bindVars($insertTotal, ':text:', $formatted, 'string');
        $insertTotal = $db->bindVars($insertTotal, ':value:', $subTotal, 'float');
        $insertTotal = $db->bindVars($insertTotal, ':class:', $totalRow['class'], 'string');

        $db->Execute($insertTotal);
    }

    $comment = trim('Created from TackQuote. ' . $note);

    $insertHistory = "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . "
        (orders_id, orders_status_id, date_added, customer_notified, comments, updated_by)
        VALUES (" . (int) $newOrderId . ", " . (int) $statusId . ", now(), 0, :comments:, :updated_by:)";

    $insertHistory = $db->bindVars($insertHistory, ':comments:', $comment, 'string');
    $insertHistory = $db->bindVars($insertHistory, ':updated_by:', 'TackQuote connector', 'string');

    $db->Execute($insertHistory);

    $statusNameSql = "SELECT orders_status_name FROM " . TABLE_ORDERS_STATUS . "
        WHERE orders_status_id = " . (int) $statusId . " AND language_id = " . (int) $languageId . " LIMIT 1";
    $statusNameResult = $db->Execute($statusNameSql);
    $statusName = $statusNameResult->EOF ? '' : (string) $statusNameResult->fields['orders_status_name'];

    tack_connector_respond(201, array(
        'id'          => $newOrderId,
        'orderNumber' => (string) $newOrderId,
        'status'      => $statusName,
        'total'       => number_format($subTotal * $currency['value'], 4, '.', ''),
        'currency'    => $currency['code'],
        'orderedAt'   => date('c'),
        'note'        => $comment,
        'lineItems'   => array_map(static function ($line) use ($currency) {
            return array(
                'productId' => $line['products_id'],
                'name'      => $line['products_name'],
                'sku'       => $line['products_model'],
                'quantity'  => $line['products_quantity'],
                'price'     => round($line['final_price'] * $currency['value'], 4),
            );
        }, $lines),
    ));
}
