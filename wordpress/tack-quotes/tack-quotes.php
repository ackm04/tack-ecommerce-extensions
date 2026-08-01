<?php
/**
 * Plugin Name:       TackQuote for WooCommerce
 * Plugin URI:        https://github.com/tackquote/tack-woocommerce
 * Description:        Add a "Request a Quote" button to your WooCommerce store and sync orders with your TackQuote B2B quoting account.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            TackQuote
 * Author URI:        https://tackquote.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tack-quotes
 * WC requires at least: 6.0
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'TACK_QUOTES_VERSION', '1.3.0' );
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
	echo esc_html__( 'TackQuote requires WooCommerce to be installed and active.', 'tack-quotes' );
	echo '</p></div>';
}

// Activation / deactivation must be registered at top level.
register_activation_hook( __FILE__, array( 'Tack_Quotes', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Tack_Quotes', 'deactivate' ) );

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
