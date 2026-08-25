<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the TackQuote API on behalf of this Shopware store.
 *
 * Today only one inbound endpoint is generic/public enough for a storefront
 * plugin to call without a full API-key-authenticated integration:
 *
 *   POST {apiUrl}/widget/quote-request
 *   Body: { tenantSlug, firstName, lastName, email, company, phone, message, currency?, items[] }
 *
 * (see apps/api/src/modules/quotes/widget.controller.ts — @Public(), no auth,
 * resolves the tenant by `tenantSlug`). It is the same endpoint the generic
 * `tack-widget.js` snippet uses on any storefront, and it creates a real
 * draft Quote + Buyer in TackQuote.
 *
 * There is NOT yet a Shopware-specific, API-key-authenticated endpoint
 * analogous to `POST /integrations/woocommerce/quote-requests` (which tags
 * the quote as plugin-sourced, upserts catalog product references, and has a
 * matching `/integrations/woocommerce/order-sync` counterpart). The `apiKey`
 * config field on this plugin is captured for that future work but is not
 * sent anywhere yet. See this plugin's README for the exact gap.
 */
class TackQuoteApiClient
{
    private const CONFIG_DOMAIN = 'TackQuote.config.';

    /**
     * Last-resort fallback only. The authoritative default is the <defaultValue> on the
     * apiUrl field in Resources/config/config.xml, which Shopware applies on install;
     * this covers the case where the setting has been explicitly blanked. Kept as a
     * named constant so the duplication is visible — if you change one, change both.
     */
    private const DEFAULT_API_URL = 'https://api.tackquote.com/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfig,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getApiUrl(?string $salesChannelId = null): string
    {
        $url = (string) ($this->systemConfig->get(self::CONFIG_DOMAIN . 'apiUrl', $salesChannelId) ?? '');
        $url = $url !== '' ? $url : self::DEFAULT_API_URL;

        return rtrim($url, '/');
    }

    public function getTenantSlug(?string $salesChannelId = null): ?string
    {
        $slug = $this->systemConfig->get(self::CONFIG_DOMAIN . 'tenantSlug', $salesChannelId);

        return $slug !== null && $slug !== '' ? (string) $slug : null;
    }

    public function getApiKey(?string $salesChannelId = null): ?string
    {
        $key = $this->systemConfig->get(self::CONFIG_DOMAIN . 'apiKey', $salesChannelId);

        return $key !== null && $key !== '' ? (string) $key : null;
    }

    // NOTE: there is deliberately no isButtonEnabled()/getButtonLabel() here. Both
    // existed, were called from nowhere, and re-read `enableButton` / `buttonLabel` with
    // their own hardcoded PHP defaults — while the Twig template reads the same two
    // settings straight from config(). That is two sources of truth for one setting, and
    // the PHP copy would silently disagree the moment config.xml's default changed.

