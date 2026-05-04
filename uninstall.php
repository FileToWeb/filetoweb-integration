<?php
/**
 * FileToWeb uninstall cleanup.
 *
 * @package FileToWeb\Integration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'filetoweb_integration_enabled' );
delete_option( 'filetoweb_integration_api_base_url' );
delete_option( 'filetoweb_integration_api_key' );
delete_option( 'filetoweb_integration_replace_links' );
delete_option( 'filetoweb_integration_batch_size' );

global $wpdb;

$meta_keys = array(
	'_filetoweb_external_id',
	'_filetoweb_document_id',
	'_filetoweb_source_fingerprint',
	'_filetoweb_source_fingerprint_algorithm',
	'_filetoweb_status',
	'_filetoweb_html_url',
	'_filetoweb_continuous_url',
	'_filetoweb_editor_url',
	'_filetoweb_page_count',
	'_filetoweb_last_error',
	'_filetoweb_last_synced_at',
	'_filetoweb_original_url',
);

foreach ( $meta_keys as $meta_key ) {
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'meta_key' => $meta_key,
		)
	);
}

wp_clear_scheduled_hook( 'filetoweb_integration_poll_pending' );
