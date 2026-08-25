<?php
/**
 * Fetches the seller's registration policy from TackQuote and caches it.
 *
 * The storefront form has to know what to render BEFORE anyone submits: whether
 * companies or individuals are allowed, which company details the seller requires, and
 * the seller's own custom questions. Hardcoding one shape in the template would make
 * every policy setting in TackQuote unenforceable in the Magento UI.
 *
 * CACHING — this response is visitor-independent: it depends only on the tenant's
 * settings, not on who is browsing. That makes it safe to render into full-page-cached
 * HTML, unlike the form key, which is per-session and must never be baked into a cached
 * page (see the FPC defect fixed in button.phtml).
 *
 * NOT IN THE RENDER PATH — read getCached() from anything a shopper is waiting on.
 * Block\QuoteList renders in `before.body.end` on EVERY storefront page, so when it used
 * get() a cold cache put an outbound HTTP call inside a shopper's page render: steady
 * state was fine, but every deploy, cache flush and newly added store view made the next
 * shopper wait for TackQuote to answer. Cron\WarmRegistrationConfig now does the fetching
 * on a schedule, and the block only ever reads what is already there. The window in which
 * a flushed cache has not yet been re-warmed is bounded by the cron interval; during it
 * the form degrades exactly as it does during an outage (see below).
 *
 * FAILURE MODE — a TackQuote outage must not take the quote button down with it. With no
 * policy the form degrades to contact fields only, which still produces a usable quote
 * request, and TackQuote re-checks the policy server-side on submit. A failed refresh
 * therefore never discards a policy that is already cached: better to render last-known
 * -good for up to CACHE_TTL than to strip the company step off a working storefront
 * because one cron run could not reach the API.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;

class RegistrationConfigProvider
{
    private const CACHE_KEY_PREFIX = 'tackquote_registration_config_';
    private const CACHE_TAG = 'TACKQUOTE_CONFIG';

    /**
     * Deliberately LONGER than the cron interval that refreshes it, so a running store
     * never has an expiry gap: the entry is rewritten every cron tick and only actually
     * lapses if cron itself has stopped for an hour. It is not the staleness bound — the
     * cron interval is.
     */
    private const CACHE_TTL = 3600;

    /**
     * Marker stored when a fetch fails AND nothing is cached yet, so a broken or
     * unreachable TackQuote is not re-requested on every single product-page render by the
     * on-demand get() path.
     */
    private const FAILURE_MARKER = '__tackquote_unavailable__';
    private const FAILURE_TTL = 60;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Client $client
     * @param CacheInterface $cache
     * @param Json $json
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        Client $client,
        CacheInterface $cache,
        Json $json,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->json = $json;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * The seller's registration policy, from cache where possible, fetching on a miss.
     *
     * For callers that may block: cron, CLI and admin screens. Storefront rendering must
     * use getCached() instead.
     *
     * @param int|null $storeId
     * @return array<string, mixed>|null Null when TackQuote is unconfigured or unreachable.
     */
    public function get(?int $storeId = null): ?array
    {
        if (!$this->config->isConfigured($storeId)) {
            return null;
        }

        $entry = $this->loadEntry($storeId);
        if ($entry['hit']) {
            return $entry['policy'];
        }

        return $this->refresh($storeId);
    }

    /**
     * The cached policy, or null. NEVER calls TackQuote.
     *
     * This is the only accessor safe to call while a shopper is waiting for HTML.
     *
     * @param int|null $storeId
     * @return array<string, mixed>|null
     */
    public function getCached(?int $storeId = null): ?array
    {
        if (!$this->config->isConfigured($storeId)) {
            return null;
        }

        return $this->loadEntry($storeId)['policy'];
    }

    /**
     * Fetch the policy from TackQuote and store it. Blocks on the network.
     *
     * @param int|null $storeId
     * @return array<string, mixed>|null The policy now in effect for this store.
     */
    public function refresh(?int $storeId = null): ?array
    {
        if (!$this->config->isConfigured($storeId)) {
            return null;
        }

        $key = $this->cacheKey($storeId);
        $response = $this->client->getRegistrationConfig($storeId);

        if (empty($response['ok']) || !is_array($response['data'] ?? null)) {
            $existing = $this->loadEntry($storeId)['policy'];

            $this->logger->warning(
                'TackQuote: registration config unavailable — '
                . ($existing !== null
                    ? 'keeping the previously cached policy. '
                    : 'the quote form will show contact fields only. ')
                . ($response['message'] ?? '')
            );

            if ($existing === null) {
                $this->cache->save(self::FAILURE_MARKER, $key, [self::CACHE_TAG], self::FAILURE_TTL);
            }

            return $existing;
        }

        $this->cache->save(
            $this->json->serialize($response['data']),
            $key,
            [self::CACHE_TAG],
            self::CACHE_TTL
        );

        return $response['data'];
    }

    /**
     * Drop the cached policy, e.g. after an admin changes the API credentials.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->cache->clean([self::CACHE_TAG]);
    }

    /**
     * Read one store's cache entry.
     *
     * `hit` distinguishes "we know the policy is unavailable" (the failure marker, a hit
     * with a null policy) from "we have not looked yet" (a miss). Without that distinction
     * get() cannot tell whether it is allowed to re-request.
     *
     * @param int|null $storeId
     * @return array{hit: bool, policy: array<string, mixed>|null}
     */
    private function loadEntry(?int $storeId): array
    {
        $cached = $this->cache->load($this->cacheKey($storeId));

        if ($cached === self::FAILURE_MARKER) {
            return ['hit' => true, 'policy' => null];
        }

        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->json->unserialize($cached);
                if (is_array($decoded)) {
                    return ['hit' => true, 'policy' => $decoded];
                }
            } catch (\Exception $e) {
                // Fall through and treat as a miss rather than trusting a corrupt entry.
                $this->logger->debug('TackQuote: discarding unreadable cached registration config.');
            }
        }

        return ['hit' => false, 'policy' => null];
    }

    /**
     * Cache identifier for one store view's policy.
     *
     * @param int|null $storeId
     * @return string
     */
    private function cacheKey(?int $storeId): string
    {
        return self::CACHE_KEY_PREFIX . ($storeId ?? 0);
    }
}
