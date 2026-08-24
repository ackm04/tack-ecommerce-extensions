<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Storefront\Framework\Twig;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `tackquote_quote_only()` to storefront templates.
 *
 * The SalesChannelContext is pulled off the current request rather than taken as a Twig
 * argument. Shopware puts it there under PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT
 * ('sw-sales-channel-context', vendor/shopware/core/PlatformRequest.php:41) for every
 * storefront request, so this works identically in a product page, a listing card, a
 * CMS block and an off-canvas AJAX fragment — including templates that never receive the
 * `context` template variable.
 *
 * This function decides only what is DRAWN. It is not a security control: the refusal that
 * matters is TackQuote\TackQuote\Core\Checkout\Cart\QuoteOnlyCartItemAddRoute::add().
 * If this function ever disagrees with that guard, the guard wins and the shopper sees a
 * 403 — which is why both call the same QuoteOnlyModeService.
 */
class QuoteOnlyTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly QuoteOnlyModeService $quoteOnlyMode,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tackquote_quote_only', [$this, 'isQuoteOnly']),
        ];
    }

    public function isQuoteOnly(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return false;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        if (!$context instanceof SalesChannelContext) {
            // No sales-channel context means this is not a storefront render (error page
            // during boot, CLI template dump, …). Returning false keeps the default,
            // fully-featured markup; nothing can be bought from a page with no context
            // anyway, and the server-side guard is unaffected either way.
            return false;
        }

        return $this->quoteOnlyMode->appliesTo($context);
    }
}
