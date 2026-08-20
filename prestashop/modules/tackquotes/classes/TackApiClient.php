<?php

/**
 * Thin HTTP client for the Tack (TackQuote) API, using cURL.
 *
 * Mirrors Tack_Api_Client in the TackQuote WooCommerce plugin
 * (integrations/wordpress/tack-quotes/includes/class-tack-api-client.php):
 * same headers, same auth scheme (Bearer + X-Api-Key), same JSON contract.
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
     * @param string $apiKey tenant's TackQuote API key
     */
    public function __construct($baseUrl, $apiKey)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = (string) $apiKey;
    }

    /**
     * Perform an authenticated request against the Tack API.
     *
     * @param string $method HTTP method
     * @param string $path path beginning with '/'
     * @param array|null $body optional JSON-encodable body
     *
     * @return array|string decoded response array on 2xx, or an error message string
     */
    public function request($method, $path, $body = null)
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
            'User-Agent: TackQuotes-PrestaShop/1.0.0',
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
     * Lightweight connectivity check.
     *
     * @return true|string true on success, or an error message string
     */
    public function testConnection()
    {
        // `GET /integrations/prestashop/ping` DOES exist (PrestaShopPluginController) and
        // is API-key authenticated, so it is tried first: it proves the key is valid for
        // this tenant, which /health cannot. The /health fallback is kept only for a Tack
        // deployment older than that route. The note that used to sit here — "there is
        // currently no /integrations/prestashop/ping endpoint" — was stale.
        $result = $this->request('GET', '/integrations/prestashop/ping');
        if (is_string($result)) {
            $result = $this->request('GET', '/health');
        }

        return is_string($result) ? $result : true;
    }

    /**
     * Create a quote request from a product/cart.
     *
     * Calls POST /integrations/prestashop/quote-requests, which mirrors the
     * WooCommerce plugin's POST /integrations/woocommerce/quote-requests (see
     * class-tack-api-client.php::create_quote_request()). That route EXISTS and
     * answers 201; the note that used to sit here saying it "does not exist on
     * the Tack API yet" was stale.
     *
     * The identity fields are OPTIONAL and must be OMITTED rather than sent
     * empty: Tack treats a blank as "the shopper supplied nothing" and leaves
     * the buyer's name columns NULL, which is the honest record. It no longer
     * invents a first name from the email local part when they are absent.
     *
     * @param array $payload {buyerEmail, note, source, currency?, firstName?, lastName?,
     *                        phone?, companyName?,
     *                        lineItems:[{sku,name,quantity,unitPrice,externalProductId}]}
     *
     * @return array|string decoded response (expected to include
     *                      id/quoteNumber/portalUrl/company/awaitingApproval), or an
     *                      error message
     */
    public function createQuoteRequest($payload)
    {
        return $this->request('POST', '/integrations/prestashop/quote-requests', $payload);
    }
}
