<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Proves the quote-only enforcement is WIRED, not merely correct.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Every other test here calls the guard directly, so they all pass whether or
 * not Shopware ever invokes it. Renaming the `decorates` target in services.xml
 * — so the guard decorates nothing and is never constructed on a real request —
 * left the whole suite green at 66 tests. A store in that state hides the buy
 * button and keeps accepting orders through the Store API: exactly the
 * "cosmetic, not enforced" failure the feature was written to avoid, shipped
 * under a green suite.
 *
 * The same blind spot was found in the WooCommerce plugin (issue #340) — its
 * suite passed with `woocommerce_is_purchasable` unregistered. It is a class of
 * defect, not a one-off, which is why it is asserted here rather than assumed.
 *
 * These assertions read the service definition as XML. That is deliberate: the
 * container is what actually decides whether the guard runs, and a test that
 * only exercises PHP objects cannot see a wiring mistake.
 */
class QuoteOnlyWiringTest extends TestCase
{
    private const CART_ITEM_ADD_ROUTE = 'Shopware\\Core\\Checkout\\Cart\\SalesChannel\\CartItemAddRoute';

    private \SimpleXMLElement $services;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Resources/config/services.xml';
        self::assertFileExists($path, 'services.xml is missing — nothing would be registered at all.');
        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'services.xml is not parseable; Shopware would fail to boot.');
        // Symfony's services.xml declares a DEFAULT namespace, so a bare //service
        // xpath silently matches nothing — which would make every assertion below
        // pass vacuously on an empty node set. Register the prefix explicitly.
        $xml->registerXPathNamespace('s', 'http://symfony.com/schema/dic/services');
        $this->services = $xml;
    }

    /** @return list<\SimpleXMLElement> */
    private function serviceNodes(): array
    {
        $nodes = $this->services->xpath('//s:service');
        return $nodes === false ? [] : $nodes;
    }

    public function testTheCartGuardDecoratesTheRealCoreRoute(): void
    {
        $decorated = [];
        foreach ($this->serviceNodes() as $node) {
            $target = (string) ($node['decorates'] ?? '');
            if ($target !== '') {
                $decorated[] = $target;
            }
        }

        self::assertContains(
            self::CART_ITEM_ADD_ROUTE,
            $decorated,
            'Nothing decorates CartItemAddRoute. The quote-only guard would never run, '
            . 'and the store would keep accepting cart adds while hiding the button.'
        );
    }

    public function testTheGuardReceivesTheInnerServiceSoTheChainIsNotBroken(): void
    {
        $found = false;
        foreach ($this->serviceNodes() as $node) {
            if ((string) ($node['decorates'] ?? '') !== self::CART_ITEM_ADD_ROUTE) {
                continue;
            }
            $found = true;
            // Child nodes returned by xpath() do NOT inherit the namespace
            // registration, so reach them through namespaced children() instead.
            $ids = [];
            foreach ($node->children('http://symfony.com/schema/dic/services')->argument as $a) {
                // With namespaced children(), attributes are only reachable via attributes().
                $ids[] = (string) ($a->attributes()['id'] ?? '');
            }
            $inner = array_filter($ids, static fn (string $id) => str_ends_with($id, '.inner'));
            self::assertNotEmpty(
                $inner,
                'The decorator does not receive the .inner service, so delegating would fail '
                . 'and adding to cart would break for everyone, not just in quote-only mode.'
            );
        }
        self::assertTrue($found, 'No decorator of CartItemAddRoute was found at all.');
    }

    public function testTheCartValidatorIsTaggedSoPreExistingCartsAreStillBlocked(): void
    {
        $tags = [];
        foreach ($this->serviceNodes() as $node) {
            foreach ($node->children('http://symfony.com/schema/dic/services')->tag as $tag) {
                $tags[] = (string) ($tag->attributes()['name'] ?? '');
            }
        }

        self::assertContains(
            'shopware.cart.validator',
            $tags,
            'No cart validator is tagged. A cart filled BEFORE quote-only mode was switched on '
            . 'could still be ordered, so the store would not actually be quote-only.'
        );
    }
}
