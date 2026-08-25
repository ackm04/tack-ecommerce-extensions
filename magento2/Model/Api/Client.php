<?php
/**
 * Thin HTTP client for the TackQuote API, mirroring the WordPress plugin's
 * Tack_Api_Client (includes/class-tack-api-client.php).
 *
 * It talks to TackQuote's own Magento plugin routes — MagentoPluginController in
 * apps/api/src/modules/integrations/magento/magento-plugin.controller.ts — which are
 * authenticated by the store's TackQuote API key rather than a seller JWT. That
 * controller is separate from MagentoController, which is JWT-guarded and drives the
 * opposite direction (Tack reading the store's catalog and creating orders).
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
     * Magento's own inbound routes. These replaced the WooCommerce routes this client
     * borrowed before `/v1/integrations/magento/*` existed. Unlike that stand-in, they
     * register the buyer AND their company through the same path the buyer portal uses
     * (PluginRegistrationService -> BuyersService::registerWithCompany), rather than
     * creating a bare buyer named after the local part of an email address.
     */
    private const PATH_PING = '/integrations/magento/ping';
    private const PATH_REGISTRATION_CONFIG = '/integrations/magento/registration-config';
    private const PATH_QUOTE_REQUESTS = '/integrations/magento/quote-requests';

    /**
     * Creating a quote is a WRITE the buyer is waiting on, and it cannot safely be cut
     * short: an aborted POST may already have created the quote server-side. It keeps the
     * generous budget.
     */
    private const WRITE_TIMEOUT_SECONDS = 20;

    /**
     * Reads get a far smaller budget, because a read is always recoverable — the caller
     * either has a cached copy or degrades gracefully. The registration-config read used
     * to share the 20-second write budget, which is how a hung TackQuote could hold a
     * storefront page open for twenty seconds on a cache miss.
     */
    private const READ_TIMEOUT_SECONDS = 3;

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
     * The seller's registration policy.
     *
     * Which of companies/individuals are allowed, which company details are required,
     * and the seller's own custom questions.
     *
     * @param int|null $storeId
     * @return array{ok: bool, data?: array, message?: string}
     */
    public function getRegistrationConfig(?int $storeId = null): array
    {
        return $this->request('GET', self::PATH_REGISTRATION_CONFIG, null, $storeId);
    }

    /**
     * Create a quote request from a product page or quote-list submission.
     *
     * Registers the buyer — and their company, where the seller's policy allows — in the
     * process.
     *
     * @param array $payload {buyerEmail, firstName, lastName, phone, companyName,
     *                        company:{...}, customFields:{...}, note, source,
     *                        lineItems:[{sku,name,quantity,unitPrice,externalProductId}]}
     * @param int|null $storeId
     * @param string $idempotencyKey Guards against a double-click or a retry creating
     *                               two identical quotes.
     * @return array{ok: bool, id?: string, quoteNumber?: string, portalUrl?: string,
     *               company?: array|null, awaitingApproval?: bool, message?: string}
     */
    public function createQuoteRequest(
        array $payload,
        ?int $storeId = null,
        string $idempotencyKey = ''
    ): array {
        $result = $this->request(
            'POST',
            self::PATH_QUOTE_REQUESTS,
            $payload,
            $storeId,
            $idempotencyKey
        );
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Could not create the quote. Please try again.'];
        }

        $data = $result['data'] ?? [];

        return [
            'ok' => true,
            'id' => isset($data['id']) ? (string) $data['id'] : null,
            'quoteNumber' => isset($data['quoteNumber']) ? (string) $data['quoteNumber'] : null,
            'portalUrl' => isset($data['portalUrl']) ? (string) $data['portalUrl'] : null,
            'company' => isset($data['company']) && is_array($data['company']) ? $data['company'] : null,
            'awaitingApproval' => !empty($data['awaitingApproval']),
        ];
    }

    /**
     * Perform one authenticated request against the TackQuote API.
     *
     * @param string $method
     * @param string $path Path beginning with '/'.
     * @param array|null $body
     * @param int|null $storeId
     * @param string $idempotencyKey
     * @return array{ok: bool, data?: array, message?: string}
     */
    private function request(
        string $method,
        string $path,
        ?array $body,
        ?int $storeId,
        string $idempotencyKey = ''
    ): array {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'No TackQuote API key configured.'];
        }

        $url = $this->config->getApiBaseUrl($storeId) . $path;

        /*
         * GET here is only ever the ping or the registration-config read, and POST is only
         * ever quote creation, so keying the budget off the verb needs no extra parameter
         * and cannot drift out of sync with the call sites.
         */
        $this->curl->setTimeout(
            $method === 'GET' ? self::READ_TIMEOUT_SECONDS : self::WRITE_TIMEOUT_SECONDS
        );
        $this->curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        $this->curl->addHeader('X-Api-Key', $apiKey);
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('User-Agent', 'TackQuote-Magento2/1.0.0');

        /*
         * Server-side duplicate suppression.
         *
         * This was suppressed for a while: TackQuote's idempotency layer wrote to
         * `api_idempotency_keys` — a FORCE-RLS table — without establishing a tenant
         * context, so any request carrying the header failed with "new row violates
         * row-level security policy" and returned 500. No plugin had ever sent the header,
         * so the defect had never surfaced. It is now fixed upstream and verified here:
         * the same key replays the original quote instead of creating a second one.
         *
         * Model\IdempotencyGuard is deliberately KEPT alongside this rather than replaced.
         * The two catch different things — the guard stops a double-click before it leaves
         * the store at all, while this catches retries the guard cannot see (a second
         * Magento node, or after a cache flush).
         */
        if ($idempotencyKey !== '') {
            $this->curl->addHeader('Idempotency-Key', $idempotencyKey);
        }

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
            /*
             * The PARSED message only — never $rawBody. TackQuote echoes the submitted
             * document back in a validation error, so logging the body wrote the buyer's
             * email address, company name and postal address into var/log/ on every 400.
             * The status plus the API's own message is everything an operator needs to
             * tell "wrong key" from "missing field" from "TackQuote is down".
             */
            $this->logger->warning(sprintf('TackQuote API error %d: %s', $status, $message));

            return ['ok' => false, 'message' => $message];
        }

        return ['ok' => true, 'data' => is_array($decoded) ? $decoded : []];
    }
}
