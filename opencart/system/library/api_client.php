<?php
/**
 * Thin cURL-based HTTP client for the TackQuote API.
 *
 * Mirrors Tack_Api_Client from the TackQuote WooCommerce plugin
 * (integrations/wordpress/tack-quotes/includes/class-tack-api-client.php)
 * and TackApiClient from the PrestaShop module
 * (integrations/prestashop/modules/tackquotes/classes/TackApiClient.php):
 * same headers, same auth scheme (Bearer + X-Api-Key), same JSON contract.
 *
 * Autoloading (verified against OpenCart 4.0.2.3 source, not assumed):
 *   - `catalog/controller/startup/extension.php` registers
 *     `Opencart\System\Library\Extension\<Code>` => `extension/<code>/system/library/`
 *     for every installed extension, so the namespace below is the one OpenCart
 *     actually resolves for an extension-shipped library.
 *     https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/controller/startup/extension.php
 *   - `system/engine/autoloader.php` maps the remainder of the class name to a
 *     file with `strtolower(preg_replace('~([a-z])([A-Z]|[0-9])~', '\1_\2', …))`,
 *     i.e. `ApiClient` => `api_client.php` — NOT `apiclient.php`. This file was
 *     previously named `apiclient.php`, which that rule can never find.
 *     https://github.com/opencart/opencart/blob/4.0.2.3/upload/system/engine/autoloader.php
 *
 * OpenCart 3.x does not autoload this namespace at all; see README.md
 * "OpenCart 3.x" for the adaptation an OC3 install requires.
 */

namespace Opencart\System\Library\Extension\Tack;

class ApiClient
{
    /** @var string */
    protected string $baseUrl;

    /** @var string */
    protected string $apiKey;

    /**
     * @param string $baseUrl e.g. https://api.tackquote.com/v1 (no trailing slash).
     * @param string $apiKey  Tenant's TackQuote API key.
     */
    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Perform an authenticated request against the Tack API.
     *
     * @param string     $method HTTP method.
     * @param string     $path   Path beginning with '/'.
     * @param array|null $body   Optional JSON-encodable body.
     *
     * @return array|string Decoded response array on 2xx, or an error message string.
     */
    public function request(string $method, string $path, ?array $body = null)
    {
        if ($this->apiKey === '') {
            return 'No TackQuote API key configured.';
        }

        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'X-Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TackQuote-OpenCart/1.0.0',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return sprintf('Connection error: %s', $error);
        }

        $data = json_decode((string) $response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = is_array($data) && isset($data['message'])
                ? (is_array($data['message']) ? implode(', ', $data['message']) : $data['message'])
                : sprintf('TackQuote API returned HTTP %d.', $httpCode);

            return $message;
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Lightweight connectivity check against the real TackQuote OpenCart
     * plugin endpoint (`GET /integrations/opencart/ping`), authenticated by
     * the store's API key. Falls back to `/health` only if that route is
     * unreachable (e.g. an out-of-date Tack API deployment).
     *
     * @return true|string True on success, or an error message string.
     */
    public function testConnection()
    {
        $result = $this->request('GET', '/integrations/opencart/ping');
        if (is_string($result)) {
            $result = $this->request('GET', '/health');
        }

        return is_string($result) ? $result : true;
    }

    /**
     * Create a quote request from a product page.
     *
     * Calls `POST /integrations/opencart/quote-requests`
     * (`OpenCartPluginController::quoteRequest`), the same JSON contract used
     * by the WooCommerce/PrestaShop plugin endpoints:
     * `{ buyerEmail, note, source: "opencart", lineItems: [{ sku, name, quantity, unitPrice, externalProductId }] }`,
     * expecting back `{ id, quoteNumber, portalUrl }`.
     *
     * @param array $payload
     *
     * @return array|string Decoded response, or an error message.
     */
    public function createQuoteRequest(array $payload)
    {
        return $this->request('POST', '/integrations/opencart/quote-requests', $payload);
    }
}
