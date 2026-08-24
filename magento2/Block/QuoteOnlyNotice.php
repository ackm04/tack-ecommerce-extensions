<?php
/**
 * The "this store works by quote" panel shown on the cart page in quote-only mode.
 *
 * Rendered by the `tackquote_quote_only_cart` layout handle, which
 * Observer\QuoteOnlyLayoutHandle adds only on `checkout_cart_index` and only for visitors
 * the mode actually applies to.
 *
 * It exists because of the redirect in Plugin\Checkout\QuoteOnlyCheckoutGuard: a shopper who
 * clicks "Proceed to Checkout" is bounced back to the cart, and a bounce with only a
 * fading message reads as a broken shop. This panel says what the store is and offers the
 * action that still works.
 *
 * isVisible() re-asks QuoteOnlyMode rather than trusting the handle. The handle is the
 * reason this block is on the page at all, so in practice the answer is always true — but a
 * block that renders a "you cannot check out" panel on a store where checkout works is a bad
 * enough failure to be worth one extra config read.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyNotice extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param Context $context
     * @param Config $config
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        QuoteOnlyMode $quoteOnlyMode,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * @return bool
     */
    public function isVisible(): bool
    {
        return $this->quoteOnlyMode->isActive();
    }

    /**
     * The merchant's own wording for the request button, so the panel matches the button the
     * shopper has been seeing on every product page.
     *
     * @return string
     */
    public function getRequestLabel(): string
    {
        return $this->config->getButtonLabel();
    }

    /**
     * @return string
     */
    public function getContinueShoppingUrl(): string
    {
        return $this->getUrl('');
    }
}
