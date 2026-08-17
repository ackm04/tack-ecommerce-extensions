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
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\RegistrationConfigProvider;

/**
 * @covers \TackQuote\Quotes\Model\RegistrationConfigProvider
 */
class RegistrationConfigProviderTest extends TestCase
{
    private const CACHE_KEY = 'tackquote_registration_config_0';
    private const FAILURE_MARKER = '__tackquote_unavailable__';

    /**
     * @var Client&MockObject
     */
    private $client;

    /**
     * @var CacheInterface&MockObject
     */
    private $cache;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var array<string, string>
     */
    private $store = [];

    /**
     * @var array<int, array{data: mixed, id: string, tags: array, ttl: mixed}>
     */
    private $saves = [];

    /**
     * @var array<int, array> Tag sets passed to clean().
     */
    private $cleans = [];

    /**
     * @var RegistrationConfigProvider
     */
    private $provider;

    protected function setUp(): void
    {
        $this->store = [];
        $this->saves = [];
        $this->cleans = [];

        $this->client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRegistrationConfig'])
            ->getMock();

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
        $this->cache->method('clean')
            ->willReturnCallback(function ($tags = []) {
                $this->cleans[] = $tags;

                return true;
            });

        $this->config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConfigured'])
            ->getMock();
        $this->config->method('isConfigured')->willReturn(true);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new RegistrationConfigProvider(
            $this->client,
            $this->cache,
            new Json(),
            $this->config,
            $this->logger
        );
    }

    public function testReturnsNullWithoutCallingTheApiWhenTheStoreIsNotConfigured(): void
    {
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConfigured'])
            ->getMock();
        $config->method('isConfigured')->willReturn(false);

        $this->client->expects($this->never())->method('getRegistrationConfig');

        $provider = new RegistrationConfigProvider(
            $this->client,
            $this->cache,
            new Json(),
            $config,
            $this->logger
        );

        $this->assertNull($provider->get());
    }

    /**
     * A TackQuote outage must not take the quote button down with it: the form degrades to
     * contact fields only.
     */
    public function testReturnsNullAndCachesAFailureMarkerWhenTheClientCallFails(): void
    {
        $this->client->method('getRegistrationConfig')
            ->willReturn(['ok' => false, 'message' => 'Could not reach the TackQuote API.']);

        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->provider->get());

        $this->assertCount(1, $this->saves);
        $this->assertSame(self::FAILURE_MARKER, $this->saves[0]['data']);
        $this->assertSame(self::CACHE_KEY, $this->saves[0]['id']);
        $this->assertSame(['TACKQUOTE_CONFIG'], $this->saves[0]['tags']);
        $this->assertSame(60, $this->saves[0]['ttl'], 'the failure marker must be short-lived');
    }

    /**
     * The marker exists so a broken or unreachable TackQuote is not re-requested on every
     * single product-page render.
     */
    public function testACachedFailureMarkerShortCircuitsWithoutReRequesting(): void
    {
        $this->client->expects($this->once())
            ->method('getRegistrationConfig')
            ->willReturn(['ok' => false, 'message' => 'boom']);

        $this->assertNull($this->provider->get());
        // Second and third renders must be served from the marker.
        $this->assertNull($this->provider->get());
        $this->assertNull($this->provider->get());
    }

    public function testReturnsAndCachesTheDecodedArrayOnSuccess(): void
    {
        $data = [
            'allowCompanies' => true,
            'allowIndividuals' => false,
            'requiredCompanyFields' => ['phone', 'country'],
            'customFields' => [
                ['key' => 'poNumber', 'label' => 'PO number', 'required' => true],
            ],
        ];

        $this->client->expects($this->once())
            ->method('getRegistrationConfig')
            ->willReturn(['ok' => true, 'data' => $data]);

        $this->assertSame($data, $this->provider->get());

        $this->assertCount(1, $this->saves);
        $this->assertSame(self::CACHE_KEY, $this->saves[0]['id']);
        $this->assertSame(['TACKQUOTE_CONFIG'], $this->saves[0]['tags']);
        $this->assertSame(900, $this->saves[0]['ttl']);
        $this->assertSame($data, json_decode((string) $this->saves[0]['data'], true));
    }

    public function testASecondCallIsServedFromCacheWithoutHittingTheApiAgain(): void
    {
        $data = ['allowCompanies' => true];

        $this->client->expects($this->once())
            ->method('getRegistrationConfig')
            ->willReturn(['ok' => true, 'data' => $data]);

        $this->assertSame($data, $this->provider->get());
        $this->assertSame($data, $this->provider->get());
    }

    /**
     * `ok` without a usable `data` payload is still a failure — otherwise the form would
     * render against nothing and look configured when it is not.
     */
    public function testAnOkResponseWithoutAnArrayPayloadIsTreatedAsAFailure(): void
    {
        $this->client->method('getRegistrationConfig')
            ->willReturn(['ok' => true, 'data' => 'not-an-array']);

        $this->assertNull($this->provider->get());
        $this->assertSame(self::FAILURE_MARKER, $this->saves[0]['data']);
    }

    /**
     * A corrupt cache entry must be discarded and refetched rather than trusted.
     */
    public function testACorruptCacheEntryIsDiscardedAndRefetched(): void
    {
        $this->store[self::CACHE_KEY] = '{"allowCompanies": tru';

        $this->client->expects($this->once())
            ->method('getRegistrationConfig')
            ->willReturn(['ok' => true, 'data' => ['allowCompanies' => true]]);

        $this->assertSame(['allowCompanies' => true], $this->provider->get());
    }

    public function testTheCacheKeyIsScopedPerStore(): void
    {
        $this->client->method('getRegistrationConfig')
            ->willReturn(['ok' => true, 'data' => ['allowCompanies' => true]]);

        $this->provider->get(3);

        $this->assertSame('tackquote_registration_config_3', $this->saves[0]['id']);
    }

    public function testFlushCleansByTag(): void
    {
        $this->provider->flush();

        $this->assertSame([['TACKQUOTE_CONFIG']], $this->cleans);
    }
}
