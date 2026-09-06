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
	 * The buyer group codes this store uses, as the merchant declared them.
	 *
	 * WHY THIS OPTION EXISTS AT ALL. Restricting a gateway to a buyer group
	 * needs the group's CODE, and the plugin cannot ask TackQuote what codes
	 * exist: the only endpoint that lists buyer groups
	 * (`GET /v1/buyer-groups`) is behind a seller JWT and the RolesGuard, and
	 * the one route a store API key can reach — `GET /v1/storefront-b2b/buyer-group`
	 * — answers "which group is THIS ONE BUYER in" and returns a single row.
	 * There is no API-key-authenticated way to enumerate them. (Verified
	 * against the TackQuote API source, not against this plugin's own tests.)
	 *
	 * So the merchant states their codes ONCE, here, and every rule below is
	 * then a checkbox. The alternative — which this replaces — was retyping
	 * the codes on every line of two free-text boxes, where a typo silently
	 * hid a payment method from every customer.
	 *
	 * Seeded automatically from rules that already exist, so nobody upgrading
	 * has to retype anything. See `known_group_codes()`.
	 */
	const OPTION_GROUP_CODES = 'tack_quotes_buyer_group_codes';

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
		register_setting( self::OPTION_GROUP, Tack_Wholesale_Pricing::OPTION_ENABLED, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, Tack_B2B_Notices::OPTION_ORDER_LIMITS, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, Tack_B2B_Notices::OPTION_BUYER_GROUP, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Group_Restrictions::OPTION_ENABLED, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, self::OPTION_GROUP_CODES, array( 'sanitize_callback' => array( $this, 'sanitize_group_codes' ) ) );

		/*
		 * One sanitizer per map rather than one shared callback, because
		 * `register_setting()` registers the callback on `sanitize_option_{$option}`
		 * with accepted_args = 1 — the callback is never told WHICH option it is
		 * sanitizing. Both maps need that name, to read the currently stored rules
		 * back and merge onto them, so each gets a thin wrapper that supplies it.
		 */
		register_setting( self::OPTION_GROUP, Tack_Group_Restrictions::OPTION_PAYMENT_MAP, array( 'sanitize_callback' => array( $this, 'sanitize_payment_group_map' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Group_Restrictions::OPTION_SHIPPING_MAP, array( 'sanitize_callback' => array( $this, 'sanitize_shipping_group_map' ) ) );

		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_MODE, array( 'sanitize_callback' => array( $this, 'sanitize_store_mode' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_SCOPE, array( 'sanitize_callback' => array( $this, 'sanitize_scope' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_ROLES, array( 'sanitize_callback' => array( $this, 'sanitize_roles' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_HIDE_PRICE, array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( self::OPTION_GROUP, Tack_Catalog_Mode::OPT_PRICE_TEXT, array( 'sanitize_callback' => 'sanitize_text_field' ) );

		/*
		 * ── SECTION ORDER IS THE INSTRUCTIONS ────────────────────────────────
		 *
		 * `do_settings_sections()` renders sections in the order they are
		 * registered here (WordPress stores them in an insertion-ordered array
		 * keyed by id and simply foreaches it), so this list IS the order a
		 * merchant reads the page in. They are therefore ordered as a setup
		 * sequence, each step depending only on the ones above it:
		 *
		 *   1 Connect      nothing else in the plugin works without a key
		 *   2 How to buy   the store-wide decision that frames everything below
		 *   3 Buttons      what a shopper actually sees
		 *   4 Order sync   independent of B2B, and off by default
		 *   5 B2B pricing  needs a key AND a TackQuote plan with B2B pricing
		 *   6 Buyer group rules  needs a key, and needs groups to exist first
		 *
		 * Steps 5 and 6 were one section until 1.8.0. Seven controls sat under
		 * a single "B2B pricing" heading, of which the last three were about
		 * hiding checkout methods and had nothing to do with pricing. Splitting
		 * them is most of what makes this page readable.
		 */
		add_settings_section(
			'tack_quotes_connection',
			__( '1. Connect to TackQuote', 'tackquote' ),
			array( $this, 'section_connection' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_store_mode',
			__( '2. How customers buy', 'tackquote' ),
			array( $this, 'section_store_mode' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_storefront',
			__( '3. Quote buttons on your storefront', 'tackquote' ),
			array( $this, 'section_storefront' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_sync',
			__( '4. Order sync', 'tackquote' ),
			array( $this, 'section_sync' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_b2b_pricing',
			__( '5. B2B pricing', 'tackquote' ),
			array( $this, 'section_b2b_pricing' ),
			self::PAGE_SLUG
		);
		add_settings_section(
			'tack_quotes_group_rules',
			__( '6. Buyer groups and checkout restrictions', 'tackquote' ),
			array( $this, 'section_group_rules' ),
			self::PAGE_SLUG
		);

		add_settings_field( 'tack_quotes_api_key', __( 'TackQuote API Key', 'tackquote' ), array( $this, 'field_api_key' ), self::PAGE_SLUG, 'tack_quotes_connection' );
		add_settings_field( 'tack_quotes_api_url', __( 'TackQuote API URL', 'tackquote' ), array( $this, 'field_api_url' ), self::PAGE_SLUG, 'tack_quotes_connection' );

		add_settings_field( Tack_Catalog_Mode::OPT_MODE, __( 'How customers buy', 'tackquote' ), array( $this, 'field_store_mode' ), self::PAGE_SLUG, 'tack_quotes_store_mode' );
		add_settings_field( Tack_Catalog_Mode::OPT_SCOPE, __( 'Applies to', 'tackquote' ), array( $this, 'field_quote_only_scope' ), self::PAGE_SLUG, 'tack_quotes_store_mode' );
		add_settings_field( Tack_Catalog_Mode::OPT_HIDE_PRICE, __( 'Prices', 'tackquote' ), array( $this, 'field_hide_prices' ), self::PAGE_SLUG, 'tack_quotes_store_mode' );

		add_settings_field( 'tack_quotes_enable_widget', __( 'Show quote buttons', 'tackquote' ), array( $this, 'field_enable_widget' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_pdp_buttons', __( 'Product page buttons', 'tackquote' ), array( $this, 'field_pdp_buttons' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_button_label', __( '"Add to Quote" button label (product page)', 'tackquote' ), array( $this, 'field_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_request_button_label', __( '"Request a Quote" button label (product page)', 'tackquote' ), array( $this, 'field_request_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );
		add_settings_field( 'tack_quotes_checkout_button_label', __( '"Checkout as Quote" button label (quote list)', 'tackquote' ), array( $this, 'field_checkout_button_label' ), self::PAGE_SLUG, 'tack_quotes_storefront' );

		add_settings_field( 'tack_quotes_enable_order_sync', __( 'Sync orders to TackQuote', 'tackquote' ), array( $this, 'field_enable_order_sync' ), self::PAGE_SLUG, 'tack_quotes_sync' );

		add_settings_field( Tack_Wholesale_Pricing::OPTION_ENABLED, __( 'Use TackQuote prices', 'tackquote' ), array( $this, 'field_enable_wholesale_pricing' ), self::PAGE_SLUG, 'tack_quotes_b2b_pricing' );
		add_settings_field( Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS, __( 'Show volume pricing table', 'tackquote' ), array( $this, 'field_show_quantity_breaks' ), self::PAGE_SLUG, 'tack_quotes_b2b_pricing' );
		add_settings_field( Tack_B2B_Notices::OPTION_ORDER_LIMITS, __( 'Enforce order limits', 'tackquote' ), array( $this, 'field_enable_order_limits' ), self::PAGE_SLUG, 'tack_quotes_b2b_pricing' );
		add_settings_field( Tack_B2B_Notices::OPTION_BUYER_GROUP, __( 'Show buyer group', 'tackquote' ), array( $this, 'field_enable_buyer_group' ), self::PAGE_SLUG, 'tack_quotes_b2b_pricing' );

		add_settings_field( Tack_Group_Restrictions::OPTION_ENABLED, __( 'Restrict methods by group', 'tackquote' ), array( $this, 'field_enable_group_restrictions' ), self::PAGE_SLUG, 'tack_quotes_group_rules' );
		add_settings_field( self::OPTION_GROUP_CODES, __( 'Your buyer group codes', 'tackquote' ), array( $this, 'field_group_codes' ), self::PAGE_SLUG, 'tack_quotes_group_rules' );
		add_settings_field( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, __( 'Payment methods', 'tackquote' ), array( $this, 'field_payment_group_map' ), self::PAGE_SLUG, 'tack_quotes_group_rules' );
		add_settings_field( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, __( 'Shipping methods', 'tackquote' ), array( $this, 'field_shipping_group_map' ), self::PAGE_SLUG, 'tack_quotes_group_rules' );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	/**
	 * Sanitize the merchant's list of buyer group codes.
	 *
	 * Accepts either the comma/newline separated text the field posts, or an
	 * array. Codes are upper-cased because that is the single normalised form
	 * `Tack_Group_Restrictions::parse_map()` stores and compares against —
	 * matching is case-insensitive either way, so this only decides how they
	 * are shown back.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public function sanitize_group_codes( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $code ) {
			$code = self::clean_group_code( $code );
			if ( '' !== $code && ! in_array( $code, $clean, true ) ) {
				$clean[] = $code;
			}
		}
		sort( $clean );
		return $clean;
	}

	/**
	 * Normalise a group code a merchant just TYPED.
	 *
	 * Allow-list, because this is new input and an allow-list is the safe way
	 * to treat it. Real TackQuote codes are plain identifiers — the seeder
	 * ships `standard`, `silver`, `gold`, `platinum` — so nothing legitimate
	 * is lost.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	private static function clean_group_code( $code ) {
		// TackQuote's `buyer_groups.code` column is varchar(100).
		$code = substr( (string) $code, 0, 100 );
		return strtoupper( preg_replace( '/[^A-Za-z0-9._\-]/', '', $code ) );
	}

	/**
	 * Normalise a group code that is ALREADY STORED in a rule.
	 *
	 * Deliberately looser than `clean_group_code()`, and the difference is a
	 * bug fix rather than an oversight. Running an existing code through the
	 * allow-list would rewrite it — `TIER~2` becomes `TIER2` — and a rewritten
	 * code no longer matches the one in the stored rule, so its checkbox
	 * renders unticked and the next save drops the rule. The merchant loses a
	 * restriction by opening a page and pressing Save.
	 *
	 * So an existing code is left alone apart from the three things that would
	 * genuinely corrupt the storage format: a comma (which separates codes), a
	 * line break (which separates rules), and control characters.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	private static function clean_stored_group_code( $code ) {
		$code = substr( (string) $code, 0, 100 );
		$code = preg_replace( '/[,\x00-\x1F\x7F]+/', '', $code );
		return strtoupper( trim( $code ) );
	}

	/**
	 * Sanitize callback for the payment map. See register_settings() for why
	 * the option name is baked into a wrapper rather than passed in.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_payment_group_map( $value ) {
		return $this->sanitize_group_map_for( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, $value );
	}

	/**
	 * Sanitize callback for the shipping map.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_shipping_group_map( $value ) {
		return $this->sanitize_group_map_for( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, $value );
	}

	/**
	 * Turn whatever the rules field posted back into the stored line format.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * THE STORED FORMAT DOES NOT CHANGE, AND IS NOT ALLOWED TO
	 * ─────────────────────────────────────────────────────────────────────────
	 * `Tack_Group_Restrictions::parse_map()` stays the only reader, and it
	 * still reads `method_id: CODE, CODE`, one rule per line. Everything the
	 * checkbox grid does is converted back into exactly that text on the way
	 * in, so a store that upgrades keeps working with no migration, and a
	 * merchant who configured rules by hand keeps them.
	 *
	 * Three submitted shapes are handled, and the differences matter:
	 *
	 *   array   The checkbox grid. Merged onto the stored rules — see below.
	 *   string  The textarea fallback, and any programmatic `update_option()`.
	 *           Behaves exactly as it did before 1.8.0.
	 *   neither `null`, in particular. WordPress's own options.php calls
	 *           `update_option( $option, null )` for every registered option
	 *           that is ABSENT from the POST, so a field that failed to render
	 *           arrives here as null. Returning '' for that would delete a
	 *           merchant's entire rule set because a page did not draw a
	 *           widget. The stored value is kept instead.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * WHY THE GRID MERGES INSTEAD OF REPLACING
	 * ─────────────────────────────────────────────────────────────────────────
	 * The grid can only draw a row for a gateway or shipping method that
	 * exists on this store RIGHT NOW. Rebuilding the option from the grid
	 * alone would therefore silently delete a rule whenever the plugin
	 * providing that gateway is deactivated — deactivate Stripe for ten
	 * minutes, save any TackQuote setting, and the Net-30 rule is gone with
	 * no message. So the form posts the list of ids it actually rendered, and
	 * only those lines are rewritten. Everything else in the stored text —
	 * rules for absent methods, `#` comments, blank lines, lines the parser
	 * cannot read — is carried through untouched.
	 *
	 * @param string $option Option being sanitized.
	 * @param mixed  $value  Submitted value.
	 * @return string
	 */
	private function sanitize_group_map_for( $option, $value ) {
		$stored = (string) get_option( $option, '' );

		if ( is_string( $value ) ) {
			return $this->sanitize_group_map( $value );
		}

		if ( ! is_array( $value ) ) {
			return $stored;
		}

		// The textarea fallback posts its text under `text`.
		if ( isset( $value['mode'] ) && 'text' === $value['mode'] ) {
			return $this->sanitize_group_map( isset( $value['text'] ) ? $value['text'] : '' );
		}

		$rendered = array();
		foreach ( (array) ( isset( $value['rendered'] ) ? $value['rendered'] : array() ) as $id ) {
			$id = $this->clean_method_id( $id );
			if ( '' !== $id && ! in_array( $id, $rendered, true ) ) {
				$rendered[] = $id;
			}
		}

		/*
		 * A grid that rendered no rows tells us nothing about the stored rules,
		 * so it must not be read as "the merchant cleared everything".
		 */
		if ( empty( $rendered ) ) {
			return $stored;
		}

		$submitted = array();
		foreach ( (array) ( isset( $value['groups'] ) ? $value['groups'] : array() ) as $id => $codes ) {
			$id = $this->clean_method_id( $id );
			if ( '' === $id || ! in_array( $id, $rendered, true ) ) {
				// Only ids the form actually offered may be written through it.
				continue;
			}
			$clean = array();
			foreach ( (array) $codes as $code ) {
				// Looser on purpose — see clean_stored_group_code(). These
				// values came from checkboxes this page rendered from the
				// stored rules, so rewriting them here would silently change
				// or drop a rule the merchant never touched.
				$code = self::clean_stored_group_code( $code );
				if ( '' !== $code && ! in_array( $code, $clean, true ) ) {
					$clean[] = $code;
				}
			}
			if ( ! empty( $clean ) ) {
				$submitted[ $id ] = $clean;
			}
		}

		return $this->merge_group_map( $stored, $rendered, $submitted );
	}

	/**
	 * Rewrite only the lines the form was responsible for.
	 *
	 * @param string   $stored    Currently stored raw text.
	 * @param string[] $rendered  Ids the form drew a row for.
	 * @param array    $submitted id => string[] of codes, empty ids omitted.
	 * @return string
	 */
	private function merge_group_map( $stored, array $rendered, array $submitted ) {
		$lines = ( '' === trim( $stored ) )
			? array()
			: preg_split( '/\r\n|\r|\n/', $stored );

		$out  = array();
		$done = array();

		foreach ( $lines as $line ) {
			$id      = '';
			$trimmed = trim( $line );
			if ( '' !== $trimmed && 0 !== strpos( $trimmed, '#' ) ) {
				$parts = explode( ':', $trimmed, 2 );
				if ( 2 === count( $parts ) ) {
					$id = $this->clean_method_id( $parts[0] );
				}
			}

			if ( '' === $id || ! in_array( $id, $rendered, true ) ) {
				// Comment, blank line, unparsable line, or a rule for a method
				// this store no longer has. None of it was the form's to touch.
				$out[] = $line;
				continue;
			}

			if ( isset( $done[ $id ] ) ) {
				// parse_map() lets a later duplicate win, so a second line for
				// the same id is already dead weight. Dropping it here stops
				// the grid turning one rule into two identical ones.
				continue;
			}
			$done[ $id ] = true;

			// Absent from $submitted means every box was cleared, which means
			// unrestricted — so the line goes away rather than becoming
			// "allowed to nobody".
			if ( isset( $submitted[ $id ] ) ) {
				$out[] = $id . ': ' . implode( ', ', $submitted[ $id ] );
			}
		}

		foreach ( $rendered as $id ) {
			if ( ! isset( $done[ $id ] ) && isset( $submitted[ $id ] ) ) {
				$out[] = $id . ': ' . implode( ', ', $submitted[ $id ] );
			}
		}

		return trim( implode( "\n", $out ) );
	}

	/**
	 * Normalise a gateway or shipping method id.
	 *
	 * Deliberately permissive about the characters gateways actually use, and
	 * deliberately strict about the two that would corrupt the stored format:
	 * a newline would split one rule into two, and a `:` past the first would
	 * confuse the id/codes boundary. A full rate id (`flat_rate:3`) still has
	 * to survive, so the colon is allowed but a line break never is.
	 *
	 * @param mixed $id Raw id.
	 * @return string
	 */
	private function clean_method_id( $id ) {
		return trim( preg_replace( '/[^A-Za-z0-9._:\-]/', '', (string) $id ) );
	}

	/**
	 * Sanitize a group map supplied as raw text.
	 *
	 * Kept as free text rather than parsed-and-rejected: a merchant mid-edit
	 * should not lose the whole box because one line is incomplete. The parser
	 * in Tack_Group_Restrictions skips lines it cannot read, so an unparsable
	 * line restricts nothing rather than breaking checkout. What is stripped
	 * here is markup, which has no business in an id list.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_group_map( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( $value ) );
	}

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
		echo '<p>' . esc_html__( 'Start here. Connect this WooCommerce store to your TackQuote account: create an API key in TackQuote under Settings → Developer → API Keys, paste it below, save, then use "Test TackQuote connection" at the bottom of this page.', 'tackquote' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Quote requests, order sync and everything in steps 5 and 6 need this key. The quote buttons in steps 2 and 3 can be set up first, but a shopper who presses one before the store is connected gets an error.', 'tackquote' ) . '</p>';
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
	 * Explains what switching store prices over actually does.
	 */
	public function section_b2b_pricing() {
		echo '<p>' . esc_html__(
			'Price signed-in trade customers using their TackQuote price book, buyer group and quantity breaks — the same pricing that would appear on a quote. Prices are resolved per customer, so nothing changes for anonymous shoppers.',
			'tackquote'
		) . '</p>';
		echo '<p class="description">' . esc_html__(
			'Requires a TackQuote plan that includes B2B pricing. If your plan does not include it, or TackQuote cannot be reached, your store keeps its own prices — no product is ever left unpriced.',
			'tackquote'
		) . '</p>';

		$this->prerequisite_notice();
	}

	/**
	 * The B2B pricing on/off switch.
	 */
	public function field_enable_wholesale_pricing() {
		$this->checkbox_default_off(
			Tack_Wholesale_Pricing::OPTION_ENABLED,
			__( 'Replace store prices with TackQuote prices for signed-in customers.', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__(
			'This changes the price used at checkout, not just the price shown. Off by default.',
			'tackquote'
		) . '</p>';
	}

	/**
	 * The quantity-break table switch.
	 */
	public function field_show_quantity_breaks() {
		$this->checkbox(
			Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS,
			__( 'Show a "Volume pricing" table on product pages.', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__(
			'Only appears when the customer actually has more than one price tier for that product.',
			'tackquote'
		) . '</p>';
	}

	/**
	 * The order-limits switch.
	 */
	public function field_enable_order_limits() {
		$this->checkbox_default_off(
			Tack_B2B_Notices::OPTION_ORDER_LIMITS,
			__( 'Show and enforce TackQuote minimum/maximum order quantities.', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__(
			'The notice on the product page is a courtesy; the cart and checkout are what actually refuse an order that breaks a limit. If TackQuote cannot be reached, nothing is blocked — a checkout that fails on a slow API is worse than an unenforced minimum.',
			'tackquote'
		) . '</p>';
	}

	/**
	 * The buyer-group badge switch.
	 */
	public function field_enable_buyer_group() {
		$this->checkbox_default_off(
			Tack_B2B_Notices::OPTION_BUYER_GROUP,
			__( 'Show the signed-in customer which pricing group they are on.', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__(
			'Without it a discounted price appears with no explanation, which reads as a pricing error rather than the negotiated rate it is.',
			'tackquote'
		) . '</p>';
	}

	/**
	 * The method-restriction switch.
	 */
	public function field_enable_group_restrictions() {
		$this->checkbox_default_off(
			Tack_Group_Restrictions::OPTION_ENABLED,
			__( 'Limit payment and shipping methods to particular TackQuote buyer groups.', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__(
			'Leave this off and the rules below are saved but ignored. A method with no groups ticked stays available to everyone either way, so switching this on changes nothing until you tick something. If a rule would remove every payment or shipping option, it is ignored and logged — a checkout nobody can complete is never the right answer to a misconfiguration.',
			'tackquote'
		) . '</p>';
	}

	/**
	 * Intro copy for the buyer-group rules section.
	 */
	public function section_group_rules() {
		echo '<p>' . esc_html__(
			'Keep a payment or shipping method for approved trade accounts only — "Net 30 is for approved accounts", "pallet delivery is for wholesale". Tick the buyer groups allowed to use each method below.',
			'tackquote'
		) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'A method with nothing ticked stays available to everyone.', 'tackquote' ) . '</strong> '
			. esc_html__(
				'That is the safe default and it is deliberate: if an unticked method meant "nobody", switching this feature on would remove every payment option at once. Tick a group only where you actually want to narrow who can use the method.',
				'tackquote'
			) . '</p>';

		$this->prerequisite_notice();
	}

	/**
	 * Warn, in place, when a section cannot do anything yet because the store
	 * is not connected.
	 *
	 * A settings page that silently does nothing is the most expensive kind of
	 * confusion: the merchant configures it correctly, sees no effect, and
	 * concludes the feature is broken. Saying so where they are reading is
	 * cheaper than any amount of documentation.
	 */
	private function prerequisite_notice() {
		if ( '' !== (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return;
		}
		echo '<p class="description" style="padding:.6em .8em;border-left:4px solid #dba617;background:#fcf9e8;">'
			. '<strong>' . esc_html__( 'Nothing in this section takes effect yet.', 'tackquote' ) . '</strong> '
			. esc_html__( 'These settings depend on TackQuote knowing who your buyers are, and no API key is saved. Add one in step 1 above. Your settings here are still saved in the meantime.', 'tackquote' )
			. '</p>';
	}

	/**
	 * Every buyer group code this store knows about.
	 *
	 * Three sources, unioned, because none of them is complete on its own:
	 *
	 *   1. What the merchant typed into the codes field.
	 *   2. Every code already used by a stored rule. This is what makes the
	 *      upgrade from the old free-text boxes seamless — an existing rule's
	 *      codes appear as ticked checkboxes without anyone retyping them —
	 *      and, more importantly, it guarantees no stored code can ever be
	 *      MISSING from the grid. A code that had no checkbox could not be
	 *      re-ticked, and saving the page would quietly drop it.
	 *   3. The `tackquote_buyer_group_codes` filter, so a site can supply the
	 *      list from somewhere else.
	 *
	 * There is deliberately no TackQuote lookup here. See OPTION_GROUP_CODES:
	 * no API-key-authenticated endpoint to list a tenant's buyer groups
	 * exists, and calling one that does not exist would spend a request on a
	 * 404 every time this page loads.
	 *
	 * @return string[] Upper-cased, unique, sorted.
	 */
	public function known_group_codes() {
		$codes = $this->sanitize_group_codes( get_option( self::OPTION_GROUP_CODES, array() ) );

		$restrictions = new Tack_Group_Restrictions();
		foreach ( array( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, Tack_Group_Restrictions::OPTION_SHIPPING_MAP ) as $option ) {
			foreach ( $restrictions->parse_map( (string) get_option( $option, '' ) ) as $rule_codes ) {
				foreach ( $rule_codes as $code ) {
					// Harvested verbatim, NOT through the typed-input
					// allow-list: a code that came back altered would not
					// match the rule it came from, so its checkbox would
					// render unticked and saving would delete the rule.
					$code = self::clean_stored_group_code( $code );
					if ( '' !== $code && ! in_array( $code, $codes, true ) ) {
						$codes[] = $code;
					}
				}
			}
		}

		/**
		 * Filters the buyer group codes offered as checkboxes on the settings page.
		 *
		 * @param string[] $codes Upper-cased group codes.
		 */
		$filtered = array();
		foreach ( (array) apply_filters( 'tackquote_buyer_group_codes', $codes ) as $code ) {
			$code = self::clean_stored_group_code( $code );
			if ( '' !== $code && ! in_array( $code, $filtered, true ) ) {
				$filtered[] = $code;
			}
		}

		sort( $filtered );
		return $filtered;
	}

	/**
	 * The codes field: the one place a merchant types a group code.
	 */
	public function field_group_codes() {
		$stored = $this->sanitize_group_codes( get_option( self::OPTION_GROUP_CODES, array() ) );
		$known  = $this->known_group_codes();

		printf(
			'<input type="text" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( self::OPTION_GROUP_CODES ),
			esc_attr( implode( ', ', $stored ) ),
			// NOT translatable: an example of the VALUES typed into this box.
			// A translator localising these turns a working example into a
			// broken one. Escaped anyway, at the point of output, so the sniff
			// does not have to reason about where the literal came from.
			esc_attr( 'TIER2, TIER3' )
		);
		echo '<p class="description">' . esc_html__(
			'Separate codes with commas. Copy them from TackQuote under Buyer Groups — use the group\'s code, not its display name. Matching ignores case.',
			'tackquote'
		) . '</p>';

		$inherited = array_values( array_diff( $known, $stored ) );
		if ( ! empty( $inherited ) ) {
			echo '<p class="description">' . esc_html__( 'Also in use by rules you already saved:', 'tackquote' ) . ' ';
			$first = true;
			foreach ( $inherited as $code ) {
				if ( ! $first ) {
					echo ', ';
				}
				// Escaped inline, one item at a time: see the note in
				// render_group_map_field() for why this is not hoisted.
				echo '<code>' . esc_html( $code ) . '</code>';
				$first = false;
			}
			echo '</p>';
		}

		if ( empty( $known ) ) {
			echo '<p class="description">' . esc_html__( 'Add at least one code to switch the rules below from typing to ticking.', 'tackquote' ) . '</p>';
		}
	}

	/**
	 * Which payment gateways this store has, as rows for the grid.
	 *
	 * `WC()->payment_gateways()->payment_gateways()` returns every REGISTERED
	 * gateway keyed by `$gateway->id`, which is the id the stored rules use.
	 * (Verified against WooCommerce 11.1.0,
	 * `includes/class-wc-payment-gateways.php::payment_gateways()`.) The
	 * available-gateway list is not used on purpose: it is the checkout's
	 * filtered view, and a gateway a rule already restricts could be missing
	 * from it precisely because the rule is working.
	 *
	 * @return array<int, array{id:string,title:string}>
	 */
	private function payment_gateway_rows() {
		if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'payment_gateways' ) || ! WC()->payment_gateways() ) {
			return array();
		}
		$rows = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			// `get_method_title()` is the ADMIN-screen name — "Direct bank
			// transfer" rather than whatever the merchant renamed it to for
			// customers — which is what makes a row recognisable against
			// WooCommerce → Settings → Payments. Falls back to the customer
			// title, then the bare id, so a third-party gateway that sets
			// neither still gets a row.
			$title = is_object( $gateway ) && method_exists( $gateway, 'get_method_title' ) ? (string) $gateway->get_method_title() : '';
			if ( '' === trim( $title ) && is_object( $gateway ) && method_exists( $gateway, 'get_title' ) ) {
				$title = (string) $gateway->get_title();
			}
			$rows[] = array(
				'id'    => (string) $id,
				'title' => '' !== trim( $title ) ? $title : (string) $id,
			);
		}
		return $rows;
	}

	/**
	 * Which shipping methods this store has, as rows for the grid.
	 *
	 * `WC()->shipping()->get_shipping_methods()` returns the registered
	 * shipping METHODS keyed by `$method->id` — `flat_rate`, `free_shipping`,
	 * `local_pickup` plus anything added through the
	 * `woocommerce_shipping_methods` filter. (Verified against WooCommerce
	 * 11.1.0, `includes/class-wc-shipping.php::register_shipping_method()`,
	 * which assigns `$this->shipping_methods[ $method->id ]`.)
	 *
	 * Methods, not per-zone rate instances, and that is the right level: a
	 * rate id is `method_id:instance_id`, and `filter_shipping_rates()`
	 * already looks a rate up by its full id first and its method id second,
	 * so a rule written against the method covers every zone that offers it.
	 * A merchant who genuinely needs one zone only can still write the full
	 * rate id by hand — such a rule is preserved untouched by the grid.
	 *
	 * @return array<int, array{id:string,title:string}>
	 */
	private function shipping_method_rows() {
		if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'shipping' ) || ! WC()->shipping() ) {
			return array();
		}
		$methods = WC()->shipping()->get_shipping_methods();
		if ( ! is_array( $methods ) ) {
			return array();
		}
		$rows = array();
		foreach ( $methods as $id => $method ) {
			$title = is_object( $method ) && method_exists( $method, 'get_method_title' ) ? (string) $method->get_method_title() : '';
			$rows[] = array(
				'id'    => (string) $id,
				'title' => '' !== trim( $title ) ? $title : (string) $id,
			);
		}
		return $rows;
	}

	/**
	 * Payment gateways, one row each.
	 */
	public function field_payment_group_map() {
		$this->render_group_map_field(
			Tack_Group_Restrictions::OPTION_PAYMENT_MAP,
			$this->payment_gateway_rows(),
			__( 'Payment method', 'tackquote' ),
			// NOT translatable: syntax the merchant would type verbatim.
			"cod: TIER2, TIER3\nbacs: TIER3"
		);
	}

	/**
	 * Shipping methods, one row each.
	 */
	public function field_shipping_group_map() {
		$this->render_group_map_field(
			Tack_Group_Restrictions::OPTION_SHIPPING_MAP,
			$this->shipping_method_rows(),
			__( 'Shipping method', 'tackquote' ),
			// Not translatable, for the same reason.
			"free_shipping: TIER3\nlocal_pickup: TIER2, TIER3"
		);
	}

	/**
	 * Render one rules field: a checkbox grid when we can, a textarea when we
	 * cannot.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * THE FALLBACK IS THE POINT, NOT AN AFTERTHOUGHT
	 * ─────────────────────────────────────────────────────────────────────────
	 * If the store has no gateways or shipping methods to list — WooCommerce
	 * not loaded on this request, a fatal in a gateway plugin, a filter that
	 * emptied the list — the grid cannot be drawn. It must NOT then render as
	 * an empty grid, because an empty grid posts an empty set of rules and
	 * would delete the merchant's configuration on the next save. So the field
	 * degrades to the textarea it used to be, pre-filled with exactly what is
	 * stored, and the merchant can still read and edit every rule.
	 *
	 * Only ONE of the two ever renders, and the mode is stated in a hidden
	 * field, so there is never a question of which input the save should
	 * believe.
	 *
	 * @param string $option      Option name.
	 * @param array  $rows        array{id:string,title:string}[].
	 * @param string $column      Heading for the first column.
	 * @param string $placeholder Fallback textarea example. Not translatable.
	 */
	private function render_group_map_field( $option, $rows, $column, $placeholder ) {
		$restrictions = new Tack_Group_Restrictions();
		$stored_raw   = (string) get_option( $option, '' );
		$stored       = $restrictions->parse_map( $stored_raw );

		if ( empty( $rows ) ) {
			$this->render_group_map_fallback( $option, $placeholder, count( $stored ) );
			return;
		}

		$known = $this->known_group_codes();

		printf( '<input type="hidden" name="%s[mode]" value="matrix" />', esc_attr( $option ) );

		echo '<table class="widefat striped" style="max-width:44em;">';
		echo '<thead><tr><th scope="col">' . esc_html( $column ) . '</th><th scope="col">'
			. esc_html__( 'Allowed buyer groups', 'tackquote' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id      = (string) $row['id'];
			$allowed = isset( $stored[ $id ] ) ? $stored[ $id ] : array();

			echo '<tr><td>';
			echo '<strong>' . esc_html( $row['title'] ) . '</strong><br />';
			echo '<code>' . esc_html( $id ) . '</code>';
			printf( '<input type="hidden" name="%1$s[rendered][]" value="%2$s" />', esc_attr( $option ), esc_attr( $id ) );
			echo '</td><td>';

			if ( empty( $known ) ) {
				echo '<span class="description">' . esc_html__( 'Add your buyer group codes above to restrict this method.', 'tackquote' ) . '</span>';
			} else {
				foreach ( $known as $code ) {
					/*
					 * Escaped INLINE, one value at a time.
					 *
					 * Two earlier versions of this file got it wrong in
					 * opposite directions: `esc_html( implode( '</code>,
					 * <code>', $ids ) )` escapes the separators too, so the
					 * merchant reads a literal "</code>, <code>"; and
					 * pre-escaping with `array_map` into a variable fails
					 * Plugin Check's EscapeOutput sniff, which cannot see
					 * through it. Escaping at the point of output satisfies
					 * both.
					 */
					printf(
						'<label style="display:inline-block;margin:0 1em .35em 0;"><input type="checkbox" name="%1$s[groups][%2$s][]" value="%3$s" %4$s /> <code>%5$s</code></label>',
						esc_attr( $option ),
						esc_attr( $id ),
						esc_attr( $code ),
						checked( in_array( $code, $allowed, true ), true, false ),
						esc_html( $code )
					);
				}
				echo '<br /><span class="description">';
				echo empty( $allowed )
					? esc_html__( 'Available to everyone.', 'tackquote' )
					: esc_html__( 'Hidden from everyone except the ticked groups.', 'tackquote' );
				echo '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$this->render_preserved_rules_note( $rows, $stored );
	}

	/**
	 * Tell the merchant about stored rules the grid could not show, so a rule
	 * that still affects checkout is never invisible on the page that owns it.
	 *
	 * @param array $rows   Rendered rows.
	 * @param array $stored Parsed stored rules.
	 */
	private function render_preserved_rules_note( $rows, $stored ) {
		$rendered = array();
		foreach ( $rows as $row ) {
			$rendered[] = (string) $row['id'];
		}
		$extra = array_diff( array_keys( $stored ), $rendered );
		if ( empty( $extra ) ) {
			return;
		}

		echo '<p class="description">' . esc_html__(
			'You also have rules for methods this store does not currently offer. They are kept exactly as they are and will apply again if the method comes back:',
			'tackquote'
		) . ' ';
		$first = true;
		foreach ( $extra as $id ) {
			if ( ! $first ) {
				echo ', ';
			}
			echo '<code>' . esc_html( $id ) . '</code>';
			$first = false;
		}
		echo '</p>';
	}

	/**
	 * The textarea the grid falls back to. Posts under `[text]` with the mode
	 * set, so the sanitizer knows to read it as raw rules.
	 *
	 * @param string $option      Option name.
	 * @param string $placeholder Example. Not translatable.
	 * @param int    $rule_count  How many rules are currently stored.
	 */
	private function render_group_map_fallback( $option, $placeholder, $rule_count ) {
		printf( '<input type="hidden" name="%s[mode]" value="text" />', esc_attr( $option ) );
		printf(
			'<textarea name="%1$s[text]" rows="4" cols="50" class="large-text code" placeholder="%2$s">%3$s</textarea>',
			esc_attr( $option ),
			esc_attr( $placeholder ),
			esc_textarea( (string) get_option( $option, '' ) )
		);
		echo '<p class="description">' . esc_html__(
			'This store reported no methods to list, so the rules are shown as text. One rule per line, as "method id: GROUP_CODE, GROUP_CODE". Your existing rules are shown above and are not changed by this screen.',
			'tackquote'
		) . '</p>';
		if ( $rule_count > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of rules currently saved. */
					_n( '%d rule is currently saved.', '%d rules are currently saved.', $rule_count, 'tackquote' ),
					$rule_count
				)
			) . '</p>';
		}
	}

	/**
	 * A checkbox whose stored default is OFF.
	 *
	 * `checkbox()` defaults to 'yes', which is right for the switches that ship
	 * enabled. Anything that changes what a customer is CHARGED must default to
	 * off, so it is opted into rather than inherited from a plugin update.
	 *
	 * @param string $option Option name.
	 * @param string $label  Visible label.
	 */
	private function checkbox_default_off( $option, $label ) {
		$checked = ( 'yes' === get_option( $option, 'no' ) );
		printf(
			'<input type="hidden" name="%1$s" value="no" />' .
			'<label><input type="checkbox" name="%1$s" value="yes" %2$s /> %3$s</label>',
			esc_attr( $option ),
			checked( $checked, true, false ),
			esc_html( $label )
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
