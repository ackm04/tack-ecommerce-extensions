<?php
/**
 * Collapses duplicate quote submissions into one.
 *
 * WHY STORE-SIDE — TackQuote does support an `Idempotency-Key` header, but sending one
 * from here currently fails: `api_idempotency_keys` is a FORCE-RLS table and
 * `apps/api/src/modules/idempotency/idempotency.service.ts` establishes no tenant RLS
 * context, so the insert is rejected —
 *
 *     new row violates row-level security policy for table "api_idempotency_keys"
 *
 * — and the whole request 500s. No storefront plugin had ever sent the header, so the
 * path had never been exercised. Until that is fixed on the TackQuote side, the header
 * is deliberately NOT sent (see Model\Api\Client) and duplicates are caught here.
 *
 * Catching it here is also simply earlier: a double-clicked button never reaches the
 * network at all.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

class IdempotencyGuard
{
    private const CACHE_PREFIX = 'tackquote_idem_';
    private const CACHE_TAG = 'TACKQUOTE_IDEMPOTENCY';

    /**
     * Long enough to absorb a double-click, an impatient retry or a flaky connection
     * retry, short enough that a genuine second request minutes later is still a new
     * quote.
     */
    private const TTL = 300;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(CacheInterface $cache, Json $json)
    {
        $this->cache = $cache;
        $this->json = $json;
    }

    /**
     * A previously recorded successful response for this exact request, if any.
     *
     * @param string $key
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $cached = $this->cache->load(self::CACHE_PREFIX . $key);
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($cached);
        } catch (\Exception $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Record a successful response so an immediate repeat returns it.
     *
     * Only successes are stored: replaying a failure would deny the shopper a legitimate
     * retry of something that never actually got created.
     *
     * @param string $key
     * @param array $response The response payload to replay, as string-keyed data.
     * @return void
     */
    public function remember(string $key, array $response): void
    {
        $this->cache->save(
            $this->json->serialize($response),
            self::CACHE_PREFIX . $key,
            [self::CACHE_TAG],
            self::TTL
        );
    }
}
