<?php
/**
 * Uninstall handler — removes the plugin's options. Runs only on delete.
 *
 * @package TackQuotes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$tack_quotes_options = array(
	'tack_quotes_api_key',
	'tack_quotes_api_url',
	'tack_quotes_button_label',
	'tack_quotes_enable_widget',
	'tack_quotes_enable_order_sync',
	'tack_quotes_schema_version',
);

foreach ( $tack_quotes_options as $tack_quotes_option ) {
	delete_option( $tack_quotes_option );
}

// Multisite: clean per-site options too.
if ( is_multisite() ) {
	global $wpdb;
	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		foreach ( $tack_quotes_options as $tack_quotes_option ) {
			delete_option( $tack_quotes_option );
		}
		restore_current_blog();
	}
}
