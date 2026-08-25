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
require_once TACK_QUOTES_DIR . 'includes/class-tack-catalog-mode.php';

/**
 * Main plugin class (singleton).
 */
final class Tack_Quotes {

	/**
	 * The single instance of this class.
	 *
	 * @var Tack_Quotes|null
	 */
	private static $instance = null;

	/**
	 * The settings screen handler.
	 *
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

		// Quote-only (B2B catalog) mode. Registered unconditionally; the class
		// re-checks whether it applies per request, because the current user is
		// not resolved this early and a page cache would freeze a wrong answer.
		( new Tack_Catalog_Mode() )->init();

		// Frontend "Request a Quote" widget/button.
		if ( 'yes' === get_option( 'tack_quotes_enable_widget', 'yes' ) ) {
			( new Tack_Widget() )->init();
		}

		// Order sync to the Tack API. The deferred worker is registered unconditionally — see
		// Tack_Order_Sync::register_worker() — while the order hooks that queue work are only
		// attached when the merchant has switched sync on.
		$order_sync = new Tack_Order_Sync();
		$order_sync->register_worker();
		if ( Tack_Order_Sync::is_enabled() ) {
			$order_sync->init();
		}

		// Settings action link on the plugins list.
		add_filter( 'plugin_action_links_' . plugin_basename( TACK_QUOTES_FILE ), array( $this, 'action_links' ) );

		// Suggested privacy policy text. `wp_add_privacy_policy_content()` must be called on
		// admin_init or later — it errors out otherwise — which is why this is a separate
		// hook rather than an inline call here on plugins_loaded.
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		// There is deliberately NO manual textdomain load here — see
		// https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/
		// WordPress.org serves translations for hosted plugins automatically from the
		// plugin slug as of WP 4.6, Plugin Check flags the manual call as a discouraged
		// function, and this plugin ships no /languages directory — so the call was
		// loading nothing anyway.
	}

	/**
	 * Register suggested privacy policy text on the Privacy Settings screen.
	 *
	 * This plugin sends personal data to a third party, so a merchant needs to be able to
	 * disclose exactly what and to where. Enumerating the fields rather than gesturing at
	 * "order data" is the point: a policy that says less than the plugin sends is not a
	 * policy.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$api_url = (string) get_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );

		$content =
			'<p class="privacy-policy-tutorial">'
			. esc_html__( 'Suggested text for stores using TackQuote for WooCommerce. Edit it to match how your store actually uses the plugin.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Quote requests', 'tackquote' ) . '</strong><br />'
			. esc_html__( 'When you request a quote, we send the details you enter in the quote form to our quoting provider, TackQuote: your email address, first and last name, phone number, and — if you are buying on behalf of a company — your company name and any company details the form asks for, together with any note you write. We also send the products, quantities, prices and currency you are asking to be quoted.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Order sync (only if the store owner has switched it on)', 'tackquote' ) . '</strong><br />'
			. esc_html__( 'When an order is placed or its status changes, we send that order to TackQuote. This includes your full billing address and your full shipping address — name, company, street, city, state or county, postal code, country, email address and phone number — along with any note you left with the order. It also includes the order number and internal order ID, its status, currency, item subtotal, discount, shipping cost, tax and total, any coupon codes used, the payment method and the payment reference our gateway issued, and the created, modified, paid and completed dates. Each line includes the product name, SKU, product and variation IDs, quantity, price, tax and the options chosen (for example size or colour). No card number or card details are ever sent.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Where it goes', 'tackquote' ) . '</strong><br />'
			. sprintf(
				/* translators: %s: the configured TackQuote API base URL. */
				esc_html__( 'Data is sent over HTTPS to the TackQuote API at %s. No payment card data is sent. Nothing is shared with any other third party by this plugin.', 'tackquote' ),
				'<code>' . esc_html( $api_url ) . '</code>'
			)
			. '</p>';

		wp_add_privacy_policy_content( __( 'TackQuote for WooCommerce', 'tackquote' ), $content );
	}

	/**
	 * Add a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		// Derive the slug from the constant the menu is actually registered with.
		// This literal was correct when PAGE_SLUG was 'tack-quotes'; the later
		// renames (to 'tackquote-for-woocommerce', then to the WordPress.org slug
		// 'tackquote') moved the constant and left the literal behind. The link then
		// pointed at an unregistered page, and wp-admin/admin.php answers that with
		// "Sorry, you are not allowed to access this page." — the same sentence it
		// uses for a capability failure, which sends the diagnosis the wrong way.
		$url  = admin_url( 'admin.php?page=' . rawurlencode( Tack_Settings::PAGE_SLUG ) );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tackquote' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Activation: set sane defaults. Does not overwrite existing values.
	 */
	public static function activate() {
		add_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );
		add_option( 'tack_quotes_enable_widget', 'yes' );
		// Order sync ships OFF. It sends personal data to a third party, so it is the
		// merchant's decision to make, not a default to be discovered later.
		add_option( 'tack_quotes_enable_order_sync', 'no' );
		add_option( 'tack_quotes_button_label', __( 'Add to Quote', 'tackquote' ) );
		add_option( 'tack_quotes_request_button_label', __( 'Request a Quote', 'tackquote' ) );
		add_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tackquote' ) );
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
