<?php
/**
 * Frontend quote buttons:
 *  - "Add to Quote" (product page) — adds the product to a separate,
 *    browser-side "quote list" (localStorage), kept deliberately apart from
 *    the WooCommerce purchase cart so quoting a product never touches stock,
 *    cart totals, or normal checkout.
 *  - "Request a Quote" (product page) — submits a quote for just that one
 *    product immediately.
 *  - "Checkout as Quote" (floating quote-list drawer, shown site-wide) —
 *    submits every item currently in the quote list as one TackQuote request.
 * Plus the AJAX handler that creates the quote request either way.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the quote buttons and handles quote-request submissions.
 */
class Tack_Widget {

	/**
	 * Quote requests allowed per client per RATE_LIMIT_WINDOW seconds.
	 */
	const RATE_LIMIT_MAX = 5;

	/**
	 * Length of the rate-limit window, in seconds.
	 */
	const RATE_LIMIT_WINDOW = 300;

	/**
	 * Hard ceiling on the free-text note, in characters.
	 */
	const NOTE_MAX_LENGTH = 2000;

	/**
	 * Hard ceiling on line items accepted from one quote-list submission.
	 */
	const ITEMS_MAX = 100;

	/**
	 * Hard ceiling on the raw `items` JSON, in bytes, checked before json_decode().
	 */
	const ITEMS_MAX_BYTES = 65536;

