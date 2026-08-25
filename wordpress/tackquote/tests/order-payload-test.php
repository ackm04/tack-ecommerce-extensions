<?php
/**
 * Offline contract test for the outbound order-sync payload.
 *
 * WHY THIS EXISTS. The payload shipped for months carrying eleven fields and no
 * address of any kind: `php -l` passed, phpcs passed, the HTTP call returned 200,
 * and the receiving end stored what it was given. A payload can only ever lose a
 * field silently, so the field list needs a test that names every field out loud.
 *
 * This is the half of that gate which runs anywhere. Its sibling,
 * `tests/test-order-payload.php`, runs the same contract through `wp eval-file`
 * against a REAL WooCommerce order, and is the only one of the two that can tell
 * you what WooCommerce's own getters return. Neither replaces the other. See
 * `tests/wc-stubs.php` for exactly which claims these doubles can and cannot
 * support.
 *
 * Loaded by tests/run.php; `check()` and $failures come from there.
 *
 * @package TackQuotes
 */

require_once __DIR__ . '/wc-stubs.php';

/**
 * Resolve a dot path against nested arrays.
 *
 * @param array  $data Payload.
 * @param string $path Dot path, e.g. 'billing.postcode'.
 * @return mixed|null Null when any segment is absent.
 */
function tack_dig( $data, $path ) {
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
 * Fields the payload must carry, as dot paths. A path that is present but blank
 * fails: "the key exists" was never the problem, "the key is empty" was.
 *
 * Read this list as the wire contract. Removing a getter from build_payload()
 * has to break a line here.
 *
 * @return string[]
 */
function tack_required_payload_fields() {
	return array(
		'externalOrderId',
		'orderNumber',
		'status',
		'currency',

		// Money, each figure separately. `subtotal` was the expensive omission:
		// the receiving end filled it in with `total`, so every order from a
		// store that charges tax or shipping claimed its goods cost what the
		// customer paid.
		'subtotal',
		'discountTotal',
		'shippingTotal',
		'taxTotal',
		'total',

		// The flat buyer identity, kept alongside `billing` so a store can
		// update this plugin before the receiving API is updated, or vice versa.
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
 * The fixture: a full billing address, a DIFFERENT shipping address, a plain
 * line and a variation line, a shipping line, a fee, a coupon and a note.
 *
 * @return WC_Order
 */
function tack_payload_fixture() {
	return new WC_Order(
		array(
			'id'                 => 4242,
			'order_number'       => 'WC-4242',
			'status'             => 'processing',
			'currency'           => 'USD',

			'subtotal'           => 75.00,
			'discount_total'     => 5.00,
			'shipping_total'     => 9.00,
			'total_tax'          => 6.30,
			'total'              => 85.30,

			'billing_first_name' => 'Ada',
			'billing_last_name'  => 'Ngozi',
			'billing_company'    => 'Ngozi Fabrication Ltd',
			'billing_address_1'  => '17 Wharf Road',
			'billing_address_2'  => 'Unit 4',
			'billing_city'       => 'Sacramento',
			'billing_state'      => 'CA',
			'billing_postcode'   => '95811',
			'billing_country'    => 'US',
			'billing_email'      => 'ada@ngozi-fab.example',
			'billing_phone'      => '+1 916 555 0101',

			'shipping_first_name' => 'Goods',
			'shipping_last_name'  => 'Inwards',
			'shipping_company'    => 'Ngozi Fabrication Ltd',
			'shipping_address_1'  => '2 Dockside Lane',
			'shipping_address_2'  => 'Bay B',
			'shipping_city'       => 'Oakland',
			'shipping_state'      => 'CA',
			'shipping_postcode'   => '94607',
			'shipping_country'    => 'US',
			'shipping_phone'      => '+1 510 555 0177',

			'payment_method'       => 'cheque',
			'payment_method_title' => 'Check payments',
			'transaction_id'       => 'ch_TEST_0001',
			'needs_payment'        => false,

			'customer_id'    => 0,
			'customer_note'  => 'Deliver to the loading bay.',
			'coupon_codes'   => array( 'TRADE5' ),

			'date_created'   => new WC_DateTime( '2026-08-01T10:00:00+00:00' ),
			'date_modified'  => new WC_DateTime( '2026-08-02T11:30:00+00:00' ),
			'date_paid'      => new WC_DateTime( '2026-08-01T10:05:00+00:00' ),
			'date_completed' => null,

			'line_items' => array(
				new TackStubLineItem(
					array(
						'product'    => new WC_Product( 'TACK-SIMPLE-1' ),
						'product_id' => 11,
						'name'       => 'Steel bracket',
						'quantity'   => 3,
						'subtotal'   => 75.00,
						'total'      => 70.00,
						'tax'        => 5.60,
					)
				),
				new TackStubLineItem(
					array(
						'product'      => new WC_Product( 'TACK-VAR-L-BLUE' ),
						'product_id'   => 22,
						'variation_id' => 33,
						'name'         => 'T-Shirt',
						'quantity'     => 1,
						'subtotal'     => 20.00,
						'total'        => 20.00,
						'tax'          => 0.70,
						'meta'         => array(
							'Size'   => 'Large',
							'Colour' => 'Blue',
						),
					)
				),
			),

			'shipping_lines' => array(
				new TackStubShippingLine(
					array(
						'method_id'    => 'flat_rate',
						'method_title' => 'Flat rate',
						'total'        => 9.00,
						'tax'          => 0.00,
					)
				),
			),

			'fees' => array(
				new TackStubFeeLine(
					array(
						'name'  => 'Handling',
						'total' => 2.50,
						'tax'   => 0.00,
					)
				),
			),
		)
	);
}

/**
 * Call one of Tack_Order_Sync's private methods.
 *
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function tack_sync_call( $method, array $args ) {
	static $sync = null;
	if ( null === $sync ) {
		$sync = new Tack_Order_Sync();
	}
	$ref = new ReflectionMethod( $sync, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $sync, $args );
}

$order   = tack_payload_fixture();
$payload = tack_sync_call( 'build_payload', array( $order ) );

// ── The field list ──────────────────────────────────────────────────────────
$missing = array();
foreach ( tack_required_payload_fields() as $field_path ) {
	$value = tack_dig( $payload, $field_path );
	if ( null === $value || '' === $value || array() === $value ) {
		$missing[] = $field_path;
	}
}
check(
	'every required payload field is present and non-blank (' . count( tack_required_payload_fields() ) . ' fields)',
	array() === $missing,
	'missing or blank: ' . implode( ', ', $missing )
);

// The two field lists are maintained in two files because one runs offline and
// one runs on a real store. This is what stops them drifting apart.
$cli_contract = file_get_contents( __DIR__ . '/test-order-payload.php' );
$undeclared   = array();
foreach ( tack_required_payload_fields() as $field_path ) {
	if ( false === strpos( $cli_contract, "'" . $field_path . "'" ) ) {
		$undeclared[] = $field_path;
	}
}
check(
	'the WP-CLI contract test names every field this one does',
	array() === $undeclared,
	'absent from tests/test-order-payload.php: ' . implode( ', ', $undeclared )
);

// ── The specific regressions ────────────────────────────────────────────────
check(
	'subtotal is the goods, not a copy of the total',
	75.00 === (float) $payload['subtotal'] && (float) $payload['subtotal'] !== (float) $payload['total'],
	'subtotal=' . var_export( $payload['subtotal'] ?? null, true ) . ' total=' . var_export( $payload['total'] ?? null, true )
);

check(
	'the shipping address is the SHIPPING address, not a copy of billing',
	'Oakland' === ( $payload['shipping']['city'] ?? '' ) && 'Sacramento' === ( $payload['billing']['city'] ?? '' )
);

check(
	'blank address fields are dropped rather than sent as empty strings',
	! array_key_exists( 'address2', tack_sync_call( 'address_payload', array( new WC_Order( array( 'billing_city' => 'Leeds' ) ), 'billing' ) ) )
);

check(
	'0 and false survive the blank-dropping (they are values, not absences)',
	array_key_exists( 'customerId', $payload ) && 0 === $payload['customerId']
		&& false === ( $payload['payment']['needsPayment'] ?? null )
);

check(
	'a simple line reports no variant, a variation line reports its ID',
	null === $payload['lineItems'][0]['externalVariantId']
		&& '33' === $payload['lineItems'][1]['externalVariantId']
);

check(
	'the variation attributes travel with the line, stripped of display markup',
	array( 'key' => 'Size', 'value' => 'Large' ) === ( $payload['lineItems'][1]['meta'][0] ?? null ),
	var_export( $payload['lineItems'][1]['meta'] ?? null, true )
);

check(
	'a line with no meta still carries the meta key, so a variation has somewhere to go',
	array_key_exists( 'meta', $payload['lineItems'][0] ) && array() === $payload['lineItems'][0]['meta']
);

check(
	'a per-line discount is visible: line subtotal and line total are both sent',
	75.00 === (float) $payload['lineItems'][0]['subtotal'] && 70.00 === (float) $payload['lineItems'][0]['total']
);

/*
 * Asserted here rather than in the required-field list above: the WP-CLI
 * fixture is an unpaid cheque order with no coupon, so on a real store these
 * three are legitimately absent and a blanket non-blank rule would be wrong.
 */
check(
	'the gateway reference, the coupon codes and the paid date all reach the wire',
	'ch_TEST_0001' === ( $payload['payment']['transactionId'] ?? null )
		&& array( 'TRADE5' ) === ( $payload['couponCodes'] ?? null )
		&& is_string( $payload['paidAt'] ?? null )
);

check(
	'the shipping method and the fee line both arrive',
	'Flat rate' === ( $payload['shippingLines'][0]['methodTitle'] ?? null )
		&& 'Handling' === ( $payload['feeLines'][0]['name'] ?? null )
);

check(
	'an unset date is null rather than a fabricated timestamp',
	array_key_exists( 'completedAt', $payload ) && null === $payload['completedAt']
);

// ── Idempotency ─────────────────────────────────────────────────────────────
check(
	'modifiedAt is SENT',
	array_key_exists( 'modifiedAt', $payload ) && null !== $payload['modifiedAt']
);

/*
 * ...but must NOT be hashed. Under HPOS, recording a successful push writes
 * order meta, and OrdersTableDataStore::after_meta_change() stamps a new
 * modified date when it does. Hashing modifiedAt would mean the key a push just
 * recorded is already stale, so de-duplication would never converge.
 */
$moved               = $payload;
$moved['modifiedAt'] = '1999-01-01T00:00:00+00:00';
check(
	'the idempotency key ignores modifiedAt, so de-duplication converges under HPOS',
	tack_sync_call( 'idempotency_key', array( $order, $payload ) )
		=== tack_sync_call( 'idempotency_key', array( $order, $moved ) )
);

$changed          = $payload;
$changed['status'] = 'completed';
check(
	'but a REAL change still produces a new key',
	tack_sync_call( 'idempotency_key', array( $order, $payload ) )
		!== tack_sync_call( 'idempotency_key', array( $order, $changed ) )
);

// ── Purchase-order number ───────────────────────────────────────────────────
check(
	'no PO number is invented when the store collects none',
	'' === $payload['poNumber']
);

add_filter( 'tack_quotes_order_po_number', function ( $po, $wc_order ) { return '  PO-99117  '; }, 10, 2 );
check(
	'a store that fills the tack_quotes_order_po_number filter gets its PO reported, trimmed',
	'PO-99117' === tack_sync_call( 'po_number', array( $order ) )
);

$GLOBALS['TACK_FILTERS']['tack_quotes_order_po_number'] = array(
	function ( $po, $wc_order ) { return array( 'not', 'a', 'string' ); },
);
check(
	'a filter returning a non-scalar cannot put an array on the wire',
	'' === tack_sync_call( 'po_number', array( $order ) )
);
$GLOBALS['TACK_FILTERS']['tack_quotes_order_po_number'] = array();
