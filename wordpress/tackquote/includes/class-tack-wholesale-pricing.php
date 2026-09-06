<?php
/**
 * B2B pricing resolved by TackQuote, applied to the WooCommerce storefront.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CLOSES
 * ─────────────────────────────────────────────────────────────────────────────
 * Until now the plugin could SEND a quote request and RECEIVE Tack-resolved
 * prices back on the quote — but a signed-in trade customer browsing the shop
 * still saw the store's retail price, because nothing let the storefront ask
 * what Tack would charge before a quote existed. The wholesale price only
 * appeared after the fact.
 *
 * `POST /storefront-pricing/resolve` is the answer to that question. It takes a
 * buyer email and a set of SKUs and returns the price Tack's own price books,
 * buyer groups and quantity breaks resolve to — the same authority that prices
 * a quote. This class asks it, and applies the answer.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY IT IS SAFE TO PUT THIS ON `woocommerce_product_get_price`
 * ─────────────────────────────────────────────────────────────────────────────
 * That filter reaches the CART and the ORDER, not just the label — which is the
 * point (a trade customer should be charged their price), and also the whole
 * risk. Three rules keep it honest, and each one is a test in
 * tests/wholesale-pricing-test.php:
 *
 *   1. NO ANSWER MEANS NO CHANGE. A network failure, a timeout, an unknown SKU
 *      or a `null` unitPrice all leave the store's own price untouched. The
 *      failure mode is "wholesale pricing did not apply", never "the product is
 *      free" — `0` is a legitimate resolved price and `null` is not, so the two
 *      are distinguished explicitly rather than through PHP's falsy rules.
 *   2. ANONYMOUS SHOPPERS ARE NEVER PRICED. Without a signed-in customer there
 *      is no buyer to resolve against, so no request is made at all. This also
 *      means a page cache holding a logged-out render can never contain one
 *      customer's negotiated price.
 *   3. ONE REQUEST PER PAGE, NOT ONE PER PRODUCT. A shop page renders dozens of
 *      products and each one calls `get_price()` several times. Every lookup is
 *      served from a request-scoped map, and the map is filled by a single
 *      batched call. Without this a category page would issue ~100 HTTP
 *      requests and time out.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GATING
 * ─────────────────────────────────────────────────────────────────────────────
 * B2B pricing is a paid TackQuote capability. That is NOT enforced here — a
 * client-side plan check is a client-side plan check. The endpoint sits behind
 * `ApiKeyGuard` and `PlanGuard` on the API, so a store on a plan without it
 * receives an error and, by rule 1 above, simply keeps its own prices. What
 * this class does is avoid asking when there is obviously nothing to ask with:
 * no API key, or no signed-in customer.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Applies TackQuote-resolved B2B prices to WooCommerce products.
 */
class Tack_Wholesale_Pricing {

	/**
	 * Option that turns the whole feature on.
	 *
	 * Default 'no': switching a store's prices over is a decision a merchant
	 * makes deliberately, not something a plugin update does to them.
	 */
	const OPTION_ENABLED = 'tack_quotes_enable_wholesale_pricing';

	/** Option controlling the quantity-break table on the product page. */
	const OPTION_SHOW_BREAKS = 'tack_quotes_show_quantity_breaks';

	/**
	 * The API caps a resolve request at 50 items, so batches are chunked to match.
	 *
	 * Sending 51 is a 400 for the WHOLE batch, which would blank the prices on a
	 * large category page rather than degrade.
	 */
	const MAX_ITEMS_PER_REQUEST = 50;

	/**
	 * Interactive timeout, in seconds.
	 *
	 * A shop page must not hang on Tack being slow. Deliberately shorter than the
	 * client's 20s default: past a couple of seconds the right answer is "show
	 * the store price" rather than "show nothing yet".
	 */
	const TIMEOUT = 5;

