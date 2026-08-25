<?php
/**
 * The slice of WooCommerce's order model that `Tack_Order_Sync::build_payload()`
 * reads, as plain doubles, so the outbound payload can be asserted without a
 * WooCommerce install.
 *
 * WHAT THIS DOES AND DOES NOT PROVE. These doubles return what they were handed.
 * They therefore prove the MAPPING — that `get_shipping_city()` reaches
 * `shipping.city`, that a blank field is dropped, that `modifiedAt` is sent but
 * not hashed. They prove nothing about what WooCommerce itself returns: whether
 * `get_subtotal()` is really pre-coupon, whether `get_formatted_meta_data()`
 * really resolves `pa_color` to "Colour: Blue", whether `get_shipping_phone()`
 * exists on the store's version. Asserting those here would be this repository's
 * own documented failure mode — a test that encodes the same assumptions as the
 * code it tests. They are asserted against a real store by the WP-CLI contract
 * test in `tests/test-order-payload.php`, which is the other half of this pair.
 *
 * @package TackQuotes
 */

/** WooCommerce's date object. `date()` is the only method the payload calls. */
class WC_DateTime extends DateTime {
	public function date( $format ) { return $this->format( $format ); }
}

/** Only ever used for `$product instanceof WC_Product` plus the SKU. */
class WC_Product {
	private $sku;
	public function __construct( $sku = '' ) { $this->sku = $sku; }
	public function get_sku() { return $this->sku; }
}

/** One formatted meta entry, as `get_formatted_meta_data()` yields them. */
class TackStubMeta {
	public $display_key;
	public $display_value;
	public function __construct( $key, $value ) {
		$this->display_key   = $key;
		// WooCommerce runs display values through wpautop() for output, so they
		// arrive wrapped in markup. Reproduced because stripping it is a
		// deliberate departure in item_meta_payload() and must stay covered.
		$this->display_value = '<p>' . $value . '</p>';
	}
}

/** A product line item. */
class TackStubLineItem {
	public $data;
	public function __construct( array $data ) { $this->data = $data; }
	public function get_product() { return $this->data['product'] ?? false; }
	public function get_product_id() { return $this->data['product_id'] ?? 0; }
	public function get_variation_id() { return $this->data['variation_id'] ?? 0; }
	public function get_name() { return $this->data['name'] ?? ''; }
	public function get_quantity() { return $this->data['quantity'] ?? 0; }
	public function get_subtotal() { return $this->data['subtotal'] ?? 0; }
	public function get_total() { return $this->data['total'] ?? 0; }
	public function get_total_tax() { return $this->data['tax'] ?? 0; }
	public function get_formatted_meta_data( $prefix = '_', $include_all = false ) {
		$out = array();
		foreach ( (array) ( $this->data['meta'] ?? array() ) as $k => $v ) {
			$out[] = new TackStubMeta( $k, $v );
		}
		return $out;
	}
}

/** A shipping line. */
class TackStubShippingLine {
	private $d;
	public function __construct( array $d ) { $this->d = $d; }
	public function get_method_id() { return $this->d['method_id'] ?? ''; }
	public function get_method_title() { return $this->d['method_title'] ?? ''; }
	public function get_total() { return $this->d['total'] ?? 0; }
	public function get_total_tax() { return $this->d['tax'] ?? 0; }
}

/** A fee line. */
class TackStubFeeLine {
	private $d;
	public function __construct( array $d ) { $this->d = $d; }
	public function get_name() { return $this->d['name'] ?? ''; }
	public function get_total() { return $this->d['total'] ?? 0; }
	public function get_total_tax() { return $this->d['tax'] ?? 0; }
}

/**
 * The order. Every getter build_payload() calls, backed by an array so a test
 * can state exactly what the store holds.
 */
class WC_Order {
	private $d;
	public function __construct( array $d = array() ) { $this->d = $d; }
	private function v( $k, $default = '' ) { return array_key_exists( $k, $this->d ) ? $this->d[ $k ] : $default; }

	public function get_id() { return $this->v( 'id', 0 ); }
	public function get_order_number() { return $this->v( 'order_number' ); }
	public function get_status() { return $this->v( 'status' ); }
	public function get_currency() { return $this->v( 'currency' ); }

	public function get_subtotal() { return $this->v( 'subtotal', 0 ); }
	public function get_discount_total() { return $this->v( 'discount_total', 0 ); }
	public function get_shipping_total() { return $this->v( 'shipping_total', 0 ); }
	public function get_total_tax() { return $this->v( 'total_tax', 0 ); }
	public function get_total() { return $this->v( 'total', 0 ); }

	public function get_billing_first_name() { return $this->v( 'billing_first_name' ); }
	public function get_billing_last_name() { return $this->v( 'billing_last_name' ); }
	public function get_billing_company() { return $this->v( 'billing_company' ); }
	public function get_billing_address_1() { return $this->v( 'billing_address_1' ); }
	public function get_billing_address_2() { return $this->v( 'billing_address_2' ); }
	public function get_billing_city() { return $this->v( 'billing_city' ); }
	public function get_billing_state() { return $this->v( 'billing_state' ); }
	public function get_billing_postcode() { return $this->v( 'billing_postcode' ); }
	public function get_billing_country() { return $this->v( 'billing_country' ); }
	public function get_billing_email() { return $this->v( 'billing_email' ); }
	public function get_billing_phone() { return $this->v( 'billing_phone' ); }

	public function get_shipping_first_name() { return $this->v( 'shipping_first_name' ); }
	public function get_shipping_last_name() { return $this->v( 'shipping_last_name' ); }
	public function get_shipping_company() { return $this->v( 'shipping_company' ); }
	public function get_shipping_address_1() { return $this->v( 'shipping_address_1' ); }
	public function get_shipping_address_2() { return $this->v( 'shipping_address_2' ); }
	public function get_shipping_city() { return $this->v( 'shipping_city' ); }
	public function get_shipping_state() { return $this->v( 'shipping_state' ); }
	public function get_shipping_postcode() { return $this->v( 'shipping_postcode' ); }
	public function get_shipping_country() { return $this->v( 'shipping_country' ); }
	public function get_shipping_phone() { return $this->v( 'shipping_phone' ); }

	public function get_payment_method() { return $this->v( 'payment_method' ); }
	public function get_payment_method_title() { return $this->v( 'payment_method_title' ); }
	public function get_transaction_id() { return $this->v( 'transaction_id' ); }
	public function needs_payment() { return $this->v( 'needs_payment', false ); }

	public function get_customer_id() { return $this->v( 'customer_id', 0 ); }
	public function get_customer_note() { return $this->v( 'customer_note' ); }
	public function get_coupon_codes() { return $this->v( 'coupon_codes', array() ); }

	public function get_date_created() { return $this->v( 'date_created', null ); }
	public function get_date_modified() { return $this->v( 'date_modified', null ); }
	public function get_date_paid() { return $this->v( 'date_paid', null ); }
	public function get_date_completed() { return $this->v( 'date_completed', null ); }

	/** WooCommerce's own signature: '' (or 'line_item') means product lines. */
	public function get_items( $types = 'line_item' ) {
		if ( 'shipping' === $types ) {
			return $this->v( 'shipping_lines', array() );
		}
		return $this->v( 'line_items', array() );
	}
	public function get_fees() { return $this->v( 'fees', array() ); }
}
