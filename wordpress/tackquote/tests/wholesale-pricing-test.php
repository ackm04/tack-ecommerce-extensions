<?php
/**
 * Tests for Tack_Wholesale_Pricing.
 *
 * The cart is priced on `woocommerce_before_calculate_totals`, which is real
 * money — so most of these are failure cases, asserting that a bad or missing
 * answer leaves the store's own price alone rather than charging the wrong
 * amount. The first test is the one that forced the current design: a quantity
 * break must reach the CART, not just the volume table.
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

	/** @var float Price currently set on the line. */
	public $price = 0.0;

	/** @var float The store's own price. */
	public $regular = 0.0;

	/**
	 * SKU accessor.
	 *
	 * @return string
	 */
	public function get_sku() {
		return $this->sku;
	}

	/**
	 * Current price.
	 *
	 * @return float
	 */
	public function get_price() {
		return $this->price;
	}

	/**
	 * The store's own price.
	 *
	 * @return float
	 */
	public function get_regular_price() {
		return $this->regular;
	}

	/**
	 * Set the line price — what `apply_cart_prices()` calls.
	 *
	 * @param float $price Price.
	 */
	public function set_price( $price ) {
		$this->price = (float) $price;
	}
}

/**
 * A cart holding the lines under test.
 */
class Tack_Test_Cart {

	/** @var array */
	private $contents;

	/**
	 * Constructor.
	 *
	 * @param array $contents Cart contents.
	 */
	public function __construct( $contents ) {
		$this->contents = $contents;
	}

	/**
	 * Cart contents.
	 *
	 * @return array
	 */
	public function get_cart() {
		return $this->contents;
	}
}

/**
 * Build a one-line cart.
 *
 * @param string $sku   SKU.
 * @param int    $qty   Quantity.
 * @param float  $price Starting price.
 * @return array{cart:Tack_Test_Cart,product:Tack_Test_Product}
 */
function tack_test_cart( $sku, $qty, $price ) {
	$product          = new Tack_Test_Product( $sku );
	$product->price   = $price;
	$product->regular = $price;
	$cart             = new Tack_Test_Cart(
		array( 'line1' => array( 'data' => $product, 'quantity' => $qty ) )
	);
	return array( 'cart' => $cart, 'product' => $product );
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

// ── THE BUG THAT FORCED THIS DESIGN ─────────────────────────────────────────
//
// The first version filtered `woocommerce_product_get_price`, which is asked
// "what does this product cost" with NO quantity — so it always resolved at
// quantity 1. The volume table advertised "100+ £55.00" and the cart charged
// £61.50. This test fails against that implementation.
$breaks = new Tack_Test_Pricing_Client(
	array(
		'buyerMatched' => true,
		'items'        => array(
			array( 'sku' => 'SG-100', 'quantity' => 100, 'unitPrice' => 55.0 ),
		),
	)
);
$pricing = new Tack_Wholesale_Pricing( $breaks );
$fixture = tack_test_cart( 'SG-100', 100, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );

check(
	'a cart line of 100 is charged the QUANTITY-100 price, not the single-unit price',
	55.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);
check(
	'and the real cart quantity is what was sent to Tack',
	isset( $breaks->last_body['items'][0]['quantity'] ) && 100 === $breaks->last_body['items'][0]['quantity'],
	var_export( $breaks->last_body, true )
);

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
$fixture = tack_test_cart( 'SG-100', 1, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );

check(
	'a resolved price replaces the store price on the cart line',
	61.5 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);
check(
	'the buyer email is sent, so the right price book is used',
	isset( $client->last_body['buyerEmail'] ) && 'buyer@trade-customer.test' === $client->last_body['buyerEmail'],
	var_export( $client->last_body, true )
);
check(
	'the whole cart is priced in ONE request, not one per line',
	1 === $client->calls,
	'calls=' . $client->calls
);

// The label is a separate hook and must show the buyer's price.
$html = $pricing->filter_price_html( '<span>$89.00</span>', $fixture['product'] );
check(
	'the displayed price is the buyer price, with the store price struck through',
	false !== strpos( $html, '61.50' ) && false !== strpos( $html, '<del' ),
	'got ' . $html
);

// ── The failure cases ───────────────────────────────────────────────────────

// A transport failure must not zero a price.
$err     = new Tack_Test_Pricing_Client( new WP_Error( 'http_request_failed', 'Connection timed out' ) );
$pricing = new Tack_Wholesale_Pricing( $err );
$fixture = tack_test_cart( 'SG-100', 5, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a network failure leaves the cart line at the store price',
	89.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);

// `null` unitPrice means "Tack does not price this SKU" — not "free".
$unpriced = new Tack_Test_Pricing_Client(
	array( 'items' => array( array( 'sku' => 'SG-100', 'quantity' => 5, 'unitPrice' => null ) ) )
);
$pricing = new Tack_Wholesale_Pricing( $unpriced );
$fixture = tack_test_cart( 'SG-100', 5, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a null unitPrice keeps the store price rather than making the line free',
	89.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);

// ...but a genuine zero IS a price. `empty()` would break this.
$freebie = new Tack_Test_Pricing_Client(
	array( 'items' => array( array( 'sku' => 'SAMPLE', 'quantity' => 1, 'unitPrice' => 0 ) ) )
);
$pricing = new Tack_Wholesale_Pricing( $freebie );
$fixture = tack_test_cart( 'SAMPLE', 1, 5.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a resolved price of ZERO is honoured, not treated as no answer',
	0.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);

// A SKU the API did not answer for at all.
$missing = new Tack_Test_Pricing_Client( array( 'items' => array() ) );
$pricing = new Tack_Wholesale_Pricing( $missing );
$fixture = tack_test_cart( 'SG-100', 2, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a SKU with no line in the response keeps the store price',
	89.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);

// A product with no SKU cannot be matched and must not be guessed at.
$nosku   = new Tack_Test_Pricing_Client( array( 'items' => array() ) );
$pricing = new Tack_Wholesale_Pricing( $nosku );
$fixture = tack_test_cart( '', 1, 12.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a product without a SKU is left alone and costs no HTTP call',
	12.0 === $fixture['product']->get_price() && 0 === $nosku->calls,
	'calls=' . $nosku->calls
);

// ── Tax basis ───────────────────────────────────────────────────────────────
//
// Tack always returns a NET unit price. `set_price()` means "this is the price
// in the basis the store is configured for". On a tax-INCLUSIVE store the two
// disagree, and handing the net figure over unchanged makes WooCommerce extract
// the tax back out of it — the seller eats the VAT on every wholesale line.

$net     = new Tack_Test_Pricing_Client(
	array( 'items' => array( array( 'sku' => 'SG-100', 'quantity' => 1, 'unitPrice' => 100.0 ) ) )
);
$pricing = new Tack_Wholesale_Pricing( $net );
$fixture = tack_test_cart( 'SG-100', 1, 150.0 );
tack_test_set_prices_include_tax( false );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a tax-EXCLUSIVE store gets the net price unchanged',
	100.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true )
);

