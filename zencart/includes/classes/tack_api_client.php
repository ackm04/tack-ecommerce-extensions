<?php
/**
 * Thin HTTP client for the TackQuote API, using cURL.
 *
 * Mirrors TackApiClient in the TackQuote PrestaShop module
 * (integrations/prestashop/modules/tackquotes/classes/TackApiClient.php) and
 * Tack_Api_Client in the WooCommerce plugin: same headers, same auth scheme
 * (Bearer + X-Api-Key), same JSON contract.
 *
 * Zen Cart ships no class autoloader guarantee across every install/version,
 * so this file is a plain, dependency-free PHP class deliberately included
 * with a direct `require_once` (see ajax_tack_quote_request.php) rather than
 * relying on Zen Cart's auto_loaders mechanism.
 */

if (!defined('DIR_WS_INCLUDES') && !defined('TACK_QUOTE_STANDALONE_TEST')) {
    // Loaded outside of a Zen Cart bootstrap — refuse to run silently.
    exit('TackApiClient must be loaded after includes/application_top.php.');
}

class TackApiClient
{
    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $apiKey;

    /**
     * @param string $baseUrl e.g. https://api.tackquote.com/v1 (no trailing slash).
     * @param string $apiKey  Tenant's TackQuote API key.
     */
    public function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = (string) $apiKey;
    }

    /**
     * Perform an authenticated request against the TackQuote API.
     *
     * @param string     $method HTTP method.
     * @param string     $path   Path beginning with '/'.
     * @param array|null $body   Optional JSON-encodable body.
     *
     * @return array|string Decoded response array on 2xx, or an error message string.
     */
    public function request($method, $path, $body = null)
    {
        if ($this->apiKey === '') {
            return 'No TackQuote API key configured.';
        }
        if (!function_exists('curl_init')) {
            return 'PHP cURL extension is not available on this server.';
        }

        $url = $this->baseUrl . $path;

        $headers = array(
            'Authorization: Bearer ' . $this->apiKey,
            'X-Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TackQuote-ZenCart/1.0.0',
        );

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

        return is_array($data) ? $data : array();
    }

    /**
     * Lightweight connectivity check against the Zen Cart plugin endpoints.
     *
     * @return true|string True on success, or an error message string.
     */
    public function testConnection()
    {
        $result = $this->request('GET', '/integrations/zencart/ping');
        if (is_string($result)) {
            // Fall back to the generic health check so a store owner still gets
            // a useful signal if /integrations/zencart/ping isn't reachable
            // (e.g. an older API deployment behind a cache/proxy).
            $result = $this->request('GET', '/health');
        }

        return is_string($result) ? $result : true;
    }

    /**
     * Create a quote request from a product page.
     *
     * Calls POST /integrations/zencart/quote-requests — see
     * apps/api/src/modules/integrations/zencart/zencart-plugin.controller.ts.
     *
     * @param array $payload {buyerEmail, note, source, lineItems:[{sku,name,quantity,unitPrice,externalProductId}]}.
     *
     * @return array|string Decoded response (includes id/quoteNumber/portalUrl), or an error message.
     */
    public function createQuoteRequest($payload)
    {
        return $this->request('POST', '/integrations/zencart/quote-requests', $payload);
    }
}
