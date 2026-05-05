<?php
/**
 * FileToWeb uninstall cleanup.
 *
 * @package FileToWeb\Integration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$option_names = array(
	'filetoweb_integration_settings',
	'filetoweb_integration_enabled',
	'filetoweb_integration_api_base_url',
	'filetoweb_integration_api_key',
	'filetoweb_integration_replace_links',
	'filetoweb_integration_batch_size',
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

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
