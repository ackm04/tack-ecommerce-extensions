<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use TackQuote\TackQuote\Core\Checkout\Cart\Error\QuoteOnlyModeError;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;

/**
 * Second half of the enforcement, covering the case QuoteOnlyCartItemAddRoute cannot:
 * a cart that ALREADY had line items when quote-only mode was switched on (or when the
 * visitor's customer group changed, or when a saved session was restored).
 *
 * Blocking the add route stops new items; it does nothing about an existing basket. Without
 * this validator a shopper mid-session would still be able to walk that basket through
 * checkout and place a real order in a store the merchant believes is catalog-only.
 *
 * A blocking error is the right mechanism rather than a decorated checkout controller,
 * because core enforces it at the point of no return: OrderPersister::persist() throws on
 * $cart->getErrors()->blockOrder() before it writes the order
 * (vendor/shopware/core/Checkout/Cart/Order/OrderPersister.php:38-40), and every cart
 * calculation runs the tagged validators (vendor/shopware/core/Checkout/Cart/Processor.php:62-63).
 * CartOrderRoute::order() recalculates the cart immediately before persisting
 * (vendor/shopware/core/Checkout/Cart/SalesChannel/CartOrderRoute.php:75 then :87), so the
 * error is guaranteed to be present at that moment — including for the Store API, which
 * shares that route.
 *
 * The empty-cart case is skipped on purpose: an empty cart cannot be ordered anyway
 * (OrderPersister::persist() rejects it at line 46), and raising an error on it would put a
 * red banner on the cart page of every visitor who has bought nothing.
 */
class QuoteOnlyCartValidator implements CartValidatorInterface
{
    public function __construct(private readonly QuoteOnlyModeService $quoteOnlyMode)
    {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        if (!$this->quoteOnlyMode->appliesTo($context)) {
            return;
        }

        $errors->add(new QuoteOnlyModeError());
    }
}
