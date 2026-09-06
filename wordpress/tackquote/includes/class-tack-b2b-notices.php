<?php
/**
 * Order limits and the buyer-group badge, resolved by TackQuote.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THESE ARE
 * ─────────────────────────────────────────────────────────────────────────────
 * Two things a trade customer needs to see BEFORE checkout, and which a B2B
 * store is expected to have:
 *
 *   · ORDER LIMITS — "minimum order 25 units". TackQuote already holds these as
 *     `order_limit_rules` and enforces them when a quote is converted, but a
 *     WooCommerce shopper never saw them: they filled a cart, went to checkout,
 *     and the order was refused (or worse, accepted and then rejected by the
 *     seller). The rule existed and the storefront could not read it.
 *   · BUYER GROUP — "You're on Tier 2 pricing". Without it, a discounted price
 *     appears with no explanation, which reads as a pricing error rather than
 *     as the negotiated rate it is.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A LIMIT THAT ONLY WARNS IS NOT A LIMIT
 * ─────────────────────────────────────────────────────────────────────────────
 * The notice on the product page is a courtesy. The enforcement is on
 * `woocommerce_check_cart_items`, which runs on the cart AND on checkout, and
 * which is the only place that can actually stop the order. Rendering the
 * notice without that hook would produce a store that displays "minimum 25" and
 * cheerfully takes an order for 3.
 *
 * The reverse mistake is worse, so it is guarded explicitly: if TackQuote cannot
 * be reached, NOTHING is blocked. A checkout that fails closed on a network
 * timeout is a store that cannot take orders when its supplier's API is slow.
 * An unenforced minimum costs a conversation; an unavailable checkout costs the
 * day's revenue.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Renders and enforces TackQuote order limits, and shows the buyer group.
 */
class Tack_B2B_Notices {

	/** Show the minimum/maximum order notice and enforce it. */
	const OPTION_ORDER_LIMITS = 'tack_quotes_enable_order_limits';

	/** Show the buyer-group badge. */
	const OPTION_BUYER_GROUP = 'tack_quotes_enable_buyer_group';

	/**
	 * Interactive timeout, in seconds.
	 *
	 * Checkout must not hang on Tack being slow — see the fail-open note above.
	 */
	const TIMEOUT = 5;

	/** API client. @var Tack_Api_Client */
	private $client;

	/**
	 * Limits per SKU for this request. `false` means "asked, no answer".
	 *
	 * @var array<string, array|false>
	 */
	private $limits = array();

