<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\SubmissionThrottle;

/**
 * @covers \TackQuote\Quotes\Model\SubmissionThrottle
 */
class SubmissionThrottleTest extends TestCase
{
    private const MAX_SUBMISSIONS = 5;
    private const WINDOW = 60;

    /**
     * @var CacheInterface&MockObject
     */
    private $cache;

    /**
     * @var RemoteAddress&MockObject
     */
    private $remoteAddress;

    /**
     * In-memory stand-in for the cache backend: identifier => stored string.
     *
     * @var array<string, string>
     */
    private $store = [];

    /**
     * Every save() the subject made, so the counter itself can be asserted on.
     *
     * @var array<int, array{data: mixed, id: string, tags: array, ttl: mixed}>
     */
    private $saves = [];

    protected function setUp(): void
    {
        $this->store = [];
        $this->saves = [];

        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')
            ->willReturnCallback(function ($identifier) {
                return $this->store[$identifier] ?? false;
            });
        $this->cache->method('save')
            ->willReturnCallback(function ($data, $identifier, $tags = [], $ttl = null) {
                $this->store[$identifier] = (string) $data;
                $this->saves[] = [
                    'data' => $data,
                    'id' => $identifier,
                    'tags' => $tags,
                    'ttl' => $ttl,
                ];

                return true;
            });

        $this->remoteAddress = $this->getMockBuilder(RemoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRemoteAddress'])
            ->getMock();
    }

    /**
     * @param string $ip
     * @return SubmissionThrottle
     */
    private function throttleFor(string $ip): SubmissionThrottle
    {
        $this->remoteAddress->method('getRemoteAddress')->willReturn($ip);

        return new SubmissionThrottle($this->cache, $this->remoteAddress);
    }

    public function testFirstFiveAttemptsInTheWindowAreAllowedAndTheSixthIsRejected(): void
    {
        $throttle = $this->throttleFor('203.0.113.7');

        for ($i = 1; $i <= self::MAX_SUBMISSIONS; $i++) {
            $this->assertFalse($throttle->isExceeded(), "attempt $i should be allowed");
        }

        $this->assertTrue($throttle->isExceeded(), 'the sixth attempt must be rejected');
    }

    /**
     * The attempt is counted BEFORE the decision. If it were counted after, a client that
     * keeps hammering would be handed one free request on every read.
     */
    public function testTheAttemptIsCountedBeforeTheDecisionIsMade(): void
    {
        $throttle = $this->throttleFor('203.0.113.7');

        $throttle->isExceeded();

        $this->assertCount(1, $this->saves, 'the first call must persist a count');
        $this->assertSame('1', $this->saves[0]['data'], 'the current attempt must be included in the count');
    }

    /**
     * A blocked client stays blocked: each further hit still increments and still re-saves,
     * refreshing the TTL rather than letting the window age out underneath it.
     */
    public function testAClientThatKeepsHammeringStaysBlockedAndKeepsRefreshingTheWindow(): void
    {
        $throttle = $this->throttleFor('203.0.113.7');

        for ($i = 0; $i < 20; $i++) {
            $throttle->isExceeded();
        }

        $this->assertTrue($throttle->isExceeded());
        $this->assertSame('21', $this->saves[20]['data'], 'the counter must keep climbing while blocked');
        $this->assertSame(self::WINDOW, $this->saves[20]['ttl'], 'every hit must refresh the TTL');
    }

    /**
     * Keyed on client IP, not session: a bot simply discards cookies, so a session-keyed
     * counter resets on every request and enforces nothing.
     */
    public function testTheCounterIsKeyedOnTheClientIpAddress(): void
    {
        $this->throttleFor('198.51.100.10')->isExceeded();

        $this->assertCount(1, $this->saves);
        $this->assertSame(
            'tackquote_submit_' . hash('sha256', '198.51.100.10'),
            $this->saves[0]['id'],
            'the cache key must be derived from the client IP'
        );
    }

    public function testTwoDifferentClientsDoNotShareACounter(): void
    {
        $first = $this->throttleFor('198.51.100.10');

        $secondAddress = $this->getMockBuilder(RemoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRemoteAddress'])
            ->getMock();
        $secondAddress->method('getRemoteAddress')->willReturn('198.51.100.11');
        $second = new SubmissionThrottle($this->cache, $secondAddress);

        // Exhaust the first client's allowance entirely.
        for ($i = 0; $i < 6; $i++) {
            $first->isExceeded();
        }

        $this->assertTrue($first->isExceeded());
        $this->assertFalse($second->isExceeded(), 'a different IP must start with a fresh allowance');
        $this->assertCount(2, $this->store, 'each IP must get its own cache entry');
    }

    public function testAnUnknownClientIpStillGetsAStableKey(): void
    {
        $this->throttleFor('')->isExceeded();

        $this->assertSame(
            'tackquote_submit_' . hash('sha256', 'unknown'),
            $this->saves[0]['id']
        );
    }

    public function testRetryAfterMatchesTheWindow(): void
    {
        $this->assertSame(self::WINDOW, $this->throttleFor('203.0.113.7')->getRetryAfter());
    }

    public function testTheCounterIsTaggedSoItCanBeFlushedSelectively(): void
    {
        $this->throttleFor('203.0.113.7')->isExceeded();

        $this->assertSame(['TACKQUOTE_THROTTLE'], $this->saves[0]['tags']);
    }
}
