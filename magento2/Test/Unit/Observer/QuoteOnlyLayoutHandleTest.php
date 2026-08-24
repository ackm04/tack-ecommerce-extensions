<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Observer\QuoteOnlyLayoutHandle;

/**
 * @covers \TackQuote\Quotes\Observer\QuoteOnlyLayoutHandle
 */
class QuoteOnlyLayoutHandleTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var ProcessorInterface&MockObject */
    private $update;

    /** @var LayoutInterface&MockObject */
    private $layout;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->update = $this->createMock(ProcessorInterface::class);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->update);
    }

    private function observerFor(string $fullActionName): Observer
    {
        $observer = new Observer();
        $observer->setData('layout', $this->layout);
        $observer->setData('full_action_name', $fullActionName);

        return $observer;
    }

    /**
     * @return string[]
     */
    private function handlesAddedOn(string $fullActionName): array
    {
        $added = [];
        $this->update->method('addHandle')->willReturnCallback(
            static function ($handle) use (&$added) {
                $added[] = $handle;
            }
        );

        (new QuoteOnlyLayoutHandle($this->mode))->execute($this->observerFor($fullActionName));

        return $added;
    }

    public function testTheProductPageGetsTheRemovalHandle(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        $handles = $this->handlesAddedOn('catalog_product_view');

        self::assertContains(QuoteOnlyLayoutHandle::HANDLE_GLOBAL, $handles);
        self::assertContains(
            QuoteOnlyLayoutHandle::HANDLE_PRODUCT,
            $handles,
            'without this handle the Add to Cart button stays on a storefront that refuses it'
        );
    }

    public function testTheCartPageGetsTheNoticeHandle(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        $handles = $this->handlesAddedOn('checkout_cart_index');

        self::assertContains(QuoteOnlyLayoutHandle::HANDLE_CART, $handles);
        self::assertNotContains(
            QuoteOnlyLayoutHandle::HANDLE_PRODUCT,
            $handles,
            'the product-page removals name blocks that do not exist here, and applying them '
            . 'everywhere would log a broken reference on every page of the store'
        );
    }

    public function testAnOrdinaryPageGetsOnlyTheGlobalHandle(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        self::assertSame(
            [QuoteOnlyLayoutHandle::HANDLE_GLOBAL],
            $this->handlesAddedOn('cms_index_index')
        );
    }

    public function testNothingIsAddedWhenTheModeDoesNotApply(): void
    {
        $this->mode->method('isActive')->willReturn(false);

        // The strongest form: getUpdate() must not even be reached, so a visitor the mode
        // does not cover cannot get a cached page with the button removed.
        $this->layout->expects(self::never())->method('getUpdate');

        self::assertSame([], $this->handlesAddedOn('catalog_product_view'));
    }

    public function testAMissingLayoutIsIgnoredRatherThanFatal(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        $observer = new Observer();
        $observer->setData('full_action_name', 'catalog_product_view');

        (new QuoteOnlyLayoutHandle($this->mode))->execute($observer);

        self::assertTrue(true, 'an observer must not fatal the whole storefront');
    }
}
