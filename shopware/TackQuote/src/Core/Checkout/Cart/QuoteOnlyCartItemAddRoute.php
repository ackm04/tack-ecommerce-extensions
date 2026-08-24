<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use TackQuote\TackQuote\Core\Checkout\Cart\Exception\QuoteOnlyModeException;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;

/**
 * THIS IS THE ENFORCEMENT POINT for B2B quote-only mode.
 *
 * Hiding the "Add to cart" button in Twig is cosmetic — a cached page, a bookmarked form
 * post, a `curl` against /store-api/checkout/cart/line-item, or any headless client would
 * sail straight past it. So the refusal lives in the service that every single add path
 * funnels through, and it refuses BEFORE delegating to the decorated core route, so no
 * line item is ever constructed, priced, persisted or announced by an event.
 *
 * That claim is verified against Shopware 6.6.10.22 source, not assumed:
 *
 *  - Store API POST /store-api/checkout/cart/line-item is this very route
 *    (vendor/shopware/core/Checkout/Cart/SalesChannel/CartItemAddRoute.php:47).
 *  - Shopware\Core\Checkout\Cart\SalesChannel\CartService::add() — the only public cart-add
 *    API — delegates to the injected AbstractCartItemAddRoute
 *    (vendor/shopware/core/Checkout/Cart/SalesChannel/CartService.php:90), and the service
 *    definition injects the decorated id
 *    (vendor/shopware/core/Checkout/DependencyInjection/cart.xml:70 + :116).
 *  - Both storefront entry points go through that CartService: `addLineItems()`
 *    (POST /checkout/line-item/add — vendor/shopware/storefront/Controller/CartLineItemController.php:314)
 *    and `addProductByNumber()` (POST /checkout/product/add-by-number — same file, line 245).
 *
 * A grep of the whole core + storefront tree for AbstractCartItemAddRoute returns only the
 * abstract, the concrete route and that CartService constructor argument — there is no
 * fourth path that writes a line item into a persisted cart while bypassing this class.
 *
 * NOTE ON WHAT IS DELIBERATELY *NOT* BLOCKED: item update (quantity change) and item
 * removal are left alone. A shopper whose cart was filled before the merchant flipped the
 * switch must still be able to empty it, and blocking quantity changes would prevent
 * nothing — checkout of that stranded cart is already refused by QuoteOnlyCartValidator.
 */
class QuoteOnlyCartItemAddRoute extends AbstractCartItemAddRoute
{
    public function __construct(
        private readonly AbstractCartItemAddRoute $decorated,
        private readonly QuoteOnlyModeService $quoteOnlyMode,
    ) {
    }

    public function getDecorated(): AbstractCartItemAddRoute
    {
        return $this->decorated;
    }

    public function add(Request $request, Cart $cart, SalesChannelContext $context, ?array $items): CartResponse
    {
        if ($this->quoteOnlyMode->appliesTo($context)) {
            throw QuoteOnlyModeException::cartDisabled();
        }

        return $this->decorated->add($request, $cart, $context, $items);
    }
}
