<?php
/**
 * Fired when the plugin is uninstalled (deleted) from the Plugins screen.
 * Removes plugin options. Does NOT delete generated WebP files or touch
 * compressed originals, since those are part of the user's media library.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'aiio_settings' );

global $wpdb;

// Clean up post meta added by the plugin.
$meta_keys = array(
	'_aiio_processed',
	'_aiio_bytes_saved',
	'_aiio_webp_files',
	'_aiio_alt_text_error',
	'_aiio_bulk_compressed',
);

foreach ( $meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
}
