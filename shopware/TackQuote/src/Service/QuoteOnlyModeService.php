<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Service;

use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The single decision point for "is this storefront in B2B quote-only mode for THIS visitor?".
 *
 * Everything that reacts to quote-only mode — the cart-add guard
 * (Core/Checkout/Cart/QuoteOnlyCartItemAddRoute), the checkout block
 * (Core/Checkout/Cart/QuoteOnlyCartValidator), the storefront templates via
 * Storefront/Framework/Twig/QuoteOnlyTwigExtension, and the HTTP-cache key
 * (Framework/Adapter/Cache/QuoteOnlyCacheCookieSubscriber) — asks THIS class.
 *
 * That is deliberate. If the template asked one question and the server asked a
 * different one, the two would drift and the store would end up showing a button that
 * the server refuses, or hiding one that it would have honoured.
 *
 * Config is read per sales channel, so a merchant can run one B2C channel normally and
 * a second B2B channel quote-only from the same installation.
 */
class QuoteOnlyModeService
{
    /**
     * Everyone: the whole storefront is a catalog, nobody can buy.
     */
    public const SCOPE_EVERYONE = 'everyone';

    /**
     * Guests only: anonymous visitors and guest-checkout customers must request a quote;
     * a registered (approved) B2B customer keeps a working cart. This is the setting that
     * makes "apply, get approved, then you may order" possible.
     */
    public const SCOPE_GUESTS = 'guests';

    /**
     * Specific customer groups: quote-only applies only to the groups selected in the
     * plugin config. Anyone outside those groups keeps the cart.
     */
    public const SCOPE_GROUPS = 'groups';

    public const CONFIG_ENABLED = 'TackQuote.config.quoteOnlyMode';
    public const CONFIG_SCOPE = 'TackQuote.config.quoteOnlyScope';
    public const CONFIG_GROUP_IDS = 'TackQuote.config.quoteOnlyCustomerGroupIds';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function appliesTo(SalesChannelContext $context): bool
    {
        $salesChannelId = $context->getSalesChannelId();

        if (!$this->systemConfigService->getBool(self::CONFIG_ENABLED, $salesChannelId)) {
            return false;
        }

        if ($this->isExemptOperator($context)) {
            return false;
        }

        return $this->matchesAudience($context, $salesChannelId);
    }

    /**
     * Staff must always be able to transact, or the merchant cannot test their own store
     * and support cannot place an order on a customer's behalf.
     *
     * Both signals are verified against Shopware 6.6.10.22 core source rather than guessed:
     *
     *  - getImitatingUserId() is set when an administrator uses "log in as customer" from
     *    the Administration. Core reads exactly this to decide the session is an imitated
     *    one — see vendor/shopware/core/System/SalesChannel/Context/CartRestorer.php:166
     *    and Framework/Routing/SalesChannelRequestContextResolver.php:73.
     *
     *  - AdminSalesChannelApiSource is the context source Shopware stamps on a
     *    sales-channel context that was created from the Administration, which is how the
     *    admin order module builds and edits carts — see
     *    vendor/shopware/core/System/SalesChannel/Context/BaseContextFactory.php:248 and
     *    Checkout/Cart/Rule/AdminSalesChannelSourceRule.php:35. AdminApiSource and
     *    SystemSource are covered for the same reason: neither is a storefront shopper.
     */
    public function isExemptOperator(SalesChannelContext $context): bool
    {
        if ($context->getImitatingUserId() !== null) {
            return true;
        }

        $source = $context->getContext()->getSource();

        return $source instanceof AdminSalesChannelApiSource
            || $source instanceof AdminApiSource
            || $source instanceof SystemSource;
    }

    /**
     * True when quote-only mode is on for this sales channel AND scoped to specific customer
     * groups — i.e. when the rendered page depends on the visitor's customer group and the
     * HTTP cache therefore has to distinguish them.
     *
     * Only QuoteOnlyCacheCookieSubscriber needs this. It deliberately does NOT apply the
     * operator exemption: an admin imitating a customer must not be able to poison the shared
     * cache bucket with a page rendered under an exemption they alone have.
     */
    public function isGroupScoped(SalesChannelContext $context): bool
    {
        $salesChannelId = $context->getSalesChannelId();

        return $this->systemConfigService->getBool(self::CONFIG_ENABLED, $salesChannelId)
            && $this->systemConfigService->getString(self::CONFIG_SCOPE, $salesChannelId) === self::SCOPE_GROUPS;
    }

    private function matchesAudience(SalesChannelContext $context, string $salesChannelId): bool
    {
        $scope = $this->systemConfigService->getString(self::CONFIG_SCOPE, $salesChannelId);

        switch ($scope) {
            case self::SCOPE_GUESTS:
                $customer = $context->getCustomer();

                return $customer === null || $customer->getGuest();

            case self::SCOPE_GROUPS:
                return \in_array(
                    mb_strtolower($context->getCustomerGroupId()),
                    $this->configuredGroupIds($salesChannelId),
                    true
                );

            case self::SCOPE_EVERYONE:
            default:
                // An empty or unrecognised scope falls back to "everyone" ON PURPOSE.
                // The master switch is on, so the merchant has said "this store does not
                // sell directly". A typo in the scope value must not silently put the
                // Add to cart button back on every product page.
                return true;
        }
    }

    /**
     * @return list<string>
     */
    private function configuredGroupIds(string $salesChannelId): array
    {
        $configured = $this->systemConfigService->get(self::CONFIG_GROUP_IDS, $salesChannelId);

        if (!\is_array($configured)) {
            return [];
        }

        $ids = [];
        foreach ($configured as $id) {
            if (\is_string($id) && $id !== '') {
                // sw-entity-multi-id-select stores lowercase hex ids; SalesChannelContext
                // returns them the same way. Normalise anyway so a hand-edited config
                // value in a different case still matches.
                $ids[] = mb_strtolower($id);
            }
        }

        return $ids;
    }
}
