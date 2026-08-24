<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Test\Resources;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinderInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateScopeDetector;
use Shopware\Core\Framework\Adapter\Twig\TokenParser\ExtendsTokenParser;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Node\Node;
use Twig\Node\TextNode;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * ── The regression this file exists to prevent ────────────────────────────────────────
 *
 * In the WooCommerce build of this same feature, the "Request a Quote" button was hooked to
 * a point that only fires INSIDE the add-to-cart form. Turning the cart off would therefore
 * have deleted the quote button along with it, leaving a storefront on which a customer
 * could neither buy nor ask — a store with no way to transact at all, and nothing in the
 * test suite would have noticed.
 *
 * So this test does not inspect the template as text. It RENDERS the plugin's real
 * buy-widget override against Shopware's real core buy-widget template, through Shopware's
 * real `sw_extends` token parser, with tackquote_quote_only() forced both ways, and asserts
 * on the resulting HTML.
 *
 * ── What is faked, stated plainly ─────────────────────────────────────────────────────
 *
 * Two things, both harness plumbing rather than logic under test:
 *
 *   1. `sw_include` is replaced by a parser that emits a `[[sw_include:<template>]]` marker
 *      instead of recursively rendering the included file. Rendering the whole include tree
 *      would drag in the icon set, the theme's price/delivery partials and their own
 *      includes, none of which this test is about. The marker is strictly BETTER for the
 *      assertion: the buy form is reached via
 *      `sw_include '@Storefront/…/buy-widget-form.html.twig'`, so the presence or absence of
 *      that exact marker is a precise statement about whether core's purchase form was
 *      rendered.
 *
 *   2. Twig functions/filters the storefront supplies at runtime (`config`, `path`,
 *      `seoUrl`, `feature`, `trans`, `sw_sanitize`) are stubbed to echo their input. They
 *      carry no branching relevant here.
 *
 * `sw_extends`, the block inheritance, the `{% if %}` conditions and both real template files
 * are the genuine article.
 */
#[CoversNothing]
class QuoteCtaRendersWithCartDisabledTest extends TestCase
{
    private const CORE_BUY_FORM_MARKER = '[[sw_include:@Storefront/storefront/component/buy-widget/buy-widget-form.html.twig]]';

    /**
     * Every add-to-cart form in the storefront posts to this route. Its absence from the
     * rendered HTML is the plain-language version of "the cart action is gone".
     */
    private const ADD_TO_CART_ROUTE = 'frontend.checkout.line-item.add';

    /**
     * @param array<string, mixed> $config
     */
    private function render(string $template, bool $quoteOnly, array $config, array $vars = []): string
    {
        $storefrontViews = TACKQUOTE_PROJECT_ROOT . '/vendor/shopware/storefront/Resources/views';

        if (!is_dir($storefrontViews)) {
            static::markTestSkipped('Shopware storefront views not found at ' . $storefrontViews);
        }

        $loader = new FilesystemLoader();
        $loader->addPath($storefrontViews, 'Storefront');
        $loader->addPath(TACKQUOTE_PLUGIN_ROOT . '/src/Resources/views', 'TackQuote');

        $twig = new Environment($loader, ['cache' => false, 'strict_variables' => false, 'debug' => true]);
        $twig->addExtension(new class($quoteOnly, $config) extends AbstractExtension {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(private readonly bool $quoteOnly, private readonly array $config)
            {
            }

            public function getTokenParsers(): array
            {
                return [
                    // The genuine Shopware parser: this is what makes the test exercise real
                    // template inheritance rather than a reimplementation of it.
                    new ExtendsTokenParser(
                        new class implements TemplateFinderInterface {
                            public function getTemplateName(string $template): string
                            {
                                return $template;
                            }

                            public function find(string $template, $ignoreMissing = false, ?string $source = null): string
                            {
                                return $template;
                            }
                        },
                        new TemplateScopeDetector(new RequestStack())
                    ),
                    new class extends AbstractTokenParser {
                        public function getTag(): string
                        {
                            return 'sw_include';
                        }

                        public function parse(Token $token): Node
                        {
                            $stream = $this->parser->getStream();

                            $name = $stream->test(Token::STRING_TYPE)
                                ? (string) $stream->getCurrent()->getValue()
                                : 'expression';

                            while (!$stream->test(Token::BLOCK_END_TYPE)) {
                                $stream->next();
                            }
                            $stream->expect(Token::BLOCK_END_TYPE);

                            return new TextNode('[[sw_include:' . $name . ']]', $token->getLine());
                        }
                    },
                ];
            }

            public function getFunctions(): array
            {
                return [
                    new TwigFunction('tackquote_quote_only', fn (): bool => $this->quoteOnly),
                    new TwigFunction('config', fn (string $key): mixed => $this->config[$key] ?? null),
                    new TwigFunction('path', fn (string $route, array $p = []): string => '/__route__/' . $route),
                    new TwigFunction('seoUrl', fn (string $route, array $p = []): string => '/__seo__/' . $route),
                    new TwigFunction('feature', fn (string $flag): bool => false),
                ];
            }

            public function getFilters(): array
            {
                return [
                    new TwigFilter('trans', fn (mixed $key, array $p = []): string => (string) $key),
                    new TwigFilter('sw_sanitize', fn (mixed $value): string => (string) $value),
                    // Supplied at runtime by twig/intl-extra, which the storefront registers
                    // and a bare Twig\Environment does not.
                    new TwigFilter('format_date', fn (mixed $date, string $pattern = ''): string => (string) $date),
                ];
            }
        });

        return $twig->render($template, $vars + self::productContext());
    }