	/**
	 * Hook registration.
	 */
	public function init() {
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_product_button' ) );
		add_action( 'wp_footer', array( $this, 'render_quote_list_drawer' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX (logged-in and guest).
		add_action( 'wp_ajax_tack_request_quote', array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_tack_request_quote', array( $this, 'handle_request' ) );
	}

	/**
	 * Enqueue the small JS/CSS the buttons need — loaded on every front-end
	 * page (not just product/cart) so the floating quote-list button/drawer
	 * is always reachable, the same way a theme's mini-cart usually is.
	 */
	public function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		// Asset version: the plugin version in production, the file's mtime when WP_DEBUG is
		// on. TACK_QUOTES_VERSION is a hardcoded constant, so during development every edit
		// to these files kept the SAME ?ver= and browsers served the cached copy — a fix
		// applied to the JS looked like a fix that did not work, which cost real debugging
		// time. Production behaviour is unchanged: released versions still bust the cache
		// through the version bump.
		$css     = TACK_QUOTES_DIR . 'assets/css/tack-quotes.css';
		$js      = TACK_QUOTES_DIR . 'assets/js/tack-quotes.js';
		$css_ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $css ) )
			? (string) filemtime( $css )
			: TACK_QUOTES_VERSION;
		$js_ver  = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $js ) )
			? (string) filemtime( $js )
			: TACK_QUOTES_VERSION;

		wp_enqueue_style( 'tack-quotes', TACK_QUOTES_URL . 'assets/css/tack-quotes.css', array(), $css_ver );
		wp_enqueue_script( 'tack-quotes', TACK_QUOTES_URL . 'assets/js/tack-quotes.js', array( 'jquery' ), $js_ver, true );
		wp_localize_script(
			'tack-quotes',
			'TackQuotes',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'tack_request_quote' ),
				'customerEmail'       => $this->current_customer_email(),
				'checkoutButtonLabel' => (string) get_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tack-quotes' ) ),
				// The seller's registration policy drives which fields the form renders. Null
				// when Tack is unreachable, in which case the JS falls back to a minimal
				// name+email form rather than rendering nothing — a shopper must still be able
				// to ask for a quote when our own API is having a bad day.
				'registration'        => $this->registration_config(),
				'i18n'                => array(
					'modalTitle'         => __( 'Request a Quote', 'tack-quotes' ),
					'firstNameLabel'     => __( 'First name', 'tack-quotes' ),
					'lastNameLabel'      => __( 'Last name', 'tack-quotes' ),
					'emailLabel'         => __( 'Email address', 'tack-quotes' ),
					'phoneLabel'         => __( 'Phone', 'tack-quotes' ),
					'companyHeading'     => __( 'Company details', 'tack-quotes' ),
					'companyNameLabel'   => __( 'Company name', 'tack-quotes' ),
					'buyingAsLabel'      => __( 'I am buying as', 'tack-quotes' ),
					'buyingAsIndividual' => __( 'An individual', 'tack-quotes' ),
					'buyingAsCompany'    => __( 'A company', 'tack-quotes' ),
					'optional'           => __( '(optional)', 'tack-quotes' ),
					'firstNameRequired'  => __( 'Please enter your first name.', 'tack-quotes' ),
					'companyRequired'    => __( 'Please complete the required company details.', 'tack-quotes' ),
					'awaitingApproval'   => __( 'Quote requested. Your company registration is awaiting approval by the seller.', 'tack-quotes' ),
					// Company field labels, keyed by the field names the API's
					// requiredCompanyFields returns. Anything not listed here falls back to a
					// humanised version of the key, so a new policy field still renders.
					'companyFields'      => array(
						'legalName'          => __( 'Legal name', 'tack-quotes' ),
						'taxId'              => __( 'Tax / VAT ID', 'tack-quotes' ),
						'registrationNumber' => __( 'Registration number', 'tack-quotes' ),
						'website'            => __( 'Website', 'tack-quotes' ),
						'addressLine1'       => __( 'Address', 'tack-quotes' ),
						'addressLine2'       => __( 'Address line 2', 'tack-quotes' ),
						'city'               => __( 'City', 'tack-quotes' ),
						'state'              => __( 'State / Province', 'tack-quotes' ),
						'postalCode'         => __( 'Postal code', 'tack-quotes' ),
						'country'            => __( 'Country', 'tack-quotes' ),
						'phone'              => __( 'Company phone', 'tack-quotes' ),
						'industry'           => __( 'Industry', 'tack-quotes' ),
						'employeeCount'      => __( 'Number of employees', 'tack-quotes' ),
					),
					'emailPlaceholder'   => __( 'you@example.com', 'tack-quotes' ),
					// Just "Note": the optional marker is appended generically by the form
					// builder now, and leaving it in the string rendered "Note (optional) (optional)".
					'noteLabel'          => __( 'Note', 'tack-quotes' ),
					'notePlaceholder'    => __( 'Anything the seller should know about this request…', 'tack-quotes' ),
					'submit'             => __( 'Send request', 'tack-quotes' ),
					'sending'            => __( 'Sending…', 'tack-quotes' ),
					'cancel'             => __( 'Cancel', 'tack-quotes' ),
					'close'              => __( 'Close', 'tack-quotes' ),
					'error'              => __( 'Could not create the quote. Please try again.', 'tack-quotes' ),
					'reload'             => __( 'Reload page', 'tack-quotes' ),
					'emailRequired'      => __( 'Please enter a valid email address.', 'tack-quotes' ),
					'success'            => __( 'Quote requested! Redirecting you to it now…', 'tack-quotes' ),
					'added'              => __( 'Added ✓', 'tack-quotes' ),
					'quoteListTitle'     => __( 'Your quote list', 'tack-quotes' ),
					'quoteListEmpty'     => __( 'No products added yet.', 'tack-quotes' ),
					'quoteListCount'     => __( 'Quote list', 'tack-quotes' ),
					'remove'             => __( 'Remove', 'tack-quotes' ),
				),
			)
		);
	}

	/**
	 * Best-effort email for pre-filling the modal: WooCommerce customer billing
	 * email first (covers logged-in and session-persisted guest checkouts),
	 * then the current WP user's account email.
	 *
	 * @return string
	 */
	private function current_customer_email() {
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$billing_email = WC()->customer->get_billing_email();
			if ( $billing_email ) {
				return $billing_email;
			}
		}
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && $user->user_email ) {
				return $user->user_email;
			}
		}
		return '';
	}

	/**
	 * Fetch the seller's registration policy for the storefront form.
	 *
	 * Deliberately tolerant: a null return means "render the minimal form", never "render
	 * nothing". The alternative — hiding the button when Tack is unreachable — loses a lead
	 * for a reason the shopper cannot see or fix.
	 *
	 * @return array|null
	 */
	private function registration_config() {
		if ( ! class_exists( 'Tack_Api_Client' ) ) {
			return null;
		}
		$client = new Tack_Api_Client();
		return $client->get_registration_config();
	}

	/**
	 * Product-page buttons — merchant-configurable: "Add to Quote" (adds the
	 * product to the browser-side quote list — never the WooCommerce cart),
	 * "Request a Quote" (submits a quote for just this product immediately),
	 * both, or neither.
	 */
	public function render_product_button() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$show_add_to_quote  = 'yes' === get_option( 'tack_quotes_show_add_to_quote', 'yes' );
		$show_request_quote = 'yes' === get_option( 'tack_quotes_show_request_quote', 'yes' );
		if ( ! $show_add_to_quote && ! $show_request_quote ) {
			return;
		}

		echo '<div class="tack-quote-buttons">';

		if ( $show_add_to_quote ) {
			$label = (string) get_option( 'tack_quotes_button_label', __( 'Add to Quote', 'tack-quotes' ) );
			$this->button(
				array(
					'product-id'    => $product->get_id(),
					'product-name'  => $product->get_name(),
					'product-sku'   => $product->get_sku(),
					'product-price' => wc_get_price_excluding_tax( $product ),
				),
				'tack-add-to-quote-btn',
				$label
			);
		}

		if ( $show_request_quote ) {
			$label = (string) get_option( 'tack_quotes_request_button_label', __( 'Request a Quote', 'tack-quotes' ) );
			$this->button( array( 'product-id' => $product->get_id() ), 'tack-quote-btn', $label );
		}

		echo '</div>';
	}

	/**
	 * Floating "quote list" button + drawer, printed once in the footer of
	 * every front-end page. Empty/hidden by JS until at least one product has
	 * been added. This — not the WooCommerce cart page — is where shoppers
	 * review what they've added and submit "Checkout as Quote".
	 */
	public function render_quote_list_drawer() {
		if ( is_admin() ) {
			return;
		}
		?>
		<div id="tack-quote-list-widget" class="tack-quote-list-widget" hidden>
			<button type="button" id="tack-quote-list-toggle" class="tack-quote-list-toggle">
				<?php esc_html_e( 'Quote list', 'tack-quotes' ); ?>
				(<span id="tack-quote-list-count">0</span>)
			</button>
			<div id="tack-quote-list-drawer" class="tack-quote-list-drawer" hidden>
				<div class="tack-quote-list-drawer-header">
					<strong><?php esc_html_e( 'Your quote list', 'tack-quotes' ); ?></strong>
					<button type="button" id="tack-quote-list-close" aria-label="<?php esc_attr_e( 'Close', 'tack-quotes' ); ?>">&times;</button>
				</div>
				<ul id="tack-quote-list-items" class="tack-quote-list-items"></ul>
				<button type="button" id="tack-quote-list-checkout" class="button tack-quote-btn tack-quote-list-checkout">
					<?php echo esc_html( (string) get_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tack-quotes' ) ) ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Output button markup with data attributes.
	 *
	 * @param array  $data      Data attributes (key is used verbatim as data-{key}).
	 * @param string $css_class CSS class selector the JS binds its click handler to.
	 * @param string $label     Visible button text.
	 */
	private function button( $data, $css_class, $label ) {
		$attrs = '';
		foreach ( $data as $k => $v ) {
			$attrs .= sprintf( ' data-%s="%s"', esc_attr( $k ), esc_attr( $v ) );
		}
		printf(
			'<button type="button" class="button %s"%s>%s</button>',
			esc_attr( $css_class ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above.
			esc_html( $label )
		);
	}

	/**
	 * Count this caller's recent quote requests and say whether they are over the limit.
	 *
	 * Deliberately coarse. It exists to make flooding expensive, not to be an authorization
	 * boundary: the client address is only ever a hint (behind a CDN or load balancer it is
	 * whatever the proxy chain reports, and that chain is forgeable unless the host is
	 * configured to trust it), so a determined attacker rotates addresses. What it does buy
	 * is that a single script cannot hold every PHP worker on the site with a loop.
	 *
	 * The counter is keyed on a SALTED HASH of the address rather than the address itself: an
	 * IP is personal data, transients live in the options table or a shared object cache, and
	 * a counter does not need to be able to name anybody.
	 *
	 * @return bool True when the caller is over the limit.
	 */
	private function rate_limit_exceeded() {
		$max = $this->rate_limit_max();
		if ( $max <= 0 ) {
			return false;
		}
		return (int) get_transient( $this->rate_limit_key() ) >= $max;
	}

	/**
	 * Count one quote request against this caller's allowance.
	 *
	 * Called immediately before the outbound API call, not at the top of the handler, and
	 * deliberately so. The expensive and abusable thing here is the request to TackQuote —
	 * it is what holds a worker open and what creates a lead — while a submission that fails
	 * validation returns in milliseconds with no outbound call at all. Charging quota for
	 * those would mean a shopper who mistypes their email address a few times locks
	 * themselves out of a form they are actively trying to use.
	 */
	private function record_rate_limit_hit() {
		if ( $this->rate_limit_max() <= 0 ) {
			return;
		}
		$key = $this->rate_limit_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_LIMIT_WINDOW );
	}

	/**
	 * The configured allowance.
	 *
	 * @return int Maximum requests per window. Zero or less disables the limit.
	 */
	private function rate_limit_max() {
		/**
		 * Filter the number of quote requests allowed per client per window.
		 *
		 * @param int $max Maximum requests. Zero or less disables the limit.
		 */
		return (int) apply_filters( 'tack_quotes_rate_limit_max', self::RATE_LIMIT_MAX );
	}

	/**
	 * Transient key identifying this caller's counter.
	 *
	 * @return string
	 */
	private function rate_limit_key() {
		// WC_Geolocation::get_ip_address() is WooCommerce's own client-address resolution, so
		// this agrees with how the rest of the store identifies a visitor instead of inventing
		// a second answer.
		$ip = '';
		if ( class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) WC_Geolocation::get_ip_address();
		}
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'tack_qr_' . substr( wp_hash( 'quote-request|' . $ip ), 0, 20 );
	}

	/**
	 * AJAX handler: build line items from a single product or the submitted
	 * quote list, then call the Tack API.
	 */
	public function handle_request() {
		/*
		 * `$die` is passed as false deliberately.
		 *
		 * At its default of true, `check_ajax_referer()` answers a failed check with
		 * `wp_die( -1, 403 )` — HTTP 403 and a body of literally `-1`, no JSON. The storefront
		 * JS has nothing to read there, so it fell back to its generic "Could not create the
		 * quote. Please try again." — advice that can never work, for the one failure the
		 * shopper could actually fix.
		 *
		 * And this is not an edge case. The nonce is printed into the page by
		 * wp_localize_script(), so on any store with full-page caching it is baked into cached
		 * HTML and stops verifying once it ages past the nonce lifetime (24h by default). From
		 * then on every quote request from that cached page fails identically and permanently,
		 * with no signal to the shopper or the merchant that a cache purge is the fix.
		 *
		 * A distinguishable, actionable answer does not make the cached-nonce problem go away
		 * — the real cure is to stop baking the nonce into cacheable HTML — but it turns
		 * "silently broken forever" into "reload the page".
		 */
		if ( ! check_ajax_referer( 'tack_request_quote', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_nonce_expired',
					'message' => __( 'This page has been open too long, or was served from a cache. Reload it and request the quote again.', 'tack-quotes' ),
					'reload'  => true,
				),
				403
			);
		}

		/*
		 * A nonce prevents CSRF. It does not prevent abuse.
		 *
		 * This handler is registered for `wp_ajax_nopriv_tack_request_quote` and the nonce it
		 * checks is printed into every page a logged-out visitor can load, so anybody can
		 * obtain one and replay this endpoint as fast as they like. Each hit holds a PHP-FPM
		 * worker open for the length of an outbound HTTP call — which is a store-wide denial
		 * of service on a small host, and an open channel for flooding the seller's TackQuote
		 * account with fake leads. Neither is a CSRF problem, so no nonce could have stopped
		 * either.
		 */
		if ( $this->rate_limit_exceeded() ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_rate_limited',
					'message' => __( 'Too many quote requests from this connection. Please wait a few minutes and try again.', 'tack-quotes' ),
				),
				429
			);
		}

		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$note         = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';

		/*
		 * Company details arrive as company[key]=value.
		 *
		 * Values are sanitised as the array is read — `map_deep()` applies
		 * `sanitize_text_field()` to every leaf — rather than one at a time inside the loop.
		 * Same result, but the raw superglobal is never the thing being iterated, which is
		 * what the coding standards are asking for and what makes it obvious by inspection
		 * that no unsanitised value can escape this block.
		 *
		 * Keys are then restricted to a safe charset. That allowlist is mirrored in the
		 * storefront JS, which renders these same names into HTML attributes.
		 */
		$company = array();
		if ( isset( $_POST['company'] ) && is_array( $_POST['company'] ) ) {
			$raw_company = map_deep( wp_unslash( (array) $_POST['company'] ), 'sanitize_text_field' );
			foreach ( $raw_company as $key => $value ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', $key ) ) {
					continue;
				}
				if ( is_scalar( $value ) ) {
					$company[ $key ] = (string) $value;
				}
			}
		}
		// The quote list, as JSON. sanitize_textarea_field() is safe to apply to it — the
		// payload is a flat array of integers keyed by product_id/variation_id/quantity, so
		// there is nothing in a well-formed body for it to strip — and a body that it does
		// alter would not have decoded into usable rows anyway.
		$items_json = isset( $_POST['items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['items'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;
		// Which VARIATION the shopper chose. A variable product's button carries the parent
		// id, so without this a quote for "X-Large" was recorded against the parent — wrong
		// SKU and the parent's (cheapest) price. Validated against the parent below, so it
		// cannot be used to quote some unrelated product.
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;

		// Free text from an unauthenticated endpoint needs a ceiling, or a single request can
		// post megabytes that we then forward to TackQuote and it stores. `mb_substr()` is
		// safe to call unconditionally: WordPress polyfills it in wp-includes/compat.php when
		// the mbstring extension is missing.
		$note = mb_substr( $note, 0, self::NOTE_MAX_LENGTH );

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'A valid email address is required.', 'tack-quotes' ) ), 400 );
		}

		if ( $items_json ) {
			$line_items = $this->quote_list_line_items( $items_json );
		} else {
			$line_items = $this->product_line_items( $product_id, $quantity, $variation_id );
		}

		if ( empty( $line_items ) ) {
			// Distinguish "you have not chosen options yet" from "there is nothing here".
			// Both produce no line items, but only one is the shopper's to fix, and the
			// generic message left them re-clicking a button that could never succeed.
			$parent          = $product_id ? wc_get_product( $product_id ) : null;
			$needs_variation = ! $items_json
				&& $parent instanceof WC_Product_Variable;

			wp_send_json_error(
				array(
					'message' => $needs_variation
						? __( 'Please choose the product options before requesting a quote.', 'tack-quotes' )
						: __( 'No products to quote.', 'tack-quotes' ),
				),
				400
			);
		}

		$payload = array(
			'buyerEmail' => $email,
			'note'       => $note,
			'source'     => 'woocommerce',
			'lineItems'  => $line_items,
		);

		// Buyer identity. Sent only when non-empty so an older storefront cache that still
		// posts email-only does not overwrite a stored name with blanks.
		if ( '' !== $first_name ) {
			$payload['firstName'] = $first_name;
		}
		if ( '' !== $last_name ) {
			$payload['lastName'] = $last_name;
		}
		if ( '' !== $phone ) {
			$payload['phone'] = $phone;
		}
		if ( '' !== $company_name ) {
			$payload['companyName'] = $company_name;
		}
		if ( ! empty( $company ) ) {
			$payload['company'] = $company;
		}

		/*
		 * The prices in $line_items are in the store's currency, so the quote has to say
		 * which currency that is. Without this Tack fell back to a hardcoded 'USD' and a
		 * store selling in EUR produced USD quotes with no error anywhere.
		 *
		 * `get_woocommerce_currency()` returns the currently selected currency code (per
		 * WooCommerce's multi-currency developer docs), which is the one the prices above
		 * were rendered in. Guarded with function_exists because this file is also
		 * reachable when WooCommerce is inactive. Sent only when it looks like an ISO 4217
		 * alpha-3 code — otherwise omitted, so Tack applies the tenant's configured
		 * currency instead of receiving junk.
		 */
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtoupper( trim( (string) get_woocommerce_currency() ) );

			if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				$payload['currency'] = $currency;
			}
		}

		$this->record_rate_limit_hit();

		$client = new Tack_Api_Client();
		$result = $client->create_quote_request( $payload );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		wp_send_json_success(
			array(
				'quoteId'          => isset( $result['id'] ) ? $result['id'] : null,
				'quoteNumber'      => isset( $result['quoteNumber'] ) ? sanitize_text_field( (string) $result['quoteNumber'] ) : null,
				'portalUrl'        => isset( $result['portalUrl'] ) ? esc_url_raw( $result['portalUrl'] ) : ( isset( $result['quoteUrl'] ) ? esc_url_raw( $result['quoteUrl'] ) : '' ),
				// Forwarded so the storefront can say "awaiting approval" instead of implying
				// the buyer portal is ready to use. Without this the shopper is redirected to a
				// login they cannot pass yet.
				'awaitingApproval' => ! empty( $result['awaitingApproval'] ),
				'company'          => isset( $result['company'] ) && is_array( $result['company'] )
					? array(
						'name'   => isset( $result['company']['name'] ) ? sanitize_text_field( (string) $result['company']['name'] ) : '',
						'status' => isset( $result['company']['status'] ) ? sanitize_text_field( (string) $result['company']['status'] ) : '',
					)
					: null,
			)
		);
	}

	/**
	 * Narrow a product to the thing actually being quoted, or reject it.
	 *
	 * Shared by both entry points — the single-product button and the quote list — because
	 * they had drifted: the single-product path was fixed to honour the chosen variation
	 * while the quote list still resolved the parent, so the same product quoted correctly
	 * one way and incorrectly the other.
	 *
	 * Returns the variation when one was chosen and it really belongs to this product, the
	 * product itself when it is not variable, or NULL when it is variable and no valid
	 * variation was supplied.
	 *
	 * Rejecting rather than falling back to the parent is deliberate. A variable parent's
	 * SKU is not something the store can fulfil, and its price is the cheapest variation's,
	 * so quoting it is both the wrong item and an underquote. WooCommerce takes the same
	 * position in its own UI: variation-add-to-cart-button.php ships
	 * `<input type="hidden" name="variation_id" value="0">` and core's frontend JS holds the
	 * add-to-cart button in `disabled wc-variation-selection-needed` until a purchasable
	 * variation is found.
	 *
	 * The variation id is caller-supplied, so it is only honoured after confirming its
	 * parent — otherwise any product's id could be passed as a "variation" of another and
	 * have its price quoted under that product's name.
	 *
	 * @param WC_Product $product      The product (a parent, for a variable product).
	 * @param int        $variation_id Caller-supplied variation id, or 0.
	 * @return WC_Product|null
	 */
	private function resolve_purchasable( $product, $variation_id ) {
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product_Variation
				&& (int) $variation->get_parent_id() === (int) $product->get_id() ) {
				// get_name() on a variation already carries the attribute summary
				// ("Cut-Resistant Gloves - X-Large"), which is what a salesperson needs on
				// the quote line.
				return $variation;
			}
		}

		if ( $product instanceof WC_Product_Variable ) {
			return null;
		}

		return $product;
	}

	/**
	 * Build a single-product line item.
	 *
	 * When the shopper picked a variation, THAT is what gets quoted: a variation carries
	 * its own SKU and its own price, and the button on a variable product page can only
	 * carry the parent id. Quoting the parent recorded the wrong SKU at the parent's
	 * price — on this devstore's gloves, "X-Large" (TQ-GLOVE-XL, 47.50) was quoted as
	 * TQ-GLOVE-PARENT at 42.00, i.e. the wrong item underpriced by 5.50 a unit.
	 *
	 * The variation id is caller-supplied, so it is only honoured after confirming it is
	 * really a variation OF THIS PRODUCT. Otherwise anyone could post any product's id as
	 * a "variation" and have its price quoted under another product's page.
	 *
	 * @param int $product_id   Product ID (the parent, for a variable product).
	 * @param int $quantity     Quantity.
	 * @param int $variation_id Selected variation ID, or 0.
	 * @return array
	 */
	private function product_line_items( $product_id, $quantity, $variation_id = 0 ) {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$product = $this->resolve_purchasable( $product, $variation_id );
		if ( ! $product ) {
			return array();
		}

		return array(
			array(
				'sku'               => $product->get_sku(),
				'name'              => $product->get_name(),
				'quantity'          => $quantity,
				'unitPrice'         => (float) wc_get_price_excluding_tax( $product ),
				'externalProductId' => (string) $product->get_id(),
			),
		);
	}

	/**
	 * Build line items from the browser-submitted quote list. Only
	 * `product_id` + `quantity` are trusted from the client — name/SKU/price
	 * are always re-derived from the live product record here, the same way
	 * `product_line_items()` already does, so a tampered client payload can't
	 * misstate what's actually being quoted.
	 *
	 * @param string $items_json JSON-encoded array of {product_id, quantity}.
	 * @return array
	 */
	private function quote_list_line_items( $items_json ) {
		// Bounded before decoding, because json_decode() on an unbounded string from an
		// unauthenticated endpoint is the cheap half of the attack; the expensive half is the
		// wc_get_product() call this does per row.
		if ( strlen( (string) $items_json ) > self::ITEMS_MAX_BYTES ) {
			return array();
		}

		$decoded = json_decode( $items_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$items = array();
		foreach ( $decoded as $row ) {
			if ( count( $items ) >= self::ITEMS_MAX ) {
				break;
			}
			if ( ! is_array( $row ) || empty( $row['product_id'] ) ) {
				continue;
			}
			$product_id   = absint( $row['product_id'] );
			$quantity     = isset( $row['quantity'] ) ? max( 1, absint( $row['quantity'] ) ) : 1;
			$variation_id = isset( $row['variation_id'] ) ? absint( $row['variation_id'] ) : 0;
			$product      = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			// Same rule as the single-product path: quote the chosen variation, and skip a
			// variable product whose variation is missing or does not belong to it rather
			// than silently quoting the parent.
			$product = $this->resolve_purchasable( $product, $variation_id );
			if ( ! $product ) {
				continue;
			}
			$items[] = array(
				'sku'               => $product->get_sku(),
				'name'              => $product->get_name(),
				'quantity'          => $quantity,
				'unitPrice'         => (float) wc_get_price_excluding_tax( $product ),
				'externalProductId' => (string) $product->get_id(),
			);
		}
		return $items;
	}
}
