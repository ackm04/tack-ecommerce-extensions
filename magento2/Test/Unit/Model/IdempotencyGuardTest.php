<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\IdempotencyGuard;

/**
 * @covers \TackQuote\Quotes\Model\IdempotencyGuard
 */
class IdempotencyGuardTest extends TestCase
{
    /**
     * @var CacheInterface&MockObject
     */
    private $cache;

    /**
     * @var array<string, string>
     */
    private $store = [];

    /**
     * @var array<int, array{data: mixed, id: string, tags: array, ttl: mixed}>
     */
    private $saves = [];

    /**
     * @var IdempotencyGuard
     */
    private $guard;

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

        // The real serializer, so the round-trip is genuinely exercised rather than mocked
        // into agreeing with itself.
        $this->guard = new IdempotencyGuard($this->cache, new Json());
    }

    public function testGetReturnsNullForAMissingEntry(): void
    {
        $this->assertNull($this->guard->get('never-seen'));
    }

    public function testGetReturnsNullForAnEmptyEntry(): void
    {
        $this->store['tackquote_idem_empty'] = '';

        $this->assertNull($this->guard->get('empty'));
    }

    /**
     * A truncated or evicted-mid-write cache entry must degrade to "no record", not blow up
     * the submission with a JSON error.
     */
    public function testGetReturnsNullForACorruptEntry(): void
    {
        $this->store['tackquote_idem_corrupt'] = '{"quoteId": "TK-2026-00';

        $this->assertNull($this->guard->get('corrupt'));
    }

    public function testGetReturnsNullWhenTheEntryDecodesToSomethingThatIsNotAnArray(): void
    {
        $this->store['tackquote_idem_scalar'] = '"just-a-string"';

        $this->assertNull($this->guard->get('scalar'));
    }

    public function testRememberThenGetRoundTripsTheArray(): void
    {
        $response = [
            'success' => true,
            'quoteId' => 'a3f1c0de-0000-4000-8000-000000000001',
            'quoteNumber' => 'TK-2026-000123',
            'portalUrl' => 'https://portal.tackquote.test/q/abc',
            'company' => ['id' => 'c-1', 'name' => 'Acme Ltd'],
            'awaitingApproval' => false,
        ];

        $this->guard->remember('dupe-key', $response);

        $this->assertSame($response, $this->guard->get('dupe-key'));
    }

    public function testRememberNamespacesTagsAndBoundsTheEntry(): void
    {
        $this->guard->remember('dupe-key', ['success' => true]);

        $this->assertCount(1, $this->saves);
        $this->assertSame('tackquote_idem_dupe-key', $this->saves[0]['id']);
        $this->assertSame(['TACKQUOTE_IDEMPOTENCY'], $this->saves[0]['tags']);
        $this->assertSame(300, $this->saves[0]['ttl']);
    }

    public function testDistinctKeysDoNotCollide(): void
    {
        $this->guard->remember('key-a', ['quoteNumber' => 'TK-2026-000001']);
        $this->guard->remember('key-b', ['quoteNumber' => 'TK-2026-000002']);

        $this->assertSame(['quoteNumber' => 'TK-2026-000001'], $this->guard->get('key-a'));
        $this->assertSame(['quoteNumber' => 'TK-2026-000002'], $this->guard->get('key-b'));
    }
}