	/** Buyer group for this request, or false when not yet asked. @var array|false|null */
	private $group = false;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
	}

	/**
	 * Is either half switched on?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ORDER_LIMITS, 'no' )
			|| 'yes' === get_option( self::OPTION_BUYER_GROUP, 'no' );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		if ( 'yes' === get_option( self::OPTION_ORDER_LIMITS, 'no' ) ) {
			// The courtesy.
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_order_limit_notice' ), 24 );
			// The enforcement. Runs on the cart page AND on checkout.
			add_action( 'woocommerce_check_cart_items', array( $this, 'enforce_order_limits' ) );
		}

		if ( 'yes' === get_option( self::OPTION_BUYER_GROUP, 'no' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_buyer_group_badge' ), 4 );
		}
	}

	/**
	 * The limits for a SKU, or null when Tack has no answer.
	 *
	 * @param string $sku Product SKU.
	 * @return array|null array{min:int|null,max:int|null,accountSpecific:bool}
	 */
	public function limits_for( $sku ) {
		if ( array_key_exists( $sku, $this->limits ) ) {
			return false === $this->limits[ $sku ] ? null : $this->limits[ $sku ];
		}
		// Remembered before the call, so a failure is not retried on every line.
		$this->limits[ $sku ] = false;

		if ( ! $this->should_ask() ) {
			return null;
		}

		$query = 'sku=' . rawurlencode( $sku );
		$email = $this->buyer_email();
		if ( '' !== $email ) {
			$query .= '&buyerEmail=' . rawurlencode( $email );
		}

		$response = $this->client->request( 'GET', '/storefront-b2b/order-limits?' . $query, null, self::TIMEOUT );
		if ( is_wp_error( $response ) ) {
			$this->log( 'order-limits lookup failed: ' . $response->get_error_message() );
			return null;
		}

		// Anything that is not an explicit `limited` means there is nothing to
		// show and nothing to enforce. `none` and `unlinked` are both that.
		$status = isset( $response['status'] ) ? (string) $response['status'] : '';
		if ( 'limited' !== $status || empty( $response['limits'] ) || ! is_array( $response['limits'] ) ) {
			return null;
		}

		$first = $response['limits'][0];
		$out   = array(
			'min'             => isset( $first['minQuantity'] ) && null !== $first['minQuantity'] ? (int) $first['minQuantity'] : null,
			'max'             => isset( $first['maxQuantity'] ) && null !== $first['maxQuantity'] ? (int) $first['maxQuantity'] : null,
			'accountSpecific' => ! empty( $response['accountSpecific'] ),
		);

		// A rule carrying neither bound constrains nothing, and saying
		// "minimum 0" would be noise on every product page.
		if ( null === $out['min'] && null === $out['max'] ) {
			return null;
		}

		$this->limits[ $sku ] = $out;
		return $out;
	}

	/**
	 * The courtesy notice on the product page.
	 */
	public function render_order_limit_notice() {
		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return;
		}
		$sku = (string) $product->get_sku();
		if ( '' === $sku ) {
			return;
		}

		$limits = $this->limits_for( $sku );
		if ( null === $limits ) {
			return;
		}

		echo '<p class="tackquote-order-limit">' . esc_html( $this->limit_sentence( $limits ) ) . '</p>';
	}

	/**
	 * Stop a cart that breaks a limit, on the cart page and at checkout.
	 *
	 * Adds a WooCommerce error notice, which is what blocks the order — this is
	 * the hook WooCommerce itself uses for stock validation.
	 */
	public function enforce_order_limits() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$cart = WC()->cart;
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		/*
		 * Quantities are summed per SKU across cart lines before checking. The
		 * same product can appear as several lines (different variations, or a
		 * line added twice with different meta), and checking each line alone
		 * would refuse a cart of 10 + 20 against a minimum of 25 — which the
		 * buyer has in fact met.
		 */
		$totals = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! method_exists( $item['data'], 'get_sku' ) ) {
				continue;
			}
			$sku = (string) $item['data']->get_sku();
			if ( '' === $sku ) {
				continue;
			}
			$qty            = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
			$totals[ $sku ] = ( isset( $totals[ $sku ] ) ? $totals[ $sku ] : 0 ) + $qty;
		}

		foreach ( $totals as $sku => $qty ) {
			$limits = $this->limits_for( $sku );
			if ( null === $limits ) {
				// No answer: nothing is blocked. See the fail-open note above.
				continue;
			}

			$name = $this->product_name_for( $cart, $sku );

			if ( null !== $limits['min'] && $qty < $limits['min'] ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: minimum quantity, 3: quantity currently in the cart. */
						__( '%1$s has a minimum order quantity of %2$d. You have %3$d in your cart.', 'tackquote' ),
						$name,
						$limits['min'],
						$qty
					),
					'error'
				);
			}

			if ( null !== $limits['max'] && $qty > $limits['max'] ) {
				wc_add_notice(
					sprintf(
						/* translators: 1: product name, 2: maximum quantity, 3: quantity currently in the cart. */
						__( '%1$s has a maximum order quantity of %2$d. You have %3$d in your cart.', 'tackquote' ),
						$name,
						$limits['max'],
						$qty
					),
					'error'
				);
			}
		}
	}

	/**
	 * "You're on Tier 2 pricing", when the buyer belongs to a group.
	 */
	public function render_buyer_group_badge() {
		$group = $this->buyer_group();
		if ( null === $group ) {
			return;
		}

		echo '<p class="tackquote-buyer-group"><span class="tackquote-buyer-group-name">'
			. esc_html( $group['name'] )
			. '</span></p>';
	}

	/**
	 * The buyer's group, or null.
	 *
	 * @return array|null array{name:string,code:string}
	 */
	public function buyer_group() {
		if ( false !== $this->group ) {
			return $this->group;
		}
		$this->group = null;

		if ( ! $this->should_ask() ) {
			return null;
		}
		$email = $this->buyer_email();
		if ( '' === $email ) {
			// Anonymous: there is no group to belong to, and asking would leak
			// nothing useful anyway.
			return null;
		}

		$response = $this->client->request(
			'GET',
			'/storefront-b2b/buyer-group?buyerEmail=' . rawurlencode( $email ),
			null,
			self::TIMEOUT
		);
		if ( is_wp_error( $response ) ) {
			$this->log( 'buyer-group lookup failed: ' . $response->get_error_message() );
			return null;
		}

		$status = isset( $response['status'] ) ? (string) $response['status'] : '';
		if ( 'grouped' !== $status || empty( $response['name'] ) ) {
			return null;
		}

		$this->group = array(
			'name' => (string) $response['name'],
			'code' => isset( $response['code'] ) ? (string) $response['code'] : '',
		);
		return $this->group;
	}

	/**
	 * A sentence describing the limits, whichever bounds are present.
	 *
	 * @param array $limits Resolved limits.
	 * @return string
	 */
	private function limit_sentence( $limits ) {
		if ( null !== $limits['min'] && null !== $limits['max'] ) {
			return sprintf(
				/* translators: 1: minimum quantity, 2: maximum quantity. */
				__( 'Order between %1$d and %2$d units of this product.', 'tackquote' ),
				$limits['min'],
				$limits['max']
			);
		}
		if ( null !== $limits['min'] ) {
			return sprintf(
				/* translators: %d: minimum order quantity. */
				__( 'Minimum order quantity: %d units.', 'tackquote' ),
				$limits['min']
			);
		}
		return sprintf(
			/* translators: %d: maximum order quantity. */
			__( 'Maximum order quantity: %d units.', 'tackquote' ),
			$limits['max']
		);
	}

	/**
	 * A product name for the SKU in the cart, for the error message.
	 *
	 * @param object $cart WC_Cart.
	 * @param string $sku  SKU.
	 * @return string
	 */
	private function product_name_for( $cart, $sku ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! method_exists( $item['data'], 'get_sku' ) ) {
				continue;
			}
			if ( (string) $item['data']->get_sku() !== $sku ) {
				continue;
			}
			if ( method_exists( $item['data'], 'get_name' ) ) {
				return (string) $item['data']->get_name();
			}
		}
		// Falls back to the SKU rather than an empty string: "has a minimum
		// order quantity of 25" with no subject names nothing.
		return $sku;
	}

	/**
	 * Is there any point asking TackQuote?
	 *
	 * @return bool
	 */
	private function should_ask() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		return '' !== (string) get_option( 'tack_quotes_api_key', '' );
	}

	/**
	 * Email of the signed-in customer, or ''.
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
