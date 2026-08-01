<?php
/**
 * Settings page (WordPress Settings API) — TackQuote API key, API URL, and feature toggles.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin settings screen and options.
 */
class Tack_Settings {

	const OPTION_GROUP = 'tack_quotes_settings';
	const PAGE_SLUG    = 'tack-quotes';

	/**
	 * Hook registration.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the top-level admin menu page.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'TackQuote', 'tack-quotes' ),
			__( 'TackQuote', 'tack-quotes' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-money-alt',
			56
		);
	}

	/**
	 * Register settings + fields with sanitization callbacks.
	 */
	public function register_settings() {
		register_setting( self::OPTION_GROUP, 'tack_quotes_api_key', array( 'sanitize_callback' => array( $this, 'sanitize_api_key' ) ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_api_url', array( 'sanitize_callback' => array( $this, 'sanitize_url' ) ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_button_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_request_button_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_checkout_button_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_show_add_to_quote', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_show_request_quote', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_enable_widget', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, 'tack_quotes_enable_order_sync', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );

		add_settings_section(
			'tack_quotes_connection',
			__( 'Connection', 'tack-quotes' ),
			array( $this, 'section_connection' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_storefront',
			__( 'Request a Quote button', 'tack-quotes' ),
			array( $this, 'section_storefront' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_sync',
			__( 'Order sync', 'tack-quotes' ),
			array( $this, 'section_sync' ),
			self::PAGE_SLUG
		);

		add_settings_field( 'tack_quotes_api_key', __( 'TackQuote API Key', 'tack-quotes' ), array( $this, 'field_api_key' ), self::PAGE_SLUG, 'tack_quotes_connection' );
		add_settings_field( 'tack_quotes_api_url', __( 'TackQuote API URL', 'tack-quotes' ), array( $this, 'field_api_url' ), self::PAGE_SLUG, 'tack_quotes_connection' );
		add_settings_field( 'tack_quotes_enable_widget', __( 'Show quote buttons', 'tack-quotes' ), array( $this, 'field_enable_widget' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_pdp_buttons', __( 'Product page buttons', 'tack-quotes' ), array( $this, 'field_pdp_buttons' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_button_label', __( '"Add to Quote" button label (product page)', 'tack-quotes' ), array( $this, 'field_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_request_button_label', __( '"Request a Quote" button label (product page)', 'tack-quotes' ), array( $this, 'field_request_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_checkout_button_label', __( '"Checkout as Quote" button label (quote list)', 'tack-quotes' ), array( $this, 'field_checkout_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_enable_order_sync', __( 'Sync orders to TackQuote', 'tack-quotes' ), array( $this, 'field_enable_order_sync' ), self::PAGE_SLUG, 'tack_quotes_sync' );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	/**
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $value );
	}

	/**
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_url( $value ) {
		$url = esc_url_raw( rtrim( (string) $value, '/' ) );
		return $url ? $url : 'https://api.tackquote.com/v1';
	}

	/**
	 * Checkbox options: unchecked fields are omitted from POST, so treat empty as "no".
	 *
	 * @param mixed $value Raw option value.
	 * @return string "yes" or "no".
	 */
	public function sanitize_checkbox( $value ) {
		return ( 'yes' === $value || '1' === $value || 'on' === $value || true === $value ) ? 'yes' : 'no';
	}

	// ── Section intros ────────────────────────────────────────────────────────

	public function section_connection() {
		echo '<p>' . esc_html__( 'Connect this WooCommerce store to your TackQuote account. Create an API key in TackQuote under Settings → Developer → API Keys.', 'tack-quotes' ) . '</p>';
	}

	public function section_storefront() {
		echo '<p>' . esc_html__( 'A floating “quote list” — separate from the WooCommerce cart — appears once a shopper adds a product, letting them review it and click “Checkout as Quote” to submit everything as one TackQuote request. On product pages, choose below whether shoppers see “Add to Quote” (adds the product to that quote list — never the WooCommerce cart, so it never touches stock or checkout), “Request a Quote” (submits a quote for just that product immediately), both, or neither.', 'tack-quotes' ) . '</p>';
	}

	public function section_sync() {
		echo '<p>' . esc_html__( 'When enabled, this plugin pushes order data one-way to TackQuote when an order is created or its status changes. It does not import orders, sync the product catalog, or update inventory. Failed pushes are logged under WooCommerce → Status → Logs (source: tack-quotes) and never block checkout.', 'tack-quotes' ) . '</p>';
	}

	// ── Field renderers (escape all output) ─────────────────────────────────────

	public function field_api_key() {
		$value  = (string) get_option( 'tack_quotes_api_key', '' );
		$masked = $value ? str_repeat( '•', 8 ) . substr( $value, -4 ) : '';
		printf(
			'<input type="password" name="tack_quotes_api_key" value="%s" class="regular-text" autocomplete="off" placeholder="%s" />',
			esc_attr( $value ),
			esc_attr__( 'Paste your TackQuote API key', 'tack-quotes' )
		);
		if ( $masked ) {
			echo '<p class="description">' . esc_html__( 'Saved key:', 'tack-quotes' ) . ' <code>' . esc_html( $masked ) . '</code></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Required for quote requests and order sync. Leave blank only while configuring the store.', 'tack-quotes' ) . '</p>';
		}
	}

	public function field_api_url() {
		printf(
			'<input type="url" name="tack_quotes_api_url" value="%s" class="regular-text" placeholder="https://api.tackquote.com/v1" />',
			esc_attr( (string) get_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' ) )
		);
		echo '<p class="description">' . esc_html__( 'Default is https://api.tackquote.com/v1. Change only if TackQuote support gives you a custom or staging API base URL (include the /v1 path, no trailing slash).', 'tack-quotes' ) . '</p>';
	}

	/**
	 * Which button(s) appear on the product page — independent checkboxes so
	 * a merchant can show "Add to Quote", "Request a Quote", both, or hide
	 * product-page buttons entirely while keeping "Checkout as Quote" on cart.
	 */
	public function field_pdp_buttons() {
		printf(
			'<input type="hidden" name="tack_quotes_show_add_to_quote" value="no" />' .
			'<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="tack_quotes_show_add_to_quote" value="yes" %s /> %s</label>',
			checked( 'yes' === get_option( 'tack_quotes_show_add_to_quote', 'yes' ), true, false ),
			esc_html__( 'Show "Add to Quote" (adds the product to the cart)', 'tack-quotes' )
		);
		printf(
			'<input type="hidden" name="tack_quotes_show_request_quote" value="no" />' .
			'<label style="display:block;"><input type="checkbox" name="tack_quotes_show_request_quote" value="yes" %s /> %s</label>',
			checked( 'yes' === get_option( 'tack_quotes_show_request_quote', 'yes' ), true, false ),
			esc_html__( 'Show "Request a Quote" (submits a quote for just this product immediately)', 'tack-quotes' )
		);
		echo '<p class="description">' . esc_html__( 'Both can be shown at once, or either alone. If neither is checked, product pages show no quote button (the cart page\'s "Checkout as Quote" is unaffected).', 'tack-quotes' ) . '</p>';
	}

	public function field_button_label() {
		printf(
			'<input type="text" name="tack_quotes_button_label" value="%s" class="regular-text" />',
			esc_attr( (string) get_option( 'tack_quotes_button_label', __( 'Add to Quote', 'tack-quotes' ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Shown next to Add to Cart on product pages. Clicking it adds the product to a separate quote list — never the WooCommerce cart — and does not submit a quote by itself.', 'tack-quotes' ) . '</p>';
	}

	public function field_request_button_label() {
		printf(
			'<input type="text" name="tack_quotes_request_button_label" value="%s" class="regular-text" />',
			esc_attr( (string) get_option( 'tack_quotes_request_button_label', __( 'Request a Quote', 'tack-quotes' ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Shown on product pages when enabled above. Clicking it immediately submits a quote request for just that product (does not add it to the cart).', 'tack-quotes' ) . '</p>';
	}

	public function field_checkout_button_label() {
		printf(
			'<input type="text" name="tack_quotes_checkout_button_label" value="%s" class="regular-text" />',
			esc_attr( (string) get_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tack-quotes' ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Shown in the floating quote-list drawer (bottom-right of every page, once at least one product is added). Clicking it submits every item in the quote list as a single TackQuote quote request.', 'tack-quotes' ) . '</p>';
	}

	public function field_enable_widget() {
		$this->checkbox(
			'tack_quotes_enable_widget',
			__( 'Display quote buttons on products and the floating quote-list drawer. Turn off to hide all of them at once.', 'tack-quotes' )
		);
	}

	public function field_enable_order_sync() {
		$this->checkbox(
			'tack_quotes_enable_order_sync',
			__( 'Push new and updated WooCommerce orders to TackQuote (one-way).', 'tack-quotes' )
		);
		echo '<p class="description">' . esc_html__( 'Uncheck to stop outbound sync immediately. Existing quotes in TackQuote are not deleted.', 'tack-quotes' ) . '</p>';
	}

	/**
	 * Render a yes/no checkbox with a hidden "no" fallback so unchecking saves correctly.
	 *
	 * @param string $option Option name.
	 * @param string $label  Visible label.
	 */
	private function checkbox( $option, $label ) {
		$checked = ( 'yes' === get_option( $option, 'yes' ) );
		printf(
			'<input type="hidden" name="%1$s" value="no" />' .
			'<label><input type="checkbox" name="%1$s" value="yes" %2$s /> %3$s</label>',
			esc_attr( $option ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	// ── Page ────────────────────────────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tack-quotes' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TackQuote', 'tack-quotes' ); ?></h1>
			<p class="description"><?php esc_html_e( 'TackQuote for WooCommerce — request-a-quote buttons and one-way order sync for B2B quoting.', 'tack-quotes' ); ?></p>
			<?php $this->maybe_show_test_result(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save TackQuote settings', 'tack-quotes' ) );
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Test connection', 'tack-quotes' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Uses the saved API URL and key to call TackQuote. Save settings first if you just changed them.', 'tack-quotes' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'tack_quotes_test', 'tack_quotes_test_nonce' ); ?>
				<input type="hidden" name="tack_quotes_action" value="test_connection" />
				<?php submit_button( __( 'Test TackQuote connection', 'tack-quotes' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the "Test connection" POST (nonce + capability enforced).
	 */
	private function maybe_show_test_result() {
		if ( ! isset( $_POST['tack_quotes_action'] ) || 'test_connection' !== $_POST['tack_quotes_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' )
			|| ! isset( $_POST['tack_quotes_test_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tack_quotes_test_nonce'] ) ), 'tack_quotes_test' ) ) {
			return;
		}
		$client = new Tack_Api_Client();
		$result = $client->test_connection();
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Connected to TackQuote successfully.', 'tack-quotes' ) . '</p></div>';
		}
	}
}
