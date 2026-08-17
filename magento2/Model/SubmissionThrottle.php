<?php
/**
 * Per-client rate limit for storefront quote submissions.
 *
 * WHY THIS EXISTS — the submit controller is, from the internet's point of view, an
 * unauthenticated public endpoint: the API key lives server-side, so anyone can POST to
 * /tackquote/quote/submit. Every accepted request creates a Buyer and a Quote in the
 * seller's TackQuote tenant. Without a limit, one script floods a seller's pipeline with
 * junk and burns their API plan quota.
 *
 * Tack's own public RFQ route already guards this with `@Throttle({limit: 5, ttl: 60s})`
 * plus a CAPTCHA. The plugin route bypassed both because an API key was assumed to be
 * sufficient authentication — but the key authenticates *the store*, not the visitor
 * driving the browser. This restores an equivalent limit on the visitor.
 *
 * Deliberately cache-backed rather than a DB table: this is throwaway counter state,
 * it must be fast on every submit, and losing it on a cache flush merely resets the
 * window rather than corrupting anything.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

class SubmissionThrottle
{
    /** Matches the limit Tack applies to its own public RFQ endpoint. */
    private const MAX_SUBMISSIONS = 5;

    /** Window in seconds. */
    private const WINDOW = 60;

    private const CACHE_PREFIX = 'tackquote_submit_';
    private const CACHE_TAG = 'TACKQUOTE_THROTTLE';

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var RemoteAddress
     */
    private $remoteAddress;

    /**
     * @param CacheInterface $cache
     * @param RemoteAddress $remoteAddress
     */
    public function __construct(CacheInterface $cache, RemoteAddress $remoteAddress)
    {
        $this->cache = $cache;
        $this->remoteAddress = $remoteAddress;
    }

    /**
     * Record an attempt and report whether the caller is over the limit.
     *
     * Counts the attempt before deciding, so a client that keeps hammering stays blocked
     * for the whole window instead of getting one free request each time the count is
     * read.
     *
     * @return bool True when the request should be rejected.
     */
    public function isExceeded(): bool
    {
        $key = $this->cacheKey();
        $count = (int) $this->cache->load($key);
        $count++;

        // Re-saving on every hit refreshes the TTL, which is intentional: a client that
        // keeps trying stays limited rather than slipping through as the window ages out.
        $this->cache->save((string) $count, $key, [self::CACHE_TAG], self::WINDOW);

        return $count > self::MAX_SUBMISSIONS;
    }

    /**
     * How long a blocked client must wait before retrying.
     *
     * @return int Seconds a blocked client should wait.
     */
    public function getRetryAfter(): int
    {
        return self::WINDOW;
    }

    /**
     * Cache key for this client's submission counter.
     *
     * Keyed on the client IP. Not on session id: a bot simply discards cookies, so a
     * session-keyed counter would reset on every request and enforce nothing.
     *
     * @return string
     */
    private function cacheKey(): string
    {
        $ip = (string) $this->remoteAddress->getRemoteAddress();

        return self::CACHE_PREFIX . hash('sha256', $ip !== '' ? $ip : 'unknown');
    }
}
