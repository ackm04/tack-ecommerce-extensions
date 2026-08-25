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
	const PAGE_SLUG    = 'tackquote';

	/**
	 * Fallback API base URL, used when the option is unset or a submitted value is
	 * rejected and nothing valid was stored before.
	 */
	const DEFAULT_API_URL = 'https://api.tackquote.com/v1';

	/**
	 * Hook registration.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the top-level admin menu page.
	 *
	 * `manage_options`, not the shop-manager capability, and deliberately so.
	 *
	 * The form on this page posts to `options.php`, and `options.php` requires
	 * `manage_options` for every registered option group unless the
	 * `option_page_capability_{$option_page}` filter says otherwise — WordPress documents
	 * that filter as "required to change the capability required for a certain options page".
	 * No such filter was registered here, so a shop_manager could see the page, fill it in,
	 * press save, and be told "Sorry, you are not allowed to access this page."
	 *
	 * Of the two ways to close that gap — filter the requirement down to the shop-manager
	 * capability, or raise the page to `manage_options` — this page holds the TackQuote API
	 * key, which authenticates the whole store to TackQuote. A secret of that blast radius
	 * belongs behind the administrator capability, so the page is raised rather than the
	 * requirement lowered.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'TackQuote', 'tackquote' ),
			__( 'TackQuote', 'tackquote' ),
			'manage_options',
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

		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_MODE, array( 'sanitize_callback' => array( $this, 'sanitize_store_mode' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_SCOPE, array( 'sanitize_callback' => array( $this, 'sanitize_scope' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_ROLES, array( 'sanitize_callback' => array( $this, 'sanitize_roles' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_HIDE_PRICE, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_PRICE_TEXT, array( 'sanitize_callback' => 'sanitize_text_field' ) );

		add_settings_section(
			'tack_quotes_store_mode',
			__( 'Store mode', 'tackquote' ),
			array( $this, 'section_store_mode' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_connection',
			__( 'Connection', 'tackquote' ),
			array( $this, 'section_connection' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_storefront',
			__( 'Request a Quote button', 'tackquote' ),
			array( $this, 'section_storefront' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_sync',
			__( 'Order sync', 'tackquote' ),
			array( $this, 'section_sync' ),
			self::PAGE_SLUG
		);

		add_settings_field( Tack_Catalog_Mode::OPT_MODE, __( 'How customers buy', 'tackquote' ), array( $this, 'field_store_mode' ), self::PAGE_SLUG, 'tack_quotes_store_mode' );
		add_settings_field( Tack_Catalog_Mode::OPT_SCOPE, __( 'Applies to', 'tackquote' ), array( $this, 'field_quote_only_scope' ), self::PAGE_SLUG, 'tack_quotes_store_mode' );
		add_settings_field( Tack_Catalog_Mode::OPT_HIDE_PRICE, __( 'Prices', 'tackquote' ), array( $this, 'field_hide_prices' ), self::PAGE_SLUG, 'tack_quotes_store_mode' );
		add_settings_field( 'tack_quotes_api_key', __( 'TackQuote API Key', 'tackquote' ), array( $this, 'field_api_key' ), self::PAGE_SLUG, 'tack_quotes_connection' );
		add_settings_field( 'tack_quotes_api_url', __( 'TackQuote API URL', 'tackquote' ), array( $this, 'field_api_url' ), self::PAGE_SLUG, 'tack_quotes_connection' );
		add_settings_field( 'tack_quotes_enable_widget', __( 'Show quote buttons', 'tackquote' ), array( $this, 'field_enable_widget' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_pdp_buttons', __( 'Product page buttons', 'tackquote' ), array( $this, 'field_pdp_buttons' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_button_label', __( '"Add to Quote" button label (product page)', 'tackquote' ), array( $this, 'field_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_request_button_label', __( '"Request a Quote" button label (product page)', 'tackquote' ), array( $this, 'field_request_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_checkout_button_label', __( '"Checkout as Quote" button label (quote list)', 'tackquote' ), array( $this, 'field_checkout_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_enable_order_sync', __( 'Sync orders to TackQuote', 'tackquote' ), array( $this, 'field_enable_order_sync' ), self::PAGE_SLUG, 'tack_quotes_sync' );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	/**
	 * Sanitize the API key, treating an empty submission as "keep what is stored".
	 *
	 * The field renders with an EMPTY value on purpose — see field_api_key() — so an
	 * untouched form posts nothing for it. Without this, saving any other setting on the page
	 * would silently wipe the key and disconnect the store, with the only symptom being
	 * "No TackQuote API key configured" on the next quote request.
	 *
	 * Clearing the key is still possible, explicitly, through the "Remove saved API key"
	 * button on the settings page.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		$clean = preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $value );
		if ( '' === $clean ) {
			return (string) get_option( 'tack_quotes_api_key', '' );
		}
		return $clean;
	}

	/**
	 * Sanitize the API base URL, falling back to the default when it is unusable.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_url( $value ) {
		$stored = (string) get_option( 'tack_quotes_api_url', self::DEFAULT_API_URL );
		if ( '' === $stored ) {
			$stored = self::DEFAULT_API_URL;
		}

		$raw = rtrim( trim( (string) $value ), '/' );

		if ( '' === $raw ) {
			return $stored;
		}

		// The scheme must be present in what the administrator actually typed.
		// `esc_url_raw()` helpfully PREPENDS `http://` to a bare string, so `not-a-url`
		// became `http://not-a-url` — a single-label host, which the development-host
		// allowance below then accepted. A typo would have been stored as valid-looking
		// configuration and only failed later, at request time, with a confusing error.
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			add_settings_error(
				'tack_quotes_api_url',
				'tack_quotes_api_url_invalid',
				__( 'The TackQuote API URL must begin with https:// (or http:// for a local development host). The previous value was kept.', 'tackquote' )
			);
			return $stored;
		}

		$candidate = esc_url_raw( $raw, array( 'http', 'https' ) );

		if ( '' === $candidate ) {
			return $stored;
		}

		$parts  = wp_parse_url( $candidate );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( '' === $host || ( 'http' !== $scheme && 'https' !== $scheme ) ) {
			add_settings_error(
				'tack_quotes_api_url',
				'tack_quotes_api_url_invalid',
				__( 'The TackQuote API URL must be a full http:// or https:// address including a host. The previous value was kept.', 'tackquote' )
			);
			return $stored;
		}

		if ( 'https' !== $scheme && ! self::is_non_public_host( $host ) ) {
			add_settings_error(
				'tack_quotes_api_url',
				'tack_quotes_api_url_insecure',
				__( 'The TackQuote API URL must use https:// — your API key and your buyers\' details are sent to it. Plain http:// is accepted only for local development hosts. The previous value was kept.', 'tackquote' )
			);
			return $stored;
		}

		return $candidate;
	}

	/**
	 * True for hosts unreachable from the public internet, which therefore cannot be
	 * expected to present a valid TLS certificate.
	 *
	 * Covers loopback, RFC1918 and link-local addresses, the reserved development TLDs,
	 * and single-label names — a container or service name such as `api`, which is how
	 * this plugin is exercised against a local stack.
	 *
	 * @param string $host Lower-cased host component.
	 * @return bool
	 */
	private static function is_non_public_host( $host ) {
		if ( 'localhost' === $host || false === strpos( $host, '.' ) ) {
			return true;
		}

		foreach ( array( '.local', '.localhost', '.test', '.internal' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Public routable space fails this check, so a false result means private.
			return false === filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return false;
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

	/**
	 * Intro copy for the Connection section.
	 */
	public function section_connection() {
		echo '<p>' . esc_html__( 'Connect this WooCommerce store to your TackQuote account. Create an API key in TackQuote under Settings → Developer → API Keys.', 'tackquote' ) . '</p>';
	}

	/**
	 * Intro copy for the storefront-buttons section.
	 */
	public function section_storefront() {
		echo '<p>' . esc_html__( 'A floating “quote list” — separate from the WooCommerce cart — appears once a shopper adds a product, letting them review it and click “Checkout as Quote” to submit everything as one TackQuote request. On product pages, choose below whether shoppers see “Add to Quote” (adds the product to that quote list — never the WooCommerce cart, so it never touches stock or checkout), “Request a Quote” (submits a quote for just that product immediately), both, or neither.', 'tackquote' ) . '</p>';
	}

	/**
	 * Intro copy for the order-sync section, including what data leaves the store.
	 */
	public function section_sync() {
		echo '<p>' . esc_html__( 'Off by default. When enabled, this plugin pushes order data one-way to TackQuote when an order is created or its status changes. It does not import orders, sync the product catalog, or update inventory.', 'tackquote' ) . '</p>';
		echo '<p>' . esc_html__( 'Each push is queued and sent on a background request through WooCommerce\'s Action Scheduler, so it never blocks checkout — queued jobs are visible under WooCommerce → Status → Scheduled Actions, and failures are logged under WooCommerce → Status → Logs (source: tackquote).', 'tackquote' ) . '</p>';
		echo '<p>' . esc_html__( 'Personal data leaves your store when this is on. Each order sends the whole order: the buyer\'s full billing and shipping addresses, email address and phone numbers, their WooCommerce customer ID and order note, the order number and ID, status, currency, subtotal, discount, shipping, tax and total, coupon codes, the created/modified/paid/completed dates, the payment method and the payment gateway\'s transaction reference, and every line item with its name, SKU, product and variation IDs, quantity, subtotal, total, tax and item meta (for example “Size: Large”). No card numbers, card details or gateway credentials are ever sent.', 'tackquote' ) . '</p>';
	}

	// ── Field renderers (escape all output) ─────────────────────────────────────

	/**
	 * Intro copy for the Store mode section.
	 */
	public function section_store_mode() {
		echo '<p class="description">' . esc_html__( 'Choose whether this is a normal shop that also takes quotes, or a B2B catalogue where every order starts as a quote.', 'tackquote' ) . '</p>';
	}

	/**
	 * The store-mode switch, rendered as two explained choices rather than a
	 * bare checkbox — turning off checkout store-wide is a big, scary action and
	 * the consequence should be readable before it is taken, not after.
	 */
	public function field_store_mode() {
		$mode = get_option( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_CART );

		$choices = array(
			Tack_Catalog_Mode::MODE_CART       => array(
				'label' => __( 'Shop and quotes', 'tackquote' ),
				'desc'  => __( 'Normal WooCommerce checkout, with the quote buttons alongside it. Customers choose which they want.', 'tackquote' ),
			),
			Tack_Catalog_Mode::MODE_QUOTE_ONLY => array(
				'label' => __( 'Quote only (B2B catalogue)', 'tackquote' ),
				'desc'  => __( 'Add to cart is switched off across the whole store and customers request a quote instead. Your products, categories and search all keep working — only checkout goes away.', 'tackquote' ),
			),
		);

		echo '<fieldset class="tack-store-mode">';
		foreach ( $choices as $value => $choice ) {
			printf(
				'<label style="display:block;margin-bottom:.75em;"><input type="radio" name="%1$s" value="%2$s" %3$s /> <strong>%4$s</strong><br /><span class="description" style="margin-left:1.9em;display:block;">%5$s</span></label>',
				esc_attr( Tack_Catalog_Mode::OPT_MODE ),
				esc_attr( $value ),
				checked( $mode, $value, false ),
				esc_html( $choice['label'] ),
				esc_html( $choice['desc'] )
			);
		}
		echo '</fieldset>';

		echo '<p class="description"><strong>' . esc_html__( 'You are not locked out.', 'tackquote' ) . '</strong> '
			. esc_html__( 'Anyone who can manage WooCommerce still sees a working cart, so you can test the store while it is closed to customers. Switching back restores checkout immediately.', 'tackquote' )
			. '</p>';
	}

	/**
	 * Who quote-only mode applies to.
	 *
	 * "Signed-out visitors only" is the option most B2B sellers actually want:
	 * the public sees a catalogue, approved trade customers keep a real cart.
	 */
	public function field_quote_only_scope() {
		$scope = get_option( Tack_Catalog_Mode::OPT_SCOPE, Tack_Catalog_Mode::SCOPE_EVERYONE );

		$choices = array(
			Tack_Catalog_Mode::SCOPE_EVERYONE => __( 'Every customer', 'tackquote' ),
			Tack_Catalog_Mode::SCOPE_GUESTS   => __( 'Signed-out visitors only — approved customers keep a normal cart', 'tackquote' ),
			Tack_Catalog_Mode::SCOPE_ROLES    => __( 'Only the roles I choose below', 'tackquote' ),
		);

		echo '<fieldset>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:.4em;"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Tack_Catalog_Mode::OPT_SCOPE ),
				esc_attr( $value ),
				checked( $scope, $value, false ),
				esc_html( $label )
			);
		}

		$selected = (array) get_option( Tack_Catalog_Mode::OPT_ROLES, array() );
		$roles    = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();

		echo '<div style="margin:.6em 0 0 1.9em;">';
		printf(
			'<label style="display:block;"><input type="checkbox" name="%1$s[]" value="guest" %2$s /> %3$s</label>',
			esc_attr( Tack_Catalog_Mode::OPT_ROLES ),
			checked( in_array( 'guest', $selected, true ), true, false ),
			esc_html__( 'Signed-out visitors', 'tackquote' )
		);
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Tack_Catalog_Mode::OPT_ROLES ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $name )
			);
		}
		echo '</div>';
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Only used when "Quote only" is selected above.', 'tackquote' ) . '</p>';
	}

	/**
	 * Optional "price on request".
	 */
	public function field_hide_prices() {
		$this->checkbox(
			Tack_Catalog_Mode::OPT_HIDE_PRICE,
			__( 'Hide prices while the store is quote-only.', 'tackquote' )
		);
		printf(
			'<p style="margin-top:.5em;"><input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" /></p>',
			esc_attr( Tack_Catalog_Mode::OPT_PRICE_TEXT ),
			esc_attr( (string) get_option( Tack_Catalog_Mode::OPT_PRICE_TEXT, '' ) ),
			esc_attr__( 'Price on request', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__( 'Shown in place of the price. Leave blank for "Price on request".', 'tackquote' ) . '</p>';
	}

	/**
	 * Only the two known modes are storable — anything else falls back to the
	 * safe one (a working shop), so a malformed POST can never silently close
	 * a store's checkout.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_store_mode( $value ) {
		$value = is_string( $value ) ? $value : '';
		return Tack_Catalog_Mode::MODE_QUOTE_ONLY === $value
			? Tack_Catalog_Mode::MODE_QUOTE_ONLY
			: Tack_Catalog_Mode::MODE_CART;
	}

	/**
	 * Only the three known scopes are storable; anything else falls back to the
	 * widest one, which is the value the mode switch itself defaults to.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_scope( $value ) {
		$allowed = array(
			Tack_Catalog_Mode::SCOPE_EVERYONE,
			Tack_Catalog_Mode::SCOPE_GUESTS,
			Tack_Catalog_Mode::SCOPE_ROLES,
		);
		return in_array( $value, $allowed, true ) ? (string) $value : Tack_Catalog_Mode::SCOPE_EVERYONE;
	}

	/**
	 * Role slugs must be real roles (or the pseudo-role `guest`), so a crafted
	 * POST cannot store arbitrary strings into the option.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public function sanitize_roles( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$known = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array();
		$known[] = 'guest';

		$clean = array();
		foreach ( $value as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $slug, $known, true ) && ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}
		return $clean;
	}

	/**
	 * The API key field. Renders EMPTY — never the stored key.
	 *
	 * `type="password"` only hides characters on screen. The value still sits in the page
	 * source, so the key leaked into browser "save page" output, password-manager autofill,
	 * screen shares and recordings, proxy caches, and anything able to read the DOM of an
	 * admin page. A masked hint in the description gives an administrator the one thing they
	 * actually need — confirmation of WHICH key is stored — without shipping the secret.
	 */
	public function field_api_key() {
		$value  = (string) get_option( 'tack_quotes_api_key', '' );
		$masked = '' !== $value ? str_repeat( '•', 8 ) . substr( $value, -4 ) : '';
		printf(
			'<input type="password" name="tack_quotes_api_key" value="" class="regular-text" autocomplete="new-password" placeholder="%s" />',
			esc_attr(
				'' !== $masked
					? __( 'Leave blank to keep the saved key', 'tackquote' )
					: __( 'Paste your TackQuote API key', 'tackquote' )
			)
		);
		if ( '' !== $masked ) {
			echo '<p class="description">'
				. esc_html__( 'A key is saved:', 'tackquote' ) . ' <code>' . esc_html( $masked ) . '</code>. '
				. esc_html__( 'Leave this field blank to keep it. Paste a new key to replace it.', 'tackquote' )
				. '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Required for quote requests and order sync. Leave blank only while configuring the store.', 'tackquote' ) . '</p>';
		}
	}

	/**
	 * The API base URL field.
	 */
	public function field_api_url() {
		printf(
			'<input type="url" name="tack_quotes_api_url" value="%s" class="regular-text" placeholder="https://api.tackquote.com/v1" />',
			esc_attr( (string) get_option( 'tack_quotes_api_url', self::DEFAULT_API_URL ) )
		);
		echo '<p class="description">' . esc_html__( 'Default is https://api.tackquote.com/v1. Change only if TackQuote support gives you a custom or staging API base URL (include the /v1 path, no trailing slash). Must use https:// — your API key and your buyers\' details are sent to this address.', 'tackquote' ) . '</p>';
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
			esc_html__( 'Show "Add to Quote" (adds the product to the cart)', 'tackquote' )
		);
		printf(
			'<input type="hidden" name="tack_quotes_show_request_quote" value="no" />' .
			'<label style="display:block;"><input type="checkbox" name="tack_quotes_show_request_quote" value="yes" %s /> %s</label>',
			checked( 'yes' === get_option( 'tack_quotes_show_request_quote', 'yes' ), true, false ),
			esc_html__( 'Show "Request a Quote" (submits a quote for just this product immediately)', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__( 'Both can be shown at once, or either alone. If neither is checked, product pages show no quote button (the cart page\'s "Checkout as Quote" is unaffected).', 'tackquote' ) . '</p>';
	}

	/**
	 * The "Add to Quote" button label field.
	 */
	public function field_button_label() {
		printf(
			'<input type="text" name="tack_quotes_button_label" value="%s" class="regular-text" />',
			esc_attr( (string) get_option( 'tack_quotes_button_label', __( 'Add to Quote', 'tackquote' ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Shown next to Add to Cart on product pages. Clicking it adds the product to a separate quote list — never the WooCommerce cart — and does not submit a quote by itself.', 'tackquote' ) . '</p>';
	}

	/**
	 * The "Request a Quote" button label field.
	 */
	public function field_request_button_label() {
		printf(
			'<input type="text" name="tack_quotes_request_button_label" value="%s" class="regular-text" />',
			esc_attr( (string) get_option( 'tack_quotes_request_button_label', __( 'Request a Quote', 'tackquote' ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Shown on product pages when enabled above. Clicking it immediately submits a quote request for just that product (does not add it to the cart).', 'tackquote' ) . '</p>';
	}

	/**
	 * The "Checkout as Quote" button label field.
	 */
	public function field_checkout_button_label() {
		printf(
			'<input type="text" name="tack_quotes_checkout_button_label" value="%s" class="regular-text" />',
			esc_attr( (string) get_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tackquote' ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Shown in the floating quote-list drawer (bottom-right of every page, once at least one product is added). Clicking it submits every item in the quote list as a single TackQuote quote request.', 'tackquote' ) . '</p>';
	}

	/**
	 * The master on/off switch for the storefront quote buttons.
	 */
	public function field_enable_widget() {
		$this->checkbox(
			'tack_quotes_enable_widget',
			__( 'Display quote buttons on products and the floating quote-list drawer. Turn off to hide all of them at once.', 'tackquote' )
		);
	}

	/**
	 * The order-sync on/off switch.
	 */
	public function field_enable_order_sync() {
		$this->checkbox(
			'tack_quotes_enable_order_sync',
			__( 'Push new and updated WooCommerce orders to TackQuote (one-way).', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__( 'Uncheck to stop outbound sync immediately. Existing quotes in TackQuote are not deleted.', 'tackquote' ) . '</p>';
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

	/**
	 * Render the settings screen.
	 */
	public function render_page() {
		// Must match add_menu()'s capability and options.php's own requirement — see the note
		// on add_menu() for why that is manage_options.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tackquote' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TackQuote', 'tackquote' ); ?></h1>
			<p class="description"><?php esc_html_e( 'TackQuote for WooCommerce — request-a-quote buttons and one-way order sync for B2B quoting.', 'tackquote' ); ?></p>
			<?php $this->maybe_handle_post_actions(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save TackQuote settings', 'tackquote' ) );
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Test connection', 'tackquote' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Uses the saved API URL and key to call TackQuote. Save settings first if you just changed them.', 'tackquote' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'tack_quotes_test', 'tack_quotes_test_nonce' ); ?>
				<input type="hidden" name="tack_quotes_action" value="test_connection" />
				<?php submit_button( __( 'Test TackQuote connection', 'tackquote' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( '' !== (string) get_option( 'tack_quotes_api_key', '' ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Remove saved API key', 'tackquote' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Deletes the stored key from this site. Quote requests and order sync stop working until a new key is saved. Nothing in your TackQuote account is deleted.', 'tackquote' ); ?>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'tack_quotes_remove_key', 'tack_quotes_remove_key_nonce' ); ?>
					<input type="hidden" name="tack_quotes_action" value="remove_api_key" />
					<?php submit_button( __( 'Remove saved API key', 'tackquote' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the page's own POST buttons: "Test connection" and "Remove saved API key".
	 *
	 * Capability first, then the nonce, then the submitted action — in that order. Reading
	 * `$_POST['tack_quotes_action']` before the nonce check (as this did) is not exploitable
	 * by itself, but it is the read order that lets an unverified value decide what runs next,
	 * and it is what WordPress' coding standards flag.
	 */
	private function maybe_handle_post_actions() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['tack_quotes_action'] ) ) {
			return;
		}

		if ( isset( $_POST['tack_quotes_test_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tack_quotes_test_nonce'] ) ), 'tack_quotes_test' )
			&& 'test_connection' === sanitize_key( wp_unslash( $_POST['tack_quotes_action'] ) ) ) {
			$this->render_test_result();
			return;
		}

		if ( isset( $_POST['tack_quotes_remove_key_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tack_quotes_remove_key_nonce'] ) ), 'tack_quotes_remove_key' )
			&& 'remove_api_key' === sanitize_key( wp_unslash( $_POST['tack_quotes_action'] ) ) ) {
			delete_option( 'tack_quotes_api_key' );
			delete_transient( 'tack_quotes_registration_config' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'The saved TackQuote API key has been removed.', 'tackquote' ) . '</p></div>';
		}
	}

	/**
	 * Call TackQuote with the saved credentials and print the outcome.
	 */
	private function render_test_result() {
		$client = new Tack_Api_Client();
		$result = $client->test_connection();
		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Connected to TackQuote successfully.', 'tackquote' ) . '</p></div>';
		}
	}
}
