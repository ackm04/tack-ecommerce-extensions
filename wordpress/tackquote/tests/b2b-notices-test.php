<?php
/**
 * Tests for Tack_B2B_Notices — order limits and the buyer-group badge.
 *
 * The two properties that matter pull in opposite directions, so both are
 * pinned here:
 *
 *   · A limit that only WARNS is not a limit. If the store says "minimum 25"
 *     and then takes an order for 3, the rule may as well not exist.
 *   · A limit that blocks on a NETWORK FAILURE is worse than no limit. A
 *     checkout that stops working because a supplier's API is slow costs the
 *     day's revenue; an unenforced minimum costs a phone call.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

/**
 * An API client returning a scripted response per path fragment.
 */
class Tack_Test_Notices_Client extends Tack_Api_Client {

	/** @var array<string,mixed> Path fragment => response. */
	private $responses;

	/** @var int */
	public $calls = 0;

	/** @var string[] Paths requested. */
	public $paths = array();

	/**
	 * Constructor.
	 *
	 * @param array $responses Path fragment => response (or WP_Error).
	 */
	public function __construct( $responses ) {
		$this->responses = $responses;
	}

	/**
	 * Intercept the request.
	 *
	 * @param string   $method  HTTP method.
	 * @param string   $path    Path.
	 * @param mixed    $body    Body.
	 * @param int|null $timeout Timeout.
	 * @param array    $headers Headers.
	 * @return mixed
	 */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		++$this->calls;
		$this->paths[] = $path;
		foreach ( $this->responses as $fragment => $response ) {
			if ( false !== strpos( $path, $fragment ) ) {
				return $response;
			}
		}
		return array( 'status' => 'none' );
	}
}

tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

/** A `limited` response with a minimum of 25. */
$min25 = array(
	'order-limits' => array(
		'status'          => 'limited',
		'accountSpecific' => true,
		'limits'          => array( array( 'minQuantity' => 25, 'maxQuantity' => null ) ),
	),
);

// ── Enforcement ─────────────────────────────────────────────────────────────

$client = new Tack_Test_Notices_Client( $min25 );
$notices = new Tack_B2B_Notices( $client );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 3, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'a cart below the minimum is REFUSED, not merely warned about',
	1 === count( tack_test_notices() ) && false !== strpos( tack_test_notices()[0], '25' ),
	var_export( tack_test_notices(), true )
);

$client  = new Tack_Test_Notices_Client( $min25 );
$notices = new Tack_B2B_Notices( $client );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 25, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'a cart that meets the minimum passes',
	0 === count( tack_test_notices() ),
	var_export( tack_test_notices(), true )
);

// The same product can appear as several lines. Checking each line alone would
// refuse 10 + 20 against a minimum of 25 — a cart the buyer has in fact met.
$client  = new Tack_Test_Notices_Client( $min25 );
$notices = new Tack_B2B_Notices( $client );
tack_test_reset_notices();
tack_test_set_cart(
	array(
		array( 'sku' => 'SG-100', 'qty' => 10, 'name' => 'Safety Gloves' ),
		array( 'sku' => 'SG-100', 'qty' => 20, 'name' => 'Safety Gloves' ),
	)
);
$notices->enforce_order_limits();
check(
	'quantities are summed ACROSS lines of the same SKU before checking',
	0 === count( tack_test_notices() ),
	var_export( tack_test_notices(), true )
);

// A maximum, from the other direction.
$client  = new Tack_Test_Notices_Client(
	array(
		'order-limits' => array(
			'status'  => 'limited',
			'limits'  => array( array( 'minQuantity' => null, 'maxQuantity' => 50 ) ),
		),
	)
);
$notices = new Tack_B2B_Notices( $client );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 80, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'a cart above the maximum is refused too',
	1 === count( tack_test_notices() ) && false !== strpos( tack_test_notices()[0], '50' ),
	var_export( tack_test_notices(), true )
);

// ── Fail OPEN, which is the opposite requirement ────────────────────────────

