<?php
/**
 * The smallest slice of WordPress the TackQuote admin surface actually touches,
 * so the plugin's menu registration and its Plugins-screen "Settings" link can be
 * exercised without a WordPress install, a database, or a web server.
 *
 * These are STUBS and are not a model of WordPress. The one behaviour they
 * reproduce faithfully is the one the bug lived in: `add_menu_page()` records a
 * page slug, and `admin.php` will only render a page whose slug was registered.
 * WordPress answers an UNREGISTERED slug with `wp_die( 'Sorry, you are not
 * allowed to access this page.' )` — the same sentence it uses for a capability
 * failure, which is why this defect reads like a permissions problem and is not
 * one. See `wp-admin/admin.php`, which checks `$_registered_pages`.
 */

$GLOBALS['TACK_REGISTERED_PAGES'] = array();

function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function esc_url( $url ) { return $url; }
function esc_attr( $t ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_html__( $text, $domain = null ) { return $text; }
function __( $text, $domain = null ) { return $text; }
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_REMOVED'] = array();
$GLOBALS['TACK_FILTERS'] = array();

function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['TACK_HOOKS'][] = array( 'hook' => $hook, 'priority' => $priority );
}
function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['TACK_HOOKS'][] = array( 'hook' => $hook, 'priority' => $priority );
	if ( null !== $callback ) {
		$GLOBALS['TACK_FILTERS'][ $hook ][] = $callback;
	}
}

/**
 * Dispatches whatever add_filter() registered, in registration order.
 *
 * Priority is recorded but not honoured: nothing under test registers two
 * callbacks on one filter, and a stub that pretended to order them would be
 * asserting its own invented rule rather than WordPress's.
 */
function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );
	foreach ( (array) ( $GLOBALS['TACK_FILTERS'][ $hook ] ?? array() ) as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}
	return $value;
}
function remove_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['TACK_REMOVED'][] = $hook . '|' . ( is_string( $callback ) ? $callback : 'closure' ) . '|' . $priority;
	return true;
}

/**
 * Current-visitor state the tests drive directly. Real WordPress resolves these
 * from the session; here they are plain globals so a test can say "now this is
 * a signed-out visitor" without a database.
 */
$GLOBALS['TACK_LOGGED_IN'] = false;
$GLOBALS['TACK_CAPS']      = array();
$GLOBALS['TACK_ROLES']     = array();
$GLOBALS['TACK_USER_EMAIL'] = '';

function is_user_logged_in() { return (bool) $GLOBALS['TACK_LOGGED_IN']; }
function wp_get_current_user() {
	$u             = new stdClass();
	$u->roles      = (array) $GLOBALS['TACK_ROLES'];
	// Added for the wholesale-pricing tests: the buyer email is what selects the
	// price book, so a stub without it would let a broken lookup pass.
	$u->user_email = (string) $GLOBALS['TACK_USER_EMAIL'];
	$u->ID         = 1;
	return $u;
}
function checked( $a, $b = true, $echo = true ) { return (string) $a === (string) $b ? "checked='checked'" : ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $t ) { return trim( (string) $t ); }
function esc_attr__( $t, $d = null ) { return $t; }
function wc_add_notice( $msg, $type = 'success' ) { $GLOBALS['TACK_NOTICES'][] = $msg; }

class TackStubRoles {
	public function get_names() { return array( 'administrator' => 'Administrator', 'customer' => 'Customer', 'wholesale' => 'Wholesale' ); }
}
function wp_roles() { return new TackStubRoles(); }
function register_setting() {}
function add_settings_section() {}
function add_settings_field() {}
function plugin_basename( $file ) { return 'tackquote/tackquote.php'; }
$GLOBALS['TACK_OPTIONS'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, (array) $GLOBALS['TACK_OPTIONS'] ) ? $GLOBALS['TACK_OPTIONS'][ $key ] : $default;
}
function update_option( $key, $value ) { $GLOBALS['TACK_OPTIONS'][ $key ] = $value; return true; }
function current_user_can( $cap ) { return in_array( $cap, (array) $GLOBALS['TACK_CAPS'], true ); }

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = null, $icon = null, $position = null ) {
	$GLOBALS['TACK_REGISTERED_PAGES'][ $menu_slug ] = $capability;
	return 'toplevel_page_' . $menu_slug;
}

