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
 * FAILURE MODE — a TackQuote outage must not take the quote button down with it. On any
 * error this returns a null policy and the form degrades to contact fields only, which
 * still produces a usable quote request.
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

    /** Short enough that a seller changing policy sees it reflected quickly. */
    private const CACHE_TTL = 900;

    /**
     * Marker stored when a fetch fails, so a broken or unreachable TackQuote is not
     * re-requested on every single product-page render.
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
     * The seller's registration policy, from cache where possible.
     *
     * @param int|null $storeId
     * @return array<string, mixed>|null Null when TackQuote is unconfigured or unreachable.
     */
    public function get(?int $storeId = null): ?array
    {
        if (!$this->config->isConfigured($storeId)) {
            return null;
        }

        $key = self::CACHE_KEY_PREFIX . ($storeId ?? 0);
        $cached = $this->cache->load($key);

        if ($cached === self::FAILURE_MARKER) {
            return null;
        }

        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->json->unserialize($cached);

                return is_array($decoded) ? $decoded : null;
            } catch (\Exception $e) {
                // Fall through and refetch rather than trusting a corrupt entry.
                $this->logger->debug('TackQuote: discarding unreadable cached registration config.');
            }
        }

        $response = $this->client->getRegistrationConfig($storeId);

        if (empty($response['ok']) || !is_array($response['data'] ?? null)) {
            $this->cache->save(self::FAILURE_MARKER, $key, [self::CACHE_TAG], self::FAILURE_TTL);
            $this->logger->warning(
                'TackQuote: registration config unavailable — the quote form will show '
                . 'contact fields only. ' . ($response['message'] ?? '')
            );

            return null;
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
}
