<?php
/**
 * Decides whether a product can be quoted straight from its SKU, or needs a selection first.
 *
 * WHY NOT `getTypeInstance()->hasRequiredOptions()` — that only reports *custom options*.
 * `Magento\Catalog\Model\Product\Type\AbstractType::hasRequiredOptions()` returns false by
 * default and neither Configurable nor Grouped overrides it, so a configurable product with
 * mandatory Size and Colour swatches reports `false`. Trusting it meant quoting the parent
 * SKU of every apparel product in a typical catalogue — a SKU the seller cannot fulfil, at
 * a price the parent usually does not carry.
 *
 * Composite types are therefore checked explicitly, and custom options on top of that.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Catalog\Model\Product;

class ProductOptionRequirement
{
    /**
     * Types whose "product" is a container: what the buyer actually receives is decided by
     * a selection, so a bare parent SKU is never quotable.
     *
     * Literals rather than class constants on purpose — referencing them would make this
     * module depend on Magento_Bundle and Magento_GroupedProduct just to name a string,
     * and the module would then fail to load on a store where either is disabled.
     */
    private const COMPOSITE_TYPES = ['configurable', 'bundle', 'grouped'];

    /**
     * Whether the product needs a selection before it can be quoted.
     *
     * @param Product $product
     * @return bool
     */
    public function requiresSelection(Product $product): bool
    {
        if (in_array((string) $product->getTypeId(), self::COMPOSITE_TYPES, true)) {
            return true;
        }

        return (bool) $product->getTypeInstance()->hasRequiredOptions($product);
    }
}
