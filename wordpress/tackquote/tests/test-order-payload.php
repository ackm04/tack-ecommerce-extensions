<?php
/**
 * Contract test for the outbound order-sync payload.
 *
 * WHY THIS EXISTS. The payload shipped for months carrying eleven fields and no address of
 * any kind. Nothing failed: `php -l` passed, phpcs passed, the HTTP call returned 200, and
 * the receiving end stored what it was given. A payload can only ever lose a field silently,
 * so the field list needs a test that names every field out loud.
 *
 * WHY IT IS A WP-CLI SCRIPT AND NOT PHPUNIT. Every assertion below is about what WooCommerce
 * itself returns from a real order — `get_subtotal()` after a coupon has been applied,
 * `get_formatted_meta_data()` resolving a variation attribute from its term slug,
 * `get_shipping_phone()` existing at all. Mock WooCommerce away and the test asserts this
 * file's own assumptions instead, which is exactly how the repository's connector audit found
 * tests that encoded the same errors as the code. There is no WordPress test harness here, so
 * this runs against a real store:
 *
 *   wp eval-file wp-content/plugins/tackquote/tests/test-order-payload.php
 *
 * Exits non-zero on failure, so it is usable as a gate wherever a WooCommerce install is
 * available. Excluded from the distributed ZIP by bin/build.sh — WordPress.org judges
 * everything in the package as plugin code, and this plugin has already been rejected once
 * for shipping a build script.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Resolve a dot path against nested arrays.
 *
 * @param array  $data Payload.
 * @param string $path Dot path, e.g. 'billing.postcode' or 'lineItems.0.sku'.
 * @return mixed|null Null when any segment is absent.
 */
function tack_quotes_test_dig( $data, $path ) {
	$cursor = $data;
	foreach ( explode( '.', $path ) as $segment ) {
		if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
			$cursor = $cursor[ $segment ];
			continue;
		}
		return null;
	}
	return $cursor;
}

/**
 * Fields the payload must carry, as dot paths. A path that is present but blank fails: "the
 * key exists" was never the problem, "the key is empty" was.
 *
 * Read this list as the wire contract. Removing a getter from build_payload() has to break a
 * line here, or the next silent regression looks exactly like the last one.
 *
 * @return string[]
 */
function tack_quotes_test_required_fields() {
	return array(
		'externalOrderId',
		'orderNumber',
		'status',
		'currency',

		/*
		 * Money, each figure separately. `subtotal` used to be filled in with `total` on the
		 * receiving end, which is wrong for every store that charges tax or shipping — so it
		 * is additionally asserted below to DIFFER from total on a fixture that has both.
		 */
		'subtotal',
		'discountTotal',
		'shippingTotal',
		'taxTotal',
		'total',

		/*
		 * The flat buyer identity, kept alongside the `billing` object so a store can
		 * update this plugin before the receiving API is updated, or the other way round.
		 * Listed because "we already send billing.email" is exactly the reasoning that
		 * would delete them.
		 */
		'buyerEmail',
		'buyerName',
		'buyerCompany',
		'buyerPhone',

		// The reported defect, in full.
		'billing.firstName',
		'billing.lastName',
		'billing.company',
		'billing.address1',
		'billing.address2',
		'billing.city',
		'billing.state',
		'billing.postcode',
		'billing.country',
		'billing.email',
		'billing.phone',
		'shipping.firstName',
		'shipping.lastName',
		'shipping.company',
		'shipping.address1',
		'shipping.address2',
		'shipping.city',
		'shipping.state',
		'shipping.postcode',
		'shipping.country',

		/*
		 * WooCommerce holds a shipping phone separately from the billing one (`@since 5.6.0`),
		 * and a delivery contact is the entire point of it. Listed because a mutation that
		 * blanked this one field passed the first version of this test.
		 */
		'shipping.phone',

		// Payment, so an order can be reconciled against the gateway.
		'payment.method',
		'payment.methodTitle',

		'customerNote',
		'lineItems.0.externalProductId',
		'lineItems.0.sku',
		'lineItems.0.name',
		'lineItems.0.quantity',
		'lineItems.0.subtotal',
		'lineItems.0.total',
		'lineItems.0.tax',
		'createdAt',
		'source',
	);
}

