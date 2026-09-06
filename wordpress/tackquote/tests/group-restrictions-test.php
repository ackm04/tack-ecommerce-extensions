<?php
/**
 * Tests for Tack_Group_Restrictions.
 *
 * The dangerous direction here is removal: this filter takes payment gateways
 * and shipping rates AWAY, and a checkout with none of either is a checkout
 * nobody can complete. So most of these assert that things are NOT removed —
 * on an unconfigured method, on an unknown buyer group, and on a rule that
 * would empty the list entirely.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

/**
 * A Tack_B2B_Notices that reports a fixed group without any HTTP.
 */
class Tack_Test_Group_Source extends Tack_B2B_Notices {

	/** @var array|null */
	private $group;

	/** @var string grouped|none|anonymous|unavailable */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param array|null $group  array{name:string,code:string} or null.
	 * @param string     $status Why, when there is no group. Defaults to the
	 *                           outage case, which is the permissive one — so a
	 *                           test asserting a DENIAL has to opt in and cannot
	 *                           pass by accident.
	 */
	public function __construct( $group, $status = 'unavailable' ) {
		$this->group  = $group;
		$this->status = $group ? 'grouped' : $status;
	}

	/**
	 * The buyer's group.
	 *
	 * @return array|null
	 */
	public function buyer_group() {
		return $this->group;
	}

	/**
	 * Why the answer is what it is.
	 *
	 * @return string
	 */
	public function buyer_group_status() {
		return $this->status;
	}
}

$tier2 = new Tack_Test_Group_Source( array( 'name' => 'Tier 2', 'code' => 'TIER2' ) );
$tier3 = new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'TIER3' ) );
$none  = new Tack_Test_Group_Source( null );

/** The gateways a store might offer. */
$gateways = array(
	'cod'    => 'Cash on delivery',
	'bacs'   => 'Bank transfer (Net 30)',
	'stripe' => 'Card',
);

// ── The rule does what it says ──────────────────────────────────────────────

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "bacs: TIER3" );

$r   = new Tack_Group_Restrictions( $tier2 );
$out = $r->filter_gateways( $gateways );
check(
	'a gateway restricted to TIER3 is hidden from a TIER2 buyer',
	! isset( $out['bacs'] ) && isset( $out['cod'] ) && isset( $out['stripe'] ),
	implode( ',', array_keys( $out ) )
);

$r   = new Tack_Group_Restrictions( $tier3 );
$out = $r->filter_gateways( $gateways );
check(
	'and is offered to a TIER3 buyer',
	isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

// ── Unconfigured means unrestricted ─────────────────────────────────────────
//
// The opposite default would make switching this feature on an immediate
// checkout outage: every method starts unconfigured.

check(
	'a gateway named in no rule stays available to everyone',
	isset( $out['cod'] ) && isset( $out['stripe'] ),
	implode( ',', array_keys( $out ) )
);

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, '' );
$r   = new Tack_Group_Restrictions( $tier2 );
$out = $r->filter_gateways( $gateways );
check(
	'with no rules at all, nothing is filtered',
	3 === count( $out ),
	implode( ',', array_keys( $out ) )
);

// ── Unknown group fails OPEN ────────────────────────────────────────────────

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "bacs: TIER3" );
$r   = new Tack_Group_Restrictions( $none );
$out = $r->filter_gateways( $gateways );
check(
	'an unknown buyer group leaves restricted methods VISIBLE, so an outage cannot remove the ability to pay',
	isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

// ...but a merchant can demand the strict reading.
tack_test_add_filter_return( 'tackquote_restrict_when_group_unknown', true );
$r   = new Tack_Group_Restrictions( $none );
$out = $r->filter_gateways( $gateways );
check(
	'the strict reading is available through a filter, for a merchant who wants it',
	! isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);
tack_test_clear_filter_returns();

// ── Never empty the list ────────────────────────────────────────────────────
//
// A misconfiguration must degrade to "too many options", never to "no way to
// pay". This is the test that stops a typo in a group code taking a store down.

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "cod: TIER9\nbacs: TIER9\nstripe: TIER9" );
$r   = new Tack_Group_Restrictions( $tier2 );
$out = $r->filter_gateways( $gateways );
check(
	'a rule set that would remove EVERY gateway is ignored, not obeyed',
	3 === count( $out ),
	implode( ',', array_keys( $out ) )
);

tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, "flat_rate: TIER9" );
$r   = new Tack_Group_Restrictions( $tier2 );
$out = $r->filter_shipping_rates( array( 'flat_rate:1' => 'Flat rate' ) );
check(
	'the same holds for shipping: the last rate is never removed',
	1 === count( $out ),
	implode( ',', array_keys( $out ) )
);

