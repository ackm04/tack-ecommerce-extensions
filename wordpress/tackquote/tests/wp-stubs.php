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

function is_user_logged_in() { return (bool) $GLOBALS['TACK_LOGGED_IN']; }
function wp_get_current_user() {
	$u        = new stdClass();
	$u->roles = (array) $GLOBALS['TACK_ROLES'];
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
