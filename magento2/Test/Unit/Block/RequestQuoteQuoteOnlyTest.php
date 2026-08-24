<?php
/**
 * The behavioural half of "never leave the store unable to transact".
 *
 * Test/Unit/Layout/QuoteOnlyLayoutTest.php proves the CTA block is not structurally coupled
 * to the Add to Cart block it removes. This proves the CTA is not switched off by
 * CONFIGURATION either — which is the trap Magento actually presents, because
 * `show_button` is a legitimate merchant preference that plenty of stores turn off in favour
 * of the multi-product quote list alone.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Block;

use Magento\Catalog\Block\Product\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Block\RequestQuote;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\ProductOptionRequirement;
use TackQuote\Quotes\Model\QuoteOnlyMode;

/**
 * @covers \TackQuote\Quotes\Block\RequestQuote
 */
class RequestQuoteQuoteOnlyTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mode = $this->createMock(QuoteOnlyMode::class);
    }

    private function block(): RequestQuote
    {
        $context = $this->createMock(Context::class);

        return new RequestQuote(
            $context,
            $this->config,
            $this->createMock(Registry::class),
            $this->createMock(ProductOptionRequirement::class),
            $this->mode
        );
    }

    public function testTheCtaRendersInQuoteOnlyModeEVENWithShowButtonTurnedOff(): void
    {
        // THE TEST THIS FEATURE EXISTS AROUND.
        //
        // With Add to Cart removed from the page and `show_button` off, the old
        // isEnabled() returned false, view/frontend/templates/button.phtml bailed out at its
        // early return, and the product page rendered with NO cart button, NO quote button,
        // and a server refusing the POST. A catalog nobody can transact with in either
        // direction — the same failure the WooCommerce and PrestaShop builds hit by other
        // routes.
        $this->config->method('isButtonEnabled')->willReturn(false);
        $this->mode->method('isActive')->willReturn(true);

        self::assertTrue(
            $this->block()->isEnabled(),
            'the quote CTA was suppressed on a page whose Add to Cart button has been removed'
        );
    }

    public function testTheOverrideIsPerVisitorNotPerStore(): void
    {
        // A store scoped to guests must not force the button on for signed-in customers who
        // still have a working cart. isEnabled() consults isActive() (this visitor), never
        // the raw config flag (this store).
        $this->config->method('isButtonEnabled')->willReturn(false);
        $this->mode->method('isActive')->willReturn(false);

        self::assertFalse($this->block()->isEnabled());
    }

    public function testAnUnconfiguredStoreShowsNoCtaAndIsAlsoNotEnforcedAgainst(): void
    {
        // The dead-storefront invariant, CTA half. With no API key Config::isButtonEnabled()
        // is false (it requires isConfigured()) and QuoteOnlyMode::isActive() is false for
        // the same reason — so there is no CTA AND the cart is not refused. The two are
        // switched by one condition; QuoteOnlyModeTest asserts the enforcement half.
        $this->config->method('isButtonEnabled')->willReturn(false);
        $this->mode->method('isActive')->willReturn(false);

        self::assertFalse($this->block()->isEnabled(), 'no CTA is claimed on an unconfigured store');
    }

    public function testAnOrdinaryStoreStillHonoursTheMerchantPreference(): void
    {
        $this->config->method('isButtonEnabled')->willReturn(true);
        $this->mode->method('isActive')->willReturn(false);

        self::assertTrue($this->block()->isEnabled());
    }

    public function testTheTemplateIsToldWhetherTheModeApplies(): void
    {
        // button.phtml promotes the request button from `secondary` to `tocart` (primary)
        // when this is true: with Add to Cart gone it is not the alternative action any
        // more, it is the only one.
        $this->mode->method('isActive')->willReturn(true);

        self::assertTrue($this->block()->isQuoteOnly());
    }
}
