<?php
/**
 * Sends WooCommerce orders to the Tack API on creation and status change.
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
	 * Hook registration.
	 */
	public function init() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * New order created.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	public function on_new_order( $order ) {
		$this->push( $order );
	}

	/**
	 * Order status changed.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @param WC_Order $order   Order object.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		$this->push( $order );
	}

	/**
	 * Normalize and send an order to Tack. Failures are logged, never fatal.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	private function push( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			$items[] = array(
				'sku'      => $product instanceof WC_Product ? $product->get_sku() : '',
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $item->get_total(),
			);
		}

		$payload = array(
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

		$result = ( new Tack_Api_Client() )->sync_order( $payload );
		if ( is_wp_error( $result ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'TackQuote order sync failed: ' . $result->get_error_message(),
					array( 'source' => 'tack-quotes' )
				);
			}
		}
	}
}
