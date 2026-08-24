<?php
/**
 * Quote-only mode — the CHECKOUT route.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * STANDING DECISION ON THE CHECKOUT ROUTE
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * The cart cannot grow (Plugin\Quote\QuoteOnlyCartGuard) and an existing one cannot be
 * increased (Plugin\Checkout\QuoteOnlyExistingCartGuard), but a cart that predates the
 * switch could still be carried to checkout and turned into an order. So `checkout/index`
 * is refused too, and the shopper is redirected to the cart page with a stated reason rather
 * than to a blank or broken checkout.
 *
 * Redirect-with-message rather than a 404 or an exception, because Magento has a message bus
 * and using it is the difference between "the shop is broken" and "the shop works this way".
 * The cart page is the destination on purpose: it is where the shopper's items still are,
 * and — because the mode also adds the `tackquote_quote_only_cart` layout handle
 * (Observer\QuoteOnlyLayoutHandle) — it is where a Request a Quote CTA is rendered for them.
 *
 * WHY A CONTROLLER PLUGIN HERE AND A MODEL PLUGIN FOR THE CART. The cart guard deliberately
 * avoids controllers because a product can enter a cart through many routes. Checkout is the
 * opposite shape: `Magento\Checkout\Controller\Index\Index` IS the storefront checkout, and
 * intercepting it is both precise and self-explanatory. It is a concrete, non-final public
 * `execute()`, which is what Magento's interception requires.
 *
 * KNOWN LIMIT, and it is a real one. Magento's Luma checkout places the order over REST
 * (`POST /rest/V1/guest-carts/{id}/payment-information`), which runs in the `webapi_rest`
 * area — this plugin is declared in `etc/frontend/di.xml` only, so it does not fire there.
 * A crafted REST call against a cart that predates the switch could therefore still place an
 * order. That surface is left open for the same reason the cart guard leaves it open: the
 * same endpoints carry the merchant's own integrations, including TackQuote placing
 * quote-accepted orders, and telling those apart needs UserContextInterface, which is only
 * bound in the webapi areas. UNVERIFIED against a running store; documented in README.md.
 * The invariant that holds unconditionally is the one the feature is sold on: nothing new
 * can enter a cart.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Checkout;

use Magento\Checkout\Controller\Index\Index as CheckoutIndex;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyCheckoutGuard
{
    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @var RedirectFactory
     */
    private $redirectFactory;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     */
    public function __construct(
        QuoteOnlyMode $quoteOnlyMode,
        RedirectFactory $redirectFactory,
        MessageManager $messageManager
    ) {
        $this->quoteOnlyMode = $quoteOnlyMode;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * `around` rather than `before`, because a refusal has to REPLACE the controller's
     * result. A `before` plugin cannot stop `execute()` from running without throwing, and
     * throwing out of a storefront controller produces an error page instead of an
     * explanation.
     *
     * @param CheckoutIndex $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(CheckoutIndex $subject, callable $proceed)
    {
        if (!$this->quoteOnlyMode->isActive()) {
            return $proceed();
        }

        $this->messageManager->addErrorMessage(
            __('This store is quote-only. Request a quote for your items and we will send you a price.')
        );

        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
