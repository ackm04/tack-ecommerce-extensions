<?php
/**
 * Quote-only (B2B catalog) mode.
 *
 * The point of these tests is that quote-only mode is a real control, not a
 * hidden button: the enforcement is asserted at the data layer, the pre-existing
 * cart hole is asserted closed, and the storefront is asserted to still have a
 * way to transact once the cart button is gone.
 *
 * Included by tests/run.php. Uses check() from there.
 */

defined( 'ABSPATH' ) || exit;

/** Put the visitor back to a signed-out customer with no capabilities. */
function tack_reset_visitor() {
	$GLOBALS['TACK_LOGGED_IN'] = false;
	$GLOBALS['TACK_CAPS']      = array();
	$GLOBALS['TACK_ROLES']     = array();
	$GLOBALS['TACK_OPTIONS']   = array();
	$GLOBALS['TACK_REMOVED']   = array();
}

$mode = new Tack_Catalog_Mode();

// ── Default: a normal shop. Nothing may change until the seller opts in. ─────
tack_reset_visitor();
check( 'default store mode leaves the shop purchasable', ! $mode->is_active() );
check( 'default store mode does not touch is_purchasable', true === $mode->filter_is_purchasable( true, null ) );

// ── Quote-only, everyone ────────────────────────────────────────────────────
tack_reset_visitor();
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ] = Tack_Catalog_Mode::MODE_QUOTE_ONLY;

check( 'quote-only applies to a signed-out customer', $mode->is_active() );
check(
	'THE CONTROL: products become non-purchasable, so a hand-crafted ?add-to-cart= is refused too',
	false === $mode->filter_is_purchasable( true, null )
);

// ── The seller is not locked out of their own store ─────────────────────────
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
check( 'a shop manager still gets a working cart', ! $mode->is_active() );
check( 'and their products stay purchasable', true === $mode->filter_is_purchasable( true, null ) );
$GLOBALS['TACK_CAPS'] = array();

// ── Scope: signed-out visitors only ─────────────────────────────────────────
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_SCOPE ] = Tack_Catalog_Mode::SCOPE_GUESTS;
$GLOBALS['TACK_LOGGED_IN'] = false;
check( 'guests-only scope: the public sees a catalogue', $mode->is_active() );
$GLOBALS['TACK_LOGGED_IN'] = true;
check( 'guests-only scope: an approved customer keeps a real cart', ! $mode->is_active() );

// ── Scope: chosen roles ─────────────────────────────────────────────────────
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_SCOPE ] = Tack_Catalog_Mode::SCOPE_ROLES;
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_ROLES ] = array( 'wholesale' );
$GLOBALS['TACK_LOGGED_IN'] = true;
$GLOBALS['TACK_ROLES']     = array( 'customer' );
check( 'role scope: a role that was not chosen keeps the cart', ! $mode->is_active() );
$GLOBALS['TACK_ROLES'] = array( 'wholesale' );
check( 'role scope: a chosen role gets quote-only', $mode->is_active() );

// ── The storefront must still have a way to transact ────────────────────────
tack_reset_visitor();
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ] = Tack_Catalog_Mode::MODE_QUOTE_ONLY;
$mode->remove_add_to_cart_templates();
$removed = $GLOBALS['TACK_REMOVED'];

check(
	'quote-only withdraws the shop-loop add-to-cart template',
	in_array( 'woocommerce_after_shop_loop_item|woocommerce_template_loop_add_to_cart|10', $removed, true )
);
check(
	'quote-only withdraws the product-page add-to-cart template',
	in_array( 'woocommerce_single_product_summary|woocommerce_template_single_add_to_cart|30', $removed, true )
);

/*
 * THE ONE THAT MATTERS.
 *
 * `woocommerce_after_add_to_cart_button` fires INSIDE the add-to-cart templates
 * that were just withdrawn. If the quote button is only mounted there, then
 * turning on quote-only mode removes the cart button AND the quote button, and
 * the store is left with no way to transact at all — a silent, total outage
 * that still renders a normal-looking product page.
 */
$GLOBALS['TACK_HOOKS'] = array();
( new Tack_Widget() )->init();
$hooks = $GLOBALS['TACK_HOOKS'];

$mounts = array();
foreach ( $hooks as $h ) {
	if ( 'woocommerce_after_add_to_cart_button' === $h['hook'] || 'woocommerce_single_product_summary' === $h['hook'] ) {
		$mounts[] = $h['hook'];
	}
}

check(
	'the quote button has a mount point OUTSIDE the add-to-cart form, so a quote-only store can still transact',
	in_array( 'woocommerce_single_product_summary', $mounts, true ),
	'mount points found: ' . ( $mounts ? implode( ', ', $mounts ) : '(none)' )
);
check( 'and it keeps its normal-mode mount point too', in_array( 'woocommerce_after_add_to_cart_button', $mounts, true ) );

// ── Fail safe: a malformed POST must never close a store's checkout ─────────
$settings = new Tack_Settings();
check( 'garbage store mode falls back to a working shop', Tack_Catalog_Mode::MODE_CART === $settings->sanitize_store_mode( 'wat' ) );
check( 'array injection falls back to a working shop', Tack_Catalog_Mode::MODE_CART === $settings->sanitize_store_mode( array( 'quote_only' ) ) );
check( 'the real value still stores', Tack_Catalog_Mode::MODE_QUOTE_ONLY === $settings->sanitize_store_mode( 'quote_only' ) );
check( 'unknown role slugs are dropped', array( 'wholesale' ) === $settings->sanitize_roles( array( 'wholesale', 'not_a_role' ) ) );

// ── Price on request ────────────────────────────────────────────────────────
tack_reset_visitor();
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ]       = Tack_Catalog_Mode::MODE_QUOTE_ONLY;
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_HIDE_PRICE ] = 'yes';
check( 'prices can be replaced with "price on request"', false !== strpos( $mode->filter_price_html( '<span>£10</span>', null ), 'Price on request' ) );
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_HIDE_PRICE ] = 'no';
check( 'and are left alone when that is off', '<span>£10</span>' === $mode->filter_price_html( '<span>£10</span>', null ) );

tack_reset_visitor();
