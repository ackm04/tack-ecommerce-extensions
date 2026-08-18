<?php declare(strict_types=1);

/**
 * PHPUnit bootstrap for the TackQuote plugin's UNIT test suite.
 *
 * Deliberately does NOT boot a Shopware kernel (no `Shopware\Core\TestBootstrapper`,
 * no database, no HTTP). Everything under tests/ is a plain unit test built on mocks
 * and `Symfony\Component\HttpClient\MockHttpClient`, so the suite runs in a fraction
 * of a second and cannot pass or fail for reasons unrelated to this plugin.
 *
 * The one thing it needs from the surrounding Shopware install is the project
 * autoloader, for the framework classes this plugin's own classes extend or type
 * against (`SystemConfigService`, `StorefrontController`, Symfony's HttpClient, …).
 *
 * The plugin's own namespace is registered here rather than relying on the project
 * autoloader: a plugin dropped into `custom/plugins/` is discovered by Shopware's
 * `KernelPluginLoader` at runtime and is NOT present in the project's
 * `vendor/composer/autoload_psr4.php`, so `composer dump-autoload` in the project
 * would not help. Registering it explicitly also means the suite runs without any
 * composer command being executed first.
 */

$pluginRoot = \dirname(__DIR__);

/**
 * Walk up from the plugin directory looking for the Shopware project root. Works for
 * `custom/plugins/<Plugin>` (3 levels up) and for `custom/plugins/<Plugin>/packages/*`
 * style nesting, and can be overridden with PROJECT_ROOT for out-of-tree checkouts.
 */
$findProjectRoot = static function (string $from): ?string {
    $envRoot = getenv('PROJECT_ROOT');
    if (\is_string($envRoot) && $envRoot !== '' && is_file(rtrim($envRoot, '/') . '/vendor/autoload.php')) {
        return rtrim($envRoot, '/');
    }

    $dir = $from;
    for ($depth = 0; $depth < 8; ++$depth) {
        if (is_file($dir . '/vendor/autoload.php') && is_dir($dir . '/vendor/shopware/core')) {
            return $dir;
        }

        $parent = \dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return null;
};

$projectRoot = $findProjectRoot($pluginRoot);

if ($projectRoot === null) {
    fwrite(
        \STDERR,
        \sprintf(
            "TackQuote tests: could not locate a Shopware project root above %s.\n"
            . "Run the suite from inside a Shopware install (the plugin mounted at\n"
            . "custom/plugins/TackQuote), or set PROJECT_ROOT to the install directory.\n",
            $pluginRoot
        )
    );
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $projectRoot . '/vendor/autoload.php';

$loader->addPsr4('TackQuote\\TackQuote\\', $pluginRoot . '/src');
$loader->addPsr4('TackQuote\\TackQuote\\Test\\', $pluginRoot . '/tests');

\define('TACKQUOTE_PLUGIN_ROOT', $pluginRoot);
\define('TACKQUOTE_PROJECT_ROOT', $projectRoot);
