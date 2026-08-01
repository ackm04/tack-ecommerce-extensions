<?php
/**
 * Thin HTTP client for the Tack (TackQuote) API, using cURL.
 *
 * Mirrors Tack_Api_Client in the TackQuote WooCommerce plugin
 * (integrations/wordpress/tack-quotes/includes/class-tack-api-client.php):
 * same headers, same auth scheme (Bearer + X-Api-Key), same JSON contract.
 *
 * @package TackQuotes
 */

if (!defined('_PS_VERSION_')) {
    exit;
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
     * Perform an authenticated request against the Tack API.
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

        $url = $this->baseUrl . $path;

        $headers = array(
            'Authorization: Bearer ' . $this->apiKey,
            'X-Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TackQuotes-PrestaShop/1.0.0',
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
     * Lightweight connectivity check.
     *
     * @return true|string True on success, or an error message string.
     */
    public function testConnection()
    {
        // NOTE: unlike the WooCommerce plugin, there is currently no
        // /integrations/prestashop/ping (API-key authenticated) endpoint on the
        // Tack API. This falls back straight to the generic /health endpoint.
        // See README.md "What still needs a backend endpoint" for details.
        $result = $this->request('GET', '/integrations/prestashop/ping');
        if (is_string($result)) {
            $result = $this->request('GET', '/health');
        }

        return is_string($result) ? $result : true;
    }

    /**
     * Create a quote request from a product/cart.
     *
     * NOTE: this calls POST /integrations/prestashop/quote-requests, which
     * mirrors the WooCommerce plugin's POST /integrations/woocommerce/quote-requests
     * (see class-tack-api-client.php::create_quote_request()). As of this
     * writing that PrestaShop route does not exist on the Tack API yet — see
     * README.md for the exact backend change needed to make this call succeed.
     *
     * @param array $payload {buyerEmail, note, source, lineItems:[{sku,name,quantity,unitPrice,externalProductId}]}.
     *
     * @return array|string Decoded response (expected to include id/quoteNumber/portalUrl), or an error message.
     */
    public function createQuoteRequest($payload)
    {
        return $this->request('POST', '/integrations/prestashop/quote-requests', $payload);
    }
}
