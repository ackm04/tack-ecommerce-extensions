<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Customer\Model\Session as CustomerSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteOnlyMode;

/**
 * @covers \TackQuote\Quotes\Model\QuoteOnlyMode
 */
class QuoteOnlyModeTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var CustomerSession&MockObject */
    private $session;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        // Both are REAL methods on Magento\Customer\Model\Session
        // (module-customer/Model/Session.php:412 and :381), not __call magic, so onlyMethods
        // is correct here. The constructor is disabled because it wants a session manager,
        // a cookie manager and a storage object, none of which this rule touches.
        $this->session = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerGroupId'])
            ->getMock();
    }

    private function mode(): QuoteOnlyMode
    {
        return new QuoteOnlyMode($this->config, $this->session);
    }

    private function withConfig(bool $configured, bool $enabled, string $scope = 'all', string $groups = ''): void
    {
        $this->config->method('isConfigured')->willReturn($configured);
        $this->config->method('isQuoteOnlyEnabled')->willReturn($enabled);
        $this->config->method('getQuoteOnlyScope')->willReturn($scope);
        $this->config->method('getQuoteOnlyCustomerGroups')->willReturn($groups);
    }

    private function asVisitor(bool $loggedIn, int $groupId): void
    {
        $this->session->method('isLoggedIn')->willReturn($loggedIn);
        $this->session->method('getCustomerGroupId')->willReturn($groupId);
    }

    public function testItIsActiveForEveryoneByDefault(): void
    {
        $this->withConfig(true, true);
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->isActive());
    }

    public function testItIsInactiveWhenTheMerchantHasNotSwitchedItOn(): void
    {
        $this->withConfig(true, false);
        $this->asVisitor(false, 0);

        self::assertFalse($this->mode()->isActive());
    }

    public function testWithNoApiKeyTheModeStaysINACTIVESoTheCartKeepsWorking(): void
    {
        // THE DEAD-STOREFRONT TEST, enforcement half.
        //
        // Config::isButtonEnabled() and Config::isAddToQuoteEnabled() both require
        // isConfigured(), so on a store with no API key NO quote control renders anywhere.
        // If enforcement did not honour the same condition, a merchant who enabled
        // quote-only before pasting their key would get a storefront with the cart refused
        // AND no quote button: nothing works, in either direction, and nothing in the admin
        // says why.
        $this->withConfig(false, true);
        $this->asVisitor(false, 0);

        self::assertFalse(
            $this->mode()->isActive(),
            'enforcement ran on a store where no quote button can render — that is a shop '
            . 'nobody can buy from AND nobody can request a quote from'
        );
    }

    public function testGuestsOnlyScopeRefusesThePublicAndSparesSignedInCustomers(): void
    {
        $this->withConfig(true, true, 'guests');
        $this->asVisitor(true, 5);

        self::assertFalse($this->mode()->isActive(), 'an approved, signed-in B2B customer keeps their cart');
    }

    public function testGuestsOnlyScopeAppliesToAGuest(): void
    {
        $this->withConfig(true, true, 'guests');
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->isActive());
    }

    public function testSelectedGroupsUsesTheSessionGroupIdVerbatimIncludingZero(): void
    {
        // Magento group 0 is NOT LOGGED IN — a real group. Unlike the OpenCart build of this
        // feature, no substitution of a "default group" is needed or wanted here.
        $this->withConfig(true, true, 'groups', '0,3');
        $this->asVisitor(false, 0);

        self::assertTrue($this->mode()->isActive(), 'selecting NOT LOGGED IN must match a guest');
    }

    public function testSelectedGroupsSparesAGroupThatWasNotSelected(): void
    {
        $this->withConfig(true, true, 'groups', '3');
        $this->asVisitor(true, 5);

        self::assertFalse($this->mode()->isActive());
    }
}