	/**
	 * Resolved unit prices for this request, keyed `sku|quantity`.
	 *
	 * Request-scoped rather than a transient: a price is per BUYER, and a shared
	 * cache keyed only by SKU is how one customer is shown another's negotiated
	 * price. If this ever becomes a transient the key must include the buyer.
	 *
	 * @var array<string, float|null>
	 */
	private $resolved = array();

	/**
	 * SKUs already asked about this request, so an unpriced one is not re-sent.
	 *
	 * Distinct from `$resolved`: a SKU Tack does not carry returns no line at
	 * all, and without this it would be re-requested on every `get_price()`.
	 *
	 * @var array<string, true>
	 */
	private $asked = array();

	/** API client. @var Tack_Api_Client */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
	}

	/**
	 * Is the feature switched on by the merchant?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		// Priority 20: after WooCommerce's own sale-price handling, so the Tack
		// price is what survives when both apply. A merchant who wants the lower
		// of the two can filter `tackquote_wholesale_price` below.
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 20, 2 );

		if ( 'yes' === get_option( self::OPTION_SHOW_BREAKS, 'yes' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_quantity_breaks' ), 25 );
		}
	}

	/**
	 * Replace the price with the one Tack resolves for the signed-in buyer.
	 *
	 * @param string|float $price   The store's price.
	 * @param object       $product WC_Product.
	 * @return string|float
	 */
	public function filter_price( $price, $product ) {
		if ( ! $this->should_apply() ) {
			return $price;
		}
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return $price;
		}

		$sku = (string) $product->get_sku();
		if ( '' === $sku ) {
			// Tack prices by SKU. A product without one cannot be matched, and
			// guessing from the title would be worse than leaving it alone.
			return $price;
		}

		$resolved = $this->unit_price( $sku, 1 );
		if ( null === $resolved ) {
			return $price;
		}

		/**
		 * Filters the wholesale unit price before it replaces the store price.
		 *
		 * @param float  $resolved Price resolved by TackQuote.
		 * @param mixed  $price    The store's own price.
		 * @param string $sku      Product SKU.
		 */
		return apply_filters( 'tackquote_wholesale_price', $resolved, $price, $sku );
	}

	/**
	 * The resolved unit price for a SKU, or null when Tack has no answer.
	 *
	 * @param string $sku      Product SKU.
	 * @param int    $quantity Quantity to price.
	 * @return float|null
	 */
	public function unit_price( $sku, $quantity = 1 ) {
		$key = $sku . '|' . (int) $quantity;
		if ( array_key_exists( $key, $this->resolved ) ) {
			return $this->resolved[ $key ];
		}
		if ( isset( $this->asked[ $key ] ) ) {
			return null;
		}

		$lines = $this->resolve( array( array( 'sku' => $sku, 'quantity' => (int) $quantity ) ) );
		return array_key_exists( $key, $lines ) ? $lines[ $key ] : null;
	}

	/**
	 * Ask Tack to price a batch, and remember every answer.
	 *
	 * @param array $items Array of array{sku:string,quantity:int}.
	 * @return array<string, float|null> Keyed `sku|quantity`.
	 */
	public function resolve( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			$this->asked[ $item['sku'] . '|' . (int) $item['quantity'] ] = true;
		}

		$body = array( 'items' => array_slice( $items, 0, self::MAX_ITEMS_PER_REQUEST ) );

		$email = $this->buyer_email();
		if ( '' !== $email ) {
			$body['buyerEmail'] = $email;
		}

		$response = $this->client->request( 'POST', '/storefront-pricing/resolve', $body, self::TIMEOUT );

		if ( is_wp_error( $response ) ) {
			/*
			 * Logged, not surfaced. The shopper sees the store's own price and
			 * nothing is broken from where they stand; the merchant needs to know
			 * their B2B pricing is not being applied, and WooCommerce's log is
			 * where the plugin's other failures already go.
			 */
			$this->log( 'storefront-pricing resolve failed: ' . $response->get_error_message() );
			return array();
		}

		$out = array();
		$lines = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();
		foreach ( $lines as $line ) {
			if ( ! isset( $line['sku'] ) ) {
				continue;
			}
			$key = (string) $line['sku'] . '|' . ( isset( $line['quantity'] ) ? (int) $line['quantity'] : 1 );

			/*
			 * `null` and `0` are different answers and must not be conflated.
			 * `null` is "Tack does not price this SKU" -> keep the store price.
			 * `0` is a real resolved price -> honour it. `isset()`/`empty()`
			 * would collapse both into "no answer".
			 */
			$unit = array_key_exists( 'unitPrice', $line ) ? $line['unitPrice'] : null;
			$out[ $key ] = ( null === $unit || '' === $unit ) ? null : (float) $unit;
			$this->resolved[ $key ] = $out[ $key ];
		}

		return $out;
	}

	/**
	 * Quantity-break table for the product page.
	 *
	 * Renders nothing at all when there is no ladder to show. An empty table
	 * under a "Volume pricing" heading tells a trade customer their discounts
	 * were removed.
	 */
	public function render_quantity_breaks() {
		if ( ! $this->should_apply() ) {
			return;
		}

		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return;
		}
		$sku = (string) $product->get_sku();
		if ( '' === $sku ) {
			return;
		}

		/**
		 * Quantities to price for the break table.
		 *
		 * A ladder is only visible by asking for each rung: the resolve endpoint
		 * answers "what does THIS quantity cost", so the tiers are discovered by
		 * pricing a few representative quantities and keeping the ones that
		 * differ.
		 *
		 * @param int[]  $quantities Quantities to probe.
		 * @param string $sku        Product SKU.
		 */
		$quantities = apply_filters( 'tackquote_quantity_break_steps', array( 1, 10, 25, 50, 100 ), $sku );

		$items = array();
		foreach ( $quantities as $qty ) {
			$items[] = array( 'sku' => $sku, 'quantity' => (int) $qty );
		}
		$prices = $this->resolve( $items );

		$rows = array();
		$previous = null;
		foreach ( $quantities as $qty ) {
			$unit = isset( $prices[ $sku . '|' . (int) $qty ] ) ? $prices[ $sku . '|' . (int) $qty ] : null;
			if ( null === $unit ) {
				continue;
			}
			// Only rungs that actually change the price are worth a row.
			if ( null !== $previous && abs( $unit - $previous ) < 0.00001 ) {
				continue;
			}
			$rows[]   = array( 'qty' => (int) $qty, 'unit' => $unit );
			$previous = $unit;
		}

		if ( count( $rows ) < 2 ) {
			// One row is just the unit price, which is already on the page.
			return;
		}

		echo '<table class="tackquote-quantity-breaks"><caption>'
			. esc_html__( 'Volume pricing', 'tackquote' )
			. '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Quantity', 'tackquote' )
			. '</th><th scope="col">'
			. esc_html__( 'Unit price', 'tackquote' )
			. '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>'
				/* translators: %d: minimum quantity for this price tier. */
				. esc_html( sprintf( __( '%d+', 'tackquote' ), $row['qty'] ) )
				. '</td><td>'
				// wc_price() returns markup that is already escaped by WooCommerce.
				. wp_kses_post( wc_price( $row['unit'] ) )
				. '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Should this request be priced at all?
	 *
	 * @return bool
	 */
	private function should_apply() {
		if ( ! self::is_enabled() ) {
			return false;
		}
		// Admin screens and REST/cron must keep the store's own prices: an order
		// edited in wp-admin should show what was charged, not what Tack would
		// charge the shop manager who happens to be logged in.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( '' === $this->buyer_email() ) {
			return false;
		}
		return '' !== (string) get_option( 'tack_quotes_api_key', '' );
	}

	/**
	 * Email of the signed-in customer, or '' when nobody is signed in.
	 *
	 * @return string
	 */
	private function buyer_email() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user = wp_get_current_user();
		return ( $user && isset( $user->user_email ) ) ? (string) $user->user_email : '';
	}

	/**
	 * Write to WooCommerce's log under the plugin's own source.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'tackquote' ) );
		}
	}
}
