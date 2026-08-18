<?php
/**
 * TackQuote for OpenCart — order history feed + quote-to-order create.
 *
 * Routes:
 *   GET  index.php?route=extension/tack/api/order.list&page=1&limit=50
 *   POST index.php?route=extension/tack/api/order.add
 *
 * Store side of the contract in
 * `apps/api/src/modules/integrations/opencart/opencart.service.ts`
 * (`syncOrdersInbound()` and `createOrder()`). See api/product.php for the
 * verified route-resolution rules and the vendor-doc citations; the same apply
 * here (`order.list` => class Order, method `list`).
 *
 * DB access follows core's own idiom — `(int)` casts for integers,
 * `$this->db->escape()` for strings — because that is the only authority there
 * is: OpenCart's Coding Standard page contains no SQL guidance whatsoever
 * (<https://docs.opencart.com/developer-guide/coding-standard>, read in full)
 * and `$this->db` exposes no prepared-statement API. Order WRITES go through
 * core's `checkout/order` model, which escapes every field itself.
 */

namespace Opencart\Catalog\Controller\Extension\Tack\Api;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Extension\Tack\ApiGuard;

class Order extends Controller
{
    /**
     * GET …&route=extension/tack/api/order.list&page=&limit=
     *
     * Response (exactly what OpenCartService.syncOrdersInbound reads):
     *   { "orders": [ { order_id, order_status, total, currency_code,
     *                   date_added, comment,
     *                   products: [ { product_id, name, model, quantity, price } ] } ],
     *     "total": n, "page": n, "limit": n }
     *
     * Ordered by `order_id` ASC. Ascending is deliberate: TackQuote walks pages
     * until one yields no order id it has not already seen, and with DESC every
     * order placed mid-walk would shift the window and hide a row. Appends
     * cannot disturb an ascending walk.
     */
    public function list(): void
    {
        if (!$this->authorize()) {
            return;
        }

        $paging = ApiGuard::paging($this->request->get, ApiGuard::DEFAULT_ORDER_LIMIT);
        $limit = $paging['limit'] > 0 ? $paging['limit'] : ApiGuard::DEFAULT_ORDER_LIMIT;

        $storeId = (int) $this->config->get('config_store_id');
        $languageId = (int) $this->config->get('config_language_id');

        // `order_status_id` = 0 is OpenCart's "missing order" state: a cart that
        // reached the confirm step and was never paid for. Those are not orders
        // and must not be imported as revenue — OpenCart's own admin order list
        // hides them behind a separate "Missing Orders" filter.
        $where = " FROM `" . DB_PREFIX . "order` `o`"
            . " WHERE `o`.`order_status_id` > 0 AND `o`.`store_id` = '" . $storeId . "'";

        $totalQuery = $this->db->query("SELECT COUNT(*) AS `total`" . $where);
        $total = (int) ($totalQuery->row['total'] ?? 0);

        $query = $this->db->query(
            "SELECT `o`.`order_id`, `o`.`total`, `o`.`currency_code`, `o`.`currency_value`,"
            . " `o`.`date_added`, `o`.`comment`, `os`.`name` AS `order_status`"
            . " FROM `" . DB_PREFIX . "order` `o`"
            . " LEFT JOIN `" . DB_PREFIX . "order_status` `os`"
            . " ON (`os`.`order_status_id` = `o`.`order_status_id` AND `os`.`language_id` = '" . $languageId . "')"
            . " WHERE `o`.`order_status_id` > 0 AND `o`.`store_id` = '" . $storeId . "'"
            . " ORDER BY `o`.`order_id` ASC"
            . " LIMIT " . (int) (($paging['page'] - 1) * $limit) . "," . (int) $limit
        );

        $orders = [];
        $orderIds = [];
        $rates = [];

        foreach ($query->rows as $row) {
            $orderId = (int) $row['order_id'];
            $orderIds[] = $orderId;

            // OpenCart stores `total` in the STORE's default currency and
            // `currency_value` as the rate into the currency the buyer actually
            // paid in; its own admin formats with
            // $this->currency->format($total, $currency_code, $currency_value),
            // which multiplies. Reporting the raw total next to `currency_code`
            // would label a EUR order with a USD amount.
            $rate = (float) $row['currency_value'];
            if ($rate <= 0) {
                $rate = 1.0;
            }
            $rates[$orderId] = $rate;

            $orders[$orderId] = [
                'order_id'      => $orderId,
                'order_status'  => (string) ($row['order_status'] ?? ''),
                'total'         => number_format((float) $row['total'] * $rate, 4, '.', ''),
                'currency_code' => (string) $row['currency_code'],
                // ISO-8601 with offset. The column is a MySQL DATETIME in the
                // server's local zone; handing "2026-01-02 03:04:05" to JS
                // Date() is parsed as local-to-the-reader, which shifts the
                // order date by the difference between the two machines.
                'date_added'    => $this->toIso8601((string) $row['date_added']),
                'comment'       => (string) $row['comment'],
                'products'      => [],
            ];
        }

        if ($orderIds) {
            // One query for the page's line items rather than N. Every id is an
            // (int) cast taken from the rows just read, so the IN list carries
            // no request-supplied string.
            $productQuery = $this->db->query(
                "SELECT `order_id`, `product_id`, `name`, `model`, `quantity`, `price`"
                . " FROM `" . DB_PREFIX . "order_product`"
                . " WHERE `order_id` IN (" . implode(',', array_map('intval', $orderIds)) . ")"
                . " ORDER BY `order_product_id` ASC"
            );

            foreach ($productQuery->rows as $row) {
                $orderId = (int) $row['order_id'];

                if (!isset($orders[$orderId])) {
                    continue;
                }

                $rate = $rates[$orderId] ?? 1.0;

                $orders[$orderId]['products'][] = [
                    'product_id' => (int) $row['product_id'],
                    'name'       => (string) $row['name'],
                    'model'      => (string) $row['model'],
                    'quantity'   => (int) $row['quantity'],
                    'price'      => number_format((float) $row['price'] * $rate, 4, '.', ''),
                ];
            }
        }

        ApiGuard::json($this->response, $this->request->server, 200, 'OK', [
            'orders' => array_values($orders),
            'total'  => $total,
            'page'   => $paging['page'],
            'limit'  => $limit,
        ]);
    }

