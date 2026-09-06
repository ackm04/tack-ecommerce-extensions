<?php
/**
 * Tests for Tack_Wholesale_Pricing.
 *
 * This class filters `woocommerce_product_get_price`, which reaches the CART and
 * the ORDER — so the interesting cases are all failure cases. Every one of these
 * asserts that a bad or missing answer leaves the store's own price alone,
 * because the alternative is charging the wrong amount.
 *
 * Run: php tests/run.php   (no PHPUnit, no WordPress required)
 *
 * @package TackQuotes
 */

/**
 * A stub product exposing only what the class touches.
 */
class Tack_Test_Product {

	/** @var string */
	private $sku;

	/**
	 * Constructor.
	 *
	 * @param string $sku SKU.
	 */
	public function __construct( $sku ) {
		$this->sku = $sku;
	}

	/**
	 * SKU accessor.
	 *
	 * @return string
	 */
	public function get_sku() {
		return $this->sku;
	}
}

/**
 * An API client that returns a scripted response and counts calls.
 */
class Tack_Test_Pricing_Client extends Tack_Api_Client {

	/** @var mixed Scripted response, or WP_Error. */
	public $response;

	/** @var int How many HTTP calls were made. */
	public $calls = 0;

	/** @var array Last body sent. */
	public $last_body = null;

	/**
	 * Constructor.
	 *
	 * @param mixed $response Scripted response.
	 */
	public function __construct( $response ) {
		$this->response = $response;
	}

	/**
	 * Intercept the request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path.
	 * @param mixed      $body    Body.
	 * @param int|null   $timeout Timeout.
	 * @param array      $headers Headers.
	 * @return mixed
	 */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		++$this->calls;
		$this->last_body = $body;
		return $this->response;
	}
}

// A signed-in customer and a configured key: the baseline where pricing applies.
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

// ── The happy path ──────────────────────────────────────────────────────────
$client = new Tack_Test_Pricing_Client(
	array(
		'buyerMatched' => true,
		'items'        => array(
			array( 'sku' => 'SG-100', 'quantity' => 1, 'unitPrice' => 61.5, 'source' => 'price_book' ),
		),
	)
);
$pricing = new Tack_Wholesale_Pricing( $client );
$product = new Tack_Test_Product( 'SG-100' );

check(
	'a resolved price replaces the store price',
	61.5 === (float) $pricing->filter_price( '89.00', $product ),
	'got ' . var_export( $pricing->filter_price( '89.00', $product ), true )
);

check(
	'the buyer email is sent, so the right price book is used',
	isset( $client->last_body['buyerEmail'] ) && 'buyer@trade-customer.test' === $client->last_body['buyerEmail'],
	var_export( $client->last_body, true )
);

check(
	'repeated get_price() calls do not re-issue the HTTP request',
	1 === $client->calls,
	'calls=' . $client->calls
);

// ── The failure cases, which are the point of this file ─────────────────────

// A transport failure must not zero a price.
$err     = new Tack_Test_Pricing_Client( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
$pricing = new Tack_Wholesale_Pricing( $err );
check(
	'a network failure leaves the store price untouched',
	'89.00' === $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ),
	'got ' . var_export( $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ), true )
);

// `null` unitPrice means "Tack does not price this SKU" — not "free".
$unpriced = new Tack_Test_Pricing_Client(
	array( 'buyerMatched' => true, 'items' => array( array( 'sku' => 'SG-100', 'quantity' => 1, 'unitPrice' => null ) ) )
);
$pricing = new Tack_Wholesale_Pricing( $unpriced );
check(
	'a null unitPrice keeps the store price rather than making the product free',
	'89.00' === $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ),
	'got ' . var_export( $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ), true )
);

// ...but a genuine zero IS a price, and must survive. This is the case a
// truthiness check (`if ( ! $unit )`) would silently break.
$freebie = new Tack_Test_Pricing_Client(
	array( 'buyerMatched' => true, 'items' => array( array( 'sku' => 'SAMPLE', 'quantity' => 1, 'unitPrice' => 0 ) ) )
);
$pricing = new Tack_Wholesale_Pricing( $freebie );
check(
	'a resolved price of ZERO is honoured, not treated as no answer',
	0.0 === (float) $pricing->filter_price( '5.00', new Tack_Test_Product( 'SAMPLE' ) ),
	'got ' . var_export( $pricing->filter_price( '5.00', new Tack_Test_Product( 'SAMPLE' ) ), true )
);

// A SKU the API did not answer for at all.
$missing = new Tack_Test_Pricing_Client( array( 'buyerMatched' => true, 'items' => array() ) );
$pricing = new Tack_Wholesale_Pricing( $missing );
check(
	'a SKU with no line in the response keeps the store price',
	'89.00' === $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ),
	'got ' . var_export( $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ), true )
);

// A product with no SKU cannot be matched and must not be guessed at.
$nosku   = new Tack_Test_Pricing_Client( array( 'items' => array() ) );
$pricing = new Tack_Wholesale_Pricing( $nosku );
check(
	'a product without a SKU is left alone and costs no HTTP call',
	'12.00' === $pricing->filter_price( '12.00', new Tack_Test_Product( '' ) ) && 0 === $nosku->calls,
	'calls=' . $nosku->calls
);

// ── Gating ──────────────────────────────────────────────────────────────────

tack_test_set_logged_in( false, '' );
$anon    = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'SG-100', 'unitPrice' => 61.5 ) ) ) );
$pricing = new Tack_Wholesale_Pricing( $anon );
check(
	'an anonymous shopper is never priced, and no request is made for them',
	'89.00' === $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ) && 0 === $anon->calls,
	'calls=' . $anon->calls
);
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

tack_test_set_option( 'tack_quotes_api_key', '' );
$nokey   = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'SG-100', 'unitPrice' => 61.5 ) ) ) );
$pricing = new Tack_Wholesale_Pricing( $nokey );
check(
	'no API key means no request and no price change',
	'89.00' === $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ) && 0 === $nokey->calls,
	'calls=' . $nokey->calls
);
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );

tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );
$off     = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'SG-100', 'unitPrice' => 61.5 ) ) ) );
$pricing = new Tack_Wholesale_Pricing( $off );
check(
	'the feature is inert until the merchant switches it on',
	'89.00' === $pricing->filter_price( '89.00', new Tack_Test_Product( 'SG-100' ) ) && 0 === $off->calls,
	'calls=' . $off->calls
);
check(
	'and it is OFF by default, so an update cannot change what a store charges',
	false === Tack_Wholesale_Pricing::is_enabled(),
	'is_enabled() returned true with the option set to no'
);
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );

// ── Batching ────────────────────────────────────────────────────────────────

$batch   = new Tack_Test_Pricing_Client( array( 'items' => array() ) );
$pricing = new Tack_Wholesale_Pricing( $batch );
$items   = array();
for ( $i = 0; $i < 80; $i++ ) {
	$items[] = array( 'sku' => 'SKU-' . $i, 'quantity' => 1 );
}
$pricing->resolve( $items );
check(
	'a batch is capped at the 50 items the API accepts, so a big category page is not a 400',
	is_array( $batch->last_body['items'] ) && 50 === count( $batch->last_body['items'] ),
	'sent ' . ( isset( $batch->last_body['items'] ) ? count( $batch->last_body['items'] ) : 'nothing' )
);

// Clean up so later test files start from a known state.
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );
tack_test_set_logged_in( false, '' );
