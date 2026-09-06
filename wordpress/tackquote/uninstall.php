<?php
/**
 * Uninstall handler — removes everything this plugin stores. Runs only on delete.
 *
 * @package TackQuotes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Every option the plugin creates.
 *
 * This list was missing five of them — the three button labels added in 1.2.0/1.3.0 and the
 * two product-page visibility toggles — all of which `Tack_Quotes::activate()` writes, so a
 * delete-and-reinstall left stale values behind while README.md claimed "no data left
 * behind". Anything added to `activate()` has to be added here in the same change.
 */
$tack_quotes_options = array(
	'tack_quotes_api_key',
	'tack_quotes_api_url',
	'tack_quotes_button_label',
	'tack_quotes_request_button_label',
	'tack_quotes_checkout_button_label',
	'tack_quotes_show_add_to_quote',
	'tack_quotes_show_request_quote',
	'tack_quotes_enable_widget',
	'tack_quotes_enable_order_sync',
	'tack_quotes_schema_version',

	/*
	 * Everything below was added in 1.6.0 and later and was never added HERE,
	 * so an uninstall left thirteen options behind while the readme promised
	 * it "removes every option above". Nothing visibly broke — orphaned rows
	 * in wp_options are silent — which is exactly why it went unnoticed
	 * through three releases.
	 *
	 * The names are written out literally rather than read from the class
	 * constants on purpose: WordPress loads uninstall.php on its own, with
	 * WP_UNINSTALL_PLUGIN defined and the plugin's own files NOT loaded, so
	 * `Tack_Group_Restrictions::OPTION_PAYMENT_MAP` would be a fatal error
	 * here. If you add an option, add it in both places.
	 */

	// Quote-only (B2B catalog) store mode — 1.5.0.
	'tack_quotes_store_mode',
	'tack_quotes_quote_only_scope',
	'tack_quotes_quote_only_roles',
	'tack_quotes_hide_prices',
	'tack_quotes_hidden_price_text',

	// B2B pricing — 1.6.0.
	'tack_quotes_enable_wholesale_pricing',
	'tack_quotes_show_quantity_breaks',

	// Order limits + buyer group badge — 1.7.0.
	'tack_quotes_enable_order_limits',
	'tack_quotes_enable_buyer_group',

	// Payment/shipping restrictions by buyer group — 1.7.0, 1.8.0.
	'tack_quotes_enable_group_restrictions',
	'tack_quotes_payment_group_map',
	'tack_quotes_shipping_group_map',
	'tack_quotes_buyer_group_codes',
);

/**
 * Transients the plugin creates under a fixed name.
 *
 * The per-visitor rate-limit counters (`tack_qr_*`) are not listed: they are keyed on a hash
 * so there is no name to delete, there is no WordPress API for wildcard transient deletion,
 * and they expire within five minutes on their own. Sweeping them would mean a direct
 * LIKE query against the options table that also silently does nothing on a site using an
 * external object cache, which is a worse trade than letting them lapse.
 */
$tack_quotes_transients = array(
	'tack_quotes_registration_config',
);

/**
 * Delete this plugin's stored values on the current site.
 *
 * @param array $options    Option names.
 * @param array $transients Transient names.
 * @return void
 */
function tack_quotes_delete_site_data( $options, $transients ) {
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}
}

tack_quotes_delete_site_data( $tack_quotes_options, $tack_quotes_transients );

/*
 * Multisite: clean every site in the network.
 *
 * `get_sites()` is the documented API for this and replaces a raw
 * `SELECT blog_id FROM {$wpdb->blogs}` that needed a phpcs suppression to exist, assigned to
 * `$blog_id` (a WordPress global, which the coding standards prohibit overwriting), and read
 * every site on the network in one unbounded query. get_sites() is paged deliberately: a
 * large network is exactly where an unbounded query hurts, and 'fields' => 'ids' keeps this
 * from hydrating a site object per row.
 */
if ( is_multisite() ) {
	$tack_quotes_page = 0;
	do {
		$tack_quotes_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
				'offset' => $tack_quotes_page * 200,
			)
		);

		$tack_quotes_found = count( $tack_quotes_site_ids );

		foreach ( $tack_quotes_site_ids as $tack_quotes_site_id ) {
			switch_to_blog( (int) $tack_quotes_site_id );
			tack_quotes_delete_site_data( $tack_quotes_options, $tack_quotes_transients );
			restore_current_blog();
		}

		++$tack_quotes_page;
	} while ( 200 === $tack_quotes_found );
}
