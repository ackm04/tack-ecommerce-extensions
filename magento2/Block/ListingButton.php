<?php
/**
 * "Add to Quote" renderer for product tiles on category and search listings.
 *
 * Attaches to Magento's `category.product.addto` container — the same extension point
 * Magento_Wishlist uses for "Add to Wish List". That means no core file is edited and no
 * theme template is overridden, so this survives Magento upgrades and works under any
 * theme.
 *
 * MUST extend Item\Block, not AbstractProduct: the parent container only injects the
 * current product into children implementing ProductAwareInterface, which Item\Block
 * provides. Extending AbstractProduct compiles and renders nothing at all, because
 * getProduct() stays null and the template returns early.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\ProductList\Item\Block as ItemBlock;
use Magento\Catalog\Model\Product;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\ProductOptionRequirement;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class ListingButton extends ItemBlock
{
    /**
     * @var Config
     */
    private $tackConfig;

    /**
     * @var ProductOptionRequirement
     */
    private $optionRequirement;

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param Context $context
     * @param Config $tackConfig
     * @param ProductOptionRequirement $optionRequirement
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $tackConfig,
        ProductOptionRequirement $optionRequirement,
        QuoteOnlyMode $quoteOnlyMode,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->tackConfig = $tackConfig;
        $this->optionRequirement = $optionRequirement;
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * Whether "Add to Quote" should render on listing tiles.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        if ($this->tackConfig->isListingButtonEnabled()) {
            return true;
        }

        // Quote-only mode overrides the listing toggle, for the same reason
        // RequestQuote::isEnabled() overrides `show_button`: the tile's own Add to Cart
        // button is hidden on a quote-only storefront (the `tackquote-quote-only` body class
        // plus the rule in view/frontend/web/css/request-quote.css), so leaving this off
        // would produce a category page whose tiles offer no action at all.
        //
        // Still gated on the quote LIST being available, because that is what this tile
        // button adds to. With the list switched off a tile has nothing to add to, and the
        // shopper reaches the request form from the product page instead — which is
        // documented in the setting's own help text rather than worked around by rendering a
        // second single-product form into every tile.
        return $this->quoteOnlyMode->isActive() && $this->tackConfig->isAddToQuoteEnabled();
    }

    /**
     * Label for the "Add to Quote" control.
     *
     * @return string
     */
    public function getAddToQuoteLabel(): string
    {
        return $this->tackConfig->getAddToQuoteLabel();
    }

    /**
     * Whether this product needs a selection before it can be quoted.
     *
     * Products with required options cannot be quoted from a bare parent SKU, and a
     * listing tile has no option UI — so those link to the product page instead.
     *
     * @param Product $product
     * @return bool
     */
    public function requiresOptions(Product $product): bool
    {
        return $this->optionRequirement->requiresSelection($product);
    }
}