$down    = new Tack_Test_Notices_Client( array( 'order-limits' => new WP_Error( 'timeout', 'Connection timed out' ) ) );
$notices = new Tack_B2B_Notices( $down );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 3, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'a TackQuote outage does NOT block checkout — nothing is refused',
	0 === count( tack_test_notices() ),
	var_export( tack_test_notices(), true )
);

// `none` is a real answer meaning "no limits apply", and must not block either.
$none    = new Tack_Test_Notices_Client( array( 'order-limits' => array( 'status' => 'none' ) ) );
$notices = new Tack_B2B_Notices( $none );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 1, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'a product with no limits is never refused',
	0 === count( tack_test_notices() ),
	var_export( tack_test_notices(), true )
);

// A rule carrying neither bound constrains nothing and must not say "minimum 0".
$empty   = new Tack_Test_Notices_Client(
	array( 'order-limits' => array( 'status' => 'limited', 'limits' => array( array( 'minQuantity' => null, 'maxQuantity' => null ) ) ) )
);
$notices = new Tack_B2B_Notices( $empty );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 1, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'a limit rule with neither bound refuses nothing',
	0 === count( tack_test_notices() ),
	var_export( tack_test_notices(), true )
);

// One lookup per SKU per request, however many lines reference it.
$counted = new Tack_Test_Notices_Client( $min25 );
$notices = new Tack_B2B_Notices( $counted );
tack_test_reset_notices();
tack_test_set_cart(
	array(
		array( 'sku' => 'SG-100', 'qty' => 30, 'name' => 'Safety Gloves' ),
		array( 'sku' => 'SG-100', 'qty' => 30, 'name' => 'Safety Gloves' ),
	)
);
$notices->enforce_order_limits();
$notices->enforce_order_limits();
check(
	'the limit for a SKU is looked up once, not once per line or per call',
	1 === $counted->calls,
	'calls=' . $counted->calls
);

// ── Gating ──────────────────────────────────────────────────────────────────

tack_test_set_option( 'tack_quotes_api_key', '' );
$nokey   = new Tack_Test_Notices_Client( $min25 );
$notices = new Tack_B2B_Notices( $nokey );
tack_test_reset_notices();
tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 1, 'name' => 'Safety Gloves' ) ) );
$notices->enforce_order_limits();
check(
	'no API key means no lookup and nothing refused',
	0 === count( tack_test_notices() ) && 0 === $nokey->calls,
	'calls=' . $nokey->calls
);
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );

check(
	'both switches are OFF by default, so an update cannot start refusing orders',
	false === Tack_B2B_Notices::is_enabled(),
	'is_enabled() was true with both options unset'
);

// ── Buyer group ─────────────────────────────────────────────────────────────

$grouped = new Tack_Test_Notices_Client(
	array( 'buyer-group' => array( 'status' => 'grouped', 'name' => 'Tier 2 — Distributor', 'code' => 'TIER2' ) )
);
$notices = new Tack_B2B_Notices( $grouped );
$group   = $notices->buyer_group();
check(
	'a grouped buyer gets their group name',
	is_array( $group ) && 'Tier 2 — Distributor' === $group['name'],
	var_export( $group, true )
);

$ungrouped = new Tack_Test_Notices_Client( array( 'buyer-group' => array( 'status' => 'none' ) ) );
$notices   = new Tack_B2B_Notices( $ungrouped );
check(
	'a buyer in no group gets no badge rather than an empty one',
	null === $notices->buyer_group(),
	'expected null'
);

tack_test_set_logged_in( false, '' );
$anon    = new Tack_Test_Notices_Client( array( 'buyer-group' => array( 'status' => 'grouped', 'name' => 'Tier 2' ) ) );
$notices = new Tack_B2B_Notices( $anon );
check(
	'an anonymous visitor is never asked about, and gets no badge',
	null === $notices->buyer_group() && 0 === $anon->calls,
	'calls=' . $anon->calls
);
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

// Clean up for later test files.
tack_test_set_logged_in( false, '' );
tack_test_set_cart( array() );
tack_test_reset_notices();