    /**
     * Verify the stored API key against TackQuote (GitHub #330).
     *
     * WHY THIS EXISTS: `getApiKey()` had no consumer. The plugin config asked the
     * merchant to paste a TackQuote API key and then never read it — a field that
     * looks like a working credential and is decoration. The storefront quote
     * button was never fake (it posts to the PUBLIC tenant-slug widget endpoint
     * and still does, unchanged), but the key itself did nothing.
     *
     * Hits `GET {apiUrl}/integrations/shopware/ping`, the API-key-authenticated
     * route added alongside this, which mirrors the proven OpenCart/Woo/Magento
     * plugin pings. Returns the resolved tenant id so the merchant can tell a key
     * for the WRONG account from a valid one — `ok: true` alone cannot.
     *
     * Returns a structured result rather than throwing: this is called from a
     * settings screen, where "could not reach" and "key rejected" are different
     * messages a merchant can act on, and an exception would surface as neither.
     *
     * @return array{ok: bool, tenantId?: string, error?: string}
     */
    public function ping(?string $salesChannelId = null): array
    {
        $apiKey = $this->getApiKey($salesChannelId);
        if ($apiKey === null) {
            return ['ok' => false, 'error' => 'No TackQuote API key is configured.'];
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->getApiUrl($salesChannelId) . '/integrations/shopware/ping',
                [
                    'headers' => [
                        'X-API-Key' => $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 10,
                ]
            );

            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                return ['ok' => false, 'error' => 'TackQuote rejected this API key.'];
            }
            if ($status >= 400) {
                // Deliberately does NOT echo the response body: it can carry
                // request context, and this string reaches the admin UI and logs.
                return ['ok' => false, 'error' => sprintf('TackQuote returned HTTP %d.', $status)];
            }

            $payload = $response->toArray(false);
            $tenantId = isset($payload['tenantId']) ? (string) $payload['tenantId'] : null;
            if (($payload['ok'] ?? false) !== true || $tenantId === null || $tenantId === '') {
                // A 200 without a tenant is not a working key. Refusing here keeps
                // this from becoming the "HTTP 200, nothing happened" shape.
                return ['ok' => false, 'error' => 'TackQuote responded without a tenant; the key may be invalid.'];
            }

            return ['ok' => true, 'tenantId' => $tenantId];
        } catch (TransportExceptionInterface $e) {
            return ['ok' => false, 'error' => 'Could not reach TackQuote. Check the API URL, DNS and TLS.'];
        }
    }

    public function isConfigured(?string $salesChannelId = null): bool
    {
        return $this->getTenantSlug($salesChannelId) !== null;
    }

    /**
     * Submits a quote request to TackQuote's public widget endpoint.
     *
     * @param array{firstName?: string, lastName?: string, email: string, company?: string, phone?: string} $buyer
     * @param array<int, array{name: string, sku?: string, quantity: int, unitPrice?: float, productUrl?: string}> $items
     * @param string|null $currency ISO 4217 alpha-3 code of the sales channel (e.g. "EUR").
     *                              Omitted from the payload when null/unrecognised, in which
     *                              case TackQuote falls back to the tenant's configured
     *                              currency and only then to USD.
     *
     * @return array<string, mixed>
     */
    public function submitQuoteRequest(
        array $buyer,
        array $items,
        string $message,
        ?string $salesChannelId = null,
        ?string $currency = null,
    ): array {
        $tenantSlug = $this->getTenantSlug($salesChannelId);
        if ($tenantSlug === null) {
            throw new \RuntimeException('TackQuote is not configured for this sales channel (missing tenant slug).');
        }
        if (empty($buyer['email'])) {
            throw new \InvalidArgumentException('Buyer email is required.');
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one line item is required.');
        }

        $payload = [
            'tenantSlug' => $tenantSlug,
            'firstName' => $buyer['firstName'] ?? '',
            'lastName' => $buyer['lastName'] ?? '',
            'email' => $buyer['email'],
            'company' => $buyer['company'] ?? '',
            'phone' => $buyer['phone'] ?? '',
            'message' => $message,
            'items' => $items,
        ];

        // Without this the quote was created in TackQuote's hardcoded USD regardless of what
        // this sales channel actually trades in. Send it only when it is a plausible ISO 4217
        // alpha-3 code, so a misconfigured store falls back to the API's default rather than
        // pushing junk into the quote's currency column.
        $currency = strtoupper(trim((string) $currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $payload['currency'] = $currency;
        }

        try {
            $response = $this->httpClient->request('POST', $this->getApiUrl($salesChannelId) . '/widget/quote-request', [
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 10,
            ]);

            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
            $data = is_array($data) ? $data : [];
        } catch (TransportExceptionInterface $e) {
            $this->logger?->error('TackQuote quote-request transport error', ['exception' => $e->getMessage()]);
            throw new \RuntimeException('Could not reach TackQuote. Please try again shortly.', 0, $e);
        }

        if ($status >= 400 || ($data['success'] ?? null) === false) {
            $this->logger?->warning('TackQuote quote-request rejected', ['status' => $status, 'response' => $data]);
            throw new \RuntimeException((string) ($data['message'] ?? 'TackQuote rejected the quote request.'));
        }

        return $data;
    }
}
