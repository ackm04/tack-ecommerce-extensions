<?php
/**
 * Quote-only (B2B catalog) mode.
 *
 * Turns the whole storefront into a request-a-quote catalogue: WooCommerce's
 * "Add to cart" action is withdrawn and the TackQuote buttons become the only
 * way to transact. This is what a seller turns on to run their entire store
 * as B2B rather than mixing retail checkout with quoting.
 *
 * ── Why this is not just "hide the button" ───────────────────────────────────
 *
 * Hiding a button with CSS, or removing only the template, leaves the store
 * fully purchasable to anyone who sends the request by hand:
 * `?add-to-cart=123`, the Store API, a cached page, a stale block. The
 * enforcement here is therefore the `woocommerce_is_purchasable` filter, which
 * `WC_Cart::add_to_cart()` checks before accepting any line
 * (woocommerce/includes/class-wc-cart.php:1331, WooCommerce 11.0.1). Removing
 * the templates is presentation on top of that, not the control itself.
 *
 * ── The pre-existing-cart hole ───────────────────────────────────────────────
 *
 * `WC_Cart::check_cart_item_validity()` only checks that a product still
 * exists and is not trashed — it does NOT re-check `is_purchasable()`
 * (class-wc-cart.php:826-839). So a customer who filled a cart BEFORE the
 * store was switched to quote-only could still walk that cart through
 * checkout, and the store would not actually be quote-only. `check_cart()`
 * below closes that: it is hooked to `woocommerce_check_cart_items`, which
 * runs on both the cart and checkout pages.
 *
 * ── Who is exempt ────────────────────────────────────────────────────────────
 *
 * Anyone who can `manage_woocommerce` is exempt, so the seller can still see
 * and test their own store while it is closed to customers.
 *
 * @package TackQuote
 */

defined( 'ABSPATH' ) || exit;

class Tack_Catalog_Mode {

	const OPT_MODE       = 'tack_quotes_store_mode';
	const OPT_SCOPE      = 'tack_quotes_quote_only_scope';
	const OPT_ROLES      = 'tack_quotes_quote_only_roles';
	const OPT_HIDE_PRICE = 'tack_quotes_hide_prices';
	const OPT_PRICE_TEXT = 'tack_quotes_hidden_price_text';

	const MODE_CART       = 'cart';
	const MODE_QUOTE_ONLY = 'quote_only';

	const SCOPE_EVERYONE = 'everyone';
	const SCOPE_GUESTS   = 'guests';
	const SCOPE_ROLES    = 'roles';

	/**
	 * Register hooks.
	 *
	 * Every callback re-checks `is_active()` at call time rather than the hooks
	 * being registered conditionally. `is_active()` depends on the current user,
	 * and the current user is not reliably resolved this early (`plugins_loaded`)
	 * — deciding once at registration would evaluate the wrong user, and would be
	 * wrong again for any request served from a page cache.
	 */
	public function init() {
		// The control itself.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 99, 2 );

		// Close the pre-existing-cart hole on both cart and checkout.
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );

		// Presentation: withdraw the add-to-cart templates.
		add_action( 'wp', array( $this, 'remove_add_to_cart_templates' ) );

		// Optional "price on request".
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 99, 2 );
	}

	/**
	 * Is quote-only mode in force for THIS request and THIS visitor?
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( self::MODE_QUOTE_ONLY !== get_option( self::OPT_MODE, self::MODE_CART ) ) {
			return false;
		}

		// The seller keeps a working store to test with.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$scope = (string) get_option( self::OPT_SCOPE, self::SCOPE_EVERYONE );

		if ( self::SCOPE_GUESTS === $scope ) {
			// Quote-only for logged-out visitors; approved B2B customers keep the cart.
			return ! is_user_logged_in();
		}

		if ( self::SCOPE_ROLES === $scope ) {
			$selected = (array) get_option( self::OPT_ROLES, array() );
			if ( ! is_user_logged_in() ) {
				return in_array( 'guest', $selected, true );
			}
			$user = wp_get_current_user();
			return (bool) array_intersect( $selected, (array) $user->roles );
		}

		return true;
	}

	/**
	 * THE control. Refuses the line at the data layer, so a hand-crafted
	 * `?add-to-cart=` request, the Store API and any cached markup are all
	 * refused the same way the button is.
	 *
	 * @param bool       $purchasable Current value.
	 * @param WC_Product $product     Product being tested.
	 * @return bool
	 */
	public function filter_is_purchasable( $purchasable, $product = null ) {
		return $this->is_active() ? false : $purchasable;
	}

	/**
	 * Refuse a cart that was filled before the store was switched to quote-only.
	 *
	 * Runs on `woocommerce_check_cart_items`, which fires on both the cart and
	 * the checkout page, so this cannot be walked past by going straight to
	 * checkout.
	 */
	public function check_cart() {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$removed = 0;
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( $product instanceof WC_Product && ! $product->is_purchasable() ) {
				WC()->cart->remove_cart_item( $key );
				$removed++;
			}
		}

		if ( $removed > 0 ) {
			wc_add_notice(
				esc_html__( 'This store is currently quote-only, so those items were removed from your cart. Request a quote instead and we will get back to you.', 'tackquote' ),
				'notice'
			);
		}
	}

	/**
	 * Withdraw WooCommerce's add-to-cart templates on the shop loop and the
	 * product page.
	 *
	 * Hooked to `wp` rather than `init` because `is_active()` needs the resolved
	 * current user, and early enough that the storefront templates have not run.
	 *
	 * NOTE for anyone editing this: `woocommerce_after_add_to_cart_button` —
	 * where the TackQuote buttons normally render — fires INSIDE these very
	 * templates (see woocommerce/templates/single-product/add-to-cart/*.php).
	 * Removing the single-product template therefore also removes the quote
	 * button unless something re-hooks it, which would leave the store with no
	 * way to transact at all. `Tack_Widget` registers a fallback on
	 * `woocommerce_single_product_summary` for exactly this reason. Do not
	 * remove one without the other.
	 */
	public function remove_add_to_cart_templates() {
		if ( ! $this->is_active() ) {
			return;
		}

		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	/**
	 * Optional "price on request" — some B2B sellers do not publish list prices.
	 *
	 * @param string     $html    Rendered price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public function filter_price_html( $html, $product = null ) {
		if ( ! $this->is_active() || 'yes' !== get_option( self::OPT_HIDE_PRICE, 'no' ) ) {
			return $html;
		}

		$text = (string) get_option( self::OPT_PRICE_TEXT, '' );
		if ( '' === trim( $text ) ) {
			$text = __( 'Price on request', 'tackquote' );
		}

		return '<span class="tack-price-on-request">' . esc_html( $text ) . '</span>';
	}
}
