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

// ── The privilege-escalation path, and the guard that closes it ─────────────
//
// TackQuote resolves a buyer — price book, group, and through the group their
// PAYMENT TERMS — from the email this plugin sends. WooCommerce lets a customer
// change their own email on My Account with no verification at all:
// `WC_Form_Handler::save_account_details()` demands the current password only
// when the PASSWORD changes, and otherwise calls `wp_update_user()` directly
// (read from the installed WooCommerce 11.1 source).
//
// Its only protection is `email_exists()`, which refuses an address already
// held by another WORDPRESS user. That is the gap: a TackQuote buyer approved
// for Net-30 who never registered on this store is not a WordPress user, so
// their address is free to take. Register, retype their email, reload.

tack_test_reset_user_meta();
tack_test_set_logged_in( true, 'attacker@example.test' );

// First sight of an account records the address without flagging it — otherwise
// upgrading the plugin would strip entitlements from every existing customer.
Tack_B2B_Notices::flag_email_change( 1 );
$c = new Tack_Test_Notices_Client( array( 'buyer-group' => array( 'status' => 'grouped', 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$n = new Tack_B2B_Notices( $c );
check(
	'an account seen for the first time is NOT flagged, so an upgrade strips nobody',
	is_array( $n->buyer_group() ),
	'expected the group to resolve'
);

// Now the customer changes their own email to an approved buyer's address.
tack_test_set_logged_in( true, 'approved-net30-buyer@example.test' );
Tack_B2B_Notices::flag_email_change( 1 );

$c2 = new Tack_Test_Notices_Client( array( 'buyer-group' => array( 'status' => 'grouped', 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$n2 = new Tack_B2B_Notices( $c2 );
check(
	'a SELF-CHANGED email no longer resolves a buyer, so the group cannot be inherited',
	null === $n2->buyer_group(),
	'group was still resolved after an unverified email change'
);
check(
	'...and it is not even asked about, so nothing leaks to TackQuote either',
	0 === $c2->calls,
	'calls=' . $c2->calls
);
check(
	'...and it reads as ANONYMOUS, not as an outage — so a restricted method is refused, not granted',
	'anonymous' === $n2->buyer_group_status(),
	'status=' . $n2->buyer_group_status()
);

// A store with its own verification can opt back in, explicitly.
tack_test_add_filter_return( 'tackquote_trust_unverified_email', true );
$c3 = new Tack_Test_Notices_Client( array( 'buyer-group' => array( 'status' => 'grouped', 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$n3 = new Tack_B2B_Notices( $c3 );
check(
	'a store that verifies email another way can opt back in through the filter',
	is_array( $n3->buyer_group() ),
	'filter did not restore trust'
);
tack_test_clear_filter_returns();
tack_test_reset_user_meta();

// Clean up for later test files.
tack_test_set_logged_in( false, '' );
tack_test_set_cart( array() );
tack_test_reset_notices();