/**
 * Build the fixture order: a full billing address, a DIFFERENT shipping address, a taxed
 * line, a shipping line and a customer note. Created here rather than borrowed from the store
 * so the test is self-contained and states what it depends on.
 *
 * @return array{order: WC_Order, product: WC_Product_Simple}
 */
function tack_quotes_test_make_fixture() {
	$product = new WC_Product_Simple();
	$product->set_name( 'Payload contract fixture' );
	$product->set_sku( 'TACK-CONTRACT-FIXTURE-' . wp_rand( 1000, 9999 ) );
	$product->set_regular_price( '25.00' );
	$product->set_status( 'publish' );
	$product_id = $product->save();

	$fixture = wc_create_order( array( 'status' => 'pending' ) );

	$fixture->set_address(
		array(
			'first_name' => 'Ada',
			'last_name'  => 'Ngozi',
			'company'    => 'Ngozi Fabrication Ltd',
			'address_1'  => '17 Wharf Road',
			'address_2'  => 'Unit 4',
			'city'       => 'Sacramento',
			'state'      => 'CA',
			'postcode'   => '95811',
			'country'    => 'US',
			'email'      => 'ada@ngozi-fab.example',
			'phone'      => '+1 916 555 0101',
		),
		'billing'
	);

	$fixture->set_address(
		array(
			'first_name' => 'Goods',
			'last_name'  => 'Inwards',
			'company'    => 'Ngozi Fabrication Ltd',
			'address_1'  => '2 Dockside Lane',
			'address_2'  => 'Bay B',
			'city'       => 'Oakland',
			'state'      => 'CA',
			'postcode'   => '94607',
			'country'    => 'US',
		),
		'shipping'
	);

	// Set through the typed setter, guarded the same way build_payload() guards the getter:
	// shipping phone is `@since 5.6.0`.
	if ( method_exists( $fixture, 'set_shipping_phone' ) ) {
		$fixture->set_shipping_phone( '+1 510 555 0177' );
	}

	$fixture->add_product( wc_get_product( $product_id ), 3 );

	$shipping_line = new WC_Order_Item_Shipping();
	$shipping_line->set_method_id( 'flat_rate' );
	$shipping_line->set_method_title( 'Flat rate' );
	$shipping_line->set_total( '9.00' );
	$fixture->add_item( $shipping_line );

	$fixture->set_payment_method( 'cheque' );
	$fixture->set_payment_method_title( 'Check payments' );
	$fixture->set_customer_note( 'Contract test fixture.' );
	$fixture->calculate_taxes();
	$fixture->calculate_totals( false );
	$fixture->save();

	return array(
		'order'   => $fixture,
		'product' => $product,
	);
}

/**
 * Check one payload against the contract.
 *
 * @param array    $payload Payload from build_payload().
 * @param WC_Order $order   The order it was built from.
 * @return string[] Failure messages; empty means the contract holds.
 */
