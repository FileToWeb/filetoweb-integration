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
	'filetoweb_integration_bulk_queue',
	'filetoweb_integration_pdf_to_page_jobs',
	'filetoweb_integration_epub_default_off_migrated',
	'filetoweb_integration_preview_migration_version',
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

global $wpdb;

if ( method_exists( $wpdb, 'get_results' ) && method_exists( $wpdb, 'prepare' ) ) {
	$preview_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_proud_html_preview'
		)
	);

	foreach ( is_array( $preview_rows ) ? $preview_rows : array() as $row ) {
		$value = isset( $row->meta_value ) ? maybe_unserialize( $row->meta_value ) : null;

		if ( ! is_array( $value ) || 'filetoweb' !== ( isset( $value['provider'] ) ? $value['provider'] : '' ) ) {
			continue;
		}

		$artifacts = isset( $value['artifacts'] ) && is_array( $value['artifacts'] ) ? $value['artifacts'] : array();
		if ( empty( $artifacts ) && ! empty( $value['artifact_key'] ) && ! empty( $value['artifact_url'] ) ) {
			$artifacts[] = array(
				'artifact_key' => $value['artifact_key'],
				'artifact_url' => $value['artifact_url'],
			);
		}

		foreach ( $artifacts as $artifact ) {
			if ( function_exists( '\\Proud\\Core\\proud_html_preview_queue_cleanup' ) && ! empty( $artifact['artifact_key'] ) && ! empty( $artifact['artifact_url'] ) ) {
				call_user_func( '\\Proud\\Core\\proud_html_preview_queue_cleanup', 'filetoweb', $artifact['artifact_key'], $artifact['artifact_url'] );
			}
		}

		if ( isset( $row->meta_id ) ) {
			$wpdb->delete(
				$wpdb->postmeta,
				array( 'meta_id' => absint( $row->meta_id ) )
			);
		}
	}
}

$providers = get_option( 'proud_html_preview_providers', array() );
if ( is_array( $providers ) && array_key_exists( 'filetoweb', $providers ) ) {
	unset( $providers['filetoweb'] );

	if ( empty( $providers ) ) {
		delete_option( 'proud_html_preview_providers' );
	} else {
		update_option( 'proud_html_preview_providers', $providers, false );
	}
}

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
	'_filetoweb_local_html_path',
	'_filetoweb_local_html_token',
	'_filetoweb_local_html_source_url',
	'_filetoweb_local_html_source_fingerprint',
	'_filetoweb_local_html_updated_at',
	'_filetoweb_local_page_id',
	'_filetoweb_local_page_approved',
	'_filetoweb_local_page_source_fingerprint',
	'_filetoweb_local_page_updated_at',
	'_filetoweb_pdf_to_page',
	'_filetoweb_pdf_to_page_filename',
	'_filetoweb_pdf_to_page_notify_email',
	'_filetoweb_pdf_to_page_notified_at',
	'_filetoweb_pdf_to_page_completed_at',
	'_filetoweb_source_post_id',
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
wp_clear_scheduled_hook( 'filetoweb_integration_process_bulk_queue' );
wp_clear_scheduled_hook( 'filetoweb_integration_migrate_html_previews' );

if ( function_exists( 'wp_upload_dir' ) ) {
	$uploads = wp_upload_dir();
	$dir     = isset( $uploads['basedir'] ) ? trailingslashit( $uploads['basedir'] ) . 'filetoweb-integration' : '';

	if ( $dir && is_dir( $dir ) ) {
		$remove_dir = function ( $path ) use ( &$remove_dir, $uploads ) {
			$items = glob( trailingslashit( $path ) . '*' );

			foreach ( is_array( $items ) ? $items : array() as $item ) {
				if ( is_dir( $item ) ) {
					$remove_dir( $item );
					continue;
				}

					if ( is_file( $item ) ) {
						$name = ltrim( str_replace( '\\', '/', str_replace( $uploads['basedir'], '', $item ) ), '/' );
						$url  = trailingslashit( $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $name ) );

						if ( function_exists( '\\Proud\\Core\\proud_html_preview_queue_cleanup' ) ) {
							call_user_func( '\\Proud\\Core\\proud_html_preview_queue_cleanup', 'filetoweb', $name, $url );
						}

						if ( function_exists( 'has_action' ) && has_action( 'sm:sync::deleteFile' ) ) {
							do_action( 'sm:sync::deleteFile', $name );
					}

					unlink( $item ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}

			rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_rmdir
		};

		$remove_dir( $dir );
	}
}
