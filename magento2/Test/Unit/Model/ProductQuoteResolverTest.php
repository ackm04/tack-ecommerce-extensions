<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\ProductOptionRequirement;
use TackQuote\Quotes\Model\ProductQuoteResolver;

/**
 * @covers \TackQuote\Quotes\Model\ProductQuoteResolver
 */
class ProductQuoteResolverTest extends TestCase
{
    /**
     * @var ProductRepositoryInterface&MockObject
     */
    private $productRepository;

    /**
     * @var Configurable&MockObject
     */
    private $configurableType;

    /**
     * @var PriceCurrencyInterface&MockObject
     */
    private $priceCurrency;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var ProductOptionRequirement&MockObject
     */
    private $optionRequirement;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var ProductQuoteResolver
     */
    private $resolver;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);

        $this->configurableType = $this->getMockBuilder(Configurable::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductByAttributes', 'getConfigurableAttributes'])
            ->getMock();

        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        // Round to 2dp so the assertions read as real money without hiding the value.
        $this->priceCurrency->method('round')
            ->willReturnCallback(static function ($price) {
                return round((float) $price, 2);
            });

        $this->logger = $this->createMock(LoggerInterface::class);

        // Real collaborator, not a mock: its whole job is knowing which product types need
        // a selection, and mocking it would let the resolver's refusal path pass while the
        // real rule was wrong.
        $this->optionRequirement = new ProductOptionRequirement();

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->resolver = new ProductQuoteResolver(
            $this->productRepository,
            $this->configurableType,
            $this->priceCurrency,
            $this->optionRequirement,
            $this->storeManager,
            $this->logger
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @return Product&MockObject
     */
    private function product(array $attributes = [])
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getPriceInfo',
                'getFinalPrice',
                'getPrice',
                'getSku',
                'getName',
                'getId',
                'getTypeId',
                'getStatus',
                'getWebsiteIds',
                'getTypeInstance',
            ])
            ->getMock();

        $product->method('getSku')->willReturn($attributes['sku'] ?? 'SKU-1');
        $product->method('getName')->willReturn($attributes['name'] ?? 'Test Product');
        $product->method('getId')->willReturn($attributes['id'] ?? 42);
        $product->method('getTypeId')->willReturn($attributes['type'] ?? 'simple');
        $product->method('getPrice')->willReturn($attributes['price'] ?? 0.0);
        $product->method('getFinalPrice')->willReturn($attributes['finalPrice'] ?? 0.0);
        $product->method('getStatus')->willReturn($attributes['status'] ?? 1);
        $product->method('getWebsiteIds')->willReturn($attributes['websiteIds'] ?? [1]);

        // Only consulted for non-composite types; composites short-circuit on type id.
        $typeInstance = $this->getMockBuilder(\Magento\Catalog\Model\Product\Type\AbstractType::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasRequiredOptions'])
            ->getMockForAbstractClass();
        $typeInstance->method('hasRequiredOptions')
            ->willReturn($attributes['hasRequiredOptions'] ?? false);
        $product->method('getTypeInstance')->willReturn($typeInstance);

        if (array_key_exists('priceInfoThrows', $attributes)) {
            $product->method('getPriceInfo')
                ->willThrowException(new \RuntimeException('price info unavailable'));

            return $product;
        }

        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($attributes['finalPriceInfo'] ?? null);

        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->expects($this->any())
            ->method('getPrice')
            ->with('final_price')
            ->willReturn($price);

        $product->method('getPriceInfo')->willReturn($priceInfo);

        return $product;
    }

    /**
     * THE BUG THIS GUARDS: the previous implementation read the raw `price` attribute via
     * getPrice(). On a configurable, price lives on the child simples and the parent returns
     * 0 — so sellers received 0.00 quotes for most of a typical catalog. The price must come
     * from the price-info pipeline, which honours special prices, catalog rules and tiers.
     */
    public function testPriceComesFromThePriceInfoPipelineNotTheRawPriceAttribute(): void
    {
        $product = $this->product([
            'sku' => 'WJ01',
            'name' => 'Stellar Jacket',
            'id' => 1043,
            'price' => 0.0,            // what the raw attribute would have given
            'finalPriceInfo' => 34.00, // what the customer actually sees
        ]);
        $this->productRepository->method('get')->with('WJ01')->willReturn($product);

        $line = $this->resolver->resolve('WJ01', 2);

        $this->assertIsArray($line);
        $this->assertSame(34.00, $line['unitPrice'], 'price must come from final_price, not getPrice()');
        $this->assertSame('WJ01', $line['sku']);
        $this->assertSame('Stellar Jacket', $line['name']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame('1043', $line['externalProductId']);
        $this->assertSame('', $line['options']);
    }

    public function testPriceInfoWinsOverAConflictingRawPriceAttribute(): void
    {
        $product = $this->product(['price' => 99.99, 'finalPriceInfo' => 24.50]);
        $this->productRepository->method('get')->willReturn($product);

        $line = $this->resolver->resolve('SKU-1', 1);

        $this->assertSame(24.50, $line['unitPrice']);
    }

    /**
     * A wrong-but-plausible price is easier for a seller to spot than a silent zero, so the
     * chain degrades rather than giving up.
     */
    public function testFallsBackToGetFinalPriceWhenThePriceInfoPipelineThrows(): void
    {
        $product = $this->product([
            'priceInfoThrows' => true,
            'finalPrice' => 12.75,
            'price' => 20.00,
        ]);
        $this->productRepository->method('get')->willReturn($product);

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $line = $this->resolver->resolve('SKU-1', 1);

        $this->assertSame(12.75, $line['unitPrice']);
    }

    public function testFallsBackToGetPriceWhenBothPriceInfoAndFinalPriceAreUnusable(): void
    {
        $product = $this->product([
            'priceInfoThrows' => true,
            'finalPrice' => 0.0,
            'price' => 9.99,
        ]);
        $this->productRepository->method('get')->willReturn($product);

        $line = $this->resolver->resolve('SKU-1', 1);

        $this->assertSame(9.99, $line['unitPrice']);
    }

    /**
     * final_price present but zero (e.g. an unpriced parent) must not short-circuit the
     * fallback chain.
     */
    public function testAZeroFinalPriceStillFallsThroughToTheNextSource(): void
    {
        $product = $this->product([
            'finalPriceInfo' => 0.0,
            'finalPrice' => 0.0,
            'price' => 15.00,
        ]);
        $this->productRepository->method('get')->willReturn($product);

        $line = $this->resolver->resolve('SKU-1', 1);

        $this->assertSame(15.00, $line['unitPrice']);
    }

    public function testReturnsNullForAnUnknownSku(): void
    {
        $this->productRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('No such entity.')));

        $this->assertNull($this->resolver->resolve('DOES-NOT-EXIST', 1));
    }

    /**
     * Quoting the parent of a configurable names a SKU the seller cannot fulfil, at a price
     * the parent usually does not carry. The chosen variant is quoted instead.
     */
    public function testAConfigurableResolvesToTheChosenChildSkuAndItsPrice(): void
    {
        $parent = $this->product([
            'sku' => 'WJ01',
            'name' => 'Stellar Jacket',
            'type' => 'configurable',
            'price' => 0.0,
            'finalPriceInfo' => 0.0,
        ]);
        $child = $this->product([
            'sku' => 'WJ01-M-Blue',
            'name' => 'Stellar Jacket-M-Blue',
            'id' => 1044,
            'finalPriceInfo' => 39.00,
        ]);

        $this->productRepository->method('get')->with('WJ01')->willReturn($parent);
        $this->configurableType->method('getProductByAttributes')->willReturn($child);
        // No configurable attributes resolvable in a unit context; the description degrades
        // to empty rather than failing the quote.
        $this->configurableType->method('getConfigurableAttributes')->willReturn([]);

        $line = $this->resolver->resolve('WJ01', 3, [93 => '167', 144 => '50']);

        $this->assertSame('WJ01-M-Blue', $line['sku']);
        $this->assertSame(39.00, $line['unitPrice']);
        $this->assertSame('1044', $line['externalProductId']);
    }

    /**
     * THE BUG THIS GUARDS: when the chosen options matched no child, the resolver used to
     * fall back to the parent — producing a quote for an unfulfillable SKU at the "as low
     * as" price. A selection that cannot be resolved must be refused, not approximated.
     */
    public function testAConfigurableWhoseSelectionMatchesNoChildIsRefused(): void
    {
        $parent = $this->product([
            'sku' => 'WJ01',
            'type' => 'configurable',
            'finalPriceInfo' => 30.00,
        ]);

        $this->productRepository->method('get')->willReturn($parent);
        $this->configurableType->method('getProductByAttributes')->willReturn(null);

        $line = $this->resolver->resolve('WJ01', 1, [93 => '167']);

        $this->assertTrue($line['unresolvedSelection']);
        $this->assertArrayNotHasKey('unitPrice', $line, 'A refused line must carry no price.');
    }

    /**
     * THE BUG THIS GUARDS: a configurable with no selection used to be quoted as its PARENT
     * sku. A configurable parent's final price is the MINIMUM across all variants ("as low
     * as"), so the seller received an unfulfillable SKU at a plausible-but-wrong price.
     * It must be refused instead.
     */
    public function testAConfigurableWithNoSelectionIsRefusedRatherThanQuotedAsTheParent(): void
    {
        $parent = $this->product([
            'sku' => 'WJ01',
            'type' => 'configurable',
            'finalPriceInfo' => 30.00,
        ]);

        $this->productRepository->method('get')->willReturn($parent);
        $this->configurableType->expects($this->never())->method('getProductByAttributes');

        $line = $this->resolver->resolve('WJ01', 1);

        $this->assertTrue($line['unresolvedSelection']);
        $this->assertSame('WJ01', $line['sku']);
        $this->assertArrayNotHasKey('unitPrice', $line, 'A refused line must carry no price.');
    }

    /**
     * Bundle and grouped selections cannot be resolved at all yet, so they must be refused
     * rather than quoted as the container product.
     *
     * @dataProvider compositeTypeProvider
     */
    public function testACompositeProductIsRefused(string $type): void
    {
        $product = $this->product(['sku' => 'KIT-1', 'type' => $type, 'finalPriceInfo' => 61.00]);
        $this->productRepository->method('get')->willReturn($product);

        $line = $this->resolver->resolve('KIT-1', 1);

        $this->assertTrue($line['unresolvedSelection']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function compositeTypeProvider(): array
    {
        return ['bundle' => ['bundle'], 'grouped' => ['grouped']];
    }

    /**
     * THE BUG THIS GUARDS: ProductRepository::get() throws only for a SKU that does not
     * exist — it applies no status filter. A quote list can outlive a product being
     * disabled, and the seller would then be quoted a product they deliberately withdrew.
     */
    public function testADisabledProductIsNotQuoted(): void
    {
        $product = $this->product(['sku' => '24-MB04', 'status' => 2, 'finalPriceInfo' => 32.00]);
        $this->productRepository->method('get')->willReturn($product);

        $this->assertNull($this->resolver->resolve('24-MB04', 1));
    }

    /**
     * THE BUG THIS GUARDS: on a shared catalogue a SKU assigned only to website B was
     * quotable from website A's storefront, priced in website A's scope.
     */
    public function testAProductFromAnotherWebsiteIsNotQuoted(): void
    {
        $product = $this->product(['sku' => 'OTHER-1', 'websiteIds' => [2], 'finalPriceInfo' => 10.00]);
        $this->productRepository->method('get')->willReturn($product);

        $this->assertNull($this->resolver->resolve('OTHER-1', 1));
    }
}
