<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Test\Core\Checkout\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use TackQuote\TackQuote\Core\Checkout\Cart\Error\QuoteOnlyModeError;
use TackQuote\TackQuote\Core\Checkout\Cart\QuoteOnlyCartValidator;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;

/**
 * Covers the half of enforcement the add-guard cannot reach: a basket that already had
 * items in it when quote-only mode was switched on.
 *
 * The assertion that actually protects the merchant is blockOrder() === true, because that
 * is the flag core reads in OrderPersister::persist() before writing an order row
 * (vendor/shopware/core/Checkout/Cart/Order/OrderPersister.php:38). An error that merely
 * displayed a warning would let the order through.
 */
#[CoversClass(QuoteOnlyCartValidator::class)]
#[CoversClass(QuoteOnlyModeError::class)]
class QuoteOnlyCartValidatorTest extends TestCase
{
    private function validator(bool $quoteOnly): QuoteOnlyCartValidator
    {
        $mode = $this->createStub(QuoteOnlyModeService::class);
        $mode->method('appliesTo')->willReturn($quoteOnly);

        return new QuoteOnlyCartValidator($mode);
    }

    private static function filledCart(): Cart
    {
        $cart = new Cart('test-token');
        $cart->add(new LineItem('line-item-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 2));

        return $cart;
    }

    public function testFilledCartIsBlockedWhileQuoteOnlyModeApplies(): void
    {
        $errors = new ErrorCollection();

        $this->validator(true)->validate(
            self::filledCart(),
            $errors,
            $this->createStub(SalesChannelContext::class)
        );

        static::assertCount(1, $errors);

        $error = $errors->first();
        static::assertInstanceOf(QuoteOnlyModeError::class, $error);
        static::assertTrue($error->blockOrder(), 'the error must block the order, not just warn');
        static::assertTrue($errors->blockOrder(), 'OrderPersister reads the collection, not the error');
        static::assertSame(Error::LEVEL_ERROR, $error->getLevel());
        static::assertFalse(
            $error->isPersistent(),
            'a persistent copy would survive the merchant turning quote-only mode back off'
        );
    }

    public function testFilledCartIsUntouchedWhenQuoteOnlyModeDoesNotApply(): void
    {
        $errors = new ErrorCollection();

        $this->validator(false)->validate(
            self::filledCart(),
            $errors,
            $this->createStub(SalesChannelContext::class)
        );

        static::assertCount(0, $errors);
    }

    /**
     * An empty cart cannot be ordered anyway (OrderPersister::persist() rejects it at line
     * 46), so flagging it would only put a red banner on the cart page of every visitor who
     * has bought nothing.
     */
    public function testEmptyCartRaisesNoError(): void
    {
        $errors = new ErrorCollection();

        $this->validator(true)->validate(
            new Cart('test-token'),
            $errors,
            $this->createStub(SalesChannelContext::class)
        );

        static::assertCount(0, $errors);
    }
}