function add_submenu_page( $parent, $page_title, $menu_title, $capability, $menu_slug, $callback = null ) {
	$GLOBALS['TACK_REGISTERED_PAGES'][ $menu_slug ] = $capability;
	return $parent . '_page_' . $menu_slug;
}

/**
 * `home_url()` scopes the idempotency key to one site. A fixed value is enough
 * here; the key is asserted for STABILITY, not for its contents.
 */
function home_url( $path = '' ) { return 'https://shop.example' . $path; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function wp_strip_all_tags( $text, $remove_breaks = false ) { return trim( strip_tags( (string) $text ) ); }


// ── Stubs added for Tack_Wholesale_Pricing ───────────────────────────────────
//
// Guarded with function_exists/class_exists so this file stays safe to include
// alongside any other harness, and so a real WordPress bootstrap would win.

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal stand-in for WordPress's error object. */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/** @return string */
		public function get_error_message() {
			return $this->message;
		}

		/** @return string */
		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/** @return bool */
	function is_admin() {
		return (bool) $GLOBALS['TACK_IS_ADMIN'];
	}
}
$GLOBALS['TACK_IS_ADMIN'] = false;

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/** @return bool */
	function wp_doing_ajax() {
		return false;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * @param string $html Markup.
	 * @return string
	 */
	function wp_kses_post( $html ) {
		return $html;
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * @param float $amount Amount.
	 * @return string
	 */
	function wc_price( $amount ) {
		return '<span class="amount">$' . number_format( (float) $amount, 2 ) . '</span>';
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	/** @return null Logging is a no-op under the harness. */
	function wc_get_logger() {
		return null;
	}
}

/**
 * Set an option from a test.
 *
 * @param string $key   Option name.
 * @param mixed  $value Value.
 */
function tack_test_set_option( $key, $value ) {
	$GLOBALS['TACK_OPTIONS'][ $key ] = $value;
}

/**
 * Set the signed-in state from a test.
 *
 * @param bool   $logged_in Whether a user is signed in.
 * @param string $email     That user's email.
 */
function tack_test_set_logged_in( $logged_in, $email ) {
	$GLOBALS['TACK_LOGGED_IN']  = (bool) $logged_in;
	$GLOBALS['TACK_USER_EMAIL'] = (string) $email;
}


// ── Tax stubs, for the net -> store-basis conversion ────────────────────────
$GLOBALS['TACK_PRICES_INCLUDE_TAX'] = false;
$GLOBALS['TACK_TAX_RATE']           = 0.20;

if ( ! function_exists( 'wc_prices_include_tax' ) ) {
	/** @return bool */
	function wc_prices_include_tax() {
		return (bool) $GLOBALS['TACK_PRICES_INCLUDE_TAX'];
	}
}

if ( ! function_exists( 'wc_get_price_including_tax' ) ) {
	/**
	 * @param object $product Product.
	 * @param array  $args    qty/price.
	 * @return float
	 */
	function wc_get_price_including_tax( $product, $args = array() ) {
		$price = isset( $args['price'] ) ? (float) $args['price'] : 0.0;
		unset( $product );
		return round( $price * ( 1 + (float) $GLOBALS['TACK_TAX_RATE'] ), 2 );
	}
}

/**
 * Switch the store's tax basis from a test.
 *
 * @param bool $include Whether entered prices include tax.
 */
function tack_test_set_prices_include_tax( $include ) {
	$GLOBALS['TACK_PRICES_INCLUDE_TAX'] = (bool) $include;
}


// ── Cart + notice stubs, for Tack_B2B_Notices ───────────────────────────────
$GLOBALS['TACK_CART_LINES'] = array();

if ( ! class_exists( 'Tack_Stub_WC' ) ) {
	/** Stands in for the WC() singleton's cart. */
	class Tack_Stub_WC {
		/** @var object|null */
		public $cart;
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * @return Tack_Stub_WC
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		$wc       = new Tack_Stub_WC();
		$wc->cart = new Tack_Stub_Cart( $GLOBALS['TACK_CART_LINES'] );
		return $wc;
	}
}

if ( ! class_exists( 'Tack_Stub_Cart' ) ) {
	/** A cart of stub lines. */
	class Tack_Stub_Cart {
		/** @var array */
		private $lines;

		/**
		 * @param array $lines Lines.
		 */
		public function __construct( $lines ) {
			$this->lines = $lines;
		}

		/** @return array */
		public function get_cart() {
			return $this->lines;
		}
	}
}

if ( ! class_exists( 'Tack_Stub_Cart_Product' ) ) {
	/** A product on a stub cart line. */
	class Tack_Stub_Cart_Product {
		/** @var string */
		private $sku;
		/** @var string */
		private $name;

		/**
		 * @param string $sku  SKU.
		 * @param string $name Name.
		 */
		public function __construct( $sku, $name ) {
			$this->sku  = $sku;
			$this->name = $name;
		}

		/** @return string */
		public function get_sku() {
			return $this->sku;
		}

		/** @return string */
		public function get_name() {
			return $this->name;
		}
	}
}

/**
 * Replace the cart contents from a test.
 *
 * @param array $lines Array of array{sku:string,qty:int,name:string}.
 */
function tack_test_set_cart( $lines ) {
	$out = array();
	foreach ( $lines as $i => $line ) {
		$out[ 'line' . $i ] = array(
			'data'     => new Tack_Stub_Cart_Product( $line['sku'], isset( $line['name'] ) ? $line['name'] : $line['sku'] ),
			'quantity' => (int) $line['qty'],
		);
	}
	$GLOBALS['TACK_CART_LINES'] = $out;
}

/** Clear collected notices. */
function tack_test_reset_notices() {
	$GLOBALS['TACK_NOTICES'] = array();
}

/**
 * Notices collected since the last reset.
 *
 * @return array
 */
function tack_test_notices() {
	return (array) $GLOBALS['TACK_NOTICES'];
}


// ── Filter-return helpers, for testing `apply_filters` defaults ─────────────

if ( ! function_exists( 'esc_textarea' ) ) {
	/**
	 * @param string $t Text.
	 * @return string
	 */
	function esc_textarea( $t ) {
		return (string) $t;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $t Text.
	 * @return string
	 */
	function wp_strip_all_tags( $t ) {
		return strip_tags( (string) $t );
	}
}

/**
 * Force a filter to return a fixed value.
 *
 * @param string $hook  Filter name.
 * @param mixed  $value Value to return.
 */
function tack_test_add_filter_return( $hook, $value ) {
	$GLOBALS['TACK_FILTERS'][ $hook ][] = function () use ( $value ) {
		return $value;
	};
}

/** Remove every forced filter return. */
function tack_test_clear_filter_returns() {
	$GLOBALS['TACK_FILTERS'] = array();
}


// ── User-meta stubs, for the email-trust guard ──────────────────────────────
$GLOBALS['TACK_USER_META'] = array();

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		unset( $single );
		return $GLOBALS['TACK_USER_META'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['TACK_USER_META'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key ) {
		unset( $GLOBALS['TACK_USER_META'][ $user_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * @param int $user_id User id.
	 * @return object|false
	 */
	function get_userdata( $user_id ) {
		if ( (int) $user_id !== 1 ) {
			return false;
		}
		$u             = new stdClass();
		$u->ID         = 1;
		$u->user_email = (string) $GLOBALS['TACK_USER_EMAIL'];
		return $u;
	}
}

/** Clear all stubbed user meta. */
function tack_test_reset_user_meta() {
	$GLOBALS['TACK_USER_META'] = array();
}
