<?php
/**
 * Plugin Name:       TackQuote for WooCommerce
 * Plugin URI:        https://tackquote.com/integrations/woocommerce
 * Description:       Add a "Request a Quote" button to your WooCommerce store and sync orders with your TackQuote B2B quoting account.
 * Version:           1.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            TackQuote
 * Author URI:        https://tackquote.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tackquote
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   11.0
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'TACK_QUOTES_VERSION', '1.5.1' );
define( 'TACK_QUOTES_FILE', __FILE__ );
define( 'TACK_QUOTES_DIR', plugin_dir_path( __FILE__ ) );
define( 'TACK_QUOTES_URL', plugin_dir_url( __FILE__ ) );

require_once TACK_QUOTES_DIR . 'includes/class-tack-quotes.php';

/**
 * Boot the plugin on plugins_loaded so WooCommerce is available.
 */
function tack_quotes_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'tack_quotes_missing_wc_notice' );
		return;
	}
	Tack_Quotes::instance()->init();
}
add_action( 'plugins_loaded', 'tack_quotes_bootstrap' );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function tack_quotes_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'TackQuote requires WooCommerce to be installed and active.', 'tackquote' );
	echo '</p></div>';
}

// Activation / deactivation must be registered at top level.
register_activation_hook( __FILE__, array( 'Tack_Quotes', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Tack_Quotes', 'deactivate' ) );

/**
 * Declare WooCommerce feature compatibility.
 *
 * Two declarations, both of which WooCommerce surfaces to merchants:
 *
 * - `custom_order_tables` (HPOS). This plugin reads and writes orders only through the order
 *   CRUD (`wc_get_order()`, `$order->update_meta_data()`, the order status hooks) and never
 *   queries `wp_posts`/`wp_postmeta` for orders, so it is compatible.
 *
 * - `cart_checkout_blocks`. The plugin hooks the Store API checkout path
 *   (`woocommerce_store_api_checkout_order_processed`) and overrides no cart or checkout
 *   template, so the block cart and block checkout work unmodified. Declaring this matters
 *   because without a declaration WooCommerce warns merchants that an active extension may
 *   be incompatible with the blocks — a warning this plugin does not deserve.
 *
 * Both are inert without the `WC tested up to` header above: WooCommerce "will only display
 * this information for extensions that declare `WC tested up to` in the header of the main
 * plugin file" (WooCommerce's HPOS recipe book), so the header is a prerequisite rather than
 * a nicety, and the declaration below produced no merchant-facing signal at all until it was
 * added.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
);
