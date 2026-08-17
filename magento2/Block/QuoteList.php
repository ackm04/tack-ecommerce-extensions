<?php
/**
 * Floating quote-list widget + the shared quote form, rendered once per storefront page.
 *
 * ARCHITECTURE — the multi-step form lives HERE, not on the product page. A shopper can
 * build a quote list from category pages, search results or several product pages, so the
 * form has to exist site-wide; rendering a second copy on product pages would duplicate
 * every field, every validation rule and every policy branch. The product page therefore
 * only renders triggers (Block\RequestQuote), and both "Request a Quote" and the drawer's
 * submit open this one form — the first seeded with the current product, the second with
 * the whole list.
 *
 * The list itself lives in the browser (localStorage), never in the Magento cart. Quoting
 * must not touch stock, cart totals or checkout, and Magento's own cart is internally
 * called a "quote" (Magento_Quote) — building on it would collide with that name for every
 * future reader. The WooCommerce plugin reached the same conclusion and says so in its own
 * source.
 *
 * This block is CACHEABLE and renders no per-visitor state: item count and rows are drawn
 * client-side from localStorage. A server-rendered count would bake one shopper's list into
 * the full-page cache and serve it to everyone.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\RegistrationConfigProvider;

class QuoteList extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var RegistrationConfigProvider
     */
    private $registrationConfig;

    /**
     * @param Context $context
     * @param Config $config
     * @param RegistrationConfigProvider $registrationConfig
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        RegistrationConfigProvider $registrationConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->registrationConfig = $registrationConfig;
    }

    /**
     * Whether the shared quote form should render at all.
     *
     * The form is needed whenever either entry point is available: the single-product
     * request button, or the multi-product quote list.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isButtonEnabled() || $this->config->isAddToQuoteEnabled();
    }

    /**
     * Whether the floating list widget itself should render.
     *
     * The form can be enabled without the list (single-product requests only).
     *
     * @return bool
     */
    public function isListEnabled(): bool
    {
        return $this->config->isAddToQuoteEnabled();
    }

    /**
     * Heading shown at the top of the quote form modal.
     *
     * @return string
     */
    public function getFormTitle(): string
    {
        return $this->config->getButtonLabel();
    }

    /**
     * Label for the button that submits the whole quote list.
     *
     * @return string
     */
    public function getSubmitLabel(): string
    {
        return $this->config->getCheckoutButtonLabel();
    }

    /**
     * URL of this module's own storefront submit controller.
     *
     * @return string
     */
    public function getSubmitUrl(): string
    {
        return $this->getUrl('tackquote/quote/submit');
    }

    /**
     * The seller's registration policy, as configured in TackQuote.
     *
     * Safe to render into full-page-cached HTML: it depends only on the tenant's settings,
     * not on who is browsing. That is the opposite of the form key, which is per-session
     * and must never be cached.
     *
     * @return array<string, mixed>|null
     */
    public function getRegistrationConfig(): ?array
    {
        return $this->registrationConfig->get((int) $this->_storeManager->getStore()->getId());
    }

    /**
     * The registration policy as JSON, for the form's client-side renderer.
     *
     * @return string
     */
    public function getRegistrationConfigJson(): string
    {
        $config = $this->getRegistrationConfig();

        /*
         * NOT Json::serialize() — that is a bare json_encode() with no escaping flags, and
         * this value is emitted inside <script type="text/x-magento-init">. <script> is a
         * raw-text element, so the HTML parser ends it at the first literal "</script"
         * regardless of JSON quoting. A seller-configured custom-field label containing
         * `</script><script>…` would therefore break out and execute — on EVERY storefront
         * page, since this block renders site-wide, and for 15 minutes at a time because
         * the policy is cached.
         *
         * JSON_HEX_TAG escapes < and > to < / >, which is still valid JSON and
         * parses back to the original characters. The other flags close the equivalent
         * holes for inline event handlers and quoted attributes.
         */
        $payload = $config ?? ['mode' => 'buyer_only', 'unavailable' => true];

        $encoded = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        // json_encode returns false on malformed UTF-8 from the API; emit a safe, inert
        // policy rather than an empty attribute that would break the x-magento-init JSON.
        return $encoded !== false ? $encoded : '{"mode":"buyer_only","unavailable":true}';
    }

    /**
     * Whether the buyer may supply company details, per the seller's policy.
     *
     * @return bool
     */
    public function isCompanyStepEnabled(): bool
    {
        $config = $this->getRegistrationConfig();

        return $config !== null && ($config['allowCompany'] ?? false);
    }

    /**
     * Whether a company is the ONLY option — no individual registrations accepted.
     *
     * @return bool
     */
    public function isCompanyRequired(): bool
    {
        $config = $this->getRegistrationConfig();

        return $config !== null && ($config['mode'] ?? '') === 'company_only';
    }

    /**
     * Built-in company fields the seller marked required.
     *
     * @return string[]
     */
    public function getRequiredCompanyFields(): array
    {
        $config = $this->getRegistrationConfig();
        $fields = $config['requiredCompanyFields'] ?? [];

        return is_array($fields) ? $fields : [];
    }

    /**
     * Seller-defined extra questions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomFields(): array
    {
        $config = $this->getRegistrationConfig();
        $fields = $config['customFields'] ?? [];

        return is_array($fields) ? $fields : [];
    }
}
