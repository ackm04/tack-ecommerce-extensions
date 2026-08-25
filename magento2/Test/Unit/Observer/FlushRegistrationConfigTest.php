<?php
/**
 * RegistrationConfigProvider::flush() shipped with NO caller at all for several versions,
 * so a merchant pasting a new API key kept seeing the previous tenant's company and custom
 * fields on the storefront until the cache lapsed. This observer is that caller.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\RegistrationConfigProvider;
use TackQuote\Quotes\Observer\FlushRegistrationConfig;

/**
 * @covers \TackQuote\Quotes\Observer\FlushRegistrationConfig
 */
class FlushRegistrationConfigTest extends TestCase
{
    /** @var RegistrationConfigProvider&MockObject */
    private $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(RegistrationConfigProvider::class);
    }

    public function testItIsAnObserverMagentoWillAcceptFromEventsXml(): void
    {
        // etc/adminhtml/events.xml names this class as an <observer instance="…">, and
        // Magento\Framework\Event\Invoker\InvokerDefault throws if it does not implement
        // ObserverInterface — a failure that only shows up on a live settings save.
        self::assertInstanceOf(ObserverInterface::class, new FlushRegistrationConfig($this->provider));
    }

    public function testSavingTheSettingsDiscardsTheCachedPolicy(): void
    {
        $this->provider->expects(self::once())->method('flush');

        (new FlushRegistrationConfig($this->provider))->execute(new Observer());
    }

    public function testItDoesNotRefetchInsideTheAdminSaveRequest(): void
    {
        // Deliberate: warming N store views synchronously inside Save is how a settings
        // save becomes a gateway timeout on a multi-store install. Cron re-warms instead.
        $this->provider->expects(self::never())->method('refresh');
        $this->provider->expects(self::never())->method('get');

        (new FlushRegistrationConfig($this->provider))->execute(new Observer());
    }
}
