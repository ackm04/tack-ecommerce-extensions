<?php
/**
 * Thin HTTP client for the Tack API using the WordPress HTTP API.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles authenticated requests to the Tack API.
 */
class Tack_Api_Client {

	/**
	 * @return string API base URL without trailing slash.
	 */
	private function base_url() {
		return rtrim( (string) get_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' ), '/' );
	}

	/**
	 * @return string The stored API key.
	 */
	private function api_key() {
		return (string) get_option( 'tack_quotes_api_key', '' );
	}

	/**
	 * Perform a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path beginning with '/'.
	 * @param array  $body   Optional JSON body.
	 * @return array|WP_Error Decoded response array, or WP_Error.
	 */
	public function request( $method, $path, $body = null ) {
		$key = $this->api_key();
		if ( '' === $key ) {
			return new WP_Error( 'tack_no_key', __( 'No TackQuote API key configured.', 'tack-quotes' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'X-Api-Key'     => $key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'TackQuotes-WooCommerce/' . TACK_QUOTES_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( ', ', $data['message'] ) : $data['message'] )
				: sprintf( /* translators: %d: HTTP status code */ __( 'TackQuote API returned HTTP %d.', 'tack-quotes' ), $code );
			return new WP_Error( 'tack_http_' . $code, $message );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Lightweight connectivity check.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$result = $this->request( 'GET', '/integrations/woocommerce/ping' );
		if ( is_wp_error( $result ) ) {
			// Fall back to a generic authenticated endpoint if /ping is unavailable.
			$result = $this->request( 'GET', '/health' );
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Create a quote request from cart/product line items.
	 *
	 * @param array $payload {buyerEmail, note, lineItems:[{sku,name,quantity,unitPrice}]}.
	 * @return array|WP_Error Response — expected to include a portalUrl/quoteUrl.
	 */
	public function create_quote_request( $payload ) {
		return $this->request( 'POST', '/integrations/woocommerce/quote-requests', $payload );
	}

	/**
	 * Push a WooCommerce order to Tack.
	 *
	 * @param array $order_payload Normalized order data.
	 * @return array|WP_Error
	 */
	public function sync_order( $order_payload ) {
		return $this->request( 'POST', '/integrations/woocommerce/order-sync', $order_payload );
	}
}