    /**
     * POST …&route=extension/tack/api/order.add
     *
     * Body (application/x-www-form-urlencoded, exactly what
     * OpenCartService.createOrder sends):
     *   firstname, lastname, email, comment, currency_code,
     *   products[i][product_id], products[i][quantity]
     *
     * The order is written through OpenCart's OWN `checkout/order` model
     * (addOrder + addHistory), not by hand-rolled INSERTs: that model escapes
     * every field itself, writes order_product/order_total consistently, and
     * addHistory() is what performs stock subtraction and status history the
     * way the rest of OpenCart expects.
     *   https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/model/checkout/order.php
     *
     * Prices are ALWAYS taken from the store's own product rows. The request
     * carries no prices and none would be honoured if it did — a store-side
     * endpoint that let the caller name its own price would be a discount
     * oracle for anyone who obtained the token.
     */
    public function add(): void
    {
        if (!$this->authorize()) {
            return;
        }

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->fail(405, 'Method Not Allowed', 'method_not_allowed', 'order.add requires POST.');

            return;
        }

        $email = trim((string) ($this->request->post['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail(400, 'Bad Request', 'invalid_email', 'A valid buyer email is required.');

            return;
        }

        $posted = $this->request->post['products'] ?? [];

        if (!is_array($posted) || !$posted) {
            $this->fail(400, 'Bad Request', 'no_products', 'At least one product is required.');

            return;
        }

        $lines = [];

        foreach ($posted as $line) {
            if (!is_array($line)) {
                continue;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                $this->fail(
                    400,
                    'Bad Request',
                    'invalid_line',
                    'Every line needs a positive product_id and quantity.'
                );

                return;
            }

            // Merge duplicate lines rather than writing the same product twice.
            $lines[$productId] = ($lines[$productId] ?? 0) + $quantity;
        }

        if (!$lines) {
            $this->fail(400, 'Bad Request', 'no_products', 'At least one product is required.');

            return;
        }

        $storeId = (int) $this->config->get('config_store_id');
        $languageId = (int) $this->config->get('config_language_id');

        $productQuery = $this->db->query(
            "SELECT `p`.`product_id`, `p`.`model`, `p`.`price`, `p`.`status`, `pd`.`name`"
            . " FROM `" . DB_PREFIX . "product_to_store` `p2s`"
            . " LEFT JOIN `" . DB_PREFIX . "product` `p` ON (`p`.`product_id` = `p2s`.`product_id`)"
            . " LEFT JOIN `" . DB_PREFIX . "product_description` `pd`"
            . " ON (`pd`.`product_id` = `p`.`product_id` AND `pd`.`language_id` = '" . $languageId . "')"
            . " WHERE `p2s`.`store_id` = '" . $storeId . "'"
            . " AND `p`.`product_id` IN (" . implode(',', array_map('intval', array_keys($lines))) . ")"
        );

        $catalog = [];

        foreach ($productQuery->rows as $row) {
            $catalog[(int) $row['product_id']] = $row;
        }

        $missing = array_values(array_diff(array_keys($lines), array_keys($catalog)));

        if ($missing) {
            // Refuse the whole order rather than placing a partial one. A
            // silently short order is the defect that made the nopCommerce
            // connector under-ship for every tenant (see W9-4).
            $this->fail(
                400,
                'Bad Request',
                'unknown_product',
                'Not sold in this store: product id(s) ' . implode(', ', $missing) . '.'
            );

            return;
        }

        $disabled = [];

        foreach ($catalog as $productId => $row) {
            if ((int) $row['status'] !== 1) {
                $disabled[] = $productId;
            }
        }

        if ($disabled) {
            $this->fail(
                409,
                'Conflict',
                'product_disabled',
                'Disabled in this store: product id(s) ' . implode(', ', $disabled) . '.'
            );

            return;
        }

        $currency = $this->resolveCurrency((string) ($this->request->post['currency_code'] ?? ''));

        if ($currency === null) {
            $this->fail(
                400,
                'Bad Request',
                'unknown_currency',
                'Currency "' . (string) ($this->request->post['currency_code'] ?? '') . '" is not enabled in this store.'
            );

            return;
        }

        $products = [];
        $subTotal = 0.0;

        foreach ($lines as $productId => $quantity) {
            $row = $catalog[$productId];
            $price = (float) $row['price'];
            $lineTotal = $price * $quantity;
            $subTotal += $lineTotal;

            $products[] = [
                'product_id'   => $productId,
                'master_id'    => 0,
                'name'         => (string) ($row['name'] ?? ('Product ' . $productId)),
                'model'        => (string) $row['model'],
                'quantity'     => $quantity,
                'price'        => $price,
                'total'        => $lineTotal,
                // No tax: this endpoint has no shipping/payment address to
                // resolve a tax zone from, so OpenCart's tax rules cannot be
                // applied honestly. TackQuote is the system of record for tax
                // on a quote. Recorded here rather than guessed.
                'tax'          => 0,
                'reward'       => 0,
                'option'       => [],
                'subscription' => [],
            ];
        }

        $comment = html_entity_decode(
            (string) ($this->request->post['comment'] ?? ''),
            ENT_COMPAT,
            'UTF-8'
        );

        $order = $this->buildOrderData($email, $comment, $currency, $products, $subTotal);

        $this->load->model('checkout/order');

        $orderId = (int) $this->model_checkout_order->addOrder($order);

        if ($orderId <= 0) {
            $this->fail(500, 'Internal Server Error', 'order_not_created', 'OpenCart did not return an order id.');

            return;
        }

        // Move the order out of the "missing order" state (0) into the store's
        // configured default order status, so it appears in the merchant's
        // normal order list. addHistory() with $notify = false does not email
        // the buyer — TackQuote owns that conversation.
        $statusId = (int) $this->config->get('config_order_status_id');

        if ($statusId > 0) {
            $this->model_checkout_order->addHistory($orderId, $statusId, $comment, false);
        }

        $statusQuery = $this->db->query(
            "SELECT `name` FROM `" . DB_PREFIX . "order_status`"
            . " WHERE `order_status_id` = '" . $statusId . "' AND `language_id` = '" . $languageId . "'"
        );

        $rate = (float) $currency['value'] > 0 ? (float) $currency['value'] : 1.0;

        ApiGuard::json($this->response, $this->request->server, 201, 'Created', [
            'order_id'      => $orderId,
            'order_status'  => (string) ($statusQuery->row['name'] ?? ''),
            'total'         => number_format($subTotal * $rate, 4, '.', ''),
            'currency_code' => $currency['code'],
            'date_added'    => date('c'),
            'comment'       => $comment,
            'products'      => array_map(static function (array $product) use ($rate): array {
                return [
                    'product_id' => $product['product_id'],
                    'name'       => $product['name'],
                    'model'      => $product['model'],
                    'quantity'   => $product['quantity'],
                    'price'      => number_format((float) $product['price'] * $rate, 4, '.', ''),
                ];
            }, $products),
        ]);
    }

    /**
     * Every key `Order::addOrder()` reads without an isset() guard is set here,
     * so the insert cannot depend on PHP's undefined-index coercion. Address
     * fields are intentionally blank: TackQuote sends none, and inventing one
     * would produce an order that looks shippable when it is not.
     *
     * @param array{currency_id: int, code: string, value: float} $currency
     * @param array<int, array<string, mixed>>                    $products
     *
     * @return array<string, mixed>
     */
    private function buildOrderData(
        string $email,
        string $comment,
        array $currency,
        array $products,
        float $subTotal
    ): array {
        $name = $this->buyerName();
        $server = $this->request->server;

        return [
            'invoice_prefix'          => (string) $this->config->get('config_invoice_prefix'),
            'store_id'                => (int) $this->config->get('config_store_id'),
            'store_name'              => (string) $this->config->get('config_name'),
            'store_url'               => (string) ($this->config->get('config_url') ?: (defined('HTTP_SERVER') ? HTTP_SERVER : '')),
            'customer_id'             => $this->resolveCustomerId($email),
            'customer_group_id'       => (int) $this->config->get('config_customer_group_id'),
            'firstname'               => $name['firstname'],
            'lastname'                => $name['lastname'],
            'email'                   => $email,
            'telephone'               => '',
            'custom_field'            => [],
            'payment_address_id'      => 0,
            'payment_firstname'       => $name['firstname'],
            'payment_lastname'        => $name['lastname'],
            'payment_company'         => '',
            'payment_address_1'       => '',
            'payment_address_2'       => '',
            'payment_city'            => '',
            'payment_postcode'        => '',
            'payment_country'         => '',
            'payment_country_id'      => 0,
            'payment_zone'            => '',
            'payment_zone_id'         => 0,
            'payment_address_format'  => '',
            'payment_custom_field'    => [],
            'payment_method'          => [],
            'shipping_address_id'     => 0,
            'shipping_firstname'      => $name['firstname'],
            'shipping_lastname'       => $name['lastname'],
            'shipping_company'        => '',
            'shipping_address_1'      => '',
            'shipping_address_2'      => '',
            'shipping_city'           => '',
            'shipping_postcode'       => '',
            'shipping_country'        => '',
            'shipping_country_id'     => 0,
            'shipping_zone'           => '',
            'shipping_zone_id'        => 0,
            'shipping_address_format' => '',
            'shipping_custom_field'   => [],
            'shipping_method'         => [],
            'comment'                 => $comment,
            // Store default currency, matching how OpenCart stores every total.
            'total'                   => $subTotal,
            'affiliate_id'            => 0,
            'commission'              => 0,
            'marketing_id'            => 0,
            'tracking'                => '',
            'language_id'             => (int) $this->config->get('config_language_id'),
            'currency_id'             => $currency['currency_id'],
            'currency_code'           => $currency['code'],
            'currency_value'          => $currency['value'],
            'ip'                      => (string) ($server['REMOTE_ADDR'] ?? ''),
            'forwarded_ip'            => (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''),
            'user_agent'              => (string) ($server['HTTP_USER_AGENT'] ?? ''),
            'accept_language'         => (string) ($server['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            'products'                => $products,
            'vouchers'                => [],
            'totals'                  => [
                [
                    'extension'  => 'opencart',
                    'code'       => 'sub_total',
                    'title'      => 'Sub-Total',
                    'value'      => $subTotal,
                    'sort_order' => 1,
                ],
                [
                    'extension'  => 'opencart',
                    'code'       => 'total',
                    'title'      => 'Total',
                    'value'      => $subTotal,
                    'sort_order' => 9,
                ],
            ],
        ];
    }

    /** @return array{firstname: string, lastname: string} */
    private function buyerName(): array
    {
        $first = trim((string) ($this->request->post['firstname'] ?? ''));
        $last = trim((string) ($this->request->post['lastname'] ?? ''));

        return [
            'firstname' => $first !== '' ? $first : 'Guest',
            'lastname'  => $last !== '' ? $last : 'Buyer',
        ];
    }

    /**
     * Attach the order to an existing customer account when the email matches
     * one exactly, so it shows up in that customer's order history. No account
     * is created: silently registering customers from a background sync is a
     * side effect a merchant did not ask for. 0 = guest order, which is a
     * first-class state in OpenCart.
     */
    private function resolveCustomerId(string $email): int
    {
        $query = $this->db->query(
            "SELECT `customer_id` FROM `" . DB_PREFIX . "customer`"
            . " WHERE LOWER(`email`) = '" . $this->db->escape(strtolower($email)) . "'"
            . " AND `store_id` = '" . (int) $this->config->get('config_store_id') . "'"
            . " LIMIT 1"
        );

        return (int) ($query->row['customer_id'] ?? 0);
    }

    /**
     * Resolve the requested currency against the store's own enabled
     * currencies. An unknown or disabled code is refused rather than defaulted:
     * booking a EUR quote as USD at rate 1.0 misstates the order value.
     *
     * @return array{currency_id: int, code: string, value: float}|null
     */
    private function resolveCurrency(string $code): ?array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            $code = strtoupper((string) $this->config->get('config_currency'));
        }

        if ($code === '') {
            return null;
        }

        $query = $this->db->query(
            "SELECT `currency_id`, `code`, `value` FROM `" . DB_PREFIX . "currency`"
            . " WHERE `code` = '" . $this->db->escape($code) . "' AND `status` = '1' LIMIT 1"
        );

        if (!$query->row) {
            return null;
        }

        return [
            'currency_id' => (int) $query->row['currency_id'],
            'code'        => (string) $query->row['code'],
            'value'       => (float) $query->row['value'],
        ];
    }

    /** MySQL DATETIME -> ISO-8601 with the server's offset; '' when unparseable. */
    private function toIso8601(string $dateTime): string
    {
        if ($dateTime === '' || strpos($dateTime, '0000-00-00') === 0) {
            return '';
        }

        $timestamp = strtotime($dateTime);

        return $timestamp !== false ? date('c', $timestamp) : '';
    }

    private function authorize(): bool
    {
        $check = ApiGuard::check(
            $this->request->server,
            (string) $this->config->get('module_tackquotes_connector_token')
        );

        if ($check['ok']) {
            return true;
        }

        $this->fail($check['status'], $check['reason'], $check['code'], $check['error']);

        return false;
    }

    private function fail(int $status, string $reason, string $code, string $message): void
    {
        ApiGuard::json($this->response, $this->request->server, $status, $reason, [
            'error' => $message,
            'code'  => $code,
        ]);
    }
}
