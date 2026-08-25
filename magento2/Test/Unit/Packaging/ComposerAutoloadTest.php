<?php
/**
 * Guards the one packaging mistake that is invisible everywhere it is normally checked.
 *
 * `autoload.psr-4` names the shipping namespaces one by one, deliberately: mapping the
 * module root instead made the whole Test/ tree — and therefore PHPUnit, an undeclared
 * dependency — resolvable on a live store. The cost of naming them is that a NEW top-level
 * source directory has to be added by hand, and forgetting is silent:
 *
 *   - `app/code` installs (this suite, and every dev store) resolve the classes anyway,
 *     through the PSR-0 `"" => app/code/` fallback in Magento's own root composer.json, so
 *     the tests stay green;
 *   - `setup:di:compile` and `php -l` see nothing wrong;
 *   - only a Marketplace / `composer require` install into vendor/ actually fails, and it
 *     fails by the classes simply never loading — for a plugin declared in di.xml that
 *     means the interception silently does not happen.
 *
 * That is exactly how `Plugin\` — the whole of quote-only enforcement — was left unmapped.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Packaging;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ComposerAutoloadTest extends TestCase
{
    private const NAMESPACE_PREFIX = 'TackQuote\\Quotes\\';

    /** @var string */
    private $root;

    /** @var array<string, mixed> */
    private $composer;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
        $json = file_get_contents($this->root . '/composer.json');
        self::assertIsString($json, 'composer.json is unreadable');
        $this->composer = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Top-level directories that contain at least one PHP file, i.e. that ship code.
     *
     * @return string[]
     */
    private function sourceDirectories(): array
    {
        $dirs = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry[0] === '.' || !is_dir($this->root . '/' . $entry)) {
                continue;
            }
            $found = new \RegexIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->root . '/' . $entry)
                ),
                '/\.php$/'
            );
            foreach ($found as $_) {
                $dirs[] = $entry;
                break;
            }
        }
        sort($dirs);

        return $dirs;
    }

    /**
     * @return array<string, string> namespace prefix => directory
     */
    private function map(string $block): array
    {
        return $this->composer[$block]['psr-4'] ?? [];
    }

    public function testEveryTopLevelSourceDirectoryIsAutoloadable(): void
    {
        $mapped = [];
        foreach (array_merge($this->map('autoload'), $this->map('autoload-dev')) as $dir) {
            $mapped[trim($dir, '/')] = true;
        }

        $unmapped = array_values(array_filter(
            $this->sourceDirectories(),
            static fn (string $dir): bool => !isset($mapped[$dir])
        ));

        self::assertSame(
            [],
            $unmapped,
            'These directories ship PHP but are in neither autoload nor autoload-dev, so they '
            . 'will not load from a vendor/ install: ' . implode(', ', $unmapped)
        );
    }

    public function testTheProductionMapNamesEveryShippingDirectoryAndOnlyThose(): void
    {
        $production = array_map(
            static fn (string $dir): string => trim($dir, '/'),
            $this->map('autoload')
        );
        sort($production);

        $expected = array_values(array_diff($this->sourceDirectories(), ['Test']));

        self::assertSame($expected, array_values($production));
    }

    public function testTheTestSuiteIsNotAutoloadableInProduction(): void
    {
        // PHPUnit is a require-dev dependency; a production autoloader that can resolve
        // TackQuote\Quotes\Test\… is offering a live store classes whose parent class is
        // not installed.
        foreach ($this->map('autoload') as $prefix => $dir) {
            self::assertStringNotContainsString(
                'Test',
                $prefix,
                'Test\\ must live in autoload-dev, not autoload'
            );
        }

        self::assertArrayHasKey(
            self::NAMESPACE_PREFIX . 'Test\\',
            $this->map('autoload-dev'),
            'autoload-dev must map Test\\ or the suite cannot be run from a composer install'
        );
    }

    public function testEveryMappedDirectoryActuallyExists(): void
    {
        foreach (array_merge($this->map('autoload'), $this->map('autoload-dev')) as $prefix => $dir) {
            self::assertDirectoryExists(
                $this->root . '/' . trim($dir, '/'),
                sprintf('%s is mapped to %s, which does not exist', $prefix, $dir)
            );
        }
    }

    public function testTheDeclaredVersionMatchesTheModuleDeclaration(): void
    {
        // Two files carry the version and a release that disagrees with itself is a support
        // problem nobody can reproduce.
        $moduleXml = simplexml_load_file($this->root . '/etc/module.xml');
        self::assertNotFalse($moduleXml);

        self::assertSame(
            (string) $this->composer['version'],
            (string) $moduleXml->module['setup_version'],
            'composer.json version and etc/module.xml setup_version have drifted apart'
        );
    }
}
