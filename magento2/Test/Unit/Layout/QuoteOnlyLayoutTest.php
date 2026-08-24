<?php
/**
 * STRUCTURAL guards for quote-only mode.
 *
 * These tests read this module's own XML rather than mocking anything, because the two
 * things they protect are properties of the XML itself and cannot be asserted any other way
 * without booting a store:
 *
 *   1. Removing Add to Cart must NEVER remove the quote CTA. This exact failure was reached
 *      in the WooCommerce build of this feature (the quote button hung off a hook that only
 *      fires inside the add-to-cart form) and in the PrestaShop one (the theme wraps the CTA
 *      hook in `{if !$configuration.is_catalog}`). Magento's version of the coupling is
 *      layout parentage, so that is what is asserted.
 *
 *   2. The refusal must never be declared globally, because Magento's ADMIN order creation
 *      goes through the very same Quote::addProduct() the storefront does.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

class QuoteOnlyLayoutTest extends TestCase
{
    /** Module root, from Test/Unit/Layout/. */
    private const ROOT = __DIR__ . '/../../..';

    /**
     * Core parents of the blocks quote-only mode removes, from
     * vendor/magento/module-catalog/view/frontend/layout/catalog_product_view.xml in 2.4.8:
     * `product.info.addtocart` is a child of `product.info.form.content` (line 72) and
     * `product.info.addtocart.additional` a child of `product.info.options.wrapper.bottom`
     * (line 86).
     *
     * @var string[]
     */
    private const REMOVED_BLOCK_PARENTS = [
        'product.info.form.content',
        'product.info.options.wrapper.bottom',
    ];

    private function xml(string $relative): \SimpleXMLElement
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, "$relative is missing");

        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, "$relative is not well-formed XML");

        return $xml;
    }

    /**
     * @return string[]
     */
    private function removedBlockNames(): array
    {
        $names = [];

        foreach ($this->xml('view/frontend/layout/tackquote_quote_only_product.xml')
                     ->xpath('//referenceBlock[@remove="true"]') ?: [] as $node) {
            $names[] = (string) $node['name'];
        }

        return $names;
    }

    public function testBothCoreAddToCartBlocksAreRemoved(): void
    {
        // Magento renders addtocart.phtml TWICE: `product.info.addtocart` for the plain case
        // and `product.info.addtocart.additional` for products with custom options. Removing
        // only the first leaves a working-looking Add to Cart button on every configurable
        // and customisable product, which the server then refuses — the "broken shop"
        // impression this mode exists to avoid.
        $removed = $this->removedBlockNames();

        self::assertContains('product.info.addtocart', $removed);
        self::assertContains(
            'product.info.addtocart.additional',
            $removed,
            'the options-page copy of the Add to Cart button is still rendered'
        );
    }

    public function testTheQuoteCtaIsNotItselfRemoved(): void
    {
        $cta = $this->xml('view/frontend/layout/catalog_product_view.xml')
            ->xpath('//block[@class="TackQuote\Quotes\Block\RequestQuote"]');

        self::assertNotEmpty($cta, 'the product-page quote CTA block has disappeared from the layout');

        $name = (string) $cta[0]['name'];

        self::assertNotContains(
            $name,
            $this->removedBlockNames(),
            'quote-only mode is removing its own CTA — the storefront would have no cart '
            . 'button and no quote button'
        );
    }

    public function testTheQuoteCtaDoesNotLiveInsideAnythingBeingRemoved(): void
    {
        // THE CONTRACT TEST. A future edit that moves the CTA under
        // `product.info.form.content` — a natural-looking "put it next to Add to Cart"
        // change — would put it inside a container whose sibling this handle deletes, and
        // would reintroduce exactly the WooCommerce/PrestaShop failure. Magento's `after=`
        // is only a sibling hint (Structure::reorderChildElement() returns without moving
        // anything when parents differ, Structure.php:122-130), so the PARENT is what
        // matters, and the parent is what is pinned here.
        $container = $this->xml('view/frontend/layout/catalog_product_view.xml')
            ->xpath('//referenceContainer[block[@class="TackQuote\Quotes\Block\RequestQuote"]]');

        self::assertNotEmpty($container, 'the CTA is no longer inside a referenceContainer');

        $parent = (string) $container[0]['name'];

        self::assertSame(
            'product.info.main',
            $parent,
            'the quote CTA must hang off product.info.main, which quote-only mode never touches'
        );

        self::assertNotContains(
            $parent,
            self::REMOVED_BLOCK_PARENTS,
            'the quote CTA has been moved into a container that holds a block quote-only '
            . 'mode removes. Removing Add to Cart would now risk taking the quote button '
            . 'with it — the exact failure this feature must never ship.'
        );
    }

    public function testTheRefusalIsDeclaredForTheFRONTENDAREAONLY(): void
    {
        // Magento's admin order creation (Sales > Orders > Create New Order) calls the same
        // Magento\Quote\Model\Quote::addProduct(). A global declaration would refuse the
        // merchant's own phone orders and TackQuote's own quote conversion. The exemption is
        // this file's PATH, so the path is what is tested.
        $di = $this->xml('etc/frontend/di.xml');

        $guarded = [];
        foreach ($di->xpath('//type[plugin]') ?: [] as $type) {
            $guarded[] = (string) $type['name'];
        }

        self::assertContains(
            'Magento\Quote\Model\Quote',
            $guarded,
            'the add-to-cart refusal is not declared at all'
        );

        $globalDi = self::ROOT . '/etc/di.xml';

        if (file_exists($globalDi)) {
            $contents = (string) file_get_contents($globalDi);

            self::assertStringNotContainsString(
                'TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartGuard',
                $contents,
                'the cart guard has been moved into the GLOBAL etc/di.xml, so it now also '
                . 'refuses admin order creation and TackQuote quote conversion'
            );
            self::assertStringNotContainsString(
                'TackQuote\Quotes\Plugin\Checkout\QuoteOnlyCheckoutGuard',
                $contents,
                'the checkout guard has been moved into the GLOBAL etc/di.xml'
            );
        }
    }

    public function testTheLayoutObserverIsAlsoFrontendOnly(): void
    {
        $events = $this->xml('etc/frontend/events.xml');

        $observers = $events->xpath('//event[@name="layout_load_before"]/observer');

        self::assertNotEmpty($observers, 'the layout handles are never added');
        self::assertSame(
            'TackQuote\Quotes\Observer\QuoteOnlyLayoutHandle',
            (string) $observers[0]['instance']
        );
    }

    public function testTheCartPageNoticeUsesItsOwnJsHookNotTheWidgetScopedOne(): void
    {
        // quote-app.js delegates every drawer handler from $root, the widget element itself
        // (view/frontend/web/js/quote-app.js:116). The cart notice renders into the `content`
        // container, OUTSIDE that element, so reusing `tackquote-request-list` here would
        // produce a button bound to nothing — a dead CTA on the one page a blocked shopper
        // is redirected to.
        $phtml = (string) file_get_contents(self::ROOT . '/view/frontend/templates/quote-only-notice.phtml');

        self::assertStringContainsString('data-role="tackquote-quote-only-cta"', $phtml);
        self::assertStringNotContainsString(
            'data-role="tackquote-request-list"',
            $phtml,
            'that hook is delegated from $root and would never fire for this button'
        );

        $js = (string) file_get_contents(self::ROOT . '/view/frontend/web/js/quote-app.js');

        self::assertStringContainsString(
            '$(document).on(\'click\', \'[data-role="tackquote-quote-only-cta"]\'',
            $js,
            'the cart-page CTA has no document-level handler, so clicking it does nothing'
        );
    }
}
