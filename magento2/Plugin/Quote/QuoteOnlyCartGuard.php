<?php
/**
 * Quote-only (B2B catalog) mode — THE REFUSAL.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXTENSION POINT AND NOT A CONTROLLER PLUGIN
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Hiding the Add to Cart button is not a policy — it is a CSS class, and anyone can
 * `curl -d 'product=42&qty=1' https://store/checkout/cart/add/`. So the refusal happens in
 * PHP, inside the model every add path funnels through.
 *
 * The guard is on **Magento\Quote\Model\Quote::addProduct()**
 * (vendor/magento/module-quote/Model/Quote.php:1630), which is marked `@api` at
 * Quote.php:34 — a stability guarantee Magento gives for exactly this purpose. It is
 * deliberately NOT a plugin on Magento\Checkout\Controller\Cart\Add:
 *
 *   - A controller plugin guards ONE route. `checkout/cart/add` is not the only way a
 *     product reaches a cart: related-product checkboxes, wishlist "Add to Cart",
 *     `checkout/cart/addgroup`, and any third-party module that calls the cart directly all
 *     bypass it. Every one of those ends at Quote::addProduct().
 *   - Magento\Checkout\Model\Cart::addProduct() (module-checkout/Model/Cart.php:380) was the
 *     other candidate and is rejected for a stated reason: its own class docblock carries
 *     `@deprecated 100.1.0 Use \Magento\Quote\Model\Quote instead`. It is also merely a
 *     caller — Cart.php:392 is literally `$result = $this->getQuote()->addProduct($product,
 *     $request);` — so guarding Quote::addProduct() catches it and everything else at once.
 *
 * A `before` plugin that throws aborts the intercepted call, so core's cart code never runs.
 * `LocalizedException` is chosen over a generic exception because the storefront already
 * knows how to present it: Magento\Checkout\Controller\Cart\Add::execute() catches
 * `\Magento\Framework\Exception\LocalizedException` at Add.php:170 and renders the message
 * to the shopper, rather than producing a 500 or a blank page.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * HOW ADMIN AND INTEGRATIONS STAY EXEMPT
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Quote::addProduct() is also how the ADMIN builds an order by hand (Sales > Orders > Create
 * New Order) and how the Web API adds items. Neither may be refused: quote-only mode is a
 * STOREFRONT policy, and blocking the admin would stop the merchant taking a phone order and
 * stop TackQuote converting the very quotes the mode exists to collect.
 *
 * The exemption is STRUCTURAL, not a runtime `if`: this plugin is declared only in
 * `etc/frontend/di.xml`, so Magento generates the interceptor for the frontend area alone.
 * Area-scoped di.xml is Magento's own mechanism for this — core does the same thing with
 * Magento\Customer\Model\App\Action\ContextPlugin, declared in
 * vendor/magento/module-customer/etc/frontend/di.xml:23-26. A runtime area check could be
 * got wrong or removed; a file that is never loaded in adminhtml cannot be.
 *
 * KNOWN LIMIT, stated rather than papered over: the Web API areas (`webapi_rest`,
 * `webapi_soap`) and `graphql` are NOT guarded, and Magento's own Luma checkout places its
 * order through `webapi_rest`. Adding items to a guest cart over REST
 * (`POST /rest/V1/guest-carts/{cartId}/items`) is an anonymous endpoint and would therefore
 * still succeed. Guarding those areas would need UserContextInterface to tell an admin or
 * integration token from an anonymous storefront caller — that interface is only bound in
 * the webapi areas, so the same plugin cannot serve both — and getting it wrong would break
 * quote conversion. This is UNVERIFIED against a running store and is documented in
 * README.md; a merchant who needs the REST surface closed should disable the anonymous
 * guest-cart endpoints in `webapi.xml`, which is a store-wide decision, not this module's to
 * make.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Quote;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyCartGuard
{
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
     * Refuse to put anything new into the cart.
     *
     * Signature mirrors Magento\Quote\Model\Quote::addProduct() (Quote.php:1630-1634). A
     * `before` plugin returning void leaves the arguments untouched; throwing aborts the
     * call before core's code runs.
     *
     * @param Quote $subject
     * @param Product $product
     * @param mixed $request
     * @param string $processMode
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddProduct(
        Quote $subject,
        Product $product,
        $request = null,
        $processMode = null
    ): void {
        $this->refuse();
    }

    /**
     * The refusal itself.
     *
     * @return void
     * @throws LocalizedException
     */
    private function refuse(): void
    {
        if (!$this->quoteOnlyMode->isActive()) {
            return;
        }

        throw new LocalizedException(
            __(
                'This store is quote-only. Add the products you need to your quote list and '
                . 'request a price — checkout is not available here.'
            )
        );
    }
}
