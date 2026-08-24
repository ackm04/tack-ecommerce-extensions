<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Test\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\ContextSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use TackQuote\TackQuote\Service\QuoteOnlyModeService;

/**
 * The audience/exemption matrix for B2B quote-only mode.
 *
 * This class is the single decision point every other piece consults, so a wrong answer
 * here is either a store that cannot sell anything (false positive) or a "quote-only"
 * store that quietly still takes orders (false negative). Both directions are asserted.
 */
#[CoversClass(QuoteOnlyModeService::class)]
class QuoteOnlyModeServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0189ab7f3a3c7a2f9d1e4b5c6d7e8f90';
    private const B2B_GROUP_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RETAIL_GROUP_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @param array<string, mixed> $config
     */
    private function service(array $config): QuoteOnlyModeService
    {
        $systemConfig = $this->createStub(SystemConfigService::class);

        $systemConfig->method('getBool')->willReturnCallback(
            static fn (string $key): bool => (bool) ($config[$key] ?? false)
        );
        $systemConfig->method('getString')->willReturnCallback(
            static fn (string $key): string => (string) ($config[$key] ?? '')
        );
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $config[$key] ?? null
        );

        return new QuoteOnlyModeService($systemConfig);
    }

    private function context(
        ?CustomerEntity $customer = null,
        string $customerGroupId = self::RETAIL_GROUP_ID,
        ?ContextSource $source = null,
        ?string $imitatingUserId = null
    ): SalesChannelContext {
        $innerContext = $this->createStub(Context::class);
        $innerContext->method('getSource')->willReturn($source ?? new SalesChannelApiSource(self::SALES_CHANNEL_ID));

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);
        $context->method('getContext')->willReturn($innerContext);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getCustomerGroupId')->willReturn($customerGroupId);
        $context->method('getImitatingUserId')->willReturn($imitatingUserId);

        return $context;
    }

    private static function customer(bool $guest): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setGuest($guest);

        return $customer;
    }

    public function testDisabledByDefault(): void
    {
        static::assertFalse($this->service([])->appliesTo($this->context()));
    }

    public function testScopeEveryoneAppliesToAnonymousAndRegisteredAlike(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_EVERYONE,
        ]);

        static::assertTrue($service->appliesTo($this->context()));
        static::assertTrue($service->appliesTo($this->context(self::customer(false))));
        static::assertTrue($service->appliesTo($this->context(self::customer(true))));
    }

    /**
     * The point of "guests only": an approved, logged-in B2B customer keeps a working
     * cart while everybody else has to go through a quote. If this ever inverted, the
     * merchant's approved buyers would be the only people unable to order.
     */
    public function testScopeGuestsSparesRegisteredCustomersOnly(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_GUESTS,
        ]);

        static::assertTrue($service->appliesTo($this->context()), 'anonymous visitor');
        static::assertTrue($service->appliesTo($this->context(self::customer(true))), 'guest-checkout customer');
        static::assertFalse($service->appliesTo($this->context(self::customer(false))), 'registered customer');
    }

    public function testScopeGroupsMatchesOnlyConfiguredGroups(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_GROUPS,
            QuoteOnlyModeService::CONFIG_GROUP_IDS => [self::B2B_GROUP_ID],
        ]);

        static::assertTrue($service->appliesTo($this->context(null, self::B2B_GROUP_ID)));
        static::assertFalse($service->appliesTo($this->context(null, self::RETAIL_GROUP_ID)));
    }

    public function testScopeGroupsMatchesRegardlessOfIdCasing(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_GROUPS,
            QuoteOnlyModeService::CONFIG_GROUP_IDS => [mb_strtoupper(self::B2B_GROUP_ID)],
        ]);

        static::assertTrue($service->appliesTo($this->context(null, self::B2B_GROUP_ID)));
    }

    public function testScopeGroupsWithNothingSelectedMatchesNobody(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_GROUPS,
            QuoteOnlyModeService::CONFIG_GROUP_IDS => [],
        ]);

        static::assertFalse($service->appliesTo($this->context(null, self::B2B_GROUP_ID)));
    }

    /**
     * A typo in the scope value must not silently switch the cart back on for a store the
     * merchant has explicitly declared quote-only.
     */
    #[DataProvider('unrecognisedScopeProvider')]
    public function testUnrecognisedScopeFallsBackToEveryone(mixed $scope): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => $scope,
        ]);

        static::assertTrue($service->appliesTo($this->context(self::customer(false))));
    }

    public static function unrecognisedScopeProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'typo' => ['guest'];
        yield 'legacy value' => ['all'];
    }

    /**
     * Staff exemptions. Without these the merchant cannot test their own storefront and
     * support cannot place an order for a customer who phoned in.
     */
    public function testAdminImitatingACustomerIsExempt(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_EVERYONE,
        ]);

        static::assertFalse($service->appliesTo(
            $this->context(imitatingUserId: '0189ab7f3a3c7a2f9d1e4b5c6d7e8f91')
        ));
    }

    #[DataProvider('exemptSourceProvider')]
    public function testAdministrativeContextSourcesAreExempt(ContextSource $source): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_EVERYONE,
        ]);

        static::assertFalse($service->appliesTo($this->context(source: $source)));
    }

    public static function exemptSourceProvider(): \Generator
    {
        yield 'admin order module' => [new AdminSalesChannelApiSource(self::SALES_CHANNEL_ID, Context::createDefaultContext())];
        yield 'admin api' => [new AdminApiSource(null)];
        yield 'system' => [new SystemSource()];
    }

    /**
     * The counterpart of the exemption tests: an ordinary storefront visitor's context
     * source is SalesChannelApiSource, and it must NOT be exempt. AdminSalesChannelApiSource
     * extends SalesChannelApiSource, so an instanceof check written against the parent would
     * exempt every shopper on the site — this pins the direction.
     */
    public function testOrdinaryStorefrontSourceIsNotExempt(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_EVERYONE,
        ]);

        static::assertTrue($service->appliesTo(
            $this->context(source: new SalesChannelApiSource(self::SALES_CHANNEL_ID))
        ));
    }

    public function testIsGroupScopedIgnoresOperatorExemption(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_GROUPS,
        ]);

        // An imitating admin would be exempt from the guard, but must still be counted as
        // group-scoped for cache purposes so their render cannot poison a shared bucket.
        static::assertTrue($service->isGroupScoped(
            $this->context(imitatingUserId: '0189ab7f3a3c7a2f9d1e4b5c6d7e8f91')
        ));
    }

    public function testIsGroupScopedIsFalseForOtherScopes(): void
    {
        $service = $this->service([
            QuoteOnlyModeService::CONFIG_ENABLED => true,
            QuoteOnlyModeService::CONFIG_SCOPE => QuoteOnlyModeService::SCOPE_GUESTS,
        ]);

        static::assertFalse($service->isGroupScoped($this->context()));
    }
}