    /**
     * @return array<string, mixed>
     */
    private static function productContext(): array
    {
        return [
            'product' => [
                'id' => '0189ab7f3a3c7a2f9d1e4b5c6d7e8f90',
                'name' => 'Bulk Widget',
                'translated' => ['name' => 'Bulk Widget', 'packUnit' => null, 'packUnitPlural' => null],
                'productNumber' => 'SW-10001',
                'active' => true,
                'parentId' => null,
                'childCount' => 0,
                'manufacturer' => null,
                'ean' => null,
                'manufacturerNumber' => null,
                'weight' => null,
                'height' => null,
                'width' => null,
                'length' => null,
                'releaseDate' => null,
                'ratingAverage' => 0,
                'stock' => 100,
                'isCloseout' => false,
                'minPurchase' => 1,
                'calculatedPrices' => [],
            ],
            'context' => [
                'taxState' => 'gross',
                'currency' => ['translated' => ['shortName' => 'EUR']],
            ],
            'totalReviews' => 0,
            'configuratorSettings' => [],
        ];
    }

    private function renderBuyWidget(bool $quoteOnly, bool $enableButton): string
    {
        return $this->render(
            '@TackQuote/storefront/component/buy-widget/buy-widget.html.twig',
            $quoteOnly,
            [
                'TackQuote.config.enableButton' => $enableButton,
                'TackQuote.config.buttonLabel' => 'Request a Quote',
                'core.cart.wishlistEnabled' => false,
                'core.listing.showReview' => false,
                'core.basicInformation.shippingPaymentInfoPage' => 'cms-page-id',
            ]
        );
    }

    /**
     * Baseline. Without this passing, the "it disappeared" assertions below would be
     * satisfied by a template that renders nothing at all in either mode.
     */
    public function testNormalModeRendersBothTheCoreBuyFormAndTheQuoteCta(): void
    {
        $html = $this->renderBuyWidget(quoteOnly: false, enableButton: true);

        static::assertStringContainsString(self::CORE_BUY_FORM_MARKER, $html);
        static::assertStringContainsString('tack-quote-request', $html);
        static::assertStringContainsString('js-tack-quote-form', $html);
    }

    /**
     * THE ASSERTION THIS FILE IS FOR: with the cart disabled, the purchase form is gone AND
     * the quote CTA is still there. Both halves in one test on purpose — they are the two
     * ways this feature can be wrong, and they are only meaningful together.
     */
    public function testQuoteOnlyModeRemovesTheBuyFormAndKeepsTheQuoteCta(): void
    {
        $html = $this->renderBuyWidget(quoteOnly: true, enableButton: true);

        static::assertStringNotContainsString(
            self::CORE_BUY_FORM_MARKER,
            $html,
            'core buy-widget-form was still rendered, so the storefront still shows Add to cart'
        );
        static::assertStringNotContainsString(self::ADD_TO_CART_ROUTE, $html);

        static::assertStringContainsString(
            'tack-quote-request',
            $html,
            'the quote CTA vanished with the cart button — the store now has NO way to transact'
        );
        static::assertStringContainsString('js-tack-quote-form', $html);
        static::assertStringContainsString('/__route__/frontend.tackquote.quote-request', $html);
    }

    /**
     * The `enableButton` setting is an opt-OUT for merchants who sell normally. If it also
     * suppressed the CTA in quote-only mode, a merchant who had switched the button off and
     * later turned on quote-only mode would end up with a dead catalog and no error anywhere.
     */
    public function testQuoteOnlyModeStillRendersTheCtaWhenTheButtonSettingIsOff(): void
    {
        $html = $this->renderBuyWidget(quoteOnly: true, enableButton: false);

        static::assertStringNotContainsString(self::CORE_BUY_FORM_MARKER, $html);
        static::assertStringContainsString('tack-quote-request', $html);
    }

    /**
     * And the opt-out is still honoured in a normal store — otherwise this change would have
     * forced the quote button onto every merchant who had deliberately turned it off.
     */
    public function testNormalModeStillHonoursTheButtonOptOut(): void
    {
        $html = $this->renderBuyWidget(quoteOnly: false, enableButton: false);

        static::assertStringContainsString(self::CORE_BUY_FORM_MARKER, $html);
        static::assertStringNotContainsString('tack-quote-request', $html);
    }

    private function renderListingCard(bool $quoteOnly): string
    {
        return $this->render(
            '@TackQuote/storefront/component/product/card/action.html.twig',
            $quoteOnly,
            [
                'core.listing.allowBuyInListing' => true,
                'TackQuote.config.buttonLabel' => 'Request a Quote',
            ]
        );
    }

    /**
     * Listing cards. Core's action template is an if/else, so a naive "render nothing"
     * override leaves the card with an empty action area — no buy button and no link either.
     * This pins that the card always offers SOMETHING.
     */
    public function testListingCardOffersAQuoteRouteInsteadOfAnEmptyActionArea(): void
    {
        $quoteOnlyHtml = $this->renderListingCard(quoteOnly: true);

        static::assertStringNotContainsString(self::ADD_TO_CART_ROUTE, $quoteOnlyHtml);
        static::assertStringContainsString('tack-quote-listing-link', $quoteOnlyHtml);
        static::assertStringContainsString('/__seo__/frontend.detail.page', $quoteOnlyHtml);

        $normalHtml = $this->renderListingCard(quoteOnly: false);

        static::assertStringContainsString(self::ADD_TO_CART_ROUTE, $normalHtml);
        static::assertStringNotContainsString('tack-quote-listing-link', $normalHtml);
    }
}