function tack_quotes_test_assert_payload( $payload, $order ) {
	$failures = array();

	foreach ( tack_quotes_test_required_fields() as $field_path ) {
		$value = tack_quotes_test_dig( $payload, $field_path );
		if ( null === $value || '' === $value || array() === $value ) {
			$failures[] = "missing or blank: {$field_path}";
		}
	}

	/*
	 * subtotal must be the goods, not a copy of the total. With a shipping line present the
	 * two cannot legitimately be equal, and equality is the exact shape of the bug that was
	 * shipped on the receiving end.
	 */
	if ( isset( $payload['subtotal'], $payload['total'] ) ) {
		if ( (float) $payload['total'] === (float) $payload['subtotal'] ) {
			$failures[] = 'subtotal equals total on an order with a shipping line — subtotal is not the real subtotal';
		}
		if ( 75.0 !== (float) $payload['subtotal'] ) {
			$failures[] = 'subtotal is ' . $payload['subtotal'] . ', expected 75.00 (3 x 25.00 goods)';
		}
	}

	// The shipping address must be the SHIPPING address. Filling both from billing would
	// satisfy every presence check above while sending the goods to the wrong city.
	if ( ( $payload['shipping']['city'] ?? '' ) === ( $payload['billing']['city'] ?? '' ) ) {
		$failures[] = 'shipping.city equals billing.city — the shipping address is not being read';
	}

	/*
	 * `meta` carries the chosen variation attributes. This fixture is a simple product, so the
	 * array is legitimately empty and cannot go in the non-blank list — but the KEY has to
	 * exist, or a variable-product order silently loses "Large / Blue".
	 */
	if ( ! array_key_exists( 'meta', $payload['lineItems'][0] ?? array() ) ) {
		$failures[] = 'lineItems[0].meta key absent — variation attributes have nowhere to go';
	}

	// A shipping line exists on the fixture, so its method must arrive.
	if ( empty( $payload['shippingLines'][0]['methodTitle'] ) ) {
		$failures[] = 'shippingLines[0].methodTitle missing';
	}

	/*
	 * modifiedAt must be SENT but must NOT be hashed — see VOLATILE_PAYLOAD_KEYS. Under HPOS,
	 * recording a push writes order meta, and OrdersTableDataStore::after_meta_change() stamps
	 * a new modified date when it does; hashing it would mean the key a push just recorded is
	 * already stale, and de-duplication would never converge.
	 */
	if ( ! array_key_exists( 'modifiedAt', $payload ) ) {
		$failures[] = 'modifiedAt is not being sent';
	}

	$sync       = new Tack_Order_Sync();
	$key_method = new ReflectionMethod( $sync, 'idempotency_key' );
	$key_method->setAccessible( true );

	$fresh               = $payload;
	$fresh['modifiedAt'] = '1999-01-01T00:00:00+00:00';
	if ( $key_method->invoke( $sync, $order, $payload ) !== $key_method->invoke( $sync, $order, $fresh ) ) {
		$failures[] = 'idempotency key changes with modifiedAt — de-duplication will never converge under HPOS';
	}

	return $failures;
}

/**
 * Run the contract test.
 *
 * Everything lives in functions because Plugin Check flags every top-level variable in a
 * plugin file as an unprefixed global — and it is right to: `wp eval-file` runs the file in
 * the global scope, so `$order` and `$payload` really would be globals.
 *
 * @return void
 */
function tack_quotes_test_order_payload() {
	if ( ! class_exists( 'Tack_Order_Sync' ) ) {
		WP_CLI::error( 'Tack_Order_Sync is not loaded — is the plugin active?' );
	}

	$fixture = tack_quotes_test_make_fixture();

	try {
		$sync = new Tack_Order_Sync();
		$ref  = new ReflectionMethod( $sync, 'build_payload' );
		$ref->setAccessible( true );

		$failures = tack_quotes_test_assert_payload(
			$ref->invoke( $sync, wc_get_order( $fixture['order']->get_id() ) ),
			$fixture['order']
		);
	} finally {
		// The fixture is this test's own row, not a merchant's record, so removing it is not
		// the "never delete an order" rule being bent — leaving one behind per run would be.
		$fixture['order']->delete( true );
		$fixture['product']->delete( true );
	}

	if ( $failures ) {
		foreach ( $failures as $failure ) {
			WP_CLI::log( 'FAIL  ' . $failure );
		}
		WP_CLI::error( count( $failures ) . ' payload contract failure(s)' );
	}

	WP_CLI::success(
		'order payload contract holds (' . count( tack_quotes_test_required_fields() ) . ' required fields present)'
	);
}

tack_quotes_test_order_payload();
