<?php
/**
 * Product-page quote triggers.
 *
 * Deliberately narrow. This block renders ONLY the buttons; the multi-step form, the
 * registration policy and the quote list all live in Block\QuoteList, which is rendered
 * once per page into before.body.end.
 *
 * This class previously also carried a duplicate of the registration-policy API
 * (getRegistrationConfig / getRegistrationConfigJson / isCompanyStepEnabled /
 * isCompanyRequired / getRequiredCompanyFields / getCustomFields), plus getFormKeyValue,
 * getSubmitUrl and isCustomerLoggedIn. All nine became dead when the form moved out — no
 * template referenced them — and the duplicate JSON encoder was the UNHARDENED one, using
 * Json::serialize(), which does not escape "</script>". Registration config carries
 * seller-authored custom-field labels, so wiring that method into any template would have
 * been an immediate script breakout. Two encoders where the dead one is unsafe is exactly
 * how the unsafe one gets picked up next, so it is deleted rather than repaired.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\ProductOptionRequirement;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class RequestQuote extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var ProductOptionRequirement
     */
    private $optionRequirement;

    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Config $config
     * @param Registry $registry
     * @param ProductOptionRequirement $optionRequirement
     * @param QuoteOnlyMode $quoteOnlyMode
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        Registry $registry,
        ProductOptionRequirement $optionRequirement,
        QuoteOnlyMode $quoteOnlyMode,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->registry = $registry;
        $this->optionRequirement = $optionRequirement;
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * Whether quote-only mode applies to the visitor looking at this page.
     *
     * The template uses it for emphasis (the request button becomes the primary action once
     * Add to Cart is gone), and isEnabled() uses it for something far more important — see
     * below.
     *
     * @return bool
     */
    public function isQuoteOnly(): bool
    {
        return $this->quoteOnlyMode->isActive();
    }

    /**
     * Whether the single-product "Request a Quote" trigger renders.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        // ── THE LINE THAT KEEPS THE STORE ABLE TO TRANSACT ──────────────────────────────
        //
        // `show_button` is a merchant preference: plenty of stores switch the single-product
        // request button off and rely on the multi-product quote list alone. That is fine
        // while the cart works. It is NOT fine once quote-only mode has removed Add to Cart
        // from this page, because the template bails out entirely when neither trigger is
        // enabled (view/frontend/templates/button.phtml) — leaving a product page with no
        // cart button, no quote button, and a server that refuses the POST. A catalog nobody
        // can transact with in either direction.
        //
        // So in quote-only mode the request trigger is not optional. This is the same
        // override the OpenCart build of this feature applies to its placement toggle, and
        // it is deliberately per-VISITOR (isActive(), not the raw config flag): a store
        // scoped to guests must not force the button on for signed-in customers who still
        // have a working cart.
        //
        // The dead-store invariant still holds in the other direction, because
        // QuoteOnlyMode::isActive() and Config::isButtonEnabled() both require
        // Config::isConfigured(). With no API key BOTH are false — and the guard does not
        // enforce either, so the cart keeps working. Test/Unit asserts both halves.
        return $this->config->isButtonEnabled() || $this->isQuoteOnly();
    }

    /**
     * Whether the "Add to Quote" trigger renders alongside it.
     *
     * @return bool
     */
    public function isAddToQuoteEnabled(): bool
    {
        return $this->config->isAddToQuoteEnabled();
    }

    /**
     * Label for the single-product trigger.
     *
     * @return string
     */
    public function getButtonLabel(): string
    {
        return $this->config->getButtonLabel();
    }

    /**
     * Label for the add-to-list trigger.
     *
     * @return string
     */
    public function getAddToQuoteLabel(): string
    {
        return $this->config->getAddToQuoteLabel();
    }

    /**
     * Whether this product needs a variant or option chosen before it can be quoted.
     *
     * The template renders this as a data attribute the JS honours. It is NOT a security
     * control — the controller enforces the same rule server-side, because a client-only
     * guard on a public endpoint guards nothing.
     *
     * @return bool
     */
    public function productRequiresOptions(): bool
    {
        $product = $this->getCurrentProduct();

        return $product !== null && $this->optionRequirement->requiresSelection($product);
    }

    /**
     * The product whose page is being rendered.
     *
     * @return Product|null
     */
    public function getCurrentProduct(): ?Product
    {
        $product = $this->registry->registry('current_product');

        return $product instanceof Product ? $product : null;
    }
}
