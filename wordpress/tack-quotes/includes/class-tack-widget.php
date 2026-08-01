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
		wp_enqueue_style( 'tack-quotes', TACK_QUOTES_URL . 'assets/css/tack-quotes.css', array(), TACK_QUOTES_VERSION );
		wp_enqueue_script( 'tack-quotes', TACK_QUOTES_URL . 'assets/js/tack-quotes.js', array( 'jquery' ), TACK_QUOTES_VERSION, true );
		wp_localize_script(
			'tack-quotes',
			'TackQuotes',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'tack_request_quote' ),
				'customerEmail' => $this->current_customer_email(),
				'checkoutButtonLabel' => (string) get_option( 'tack_quotes_checkout_button_label', __( 'Checkout as Quote', 'tack-quotes' ) ),
				'i18n'          => array(
					'modalTitle'        => __( 'Request a Quote', 'tack-quotes' ),
					'emailLabel'        => __( 'Email address', 'tack-quotes' ),
					'emailPlaceholder'  => __( 'you@example.com', 'tack-quotes' ),
					'noteLabel'         => __( 'Note (optional)', 'tack-quotes' ),
					'notePlaceholder'   => __( 'Anything the seller should know about this request…', 'tack-quotes' ),
					'submit'            => __( 'Send request', 'tack-quotes' ),
					'sending'           => __( 'Sending…', 'tack-quotes' ),
					'cancel'            => __( 'Cancel', 'tack-quotes' ),
					'close'             => __( 'Close', 'tack-quotes' ),
					'error'             => __( 'Could not create the quote. Please try again.', 'tack-quotes' ),
					'emailRequired'     => __( 'Please enter a valid email address.', 'tack-quotes' ),
					'success'           => __( 'Quote requested! Redirecting you to it now…', 'tack-quotes' ),
					'added'             => __( 'Added ✓', 'tack-quotes' ),
					'quoteListTitle'    => __( 'Your quote list', 'tack-quotes' ),
					'quoteListEmpty'    => __( 'No products added yet.', 'tack-quotes' ),
					'quoteListCount'    => __( 'Quote list', 'tack-quotes' ),
					'remove'            => __( 'Remove', 'tack-quotes' ),
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
	 * @param array  $data  Data attributes (key is used verbatim as data-{key}).
	 * @param string $class CSS class selector the JS binds its click handler to.
	 * @param string $label Visible button text.
	 */
	private function button( $data, $class, $label ) {
		$attrs = '';
		foreach ( $data as $k => $v ) {
			$attrs .= sprintf( ' data-%s="%s"', esc_attr( $k ), esc_attr( $v ) );
		}
		printf(
			'<button type="button" class="button %s"%s>%s</button>',
			esc_attr( $class ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above.
			esc_html( $label )
		);
	}

	/**
	 * AJAX handler: build line items from a single product or the submitted
	 * quote list, then call the Tack API.
	 */
	public function handle_request() {
		check_ajax_referer( 'tack_request_quote', 'nonce' );

		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$note       = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'A valid email address is required.', 'tack-quotes' ) ), 400 );
		}

		if ( $items_json ) {
			$line_items = $this->quote_list_line_items( $items_json );
		} else {
			$line_items = $this->product_line_items( $product_id, $quantity );
		}

		if ( empty( $line_items ) ) {
			wp_send_json_error( array( 'message' => __( 'No products to quote.', 'tack-quotes' ) ), 400 );
		}

		$client = new Tack_Api_Client();
		$result = $client->create_quote_request(
			array(
				'buyerEmail' => $email,
				'note'       => $note,
				'source'     => 'woocommerce',
				'lineItems'  => $line_items,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		wp_send_json_success(
			array(
				'quoteId'  => isset( $result['id'] ) ? $result['id'] : null,
				'portalUrl' => isset( $result['portalUrl'] ) ? esc_url_raw( $result['portalUrl'] ) : ( isset( $result['quoteUrl'] ) ? esc_url_raw( $result['quoteUrl'] ) : '' ),
			)
		);
	}

	/**
	 * Build a single-product line item.
	 *
	 * @param int $product_id Product ID.
	 * @param int $quantity   Quantity.
	 * @return array
	 */
	private function product_line_items( $product_id, $quantity ) {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return array();
		}
		return array(
			array(
				'sku'                => $product->get_sku(),
				'name'               => $product->get_name(),
				'quantity'           => $quantity,
				'unitPrice'          => (float) wc_get_price_excluding_tax( $product ),
				'externalProductId'  => (string) $product->get_id(),
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
		$decoded = json_decode( $items_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$items = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) || empty( $row['product_id'] ) ) {
				continue;
			}
			$product_id = absint( $row['product_id'] );
			$quantity   = isset( $row['quantity'] ) ? max( 1, absint( $row['quantity'] ) ) : 1;
			$product    = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
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
