<?php
/**
 * Quote-only mode — removes the Add to Cart button, by adding layout handles.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY A HANDLE AND NOT AN UNCONDITIONAL LAYOUT FILE
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Quote-only mode can be scoped to guests, or to particular customer groups, so the Add to
 * Cart button has to disappear for SOME visitors and stay for others. A plain
 * `catalog_product_view.xml` in this module would apply to everyone. Handles added at
 * request time can be conditional; the layout file they pull in cannot.
 *
 * `layout_load_before` is where Magento itself says handles belong: the dispatch at
 * Magento\Framework\View\Layout\Builder::loadLayoutUpdates() (Builder.php:78-82) is preceded
 * by core's own comment, "dispatch event for adding handles to layout update", and
 * `$this->layout->getUpdate()->load()` runs two lines later (Builder.php:86).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * FULL-PAGE CACHE — why this is safe
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * A handle added per request would be a cache-poisoning bug if the decision depended on
 * something the FPC does not vary on. It does not. QuoteOnlyMode::isActive() reads only
 * store-scoped config plus two customer facts — signed-in, and customer group — and Magento
 * already puts both into the HTTP context that produces the vary hash:
 * Magento\Customer\Model\App\Action\ContextPlugin sets Context::CONTEXT_GROUP (line 50) and
 * Context::CONTEXT_AUTH (line 55) on every frontend action
 * (vendor/magento/module-customer/etc/frontend/di.xml:23-26). So a guest and a Trade-group
 * customer get different cache entries and therefore different markup, without this module
 * declaring a vary dimension of its own.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE HANDLES ARE SPLIT BY PAGE
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * The removals name product-page blocks and the CTA notice names a cart-page container.
 * Applying either everywhere would leave broken references logged on every other page of the
 * store. `full_action_name` comes free with the event, so each handle is added only where
 * its targets exist.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyLayoutHandle implements ObserverInterface
{
    /** Applied on every page: adds the body class the stylesheet keys off. */
    public const HANDLE_GLOBAL = 'tackquote_quote_only';

    /** Product page only: removes core's two Add to Cart blocks. */
    public const HANDLE_PRODUCT = 'tackquote_quote_only_product';

    /** Cart page only: renders the "request a quote instead" notice. */
    public const HANDLE_CART = 'tackquote_quote_only_cart';

    /** Magento's full action name for the product view page. */
    private const ACTION_PRODUCT_VIEW = 'catalog_product_view';

    /** Magento's full action name for the cart page. */
    private const ACTION_CART_INDEX = 'checkout_cart_index';

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     */
    public function __construct(QuoteOnlyMode $quoteOnlyMode)
    {
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->quoteOnlyMode->isActive()) {
            return;
        }

        $layout = $observer->getData('layout');

        if (!$layout instanceof LayoutInterface) {
            return;
        }

        $update = $layout->getUpdate();
        $update->addHandle(self::HANDLE_GLOBAL);

        $action = (string) $observer->getData('full_action_name');

        if ($action === self::ACTION_PRODUCT_VIEW) {
            $update->addHandle(self::HANDLE_PRODUCT);
        }

        if ($action === self::ACTION_CART_INDEX) {
            $update->addHandle(self::HANDLE_CART);
        }
    }
}
