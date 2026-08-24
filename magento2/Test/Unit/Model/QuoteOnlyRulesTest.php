<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyRules;

/**
 * @covers \TackQuote\Quotes\Model\QuoteOnlyRules
 */
class QuoteOnlyRulesTest extends TestCase
{
    public function testScopeAllCoversGuestsAndSignedInCustomersAlike(): void
    {
        self::assertTrue(QuoteOnlyRules::applies(true, 'all', [], false, 0), 'a guest is covered');
        self::assertTrue(QuoteOnlyRules::applies(true, 'all', [], true, 5), 'a signed-in customer is covered');
    }

    public function testScopeGuestsLetsApprovedCustomersKeepTheCart(): void
    {
        self::assertTrue(
            QuoteOnlyRules::applies(true, 'guests', [], false, 0),
            'the public gets the catalog'
        );
        self::assertFalse(
            QuoteOnlyRules::applies(true, 'guests', [], true, 5),
            'a signed-in customer must keep a working cart — this scope IS the "approved '
            . 'buyers still buy" setting, and inverting it turns every B2B account into a catalog'
        );
    }

    public function testScopeGroupsMatchesOnlyTheSelectedGroups(): void
    {
        self::assertTrue(QuoteOnlyRules::applies(true, 'groups', [2, 3], true, 2), 'group 2 is selected');
        self::assertFalse(QuoteOnlyRules::applies(true, 'groups', [2, 3], true, 5), 'group 5 is not');
    }

    public function testGroupZeroIsARealGroupInMagentoAndCanBeSelected(): void
    {
        // NOT LOGGED IN is customer group 0 here — a row in the customer-group grid, not a
        // sentinel. The OpenCart build of this feature deliberately DROPS 0 because OpenCart
        // uses it to mean "no group"; copying that would silently delete a merchant's
        // selection of the guest group.
        self::assertSame([0], QuoteOnlyRules::normaliseGroups('0'), 'group 0 must survive parsing');
        self::assertTrue(
            QuoteOnlyRules::applies(true, 'groups', [0], false, 0),
            'selecting NOT LOGGED IN must actually match a guest'
        );
    }

    public function testAnEmptyGroupSelectionMatchesNobodySoTheCartStaysOpen(): void
    {
        self::assertFalse(
            QuoteOnlyRules::applies(true, 'groups', [], true, 2),
            'a store with no groups chosen must fail towards a WORKING store, not a locked one'
        );
    }

    public function testTheModeIsOffUnlessTheMerchantSwitchedItOn(): void
    {
        self::assertFalse(QuoteOnlyRules::applies(false, 'all', [], false, 0), 'disabled beats every scope');
    }

    public function testAnUnrecognisedScopeFallsBackToAllNotToNobody(): void
    {
        self::assertTrue(QuoteOnlyRules::applies(true, 'ALL', [], false, 0), 'case is normalised');
        self::assertTrue(
            QuoteOnlyRules::applies(true, 'nonsense', [], false, 0),
            'a corrupted core_config_data row must not quietly re-open the cart on a store '
            . 'whose owner switched quote-only on'
        );
    }

    /**
     * @dataProvider groupShapes
     * @param mixed $input
     * @param int[] $expected
     */
    public function testGroupIdsAreNormalisedFromEveryShapeMagentoCanReturn($input, array $expected): void
    {
        self::assertSame($expected, QuoteOnlyRules::normaliseGroups($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: int[]}>
     */
    public static function groupShapes(): array
    {
        return [
            'multiselect string, which is how Magento persists it' => ['0,1,3', [0, 1, 3]],
            'multiselect string with spaces' => ['1, 2', [1, 2]],
            'a real array from a fixture' => [[1, 2], [1, 2]],
            'strings and duplicates' => [['2', 2, '3'], [2, 3]],
            'never saved' => [null, []],
            'empty string' => ['', []],
            'junk is dropped, not cast to group 0' => [['abc', '', '-1', '1'], [1]],
            'nested junk is skipped' => [[['x'], true, 4], [4]],
        ];
    }

    public function testScopeConstantsAreTheAdminDropdownValues(): void
    {
        // The admin source model is built from these constants; if the vocabulary changes,
        // storefront and admin must change together.
        self::assertSame(['all', 'guests', 'groups'], QuoteOnlyRules::scopes());
    }
}
