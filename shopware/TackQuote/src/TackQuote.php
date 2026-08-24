<?php declare(strict_types=1);

namespace TackQuote\TackQuote;

use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheCookieEvent;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use TackQuote\TackQuote\Framework\Adapter\Cache\QuoteOnlyCacheCookieSubscriber;

/**
 * TackQuote Shopware 6 companion plugin.
 *
 * Primary catalog / order sync for Shopware still runs from TackQuote against
 * the Shopware Admin API (client-credentials OAuth) — see
 * apps/api/src/modules/integrations/shopware/shopware.service.ts and
 * Settings → Integrations → Shopware 6 in the seller portal. This plugin adds
 * the store-side half: a config screen for the TackQuote tenant/API details,
 * and a storefront "Request a Quote" button that submits into TackQuote's
 * public widget quote-request endpoint.
 *
 * Not published on the Shopware Store — install via a Composer path
 * repository or by copying this directory into `custom/plugins/`.
 */
class TackQuote extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.xml');

        // HttpCacheCookieEvent is the extension point that lets a plugin add its own
        // dimension to the reverse-proxy cache key. It does not exist on every Shopware
        // version this plugin declares support for in composer.json, and a subscriber whose
        // getSubscribedEvents() names a missing class is a fatal error at container compile
        // time — i.e. the whole shop would fail to boot rather than lose one cache
        // dimension. So the subscriber is dropped when the class is absent; the quote-only
        // guard itself does not depend on it.
        //
        // UNVERIFIED: which Shopware minor first shipped HttpCacheCookieEvent. It is present
        // in 6.6.10.22 (the version verified against on disk); the guard here exists precisely
        // because that could not be confirmed for 6.5.x from the sources available.
        if (!class_exists(HttpCacheCookieEvent::class)
            && $container->hasDefinition(QuoteOnlyCacheCookieSubscriber::class)) {
            $container->removeDefinition(QuoteOnlyCacheCookieSubscriber::class);
        }

        parent::build($container);
    }
}
