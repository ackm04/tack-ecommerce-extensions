<?php
/**
 * Turns a product page submission into a correctly priced quote line item.
 *
 * WHY THIS EXISTS — the previous implementation did:
 *
 *     'unitPrice' => (float) $product->getPrice()
 *
 * `getPrice()` reads the `price` attribute directly, which is only meaningful for a
 * simple product. For the rest of Magento's catalog it is wrong or absent:
 *
 *   * configurable — the price attribute lives on the child simple products, so the
 *     parent commonly returns 0. Almost every apparel product in Luma is configurable.
 *   * bundle       — price is computed from the selected options; the attribute alone
 *     says nothing.
 *   * grouped      — the parent has no price at all; its associated products do.
 *   * any product  — special prices, catalog price rules, tier prices and tax settings
 *     are all ignored by the raw attribute.
 *
 * So sellers received quotes for the wrong amount, most often 0.00, on the majority of a
 * typical catalog. This resolves the real, customer-visible price through the price-info
 * pipeline, and resolves a configurable's chosen variant to the child SKU actually being
 * quoted.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ProductQuoteResolver
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var Configurable
     */
    private $configurableType;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ProductOptionRequirement
     */
    private $optionRequirement;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param Configurable $configurableType
     * @param PriceCurrencyInterface $priceCurrency
     * @param ProductOptionRequirement $optionRequirement
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        Configurable $configurableType,
        PriceCurrencyInterface $priceCurrency,
        ProductOptionRequirement $optionRequirement,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->configurableType = $configurableType;
        $this->priceCurrency = $priceCurrency;
        $this->optionRequirement = $optionRequirement;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Whether the product is sellable on the website the shopper is browsing.
     *
     * Matters on a shared catalogue: without it a SKU assigned only to website B is
     * quotable from website A's storefront, priced in website A's scope.
     *
     * @param Product $product
     * @return bool
     */
    private function isAssignedToCurrentWebsite(Product $product): bool
    {
        try {
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        } catch (\Exception $e) {
            // Cannot determine scope (CLI, cron): do not block on it.
            return true;
        }

        $assigned = $product->getWebsiteIds();

        // An unsaved or partially loaded product may report no websites at all; treating
        // that as "not assigned" would reject legitimate products in single-site installs.
        if (!is_array($assigned) || $assigned === []) {
            return true;
        }

        return in_array($websiteId, array_map('intval', $assigned), true);
    }

    /**
     * Build a Tack line item from a submitted SKU, quantity and any chosen options.
     *
     * @param string $sku Parent SKU as rendered on the product page.
     * @param int $qty
     * @param array $superAttribute Configurable selections, keyed attributeId => optionId.
     * @return array|null {sku, name, quantity, unitPrice, externalProductId, options};
     *         null when the SKU does not resolve to a visible product.
     */
    public function resolve(string $sku, int $qty, array $superAttribute = []): ?array
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        /*
         * ProductRepository::get() throws ONLY for a SKU that does not exist — it applies
         * no status, visibility or website filter. Without these checks a quote list that
         * outlives a product being disabled still quotes it, and a direct POST can quote a
         * child variant that is "Not Visible Individually" or a SKU belonging to another
         * website in a shared catalogue.
         */
        if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
            return null;
        }

        if (!$this->isAssignedToCurrentWebsite($product)) {
            return null;
        }

        $quoted = $product;
        $options = '';

        // For a configurable, quote the variant the buyer actually chose. Quoting the
        // parent would name a SKU the seller cannot fulfil and price it at the parent's
        // (usually empty) price attribute.
        if ($product->getTypeId() === Configurable::TYPE_CODE && $superAttribute !== []) {
            $child = $this->resolveConfigurableChild($product, $superAttribute);
            if ($child !== null) {
                $quoted = $child;
                $options = $this->describeSelection($product, $superAttribute);
            }
        }

        /*
         * If we are still holding a container product, we could not turn the request into
         * something the seller can actually fulfil. Signal that explicitly rather than
         * quoting the parent: the parent's price for a configurable is the MINIMUM across
         * all variants ("as low as"), so a silent fallback produces a plausible-looking
         * but wrong price on a SKU that cannot be shipped.
         *
         * Bundle and grouped selections are not resolvable here at all yet, so they land
         * in this branch by design and are refused rather than mis-quoted.
         */
        if ($this->optionRequirement->requiresSelection($quoted)) {
            return ['unresolvedSelection' => true, 'sku' => (string) $product->getSku()];
        }

        return [
            'sku' => (string) $quoted->getSku(),
            'name' => (string) $quoted->getName(),
            'quantity' => $qty,
            'unitPrice' => $this->resolvePrice($quoted),
            'externalProductId' => (string) $quoted->getId(),
            'options' => $options,
        ];
    }

    /**
     * The real, customer-visible price of a product.
     *
     * Resolved through the price-info pipeline so special prices, catalog rules and tier
     * prices are all honoured.
     *
     * Falls back through final_price -> getFinalPrice() -> getPrice() rather than
     * returning 0 on an unexpected product type: a wrong-but-plausible price is easier
     * for a seller to spot and correct than a silent zero.
     *
     * @param Product $product
     * @return float
     */
    private function resolvePrice(Product $product): float
    {
        try {
            $amount = $product->getPriceInfo()->getPrice('final_price')->getAmount();
            $value = $amount->getValue();
            if ($value !== null && (float) $value > 0.0) {
                return $this->priceCurrency->round((float) $value);
            }
        } catch (\Exception $e) {
            $this->logger->debug(
                'TackQuote: price_info unavailable for ' . $product->getSku() . ': ' . $e->getMessage()
            );
        }

        $final = (float) $product->getFinalPrice();
        if ($final > 0.0) {
            return $this->priceCurrency->round($final);
        }

        return $this->priceCurrency->round((float) $product->getPrice());
    }

    /**
     * The configurable child matching the buyer's chosen options.
     *
     * @param Product $product
     * @param array $superAttribute Configurable selections, keyed attributeId => optionId.
     * @return Product|null
     */
    private function resolveConfigurableChild(Product $product, array $superAttribute): ?Product
    {
        try {
            /** @var Product|null $child */
            $child = $this->configurableType->getProductByAttributes($superAttribute, $product);

            return $child instanceof Product ? $child : null;
        } catch (\Exception $e) {
            $this->logger->debug(
                'TackQuote: could not resolve configurable child for ' . $product->getSku()
                . ': ' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Human-readable rendering of the chosen options, e.g. "Size: M, Color: Blue".
     *
     * Lets the seller see the selection on the quote rather than an opaque child SKU.
     *
     * @param Product $product
     * @param array $superAttribute Configurable selections, keyed attributeId => optionId.
     * @return string
     */
    private function describeSelection(Product $product, array $superAttribute): string
    {
        $parts = [];

        try {
            foreach ($this->configurableType->getConfigurableAttributes($product) as $attribute) {
                $attributeId = (int) $attribute->getAttributeId();
                if (!isset($superAttribute[$attributeId])) {
                    continue;
                }
                $productAttribute = $attribute->getProductAttribute();
                $label = (string) $productAttribute->getStoreLabel();
                $optionText = $productAttribute->getSource()
                    ->getOptionText($superAttribute[$attributeId]);
                if ($label !== '' && $optionText) {
                    $parts[] = $label . ': ' . (is_array($optionText) ? implode(' / ', $optionText) : $optionText);
                }
            }
        } catch (\Exception $e) {
            return '';
        }

        return implode(', ', $parts);
    }
}
