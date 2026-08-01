<?php
/**
 * Core loader — wires the plugin's components onto WordPress/WooCommerce hooks.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once TACK_QUOTES_DIR . 'includes/class-tack-settings.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-widget.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-order-sync.php';

/**
 * Main plugin class (singleton).
 */
final class Tack_Quotes {

	/**
	 * @var Tack_Quotes|null
	 */
	private static $instance = null;

	/**
	 * @var Tack_Settings
	 */
	public $settings;

	/**
	 * Singleton accessor.
	 *
	 * @return Tack_Quotes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks. Called once on plugins_loaded.
	 */
	public function init() {
		$this->settings = new Tack_Settings();
		$this->settings->init();

		// Frontend "Request a Quote" widget/button.
		if ( 'yes' === get_option( 'tack_quotes_enable_widget', 'yes' ) ) {
			( new Tack_Widget() )->init();
		}

		// Order sync to the Tack API.
		if ( 'yes' === get_option( 'tack_quotes_enable_order_sync', 'yes' ) ) {
			( new Tack_Order_Sync() )->init();
		}

		// Settings action link on the plugins list.
		add_filter( 'plugin_action_links_' . plugin_basename( TACK_QUOTES_FILE ), array( $this, 'action_links' ) );

		load_plugin_textdomain( 'tack-quotes', false, dirname( plugin_basename( TACK_QUOTES_FILE ) ) . '/languages' );
	}

	/**
	 * Add a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'admin.php?page=tack-quotes' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tack-quotes' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Activation: set sane defaults. Does not overwrite existing values.
	 */
	public static function activate() {
		add_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );
		add_option( 'tack_quotes_enable_widget', 'yes' );
		add_option( 'tack_quotes_enable_order_sync', 'yes' );
		add_option( 'tack_quotes_button_label', __( 'Add to Quote', 'tack-quotes' ) );
		add_option( 'tack_quotes_request_button_label', __( 'Request a Quote', 'tack-quotes' ) );
		add_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tack-quotes' ) );
		add_option( 'tack_quotes_show_add_to_quote', 'yes' );
		add_option( 'tack_quotes_show_request_quote', 'yes' );
		add_option( 'tack_quotes_schema_version', TACK_QUOTES_VERSION );
	}

	/**
	 * Deactivation: clear scheduled events (none yet) — kept for future cron.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tack_quotes_retry_sync' );
	}
}
