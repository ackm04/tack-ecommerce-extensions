<?php
/**
 * Quote-only (B2B catalog) mode — is it active for THIS storefront visitor?
 *
 * One instance of the answer, shared by everything that needs it:
 *
 *   Plugin\Checkout\CartGuard          refuses the add-to-cart POST
 *   Plugin\Checkout\CheckoutGuard      refuses the checkout page and frontend order placement
 *   Observer\QuoteOnlyLayoutHandle     removes the Add to Cart button from the product page
 *   Block\RequestQuote                 renders the quote CTA in its place
 *
 * THE ONE CONDITION THAT KEEPS THE STOREFRONT ALIVE.
 *
 * isActive() starts with Config::isConfigured(), and that is load-bearing rather than an
 * optimisation. Config::isButtonEnabled() and Config::isAddToQuoteEnabled() both already
 * gate on isConfigured(), so on a store with no API key NO quote control renders anywhere —
 * a button that cannot reach TackQuote drops the shopper's request on the floor. If
 * enforcement did not honour the same condition, a merchant who enabled quote-only before
 * pasting their key would get a storefront with the cart refused AND no quote button:
 * nothing works in either direction, and nothing in the admin says why. Enforcement and CTA
 * are switched on by the same condition on purpose, and Test/Unit asserts both halves.
 *
 * FULL-PAGE CACHE. Everything this class reads is either store-scoped config or one of two
 * customer facts — signed-in, and customer group — and Magento already varies the FPC entry
 * on exactly those two: Magento\Customer\Model\App\Action\ContextPlugin sets
 * Context::CONTEXT_GROUP (line 50) and Context::CONTEXT_AUTH (line 55) into the HTTP context
 * on every frontend action, declared as a plugin on Magento\Framework\App\ActionInterface in
 * vendor/magento/module-customer/etc/frontend/di.xml:23-26. So the layout change this
 * decision drives is cached per group and per signed-in state without this module adding a
 * custom vary dimension. Adding a THIRD input to this class — anything not already in that
 * context, such as a cookie, the time of day, or the specific customer id — would silently
 * serve one visitor's storefront to another, and would need its own
 * HttpContext::setValue() before it could be trusted.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Customer\Model\Session as CustomerSession;

class QuoteOnlyMode
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @param Config $config
     * @param CustomerSession $customerSession Injected as a Proxy in etc/frontend/di.xml so
     *                                         that constructing this service never starts a
     *                                         session on its own; the session is only
     *                                         touched once the cheap config checks have
     *                                         already decided the mode might apply.
     */
    public function __construct(Config $config, CustomerSession $customerSession)
    {
        $this->config = $config;
        $this->customerSession = $customerSession;
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        // See "THE ONE CONDITION" above. Do not reorder this below the enable check without
        // reading it: on an unconfigured store this is the difference between "quote-only is
        // off" and "the storefront cannot transact at all".
        if (!$this->config->isConfigured($storeId)) {
            return false;
        }

        if (!$this->config->isQuoteOnlyEnabled($storeId)) {
            return false;
        }

        return QuoteOnlyRules::applies(
            true,
            $this->config->getQuoteOnlyScope($storeId),
            QuoteOnlyRules::normaliseGroups($this->config->getQuoteOnlyCustomerGroups($storeId)),
            (bool) $this->customerSession->isLoggedIn(),
            (int) $this->customerSession->getCustomerGroupId()
        );
    }
}
