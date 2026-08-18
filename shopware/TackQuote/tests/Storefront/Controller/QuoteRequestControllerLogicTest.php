<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Test\Storefront\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PriceCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use TackQuote\TackQuote\Storefront\Controller\QuoteRequestController;

/**
 * Covers the two pure resolvers in QuoteRequestController: the advanced-price tier
 * lookup and the purchase-constraint clamp.
 *
 * These are the parts that decide what MONEY and what QUANTITY land on a real quote,
 * from input a hostile browser controls, so they are worth pinning precisely.
 *
 * ── Why reflection ────────────────────────────────────────────────────────────────
 * Both are `private`, and they should stay private: they are implementation detail of
 * one action, not plugin API. Widening them to `public`/`protected` purely so a test
 * can reach them would make the test dictate the production design. The alternative —
 * driving them through `requestQuote()` — would require standing up a Symfony container
 * with a translator and a router just to observe an arithmetic result, and would test
 * the framework rather than this logic. Reflection is the narrower instrument here.
 */
#[CoversClass(QuoteRequestController::class)]
class QuoteRequestControllerLogicTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        // No constructor: these resolvers touch no injected dependency, and going
        // through the real constructor would drag in the DAL repository for nothing.
        $controller = (new \ReflectionClass(QuoteRequestController::class))
            ->newInstanceWithoutConstructor();

        $ref = new \ReflectionMethod(QuoteRequestController::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($controller, ...$args);
    }

    private static function price(float $unitPrice, int $quantity): CalculatedPrice
    {
        return new CalculatedPrice(
            $unitPrice,
            $unitPrice * $quantity,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            $quantity
        );
    }

    /**
     * Builds a product whose advanced-price ladder mirrors how core stamps it in
     * ProductPriceCalculator::calculateAdvancePrices() — ascending, each entry's
     * `quantity` being the tier's `quantityEnd ?? quantityStart`.
     *
     * @param array<int, array{0: float, 1: int}> $tiers [unitPrice, tierQuantity]
     */
    private static function product(float $basePrice, array $tiers = []): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setCalculatedPrice(self::price($basePrice, 1));
        $product->setCalculatedPrices(new PriceCollection(
            array_map(static fn (array $t) => self::price($t[0], $t[1]), $tiers)
        ));

        return $product;
    }

    // ── resolveUnitPrice ────────────────────────────────────────────────────────

    public function testFallsBackToBasePriceWhenThereIsNoLadder(): void
    {
        $product = self::product(966.27);

        static::assertSame(966.27, $this->invoke('resolveUnitPrice', $product, 40));
    }

    /**
     * The exact regression this endpoint had: the browser posted
     * `product.calculatedPrice.unitPrice`, i.e. the SINGLE-UNIT price, so a 40-unit
     * request was quoted at the 1-unit rate even though the merchant had published an
     * 11+ tier. On the live demo store that overcharged by 40 × (966.27 - 900.29).
     */
    public function testPicksTheBulkTierForALargeQuantityRatherThanTheSingleUnitPrice(): void
    {
        $product = self::product(966.27, [[1233.27, 10], [900.29, 11]]);

        static::assertSame(900.29, $this->invoke('resolveUnitPrice', $product, 40));
    }

    /**
     * @param array<int, array{0: float, 1: int}> $tiers
     */
    #[DataProvider('tierBoundaries')]
    public function testSelectsTheTierCoveringTheRequestedQuantity(
        int $quantity,
        float $expected,
        array $tiers
    ): void {
        static::assertSame(
            $expected,
            $this->invoke('resolveUnitPrice', self::product(50.0, $tiers), $quantity)
        );
    }

    /**
     * @return iterable<string, array{int, float, array<int, array{0: float, 1: int}>}>
     */
    public static function tierBoundaries(): iterable
    {
        // 1-10 @ 30.0, then an open-ended 11+ @ 20.0 (stamped with its quantityStart).
        $ladder = [[30.0, 10], [20.0, 11]];

        yield 'below the first tier end'   => [1, 30.0, $ladder];
        yield 'at the first tier end'      => [10, 30.0, $ladder];
        yield 'first quantity of tier two' => [11, 20.0, $ladder];
        // Nothing in the ladder "covers" 5000; the open-ended top tier must win rather
        // than the loop falling through to the single-unit base price.
        yield 'far above the last tier'    => [5000, 20.0, $ladder];
        yield 'single tier only'           => [7, 12.5, [[12.5, 10]]];
    }

    // ── resolveQuantity ─────────────────────────────────────────────────────────

    /**
     * @param array{min?: int|null, max?: int|null, steps?: int|null} $constraints
     */
    #[DataProvider('quantityConstraints')]
    public function testClampsQuantityToThePurchaseConstraints(
        int $requested,
        int $expected,
        array $constraints
    ): void {
        $product = new SalesChannelProductEntity();
        $product->setMinPurchase($constraints['min'] ?? null);
        $product->setMaxPurchase($constraints['max'] ?? null);
        $product->setPurchaseSteps($constraints['steps'] ?? null);

        static::assertSame(
            $expected,
            $this->invoke('resolveQuantity', $requested, $product)
        );
    }

    /**
     * @return iterable<string, array{int, int, array{min?: int|null, max?: int|null, steps?: int|null}}>
     */
    public static function quantityConstraints(): iterable
    {
        yield 'unconstrained product passes through' => [7, 7, []];

        // A hostile or empty value must never become 0 or negative on a quote.
        yield 'zero becomes one'     => [0, 1, []];
        yield 'negative becomes one' => [-5, 1, []];

        yield 'raised to minPurchase'          => [1, 48, ['min' => 48]];
        yield 'above minPurchase is untouched' => [50, 50, ['min' => 48]];
        yield 'capped at maxPurchase'          => [999, 100, ['max' => 100]];

        // Step rounding goes UP: rounding down from 50 with min 48 would land at 48 for
        // a step of 48 — inside the minimum, but a smaller order than asked for. More
        // importantly, rounding down can never be below min because it is measured from min.
        yield 'rounded up to the next step'   => [50, 96, ['min' => 48, 'steps' => 48]];
        yield 'already on a step boundary'    => [96, 96, ['min' => 48, 'steps' => 48]];
        yield 'step measured from min not 0'  => [12, 15, ['min' => 5, 'steps' => 5]];

        // Stepping up to exactly max is legal, so this must NOT step back.
        yield 'step-up landing on max' => [50, 96, ['min' => 48, 'max' => 96, 'steps' => 48]];

        // Only when the rounded-up step would OVERSHOOT max does it step back — here 50
        // rounds up to 96, which exceeds max 90, so the last valid step (48) is used.
        yield 'step-up overshooting max steps back' => [50, 48, ['min' => 48, 'max' => 90, 'steps' => 48]];

        // A max below min is a merchant misconfiguration; min must win so the quote is
        // still fulfillable rather than collapsing to an impossible quantity.
        yield 'contradictory min/max favours min' => [10, 48, ['min' => 48, 'max' => 10]];
    }
}
