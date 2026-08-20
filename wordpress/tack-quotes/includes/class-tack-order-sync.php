<?php
/**
 * Sends WooCommerce orders to the Tack API on creation and status change.
 *
 * Every push is DEFERRED. The three hooks below all fire inside a request the shopper or
 * the administrator is waiting on — two of them inside checkout itself — and the push is an
 * outbound HTTP call with a 20 second timeout. Doing that inline made "never blocks
 * checkout", which this plugin's readme and settings screen both claim, false: a slow or
 * unreachable TackQuote added up to 20 seconds to the customer's place-order request, and a
 * PHP timeout there can leave an order paid and unrecorded.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order sync handler.
 */
class Tack_Order_Sync {

	/**
	 * Action fired to perform one deferred order push.
	 */
	const SYNC_HOOK = 'tack_quotes_sync_order';

	/**
	 * Action Scheduler group, so these jobs are identifiable in WooCommerce → Status →
	 * Scheduled Actions.
	 */
	const SYNC_GROUP = 'tack-quotes';

	/**
	 * Order meta holding the idempotency key of the last state successfully pushed.
	 */
	const SYNC_KEY_META = '_tack_quotes_sync_key';

	/**
	 * Whether outbound order sync is switched on.
	 *
	 * Single source of truth for the default, because it is consulted both when deciding
	 * whether to register the hooks at all and again inside the deferred worker.
	 *
	 * Defaults to OFF. This feature sends personal data — the buyer's email address, name and
	 * company, plus order totals and line items — to a third-party service, and a plugin must
	 * not start doing that on activation without the merchant choosing it. Stores that already
	 * have the option stored keep whatever they chose; `add_option()` never overwrites, so
	 * this changes nothing for an existing install.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( 'tack_quotes_enable_order_sync', 'no' );
	}

	/**
	 * Hook registration.
	 */
	public function init() {
		/*
		 * Both creation hooks are registered, and they are not redundant with each other:
		 * `woocommerce_checkout_order_processed` does not fire for Store API requests, which
		 * is exactly why WooCommerce added `woocommerce_store_api_checkout_order_processed`
		 * as its block-checkout equivalent. A store running the block checkout fires only the
		 * second; a store running the classic shortcode checkout fires only the first.
		 *
		 * They are also not redundant with the status hook, which is a claim worth being
		 * precise about because it looks true and is not. `WC_Order::set_status()` only
		 * records a transition when `true === $this->object_read && ! empty( $result['from'] )`
		 * (woocommerce/includes/class-wc-order.php), so a brand-new order created straight
		 * into `pending` fires no status change at all. Dropping the creation hooks would
		 * therefore silently stop syncing every order that a gateway leaves at `pending` —
		 * off-site redirect gateways that the customer abandons, most obviously — and those
		 * are precisely the leads a quoting platform wants.
		 *
		 * What made the pair a defect was not the coverage, it was the duplication: on one
		 * classic checkout both fired, and the payload carried nothing that let TackQuote
		 * recognise the second push as the same event. That is fixed by deferral plus the
		 * idempotency key below, not by deleting a hook.
		 */
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * Register the deferred worker. Runs on a background request, where a 20s call is free.
	 *
	 * Deliberately separate from init() and registered even when order sync is switched OFF.
	 * A job queued while it was on has to find a callback when it is claimed, or Action
	 * Scheduler records it as a permanently failed action with no handler — noise in the
	 * merchant's Scheduled Actions list describing a decision they already made. The worker
	 * re-checks the toggle itself and returns without sending anything.
	 */
	public function register_worker() {
		add_action( self::SYNC_HOOK, array( $this, 'run_sync' ), 10, 1 );
	}

	/**
	 * New order created.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	public function on_new_order( $order ) {
		$this->enqueue( $order );
	}

	/**
	 * Order status changed.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		$this->enqueue( $order );
	}

	/**
	 * Queue a deferred push for one order.
	 *
	 * Nothing about the order is captured here beyond its id. The worker re-reads the order
	 * when it runs, and that is what collapses a classic checkout's two triggers into one
	 * push: by the time either job runs the order is in its settled status, both jobs compute
	 * the same payload and the same key, and the second one finds the key already recorded
	 * and does nothing.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	private function enqueue( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		$args     = array( $order_id );

		/*
		 * Action Scheduler ships inside WooCommerce, and every hook that reaches this method
		 * fires long after `init`, which is the point Action Scheduler asks callers to wait
		 * for. It is still probed rather than assumed: a host that has interfered with it, or
		 * a WooCommerce old enough not to bundle it, must degrade instead of fatalling. The
		 * fallback is WP-Cron, which still gets the call off the checkout request — later than
		 * Action Scheduler would, but not inside the customer's page load.
		 *
		 * $unique = true drops a second PENDING job for the same order, which is the common
		 * case here (creation hook, then the gateway's status change, milliseconds apart).
		 */
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SYNC_HOOK, $args, self::SYNC_GROUP, true );
			return;
		}

		if ( ! wp_next_scheduled( self::SYNC_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::SYNC_HOOK, $args );
		}
	}

	/**
	 * Perform one deferred push. Failures are logged, never fatal.
	 *
	 * @param int $order_id Order id.
	 */
	public function run_sync( $order_id ) {
		// Re-checked at run time: a merchant may have switched sync off between the order
		// being placed and this job being claimed, and the answer they expect from that switch
		// is "stop sending", including for work already queued.
		if ( ! self::is_enabled() ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$payload = $this->build_payload( $order );
		$key     = $this->idempotency_key( $order, $payload );

		// Same state already accepted by TackQuote — nothing to say. This is what stops the
		// duplicate pushes, and also stops every later admin save that does not actually
		// change the order from generating traffic.
		if ( $key === (string) $order->get_meta( self::SYNC_KEY_META ) ) {
			return;
		}

		$payload['idempotencyKey'] = $key;

		$result = ( new Tack_Api_Client() )->sync_order( $payload, $key );
		if ( is_wp_error( $result ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf(
						/* translators: 1: order id, 2: error message */
						'TackQuote order sync failed for order %1$d: %2$s',
						(int) $order->get_id(),
						$result->get_error_message()
					),
					array( 'source' => 'tack-quotes' )
				);
			}
			return;
		}

		// Recorded only on success, so a failed push is retried by the next trigger rather
		// than being suppressed as a duplicate. Written through the order CRUD, not
		// update_post_meta(), because with HPOS enabled orders do not live in wp_postmeta.
		$order->update_meta_data( self::SYNC_KEY_META, $key );
		$order->save_meta_data();
	}

	/**
	 * Normalize an order for the Tack API.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_payload( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			// $item is a WC_Order_Item_Product; get_product() returns false for a deleted
			// product, which is why the SKU below is guarded rather than read directly.
			$product = $item->get_product();
			$items[] = array(
				'sku'      => $product instanceof WC_Product ? $product->get_sku() : '',
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $item->get_total(),
			);
		}

		return array(
			'externalOrderId' => (string) $order->get_id(),
			'orderNumber'     => $order->get_order_number(),
			'status'          => $order->get_status(),
			'currency'        => $order->get_currency(),
			'total'           => (float) $order->get_total(),
			'buyerEmail'      => $order->get_billing_email(),
			'buyerName'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'buyerCompany'    => $order->get_billing_company(),
			'lineItems'       => $items,
			'createdAt'       => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : null,
			'source'          => 'woocommerce',
		);
	}

	/**
	 * Build the idempotency key for one order state.
	 *
	 * Scoped by site so two stores pushing the same order id cannot collide, and derived from
	 * the payload so a genuine change — a new status, an edited total, an added line — is a
	 * NEW event rather than a suppressed duplicate. It is a de-duplication token, not a
	 * signature, which is why an ordinary hash is appropriate here.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Payload about to be sent.
	 * @return string
	 */
	private function idempotency_key( $order, $payload ) {
		return sprintf(
			'woocommerce-%s-%d-%s',
			substr( md5( home_url( '/' ) ), 0, 12 ),
			(int) $order->get_id(),
			substr( md5( (string) wp_json_encode( $payload ) ), 0, 16 )
		);
	}
}
