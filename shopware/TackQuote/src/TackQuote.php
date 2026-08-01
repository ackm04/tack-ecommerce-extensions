<?php declare(strict_types=1);

namespace TackQuote\TackQuote;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

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

        parent::build($container);
    }
}
