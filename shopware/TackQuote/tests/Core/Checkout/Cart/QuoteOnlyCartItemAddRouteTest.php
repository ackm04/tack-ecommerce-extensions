<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Test\Core\Checkout\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TackQuote\TackQuote\Core\Checkout\Cart\Exception\QuoteOnlyModeException;
use TackQuote\TackQuote\Core\Checkout\Cart\QuoteOnlyCartItemAddRoute;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;

/**
 * The server-side guard itself.
 *
 * These assertions are about a HOSTILE request, not about the UI: the template hiding the
 * button proves nothing, because a POST to /store-api/checkout/cart/line-item never renders
 * a template. What matters is that the decorated core route is never reached, so no line
 * item is priced, persisted, or announced by BeforeLineItemAddedEvent.
 */
#[CoversClass(QuoteOnlyCartItemAddRoute::class)]
#[CoversClass(QuoteOnlyModeException::class)]
class QuoteOnlyCartItemAddRouteTest extends TestCase
{
    private function route(bool $quoteOnly, AbstractCartItemAddRoute $inner): QuoteOnlyCartItemAddRoute
    {
        $mode = $this->createStub(QuoteOnlyModeService::class);
        $mode->method('appliesTo')->willReturn($quoteOnly);

        return new QuoteOnlyCartItemAddRoute($inner, $mode);
    }

    public function testAddIsRefusedWhileQuoteOnlyModeApplies(): void
    {
        $inner = $this->createMock(AbstractCartItemAddRoute::class);
        // The whole point: the core route must not run at all.
        $inner->expects(static::never())->method('add');

        $route = $this->route(true, $inner);

        try {
            $route->add(new Request(), new Cart('test-token'), $this->createStub(SalesChannelContext::class), [
                new LineItem('line-item-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1),
            ]);
            static::fail('Expected QuoteOnlyModeException, none thrown — the cart guard is not refusing.');
        } catch (QuoteOnlyModeException $e) {
            static::assertSame(Response::HTTP_FORBIDDEN, $e->getStatusCode());
            static::assertSame(QuoteOnlyModeException::QUOTE_ONLY_MODE_ACTIVE, $e->getErrorCode());
        }
    }

    /**
     * A raw Store API call supplies no $items array at all — the core route builds the line
     * items from the request body itself. The guard must fire before that happens, or the
     * headless path would be the one hole left open.
     */
    public function testAddIsRefusedEvenWhenItemsAreOnlyInTheRequestBody(): void
    {
        $inner = $this->createMock(AbstractCartItemAddRoute::class);
        $inner->expects(static::never())->method('add');

        $request = new Request();
        $request->request->set('items', [[
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => 'product-id',
            'quantity' => 500,
        ]]);

        $this->expectException(QuoteOnlyModeException::class);

        $this->route(true, $inner)->add(
            $request,
            new Cart('test-token'),
            $this->createStub(SalesChannelContext::class),
            null
        );
    }

    /**
     * The other direction, which matters just as much: with the mode off, or for an exempt
     * operator, the decorator must be transparent. A guard that always refuses would take
     * the whole shop down.
     */
    public function testAddIsDelegatedUnchangedWhenQuoteOnlyModeDoesNotApply(): void
    {
        $cart = new Cart('test-token');
        $expected = new CartResponse($cart);
        $request = new Request();
        $context = $this->createStub(SalesChannelContext::class);
        $items = [new LineItem('line-item-id', LineItem::PRODUCT_LINE_ITEM_TYPE, 'product-id', 1)];

        $inner = $this->createMock(AbstractCartItemAddRoute::class);
        $inner->expects(static::once())
            ->method('add')
            ->with($request, $cart, $context, $items)
            ->willReturn($expected);

        static::assertSame($expected, $this->route(false, $inner)->add($request, $cart, $context, $items));
    }

    public function testGetDecoratedReturnsTheWrappedRoute(): void
    {
        $inner = $this->createStub(AbstractCartItemAddRoute::class);

        static::assertSame($inner, $this->route(true, $inner)->getDecorated());
    }
}