// ── Shipping rate ids ───────────────────────────────────────────────────────

tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, "free_shipping: TIER3" );
$rates = array( 'free_shipping:3' => 'Free shipping', 'flat_rate:1' => 'Flat rate' );

$r   = new Tack_Group_Restrictions( $tier2 );
$out = $r->filter_shipping_rates( $rates );
check(
	'a rule on the METHOD id matches the full rate id (method:instance)',
	! isset( $out['free_shipping:3'] ) && isset( $out['flat_rate:1'] ),
	implode( ',', array_keys( $out ) )
);

$r   = new Tack_Group_Restrictions( $tier3 );
$out = $r->filter_shipping_rates( $rates );
check(
	'and the permitted group still gets it',
	isset( $out['free_shipping:3'] ),
	implode( ',', array_keys( $out ) )
);

// ── "Could not ask" vs "asked, answer is no" ────────────────────────────────
//
// These need OPPOSITE defaults and were collapsed into one `null`. A buyer
// TackQuote had DEFINITIVELY placed in no group was treated exactly like an
// outage, and therefore handed every restricted payment method — Net-30 for
// anyone who registers. This is the pair that pins the fix.

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "bacs: TIER3" );

$definitely_none = new Tack_Test_Group_Source( null, 'none' );
$r   = new Tack_Group_Restrictions( $definitely_none );
$out = $r->filter_gateways( $gateways );
check(
	'a buyer TackQuote says is in NO group is REFUSED a group-restricted gateway',
	! isset( $out['bacs'] ) && isset( $out['cod'] ),
	implode( ',', array_keys( $out ) )
);

$anon_answer = new Tack_Test_Group_Source( null, 'anonymous' );
$r   = new Tack_Group_Restrictions( $anon_answer );
$out = $r->filter_gateways( $gateways );
check(
	'an anonymous shopper is refused it too — that is a real answer, not an outage',
	! isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

$outage = new Tack_Test_Group_Source( null, 'unavailable' );
$r   = new Tack_Group_Restrictions( $outage );
$out = $r->filter_gateways( $gateways );
check(
	'but a TackQuote OUTAGE still leaves it visible, so a slow API cannot stop checkout',
	isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

// ── Case ────────────────────────────────────────────────────────────────────
//
// A merchant typing a code in the wrong case would otherwise match nothing —
// and because the rule then fails for EVERY group, the gateway silently
// disappears for every customer, permanently. The "never empty" guard does not
// catch it: that only fires when a rule removes EVERY gateway.

tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "bacs: tier3" );
$r   = new Tack_Group_Restrictions( $tier3 );
$out = $r->filter_gateways( $gateways );
check(
	'a lowercase group code in the config still matches an uppercase group',
	isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

$r   = new Tack_Group_Restrictions( $tier2 );
$out = $r->filter_gateways( $gateways );
check(
	'...and still excludes the group it should',
	! isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

// The falsifier for the OTHER half. The parser upper-cases the config, so the
// two cases above pass with or without normalising the group side — a mutation
// removing `strtoupper($allowed)` left them green, which is exactly the kind of
// test that proves nothing. This one drives a LOWERCASE group code back from
// TackQuote, which is the only input that exercises it.
$lower = new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'tier3' ) );
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, "bacs: TIER3" );
$r     = new Tack_Group_Restrictions( $lower );
$out   = $r->filter_gateways( $gateways );
check(
	'a lowercase code RETURNED BY TACKQUOTE still matches an uppercase rule',
	isset( $out['bacs'] ),
	implode( ',', array_keys( $out ) )
);

// ── Parsing ─────────────────────────────────────────────────────────────────

$r   = new Tack_Group_Restrictions( $tier2 );
$map = $r->parse_map( "  cod : TIER2 , TIER3 \n\n# a comment\nbacs:TIER3\ngarbage-with-no-colon\n: nogroups\nempty:" );
check(
	'the map parser trims, skips blanks, comments and unparsable lines',
	array( 'TIER2', 'TIER3' ) === $map['cod'] && array( 'TIER3' ) === $map['bacs'] && 2 === count( $map ),
	var_export( $map, true )
);

// A typo must not throw — an unparsable line restricts nothing.
check(
	'a line with no groups after the colon is skipped rather than restricting to nobody',
	! isset( $map['empty'] ),
	var_export( $map, true )
);

// Clean up for later test files.
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, '' );
tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, '' );
