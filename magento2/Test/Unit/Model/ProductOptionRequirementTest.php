<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\ProductOptionRequirement;

/**
 * @covers \TackQuote\Quotes\Model\ProductOptionRequirement
 */
class ProductOptionRequirementTest extends TestCase
{
    /**
     * @var ProductOptionRequirement
     */
    private $subject;

    protected function setUp(): void
    {
        $this->subject = new ProductOptionRequirement();
    }

    /**
     * @param string $typeId
     * @param bool|null $hasRequiredOptions Null when getTypeInstance() must never be reached.
     * @return Product
     */
    private function product(string $typeId, ?bool $hasRequiredOptions): Product
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTypeId', 'getTypeInstance'])
            ->getMock();
        $product->method('getTypeId')->willReturn($typeId);

        if ($hasRequiredOptions === null) {
            $product->expects($this->never())->method('getTypeInstance');

            return $product;
        }

        $type = $this->getMockBuilder(AbstractType::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasRequiredOptions'])
            ->getMockForAbstractClass();
        $type->method('hasRequiredOptions')->willReturn($hasRequiredOptions);
        $product->method('getTypeInstance')->willReturn($type);

        return $product;
    }

    /**
     * THE BUG THIS GUARDS: AbstractType::hasRequiredOptions() reports on *custom options*
     * only. Neither Configurable nor Grouped overrides it, so a configurable with mandatory
     * Size and Colour swatches answers false — and the module happily quoted the parent SKU,
     * which the seller cannot fulfil, usually at the parent's empty price.
     *
     * hasRequiredOptions() is stubbed false here on purpose: the composite branch must win
     * without ever consulting it.
     *
     * @dataProvider compositeTypeProvider
     * @param string $typeId
     * @return void
     */
    public function testCompositeTypesAlwaysRequireSelectionEvenWhenHasRequiredOptionsIsFalse(
        string $typeId
    ): void {
        $this->assertTrue(
            $this->subject->requiresSelection($this->product($typeId, null)),
            sprintf('%s must require a selection regardless of hasRequiredOptions()', $typeId)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function compositeTypeProvider(): array
    {
        return [
            'configurable' => ['configurable'],
            'bundle' => ['bundle'],
            'grouped' => ['grouped'],
        ];
    }

    public function testSimpleProductWithRequiredCustomOptionsRequiresSelection(): void
    {
        $this->assertTrue($this->subject->requiresSelection($this->product('simple', true)));
    }

    public function testPlainSimpleProductDoesNotRequireSelection(): void
    {
        $this->assertFalse($this->subject->requiresSelection($this->product('simple', false)));
    }

    public function testVirtualAndDownloadableFallBackToTheTypeInstance(): void
    {
        $this->assertFalse($this->subject->requiresSelection($this->product('virtual', false)));
        $this->assertTrue($this->subject->requiresSelection($this->product('downloadable', true)));
    }
}
