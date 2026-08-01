<?php
/**
 * Thin HTTP client for the TackQuote API, mirroring the WordPress plugin's
 * Tack_Api_Client (includes/class-tack-api-client.php).
 *
 * IMPORTANT — inbound endpoint gap (see README.md "What's real vs. what's a gap"):
 * As of this module's initial version, apps/api ships an API-key-authenticated
 * plugin controller only for WooCommerce
 * (apps/api/src/modules/integrations/woocommerce/woocommerce-plugin.controller.ts,
 * routes under /v1/integrations/woocommerce/*). There is no Magento-specific
 * equivalent yet. Those two routes are payload-generic (buyerEmail/note/
 * lineItems for quote-requests; {ok,tenantId} for ping) and this client reuses
 * them as a stand-in so quote-request creation genuinely works end-to-end
 * today. Quotes created this way are tagged server-side using the `source`
 * field this client sends (WooCommerceService::createQuoteFromPluginRequest
 * reads `payload.source`, defaulting to 'woocommerce' when absent), so a
 * Magento-originated quote is correctly tagged ['magento','plugin-request']
 * rather than ['woocommerce','plugin-request']. A dedicated
 * /v1/integrations/magento/quote-requests endpoint still doesn't exist — see
 * the README for that remaining gap.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;

class Client
{
    /**
     * Reused WooCommerce-plugin inbound route — see class docblock.
     */
    private const PATH_PING = '/integrations/woocommerce/ping';
    private const PATH_QUOTE_REQUESTS = '/integrations/woocommerce/quote-requests';

    private const TIMEOUT_SECONDS = 20;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        Curl $curl,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Lightweight connectivity + API-key check.
     *
     * @param int|null $storeId
     * @return array{ok: bool, message?: string}
     */
    public function testConnection(?int $storeId = null): array
    {
        $result = $this->request('GET', self::PATH_PING, null, $storeId);
        if ($result['ok']) {
            return ['ok' => true];
        }

        return ['ok' => false, 'message' => $result['message'] ?? 'Could not reach TackQuote.'];
    }

    /**
     * Create a quote request from a product page or (future) cart submission.
     *
     * @param array $payload {buyerEmail, note, source, lineItems:[{sku,name,quantity,unitPrice,externalProductId}]}
     * @param int|null $storeId
     * @return array{ok: bool, id?: string, quoteNumber?: string, portalUrl?: string, message?: string}
     */
    public function createQuoteRequest(array $payload, ?int $storeId = null): array
    {
        $result = $this->request('POST', self::PATH_QUOTE_REQUESTS, $payload, $storeId);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Could not create the quote. Please try again.'];
        }

        $data = $result['data'] ?? [];

        return [
            'ok' => true,
            'id' => isset($data['id']) ? (string) $data['id'] : null,
            'quoteNumber' => isset($data['quoteNumber']) ? (string) $data['quoteNumber'] : null,
            'portalUrl' => isset($data['portalUrl']) ? (string) $data['portalUrl'] : null,
        ];
    }

    /**
     * @param string $method
     * @param string $path Path beginning with '/'.
     * @param array|null $body
     * @param int|null $storeId
     * @return array{ok: bool, data?: array, message?: string}
     */
    private function request(string $method, string $path, ?array $body, ?int $storeId): array
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'No TackQuote API key configured.'];
        }

        $url = $this->config->getApiBaseUrl($storeId) . $path;

        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        $this->curl->addHeader('X-Api-Key', $apiKey);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('User-Agent', 'TackQuote-Magento2/1.0.0');

        try {
            if ($method === 'GET') {
                $this->curl->get($url);
            } else {
                $this->curl->post($url, $body !== null ? $this->json->serialize($body) : '');
            }
        } catch (\Exception $e) {
            $this->logger->error('TackQuote API request failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not reach the TackQuote API.'];
        }

        $status = (int) $this->curl->getStatus();
        $rawBody = (string) $this->curl->getBody();
        $decoded = [];
        if ($rawBody !== '') {
            try {
                $decoded = $this->json->unserialize($rawBody);
            } catch (\Exception $e) {
                $decoded = [];
            }
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (is_array($decoded['message']) ? implode(', ', $decoded['message']) : (string) $decoded['message'])
                : sprintf('TackQuote API returned HTTP %d.', $status);
            $this->logger->warning('TackQuote API error ' . $status . ': ' . $rawBody);

            return ['ok' => false, 'message' => $message];
        }

        return ['ok' => true, 'data' => is_array($decoded) ? $decoded : []];
    }
}