$net2    = new Tack_Test_Pricing_Client(
	array( 'items' => array( array( 'sku' => 'SG-100', 'quantity' => 1, 'unitPrice' => 100.0 ) ) )
);
$pricing = new Tack_Wholesale_Pricing( $net2 );
$fixture = tack_test_cart( 'SG-100', 1, 150.0 );
tack_test_set_prices_include_tax( true );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'a tax-INCLUSIVE store gets the net price grossed up, so the seller does not eat the VAT',
	120.0 === $fixture['product']->get_price(),
	'got ' . var_export( $fixture['product']->get_price(), true ) . ' (expected 120.00 from 100.00 net at 20%)'
);
tack_test_set_prices_include_tax( false );

// ── Gating ──────────────────────────────────────────────────────────────────

tack_test_set_logged_in( false, '' );
$anon    = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'SG-100', 'unitPrice' => 61.5 ) ) ) );
$pricing = new Tack_Wholesale_Pricing( $anon );
$fixture = tack_test_cart( 'SG-100', 1, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'an anonymous shopper is never priced, and no request is made for them',
	89.0 === $fixture['product']->get_price() && 0 === $anon->calls,
	'calls=' . $anon->calls
);
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

tack_test_set_option( 'tack_quotes_api_key', '' );
$nokey   = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'SG-100', 'unitPrice' => 61.5 ) ) ) );
$pricing = new Tack_Wholesale_Pricing( $nokey );
$fixture = tack_test_cart( 'SG-100', 1, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'no API key means no request and no price change',
	89.0 === $fixture['product']->get_price() && 0 === $nokey->calls,
	'calls=' . $nokey->calls
);
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );

tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );
$off     = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'SG-100', 'unitPrice' => 61.5 ) ) ) );
$pricing = new Tack_Wholesale_Pricing( $off );
$fixture = tack_test_cart( 'SG-100', 1, 89.0 );
$pricing->apply_cart_prices( $fixture['cart'] );
check(
	'the feature is inert until the merchant switches it on',
	89.0 === $fixture['product']->get_price() && 0 === $off->calls,
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
