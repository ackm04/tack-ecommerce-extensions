<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Core\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Blocking cart error raised while the storefront is in quote-only mode and the cart is
 * not empty.
 *
 * This is what stops a cart that was filled BEFORE the merchant flipped the switch (or
 * restored from a saved session) from being checked out: a blocking error makes
 * Shopware\Core\Checkout\Cart\Order\OrderPersister::persist() throw before an order row is
 * ever written — see vendor/shopware/core/Checkout/Cart/Order/OrderPersister.php:38, which
 * calls $cart->getErrors()->blockOrder(). Nothing else in core has to cooperate for that to
 * hold, which is why the block lives here rather than in a checkout controller.
 */
class QuoteOnlyModeError extends Error
{
    private const KEY = 'tackquote-quote-only-mode';

    public function __construct()
    {
        parent::__construct('This store is running in quote-only mode. Please request a quote instead of checking out.');
    }

    public function getId(): string
    {
        return self::KEY;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * Non-persistent: the QuoteOnlyCartValidator re-adds this on EVERY cart calculation for
     * as long as quote-only mode applies, so it never needs to be carried over. Making it
     * persistent would instead pin a stale copy into the cart that survives the merchant
     * turning quote-only mode back off — see the "Persistent Errors" docblock on
     * vendor/shopware/core/Checkout/Cart/Error/Error.php:53.
     */
    public function isPersistent(): bool
    {
        return false;
    }

    public function getParameters(): array
    {
        return [];
    }
}
