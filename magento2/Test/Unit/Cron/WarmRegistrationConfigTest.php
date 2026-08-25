<?php
/**
 * The cron job is the ONLY thing that fetches the registration policy now, so its failure
 * modes are the storefront's failure modes.
 *
 * Two properties matter more than the happy path:
 *   - it must skip store views that cannot or should not be warmed, because refresh() on an
 *     unconfigured store is a guaranteed-failing outbound call every five minutes; and
 *   - one bad store view must not abort the loop. A throw partway through leaves every
 *     later store view cold, and a cold store view renders the degraded form forever.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Cron\WarmRegistrationConfig;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\RegistrationConfigProvider;

/**
 * @covers \TackQuote\Quotes\Cron\WarmRegistrationConfig
 */
class WarmRegistrationConfigTest extends TestCase
{
    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var Config&MockObject */
    private $config;

    /** @var RegistrationConfigProvider&MockObject */
    private $provider;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->provider = $this->createMock(RegistrationConfigProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param array<int, bool> $activeById
     */
    private function stores(array $activeById): void
    {
        $stores = [];
        foreach ($activeById as $id => $isActive) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getIsActive')->willReturn($isActive);
            $stores[] = $store;
        }
        $this->storeManager->method('getStores')->willReturn($stores);
    }

    private function job(): WarmRegistrationConfig
    {
        return new WarmRegistrationConfig(
            $this->storeManager,
            $this->config,
            $this->provider,
            $this->logger
        );
    }

    /**
     * @return int[] Store ids refresh() was actually called for, in order.
     */
    private function warmed(): array
    {
        $seen = [];
        $this->provider->method('refresh')->willReturnCallback(
            static function ($storeId) use (&$seen) {
                $seen[] = $storeId;

                return null;
            }
        );
        $this->job()->execute();

        return $seen;
    }

    public function testEveryConfiguredActiveStoreViewIsWarmed(): void
    {
        $this->stores([1 => true, 2 => true]);
        $this->config->method('isConfigured')->willReturn(true);

        self::assertSame([1, 2], $this->warmed());
    }

    public function testAnInactiveStoreViewIsSkipped(): void
    {
        $this->stores([1 => true, 2 => false]);
        $this->config->method('isConfigured')->willReturn(true);

        self::assertSame([1], $this->warmed());
    }

    public function testAnUnconfiguredStoreViewIsSkippedRatherThanCalledEveryFiveMinutes(): void
    {
        $this->stores([1 => true, 2 => true]);
        $this->config->method('isConfigured')->willReturnCallback(
            static fn ($storeId) => $storeId === 1
        );

        self::assertSame([1], $this->warmed());
    }

    public function testTheInactiveCheckHappensBeforeTheConfiguredCheck(): void
    {
        // Guards against reordering the condition into something that still "passes" the
        // two tests above by accident: an inactive store must be rejected without the
        // configuration ever being consulted for it.
        $this->stores([9 => false]);
        $this->config->expects(self::never())->method('isConfigured');
        $this->provider->expects(self::never())->method('refresh');

        $this->job()->execute();
    }

    public function testOneUnreachableStoreViewDoesNotStopTheOthersBeingWarmed(): void
    {
        // THE TEST THIS try/catch EXISTS AROUND. Without it store 3 never gets warmed and
        // renders the degraded quote form until someone notices.
        $this->stores([1 => true, 2 => true, 3 => true]);
        $this->config->method('isConfigured')->willReturn(true);

        $seen = [];
        $this->provider->method('refresh')->willReturnCallback(
            static function ($storeId) use (&$seen) {
                $seen[] = $storeId;
                if ($storeId === 2) {
                    throw new \RuntimeException('store 2 is broken');
                }

                return null;
            }
        );

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('store 2'));

        $this->job()->execute();

        self::assertSame([1, 2, 3], $seen);
    }

    public function testNothingIsWarmedWhenTheStoreListIsEmpty(): void
    {
        $this->stores([]);
        $this->provider->expects(self::never())->method('refresh');

        $this->job()->execute();
    }
}
