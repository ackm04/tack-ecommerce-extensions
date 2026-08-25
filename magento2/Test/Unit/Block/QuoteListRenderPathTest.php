<?php
/**
 * The render-path guarantee, asserted on the block rather than on the provider.
 *
 * Block\QuoteList renders in `before.body.end` on EVERY storefront page (see
 * view/frontend/layout/default.xml), so anything it does on a cache miss happens while a
 * shopper waits for HTML. It used to call RegistrationConfigProvider::get(), which fetches
 * on a miss, which put a 20-second-budget outbound call to TackQuote inside the render of
 * every page served after a deploy, a cache:flush or a store-view addition.
 *
 * Test/Unit/Model/RegistrationConfigProviderTest.php proves getCached() itself never calls
 * the API. That is necessary but not sufficient: it says nothing about which accessor the
 * block picks, and the block picking get() again is exactly how the regression would come
 * back. These tests pin the caller.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Block;

use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Block\QuoteList;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\RegistrationConfigProvider;

/**
 * @covers \TackQuote\Quotes\Block\QuoteList
 */
class QuoteListRenderPathTest extends TestCase
{
    /** @var RegistrationConfigProvider&MockObject */
    private $provider;

    /** @var Config&MockObject */
    private $config;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(RegistrationConfigProvider::class);
        $this->config = $this->createMock(Config::class);
    }

    private function block(int $storeId = 7): QuoteList
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);

        return new QuoteList($context, $this->config, $this->provider);
    }

    public function testTheBlockNeverFetchesWhileAShopperIsWaiting(): void
    {
        // THE TEST THIS CHANGE EXISTS AROUND. get() and refresh() both block on the
        // network; neither may be reachable from a render.
        $this->provider->expects(self::never())->method('get');
        $this->provider->expects(self::never())->method('refresh');
        $this->provider->expects(self::once())->method('getCached')->willReturn(['mode' => 'both']);

        self::assertSame(['mode' => 'both'], $this->block()->getRegistrationConfig());
    }

    public function testTheStoreIdIsPassedThroughSoEachStoreViewGetsItsOwnPolicy(): void
    {
        $this->provider->expects(self::once())
            ->method('getCached')
            ->with(42)
            ->willReturn(null);

        self::assertNull($this->block(42)->getRegistrationConfig());
    }

    public function testThePolicyIsReadOnceEvenThoughTheTemplateAsksFiveTimes(): void
    {
        // quote-list.phtml asks isCompanyStepEnabled / isCompanyRequired /
        // getRequiredCompanyFields / getCustomFields / getRegistrationConfigJson, and each
        // one used to be its own cache round trip.
        $this->provider->expects(self::once())->method('getCached')->willReturn(['allowCompany' => true]);

        $block = $this->block();
        for ($i = 0; $i < 5; $i++) {
            $block->getRegistrationConfig();
        }
    }

    public function testAnUnavailablePolicyIsMemoisedTooRatherThanRetriedEveryCall(): void
    {
        // Null is a real policy value ("we know it is unavailable"), so memoisation cannot
        // key off the value alone — without the separate loaded flag every one of the five
        // template questions would go back to the cache.
        $this->provider->expects(self::once())->method('getCached')->willReturn(null);

        $block = $this->block();
        self::assertNull($block->getRegistrationConfig());
        self::assertNull($block->getRegistrationConfig());
        self::assertNull($block->getRegistrationConfig());
    }
}
