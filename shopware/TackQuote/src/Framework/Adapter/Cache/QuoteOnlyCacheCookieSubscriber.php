<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Framework\Adapter\Cache;

use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheCookieEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;

/**
 * Adds the customer group to the HTTP cache hash so the "specific customer groups" scope
 * renders correctly behind the reverse proxy.
 *
 * Why this is needed: core's cache hash is built from rule ids, version, currency, tax state
 * and a coarse logged-in / not-logged-in flag — see
 * vendor/shopware/core/Framework/Adapter/Cache/Http/CacheResponseSubscriber.php:238-246.
 * The customer GROUP is not part of it. So with scope = "groups", two logged-in customers in
 * different groups share one cache entry, and whichever of them warmed it decides whether the
 * other sees "Add to cart" or "Request a quote".
 *
 * That is a display bug, never a security hole — the server-side guard reads the live
 * SalesChannelContext and is not cached. But a B2B buyer being shown a cart button that then
 * 403s is a bad enough experience to be worth the extra cache dimension, and the cost is one
 * extra bucket only for merchants who actually chose the group scope.
 *
 * The scope check keeps the cache from being fragmented for the "everyone" and "guests"
 * scopes, which core's existing hash already covers correctly.
 *
 * Registered conditionally: HttpCacheCookieEvent was not present in every Shopware version
 * this plugin declares support for, so TackQuote::build() removes this service when the class
 * does not exist. See the note there.
 */
class QuoteOnlyCacheCookieSubscriber implements EventSubscriberInterface
{
    public const CACHE_PART = 'tackquote-customer-group';

    public function __construct(private readonly QuoteOnlyModeService $quoteOnlyMode)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            HttpCacheCookieEvent::class => 'onCacheCookie',
        ];
    }

    public function onCacheCookie(HttpCacheCookieEvent $event): void
    {
        $context = $event->context;

        if (!$this->quoteOnlyMode->isGroupScoped($context)) {
            return;
        }

        $event->add(self::CACHE_PART, $context->getCustomerGroupId());
    }
}
