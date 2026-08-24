<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Checkout\QuoteOnlyExistingCartGuard;

/**
 * @covers \TackQuote\Quotes\Plugin\Checkout\QuoteOnlyExistingCartGuard
 */
class QuoteOnlyExistingCartGuardTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var Cart&MockObject */
    private $cart;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->cart = $this->createMock(Cart::class);
    }

    private function guard(): QuoteOnlyExistingCartGuard
    {
        return new QuoteOnlyExistingCartGuard($this->mode);
    }

    /**
     * A cart holding one line of the given quantity.
     */
    private function cartHoldingQty(float $qty): void
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQty'])
            ->getMock();
        $item->method('getQty')->willReturn($qty);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById'])
            ->getMock();
        $quote->method('getItemById')->willReturn($item);

        $this->cart->method('getQuote')->willReturn($quote);
    }

    public function testRaisingTheQuantityOfAPreEXISTINGLineIsREFUSED(): void
    {
        // Not decoration. Without this, a cart holding one item from before the switch could
        // be edited to quantity 10 000 and checked out — a policy with a hole in it.
        $this->mode->method('isActive')->willReturn(true);
        $this->cartHoldingQty(1.0);

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeUpdateItems($this->cart, [7 => ['qty' => '10000']]);
    }

    public function testLoweringTheQuantityIsALLOWED(): void
    {
        // Refusing the whole call would be simpler and wrong: it would trap a shopper with a
        // cart they can see, cannot use and cannot shrink. The cart still cannot be checked
        // out, so allowing this costs the policy nothing.
        $this->mode->method('isActive')->willReturn(true);
        $this->cartHoldingQty(5.0);

        $this->guard()->beforeUpdateItems($this->cart, [7 => ['qty' => '2']]);

        self::assertTrue(true, 'no exception: the shopper may shrink their basket');
    }

    public function testRemovingALineIsALLOWED(): void
    {
        $this->mode->method('isActive')->willReturn(true);
        $this->cartHoldingQty(5.0);

        $this->guard()->beforeUpdateItems($this->cart, [7 => ['remove' => '1']]);

        self::assertTrue(true, 'turning the mode on must never trap a cart the shopper cannot empty');
    }

    public function testSettingQuantityToZeroIsALLOWEDBecauseMagentoTreatsItAsRemoval(): void
    {
        // Magento\Checkout\Model\Cart::updateItems() treats `qty == '0'` as a removal at
        // module-checkout/Model/Cart.php:533, so this guard has to as well.
        $this->mode->method('isActive')->willReturn(true);
        $this->cartHoldingQty(5.0);

        $this->guard()->beforeUpdateItems($this->cart, [7 => ['qty' => '0']]);

        self::assertTrue(true);
    }

    public function testReorderIsRefused(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeAddOrderItem($this->cart, new \stdClass());
    }

    public function testReconfiguringACartLineIsRefused(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        $this->expectException(LocalizedException::class);

        $this->guard()->beforeUpdateItem($this->cart, 7, ['qty' => 3]);
    }

    public function testANormalStoreCanStillRaiseQuantities(): void
    {
        $this->mode->method('isActive')->willReturn(false);

        $this->guard()->beforeUpdateItems($this->cart, [7 => ['qty' => '10000']]);
        $this->guard()->beforeUpdateItem($this->cart, 7, ['qty' => 3]);
        $this->guard()->beforeAddOrderItem($this->cart, new \stdClass());

        self::assertTrue(true, 'with the mode off nothing is refused');
    }
}
