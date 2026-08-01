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
 *   Body: { tenantSlug, firstName, lastName, email, company, phone, message, items[] }
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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SystemConfigService $systemConfig,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getApiUrl(?string $salesChannelId = null): string
    {
        $url = (string) ($this->systemConfig->get(self::CONFIG_DOMAIN . 'apiUrl', $salesChannelId) ?? '');
        $url = $url !== '' ? $url : 'https://api.tackquote.com/v1';

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

    public function isButtonEnabled(?string $salesChannelId = null): bool
    {
        $value = $this->systemConfig->get(self::CONFIG_DOMAIN . 'enableButton', $salesChannelId);

        return $value === null ? true : (bool) $value;
    }

    public function getButtonLabel(?string $salesChannelId = null): string
    {
        $label = (string) ($this->systemConfig->get(self::CONFIG_DOMAIN . 'buttonLabel', $salesChannelId) ?? '');

        return $label !== '' ? $label : 'Request a Quote';
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
     *
     * @return array<string, mixed>
     */
    public function submitQuoteRequest(array $buyer, array $items, string $message, ?string $salesChannelId = null): array
    {
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
