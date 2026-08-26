<?php
/**
 * Durable provider-neutral ProudCity HTML preview publication.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proud_HTML_Preview {
	const META_KEY                 = '_proud_html_preview';
	const META_PUBLIC_PAUSED       = '_filetoweb_public_replacement_paused';
	const META_PAUSED_RECORD       = '_filetoweb_paused_html_preview';
	const META_STORAGE_SCHEMA      = '_filetoweb_preview_storage_schema';
	const OPTION_PROVIDERS         = 'proud_html_preview_providers';
	const OPTION_MIGRATION_VERSION = 'filetoweb_integration_preview_migration_version';
	const PROVIDER                 = 'filetoweb';
	const SCHEMA_VERSION           = 3;
	const TENANT_PREFIX_SCHEMA_VERSION = 2;
	const MIGRATION_HOOK           = 'filetoweb_integration_migrate_html_previews';
	const MIGRATION_VERSION        = '3';
	const STORAGE_BACKEND_LOCAL    = 'local';
	const STORAGE_BACKEND_STATELESS = 'wp-stateless';
	const BUNDLE_ROOT              = 'filetoweb-integration/previews';

	/**
	 * Customer-safe reason the latest publication attempt failed.
	 *
	 * @var string
	 */
	private static $last_publish_error = '';

	/**
	 * Register preview publication lifecycle hooks.
	 */
	public static function init() {
		add_action( 'update_option_' . Settings::OPTION_SETTINGS, array( __CLASS__, 'settings_updated' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'disable_automatic_migration' ), 1 );
		add_action( self::MIGRATION_HOOK, array( __CLASS__, 'migrate_legacy_batch' ) );
	}

	/**
	 * Initialize the provider without changing it during deactivation.
	 */
	public static function activate() {
		self::ensure_provider_state();
		self::disable_automatic_migration( true );
	}

	/**
	 * Keep the persistent provider map aligned with the explicit public toggle.
	 *
	 * @param mixed  $old_value Previous settings.
	 * @param mixed  $new_value New settings.
	 * @param string $option Option name.
	 */
	public static function settings_updated( $old_value, $new_value, $option = '' ) {
		unset( $old_value, $option );

		$new_value = is_array( $new_value ) ? $new_value : array();
		$enabled   = ! empty( $new_value[ Settings::KEY_REPLACE_LINKS ] ) && '0' !== (string) $new_value[ Settings::KEY_REPLACE_LINKS ];

		self::set_provider_enabled( $enabled );
	}

	/**
	 * Persist the FileToWeb provider state while preserving other providers.
	 *
	 * @param bool $enabled Enabled state.
	 */
	public static function set_provider_enabled( $enabled ) {
		$providers = get_option( self::OPTION_PROVIDERS, array() );
		$providers = is_array( $providers ) ? $providers : array();

		$providers[ self::PROVIDER ] = (bool) $enabled;
		update_option( self::OPTION_PROVIDERS, $providers, false );
	}

	/**
	 * Return whether this provider is enabled for public rendering.
	 *
	 * @return bool
	 */
	public static function provider_enabled() {
		$providers = get_option( self::OPTION_PROVIDERS, array() );

		return is_array( $providers ) && ! empty( $providers[ self::PROVIDER ] );
	}

	/**
	 * Publish a complete immutable HTML preview bundle.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $html Prepared FileToWeb HTML.
	 * @param string $viewer_url FileToWeb viewer URL used to resolve assets.
	 * @param string $source_url Original PDF URL.
	 * @param string $fingerprint Source fingerprint.
	 * @param bool   $content_versioned Whether the bundle path must also identify the current published HTML.
	 * @return array|false Preview record or false.
	 */
	public static function publish( $post_id, $html, $viewer_url, $source_url, $fingerprint, $content_versioned = false ) {
		self::$last_publish_error = '';

		$post_id     = absint( $post_id );
		$html        = is_string( $html ) ? trim( $html ) : '';
		$viewer_url  = Security::sanitize_filetoweb_url( $viewer_url );
		$source_url  = esc_url_raw( $source_url );
		$fingerprint = self::sanitize_fingerprint( $fingerprint );

		if ( ! $post_id || ! $html || ! $viewer_url || ! $source_url || ! $fingerprint ) {
			return self::publish_failure( __( 'FileToWeb preview publication data was incomplete.', 'filetoweb-integration' ) );
		}

		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';

		if ( ! $basedir || ! $baseurl ) {
			return self::publish_failure( __( 'WordPress storage is unavailable for the FileToWeb preview.', 'filetoweb-integration' ) );
		}

		$hash               = self::bundle_slug( $fingerprint, $html, $content_versioned );
		$local_artifact_key = self::BUNDLE_ROOT . '/' . $post_id . '/' . $hash . '/index.html';
		$storage            = self::storage_context( $basedir, $baseurl, $local_artifact_key );

		if ( empty( $storage ) ) {
			return self::publish_failure( __( 'WP Stateless storage details are unavailable for the FileToWeb preview.', 'filetoweb-integration' ) );
		}

		$artifact_key     = self::storage_artifact_key( $local_artifact_key, $storage );
		$artifact_url     = self::storage_artifact_url( $local_artifact_key, $storage );
		$bundle_dir       = trailingslashit( $basedir ) . dirname( $local_artifact_key );
		$index_path       = trailingslashit( $basedir ) . $local_artifact_key;
		$current          = self::record_for_post( $post_id );
		$legacy_artifacts = self::legacy_artifacts_for_record( $current );

		if ( self::record_matches( $current, $source_url, $fingerprint, $artifact_key ) && is_readable( $index_path ) ) {
			$manifest  = self::artifact_manifest( $bundle_dir, $basedir, $baseurl, $storage );
			$algorithm = sanitize_key( get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT_ALGORITHM, true ) );
			if ( empty( $manifest ) ) {
				return self::publish_failure( __( 'FileToWeb preview files could not be enumerated in WordPress storage.', 'filetoweb-integration' ) );
			}

			if ( empty( $current['artifacts'] ) || $manifest !== $current['artifacts'] || $algorithm !== ( isset( $current['source_fingerprint_algorithm'] ) ? $current['source_fingerprint_algorithm'] : '' ) ) {
				if ( ! self::sync_bundle_with_stateless( $bundle_dir, $basedir, $baseurl, $storage ) ) {
					return self::publish_failure( __( 'FileToWeb preview files could not be verified in WordPress storage.', 'filetoweb-integration' ) );
				}

				$current['artifacts']                    = $manifest;
				$current['source_fingerprint_algorithm'] = $algorithm;
				self::store_record( $post_id, $current );
			}

			self::write_legacy_pointer( $post_id, $index_path, $current['token'], $viewer_url, $fingerprint, $current['published_at'] );
			return $current;
		}

		$parent_dir = dirname( $bundle_dir );
		if ( ! wp_mkdir_p( $parent_dir ) ) {
			return self::publish_failure( __( 'WordPress storage could not create the FileToWeb preview directory.', 'filetoweb-integration' ) );
		}

		$temp_dir = $bundle_dir . '.tmp-' . strtolower( wp_generate_password( 10, false, false ) );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return self::publish_failure( __( 'WordPress storage could not create the temporary FileToWeb preview directory.', 'filetoweb-integration' ) );
		}

		$bundle_url = self::storage_artifact_url( dirname( $local_artifact_key ), $storage );
		$mirrored   = array();
		$complete   = true;
		$html       = self::mirror_assets( $html, $viewer_url, $temp_dir, $bundle_url, $mirrored, $complete );

		if ( ! $complete ) {
			self::remove_directory( $temp_dir );
			return self::publish_failure( __( 'One or more FileToWeb preview assets could not be written to WordPress storage.', 'filetoweb-integration' ) );
		}

		$html       = self::sanitize_html( $html );

		if ( ! $html || self::contains_filetoweb_asset_reference( $html, $viewer_url ) || false === file_put_contents( trailingslashit( $temp_dir ) . 'index.html', $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			self::remove_directory( $temp_dir );
			return self::publish_failure( __( 'FileToWeb preview HTML could not be written to WordPress storage.', 'filetoweb-integration' ) );
		}

		if ( is_dir( $bundle_dir ) ) {
			$stale_dir = $bundle_dir . '.stale-' . strtolower( wp_generate_password( 10, false, false ) );

			if ( ! rename( $bundle_dir, $stale_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				self::remove_directory( $temp_dir );
				return self::publish_failure( __( 'The existing FileToWeb preview bundle could not be prepared for replacement.', 'filetoweb-integration' ) );
			}

			if ( ! rename( $temp_dir, $bundle_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $stale_dir, $bundle_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				self::remove_directory( $temp_dir );
				return self::publish_failure( __( 'The FileToWeb preview bundle could not be finalized in WordPress storage.', 'filetoweb-integration' ) );
			}

			self::remove_directory( $stale_dir );
		} elseif ( ! rename( $temp_dir, $bundle_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			self::remove_directory( $temp_dir );
			return self::publish_failure( __( 'The FileToWeb preview bundle could not be finalized in WordPress storage.', 'filetoweb-integration' ) );
		}

		$token        = wp_generate_password( 40, false, false );
		$published_at = current_time( 'mysql', true );
		$algorithm    = sanitize_key( get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT_ALGORITHM, true ) );
		$manifest     = self::artifact_manifest( $bundle_dir, $basedir, $baseurl, $storage );

		if ( empty( $manifest ) ) {
			return self::publish_failure( __( 'FileToWeb preview files could not be enumerated in WordPress storage.', 'filetoweb-integration' ) );
		}

		$record       = array(
			'version'            => self::SCHEMA_VERSION,
			'provider'           => self::PROVIDER,
			'storage_backend'    => ! empty( $storage['stateless'] ) ? self::STORAGE_BACKEND_STATELESS : self::STORAGE_BACKEND_LOCAL,
			'source_url'         => $source_url,
			'source_fingerprint' => $fingerprint,
			'source_fingerprint_algorithm' => $algorithm,
			'artifact_key'       => $artifact_key,
			'artifact_url'       => esc_url_raw( $artifact_url ),
			'local_artifact_key' => $local_artifact_key,
			'artifacts'          => $manifest,
			'token'              => $token,
			'published_at'       => $published_at,
		);
		if ( $legacy_artifacts ) {
			$record['legacy_artifacts'] = $legacy_artifacts;
		}
		$superseded_artifacts = self::superseded_artifacts_for_record( $current, $manifest );
		if ( $superseded_artifacts ) {
			$record['superseded_artifacts'] = $superseded_artifacts;
		}

		if ( ! self::sync_bundle_with_stateless( $bundle_dir, $basedir, $baseurl, $storage ) ) {
			return self::publish_failure( __( 'FileToWeb preview files could not be verified in WordPress storage.', 'filetoweb-integration' ) );
		}

		self::store_record( $post_id, $record );
		self::write_legacy_pointer( $post_id, $index_path, $token, $viewer_url, $fingerprint, $published_at );
		self::ensure_provider_state();

		return $record;
	}

	/**
	 * Return the customer-safe reason the latest publication attempt failed.
	 *
	 * @return string
	 */
	public static function last_publish_error() {
		return self::$last_publish_error;
	}

	/**
	 * Store a customer-safe publication failure.
	 *
	 * @param string $message Failure message.
	 * @return false
	 */
	private static function publish_failure( $message ) {
		self::$last_publish_error = sanitize_text_field( $message );

		return false;
	}

	/**
	 * Return a normalized FileToWeb preview record.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function record_for_post( $post_id ) {
		$post_id = absint( $post_id );
		$record  = get_post_meta( $post_id, self::META_KEY, true );

		if ( self::is_public_paused( $post_id ) ) {
			$record = get_post_meta( $post_id, self::META_PAUSED_RECORD, true );
		}

		if ( ! is_array( $record ) || self::PROVIDER !== ( isset( $record['provider'] ) ? $record['provider'] : '' ) ) {
			return array();
		}

		return $record;
	}

	/**
	 * Does a record point at a verified shared-storage preview?
	 *
	 * @param mixed $record Preview record.
	 * @return bool
	 */
	public static function is_durable_record( $record ) {
		return is_array( $record )
			&& self::SCHEMA_VERSION <= (int) ( isset( $record['version'] ) ? $record['version'] : 0 )
			&& self::STORAGE_BACKEND_STATELESS === ( isset( $record['storage_backend'] ) ? $record['storage_backend'] : '' )
			&& ! empty( $record['artifact_url'] );
	}

	/**
	 * Is one source explicitly using its original PDF on the public site?
	 *
	 * @param int $post_id Source post ID.
	 * @return bool
	 */
	public static function is_public_paused( $post_id ) {
		return '1' === (string) get_post_meta( absint( $post_id ), self::META_PUBLIC_PAUSED, true );
	}

	/**
	 * Temporarily withdraw one public HTML preview without deleting its bundle.
	 *
	 * @param int $post_id Source post ID.
	 * @return bool
	 */
	public static function pause_public( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		$record   = get_post_meta( $post_id, self::META_KEY, true );
		$provider = is_array( $record ) && isset( $record['provider'] ) ? (string) $record['provider'] : '';

		if ( $provider && self::PROVIDER !== $provider ) {
			return false;
		}

		if ( ! self::is_filetoweb_record( $record ) ) {
			self::migrate_existing_post( $post_id );
			$record = get_post_meta( $post_id, self::META_KEY, true );
		}

		if ( ! self::is_filetoweb_record( $record ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_PUBLIC_PAUSED, '1' );
		update_post_meta( $post_id, self::META_PAUSED_RECORD, $record );
		delete_post_meta( $post_id, self::META_KEY );
		self::clean_public_cache( $post_id );

		return true;
	}

	/**
	 * Restore the latest current local HTML preview for one source.
	 *
	 * @param int $post_id Source post ID.
	 * @return bool
	 */
	public static function restore_public( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! self::is_public_paused( $post_id ) || ! self::has_current_local_preview( $post_id ) ) {
			return false;
		}

		$record = get_post_meta( $post_id, self::META_PAUSED_RECORD, true );
		$active = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! self::is_filetoweb_record( $record ) || ( is_array( $active ) && ! empty( $active['provider'] ) && self::PROVIDER !== (string) $active['provider'] ) ) {
			return false;
		}

		$current_fingerprint = (string) get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT, true );
		$current_source_url  = (string) get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true );

		if ( $current_fingerprint !== (string) $record['source_fingerprint'] || $current_source_url !== (string) $record['source_url'] ) {
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, $record );

		delete_post_meta( $post_id, self::META_PAUSED_RECORD );
		delete_post_meta( $post_id, self::META_PUBLIC_PAUSED );

		self::clean_public_cache( $post_id );

		return true;
	}

	/**
	 * Persist a preview either publicly or in the paused holding record.
	 *
	 * @param int   $post_id Source post ID.
	 * @param array $record Preview record.
	 */
	private static function store_record( $post_id, $record ) {
		update_post_meta( $post_id, self::META_STORAGE_SCHEMA, (string) self::SCHEMA_VERSION );

		if ( self::is_public_paused( $post_id ) ) {
			update_post_meta( $post_id, self::META_PAUSED_RECORD, $record );

			$active = get_post_meta( $post_id, self::META_KEY, true );
			if ( is_array( $active ) && self::PROVIDER === ( isset( $active['provider'] ) ? $active['provider'] : '' ) ) {
				delete_post_meta( $post_id, self::META_KEY );
			}
			return;
		}

		update_post_meta( $post_id, self::META_KEY, $record );
	}

	/**
	 * Does a value contain a FileToWeb-owned durable preview record?
	 *
	 * @param mixed $record Raw record.
	 * @return bool
	 */
	private static function is_filetoweb_record( $record ) {
		return is_array( $record )
			&& self::PROVIDER === ( isset( $record['provider'] ) ? $record['provider'] : '' )
			&& ! empty( $record['source_url'] )
			&& ! empty( $record['source_fingerprint'] );
	}

	/**
	 * Is the local preview complete and matched to the current PDF source?
	 *
	 * @param int $post_id Source post ID.
	 * @return bool
	 */
	public static function has_current_local_preview( $post_id ) {
		$current_fingerprint = (string) get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT, true );
		$local_fingerprint   = (string) get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, true );

		return 'ready' === get_post_meta( $post_id, Document_State::META_STATUS, true )
			&& $current_fingerprint
			&& hash_equals( $current_fingerprint, $local_fingerprint )
			&& Local_HTML::has_local_html( $post_id );
	}

	/**
	 * Clear WordPress's cached view of a changed source record.
	 *
	 * @param int $post_id Source post ID.
	 */
	private static function clean_public_cache( $post_id ) {
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Publish an existing legacy cache without reconverting its PDF.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function migrate_existing_post( $post_id ) {
		$post_id = absint( $post_id );
		$record  = self::record_for_post( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		if ( $record && ! empty( $record['artifacts'] ) ) {
			$record_version = (int) ( isset( $record['version'] ) ? $record['version'] : 0 );
			if ( self::SCHEMA_VERSION <= $record_version ) {
				update_post_meta( $post_id, self::META_STORAGE_SCHEMA, (string) self::SCHEMA_VERSION );
				return false;
			}

			if ( self::TENANT_PREFIX_SCHEMA_VERSION <= $record_version && self::migrate_storage_backend_marker( $post_id, $record ) ) {
				return true;
			}

			return self::migrate_stateless_record( $post_id, $record );
		}

		$path        = self::safe_legacy_path( get_post_meta( $post_id, Document_State::META_LOCAL_HTML_PATH, true ) );
		$viewer_url  = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, true );
		$fingerprint = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, true );
		$source_url  = get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true );

		if ( ! $source_url && function_exists( 'get_post' ) ) {
			$source_url = Source_Resolver::admin_original_source_url( get_post( $post_id ) );
		}

		if ( ! $path || ! $viewer_url || ! $fingerprint || ! $source_url ) {
			return false;
		}

		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_string( $html ) && (bool) self::publish( $post_id, $html, $viewer_url, $source_url, $fingerprint );
	}

	/**
	 * Upgrade a verified 0.1.46 record without downloading or resyncing its bundle.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $record Existing schema-v2 record.
	 * @return bool
	 */
	private static function migrate_storage_backend_marker( $post_id, $record ) {
		$uploads          = wp_upload_dir();
		$basedir          = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		$baseurl          = isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
		$stored_index_key = isset( $record['local_artifact_key'] )
			? $record['local_artifact_key']
			: ( isset( $record['artifact_key'] ) ? $record['artifact_key'] : '' );
		$index_key        = self::legacy_local_artifact_key( $stored_index_key );
		$artifacts        = isset( $record['artifacts'] ) && is_array( $record['artifacts'] ) ? $record['artifacts'] : array();
		$storage          = $basedir && $baseurl && $index_key ? self::storage_context( $basedir, $baseurl, $index_key ) : array();

		if ( empty( $storage ) || empty( $artifacts ) ) {
			return false;
		}

		$record_key    = isset( $record['artifact_key'] ) ? ltrim( wp_normalize_path( (string) $record['artifact_key'] ), '/' ) : '';
		$record_url    = isset( $record['artifact_url'] ) ? esc_url_raw( (string) $record['artifact_url'] ) : '';
		$record_found  = false;
		$object_prefix = null;

		foreach ( $artifacts as $artifact ) {
			$artifact_key = isset( $artifact['artifact_key'] ) ? ltrim( wp_normalize_path( (string) $artifact['artifact_key'] ), '/' ) : '';
			$artifact_url = isset( $artifact['artifact_url'] ) ? esc_url_raw( (string) $artifact['artifact_url'] ) : '';
			$local_key    = self::legacy_local_artifact_key( $artifact_key );

			if ( ! $local_key ) {
				return false;
			}

			if ( ! empty( $storage['stateless'] ) ) {
				$position       = strpos( $artifact_key, self::BUNDLE_ROOT . '/' );
				$prefix         = false === $position ? '' : substr( $artifact_key, 0, $position );
				$expected_url   = trailingslashit( $storage['public_baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $artifact_key ) );
				$object_prefix  = null === $object_prefix ? $prefix : $object_prefix;

				if ( ! $prefix || $prefix !== $object_prefix || $expected_url !== $artifact_url ) {
					return false;
				}

				$remote = $storage['client']->media_exists( $artifact_key );
				if ( is_wp_error( $remote ) || ! $remote ) {
					return false;
				}
			} elseif (
				$local_key !== $artifact_key
				|| self::storage_artifact_url( $local_key, $storage ) !== $artifact_url
				|| ! is_readable( trailingslashit( $basedir ) . $local_key )
			) {
				return false;
			}

			if ( $record_key === $artifact_key && $record_url === $artifact_url ) {
				$record_found = true;
			}
		}

		if ( ! $record_found ) {
			return false;
		}

		$record['version']         = self::SCHEMA_VERSION;
		$record['storage_backend'] = ! empty( $storage['stateless'] ) ? self::STORAGE_BACKEND_STATELESS : self::STORAGE_BACKEND_LOCAL;
		self::store_record( $post_id, $record );
		self::clean_public_cache( $post_id );

		return true;
	}

	/**
	 * Rebuild a pre-marker bundle from its intact GCS objects without reconversion.
	 *
	 * @param int   $post_id Source post ID.
	 * @param array $record Existing preview record.
	 * @return bool
	 */
	private static function migrate_stateless_record( $post_id, $record ) {
		$uploads          = wp_upload_dir();
		$basedir          = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		$baseurl          = isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
		$stored_index_key = isset( $record['local_artifact_key'] )
			? $record['local_artifact_key']
			: ( isset( $record['artifact_key'] ) ? $record['artifact_key'] : '' );
		$index_key        = self::legacy_local_artifact_key( $stored_index_key );
		$legacy_artifacts = self::legacy_artifacts_for_record( $record );

		if ( ! $basedir || ! $baseurl || ! $index_key || 'index.html' !== basename( $index_key ) ) {
			return false;
		}

		$bundle_dir = trailingslashit( $basedir ) . dirname( $index_key );
		$storage    = self::storage_context( $basedir, $baseurl, $index_key );

		if ( empty( $storage ) ) {
			return false;
		}

		if ( empty( $storage['stateless'] ) ) {
			$manifest = self::artifact_manifest( $bundle_dir, $basedir, $baseurl, $storage );
			if ( empty( $manifest ) || ! is_readable( trailingslashit( $basedir ) . $index_key ) ) {
				return false;
			}

			$superseded_artifacts         = self::superseded_artifacts_for_record( $record, $manifest );
			$record['version']            = self::SCHEMA_VERSION;
			$record['storage_backend']    = self::STORAGE_BACKEND_LOCAL;
			$record['artifact_key']       = $index_key;
			$record['artifact_url']       = self::storage_artifact_url( $index_key, $storage );
			$record['local_artifact_key'] = $index_key;
			$record['artifacts']          = $manifest;
			if ( $legacy_artifacts ) {
				$record['legacy_artifacts'] = $legacy_artifacts;
			}
			if ( $superseded_artifacts ) {
				$record['superseded_artifacts'] = $superseded_artifacts;
			} else {
				unset( $record['superseded_artifacts'] );
			}
			self::store_record( $post_id, $record );

			return true;
		}

		$artifacts = isset( $record['artifacts'] ) && is_array( $record['artifacts'] ) ? $record['artifacts'] : array();
		$client   = $storage['client'];
		$temp_dir = $bundle_dir . '.migration-' . strtolower( wp_generate_password( 10, false, false ) );
		$url_map  = array();

		if ( empty( $artifacts ) || ! wp_mkdir_p( $temp_dir ) ) {
			return false;
		}

		foreach ( $artifacts as $artifact ) {
			$old_key   = isset( $artifact['artifact_key'] ) ? ltrim( (string) $artifact['artifact_key'], '/' ) : '';
			$local_key = self::legacy_local_artifact_key( $old_key );

			if ( ! $local_key || 0 !== strpos( $local_key, trailingslashit( dirname( $index_key ) ) ) ) {
				self::remove_directory( $temp_dir );
				return false;
			}

			$relative = ltrim( substr( $local_key, strlen( dirname( $index_key ) ) ), '/' );
			$source   = trailingslashit( $basedir ) . $local_key;
			$target   = trailingslashit( $temp_dir ) . $relative;

			if ( ! $relative || ! wp_mkdir_p( dirname( $target ) ) ) {
				self::remove_directory( $temp_dir );
				return false;
			}

			$copied = is_readable( $source ) && copy( $source, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_copy
			if ( ! $copied && is_callable( array( $client, 'get_media' ) ) ) {
				$copied = 200 === (int) $client->get_media( $old_key, true, $target );
			}

			if ( ! $copied || ! is_readable( $target ) ) {
				self::remove_directory( $temp_dir );
				return false;
			}

			$new_url  = self::storage_artifact_url( $local_key, $storage );
			$old_urls = array(
				isset( $artifact['artifact_url'] ) ? (string) $artifact['artifact_url'] : '',
				trailingslashit( $baseurl ) . str_replace( '%2F', '/', rawurlencode( $local_key ) ),
				trailingslashit( $storage['public_baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $old_key ) ),
			);

			foreach ( array_filter( array_unique( $old_urls ) ) as $old_url ) {
				$url_map[ $old_url ] = $new_url;
			}
		}

		foreach ( self::files_in_directory( $temp_dir ) as $path ) {
			$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, array( 'html', 'css' ), true ) ) {
				continue;
			}

			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = is_string( $contents ) ? str_replace( array_keys( $url_map ), array_values( $url_map ), $contents ) : '';

			$written = '' !== $contents && false !== file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			if ( ! $written ) {
				self::remove_directory( $temp_dir );
				return false;
			}
		}

		$stale_dir = '';
		if ( is_dir( $bundle_dir ) ) {
			$stale_dir = $bundle_dir . '.stale-' . strtolower( wp_generate_password( 10, false, false ) );
			if ( ! rename( $bundle_dir, $stale_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				self::remove_directory( $temp_dir );
				return false;
			}
		}

		if ( ! rename( $temp_dir, $bundle_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( $stale_dir ) {
				rename( $stale_dir, $bundle_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			self::remove_directory( $temp_dir );
			return false;
		}

		if ( ! self::sync_bundle_with_stateless( $bundle_dir, $basedir, $baseurl, $storage ) ) {
			self::remove_directory( $bundle_dir );
			if ( $stale_dir ) {
				rename( $stale_dir, $bundle_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
			return false;
		}

		if ( $stale_dir ) {
			self::remove_directory( $stale_dir );
		}

		$manifest = self::artifact_manifest( $bundle_dir, $basedir, $baseurl, $storage );
		if ( empty( $manifest ) ) {
			return false;
		}

		$superseded_artifacts         = self::superseded_artifacts_for_record( $record, $manifest );
		$record['version']            = self::SCHEMA_VERSION;
		$record['storage_backend']    = self::STORAGE_BACKEND_STATELESS;
		$record['artifact_key']       = self::storage_artifact_key( $index_key, $storage );
		$record['artifact_url']       = self::storage_artifact_url( $index_key, $storage );
		$record['local_artifact_key'] = $index_key;
		$record['artifacts']          = $manifest;
		$record['published_at']       = current_time( 'mysql', true );
		if ( $legacy_artifacts ) {
			$record['legacy_artifacts'] = $legacy_artifacts;
		}
		if ( $superseded_artifacts ) {
			$record['superseded_artifacts'] = $superseded_artifacts;
		} else {
			unset( $record['superseded_artifacts'] );
		}
		self::store_record( $post_id, $record );

		$viewer_url = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, true );
		if ( $viewer_url && ! empty( $record['token'] ) ) {
			self::write_legacy_pointer(
				$post_id,
				trailingslashit( $basedir ) . $index_key,
				$record['token'],
				$viewer_url,
				$record['source_fingerprint'],
				$record['published_at']
			);
		}

		self::clean_public_cache( $post_id );

		return true;
	}

	/**
	 * Recover the WordPress-local part of a schema-v1 artifact key.
	 *
	 * @param mixed $key Stored artifact key.
	 * @return string
	 */
	private static function legacy_local_artifact_key( $key ) {
		$key      = wp_normalize_path( ltrim( (string) $key, '/' ) );
		$position = strpos( $key, self::BUNDLE_ROOT . '/' );

		if ( false === $position ) {
			return '';
		}

		$key = substr( $key, $position );

		return false === strpos( $key, '../' ) && false === strpos( $key, '/..' ) ? $key : '';
	}

	/**
	 * Preserve old shared object identities for deferred fleet-wide cleanup.
	 *
	 * Rootless schema-v1 objects may have been claimed by multiple tenants. They
	 * must not be deleted during an individual site's migration, because another
	 * tenant may still need the object for recovery. Keeping the identities in
	 * the v2 record allows ProudCity to remove them after every tenant migrates.
	 *
	 * @param array $record Preview record.
	 * @return array
	 */
	private static function legacy_artifacts_for_record( $record ) {
		if ( ! is_array( $record ) ) {
			return array();
		}

		$artifacts = isset( $record['legacy_artifacts'] ) && is_array( $record['legacy_artifacts'] )
			? $record['legacy_artifacts']
			: array();

		if ( self::TENANT_PREFIX_SCHEMA_VERSION > (int) ( isset( $record['version'] ) ? $record['version'] : 0 ) ) {
			$current_artifacts = isset( $record['artifacts'] ) && is_array( $record['artifacts'] )
				? $record['artifacts']
				: array();
			$artifacts         = array_merge( $artifacts, $current_artifacts );
		}

		$legacy = array();
		$seen   = array();
		foreach ( $artifacts as $artifact ) {
			$key = isset( $artifact['artifact_key'] ) ? ltrim( wp_normalize_path( (string) $artifact['artifact_key'] ), '/' ) : '';
			$url = isset( $artifact['artifact_url'] ) ? esc_url_raw( (string) $artifact['artifact_url'] ) : '';

			if ( ! $key || ! $url || false !== strpos( $key, '../' ) || false !== strpos( $key, '/..' ) ) {
				continue;
			}

			$identity = $key . '|' . $url;
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}

			$legacy[]         = array(
				'artifact_key' => $key,
				'artifact_url' => $url,
			);
			$seen[ $identity ] = true;
		}

		return $legacy;
	}

	/**
	 * Retain replaced tenant-specific artifacts until provider-neutral cleanup.
	 *
	 * @param array $record Previous preview record.
	 * @param array $current_artifacts Newly published manifest.
	 * @return array
	 */
	private static function superseded_artifacts_for_record( $record, $current_artifacts = array() ) {
		if ( ! is_array( $record ) ) {
			return array();
		}

		$artifacts = isset( $record['superseded_artifacts'] ) && is_array( $record['superseded_artifacts'] )
			? $record['superseded_artifacts']
			: array();
		if ( self::TENANT_PREFIX_SCHEMA_VERSION <= (int) ( isset( $record['version'] ) ? $record['version'] : 0 ) ) {
			$previous = isset( $record['artifacts'] ) && is_array( $record['artifacts'] ) ? $record['artifacts'] : array();
			$artifacts = array_merge( $artifacts, $previous );
		}

		$current = array();
		foreach ( is_array( $current_artifacts ) ? $current_artifacts : array() as $artifact ) {
			if ( ! empty( $artifact['artifact_key'] ) && ! empty( $artifact['artifact_url'] ) ) {
				$current[ (string) $artifact['artifact_key'] . '|' . (string) $artifact['artifact_url'] ] = true;
			}
		}

		$superseded = array();
		$seen       = array();
		foreach ( $artifacts as $artifact ) {
			$key = isset( $artifact['artifact_key'] ) ? ltrim( wp_normalize_path( (string) $artifact['artifact_key'] ), '/' ) : '';
			$url = isset( $artifact['artifact_url'] ) ? esc_url_raw( (string) $artifact['artifact_url'] ) : '';
			$identity = $key . '|' . $url;

			if ( ! $key || ! $url || isset( $current[ $identity ] ) || isset( $seen[ $identity ] ) || false !== strpos( $key, '../' ) || false !== strpos( $key, '/..' ) ) {
				continue;
			}

			$superseded[]     = array(
				'artifact_key' => $key,
				'artifact_url' => $url,
			);
			$seen[ $identity ] = true;
		}

		return $superseded;
	}

	/**
	 * Backward-compatible entry point that now disables automatic migration.
	 *
	 * Automatic fleet migration was removed in 0.1.48 after its database query
	 * caused production lock contention. Existing previews remain available for
	 * explicit, per-document refresh without scanning wp_postmeta.
	 */
	public static function schedule_migration() {
		self::disable_automatic_migration();
	}

	/**
	 * Make any migration event left by 0.1.46 or 0.1.47 harmless.
	 */
	public static function migrate_legacy_batch() {
		self::disable_automatic_migration( true );
	}

	/**
	 * Remove all queued automatic migration events without scanning post data.
	 *
	 * @param bool $force_clear Clear the hook without first reading the cron array.
	 */
	public static function disable_automatic_migration( $force_clear = false ) {
		if ( $force_clear || ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::MIGRATION_HOOK ) ) ) {
			wp_clear_scheduled_hook( self::MIGRATION_HOOK );
		}

		if ( self::MIGRATION_VERSION !== (string) get_option( self::OPTION_MIGRATION_VERSION, '' ) ) {
			update_option( self::OPTION_MIGRATION_VERSION, self::MIGRATION_VERSION, false );
		}
	}

	/**
	 * Sanitize an HTML document for script-free same-site iframe rendering.
	 *
	 * @param string $html HTML document.
	 * @return string
	 */
	public static function sanitize_html( $html ) {
		$html = is_string( $html ) ? trim( $html ) : '';

		if ( '' === $html ) {
			return '';
		}

		if ( ! class_exists( '\\DOMDocument' ) ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );

		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return '';
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//script|//iframe|//frame|//frameset|//object|//embed|//applet|//form|//input|//button|//textarea|//select|//base|//meta[@http-equiv]|//*[local-name()="svg" or local-name()="math"]' );

		if ( $nodes ) {
			for ( $index = $nodes->length - 1; $index >= 0; --$index ) {
				$node = $nodes->item( $index );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		$elements = $xpath->query( '//*' );
		if ( $elements ) {
			foreach ( $elements as $element ) {
				$remove = array();

				foreach ( $element->attributes as $attribute ) {
					$name  = strtolower( $attribute->name );
					$value = trim( $attribute->value );

					if ( 0 === strpos( $name, 'on' ) || in_array( $name, array( 'srcdoc', 'formaction' ), true ) ) {
						$remove[] = $attribute->name;
						continue;
					}

					if ( 'style' === $name ) {
						$element->setAttribute( $attribute->name, self::sanitize_css( $value ) );
						continue;
					}

					if ( in_array( $name, array( 'href', 'src', 'poster', 'xlink:href' ), true ) && ! self::safe_embedded_url( $value, 'href' === $name ) ) {
						$remove[] = $attribute->name;
					}
				}

				foreach ( $remove as $name ) {
					$element->removeAttribute( $name );
				}

				if ( 'a' === strtolower( $element->nodeName ) && '_blank' === strtolower( $element->getAttribute( 'target' ) ) ) {
					$element->setAttribute( 'rel', 'noopener noreferrer' );
				}
			}
		}

		$styles = $xpath->query( '//style' );
		$raw_styles = array();
		if ( $styles ) {
			foreach ( $styles as $index => $style ) {
				$css                  = self::sanitize_css( $style->nodeValue );
				$marker               = self::raw_style_marker( $html, $raw_styles, $index );
				$raw_styles[ $marker ] = $css;
				$style->nodeValue      = $marker;
			}
		}

		$links = $xpath->query( '//link' );
		if ( $links ) {
			for ( $index = $links->length - 1; $index >= 0; --$index ) {
				$link = $links->item( $index );
				if ( ! $link || 'stylesheet' !== strtolower( trim( $link->getAttribute( 'rel' ) ) ) || ! self::safe_embedded_url( $link->getAttribute( 'href' ), false ) ) {
					if ( $link && $link->parentNode ) {
						$link->parentNode->removeChild( $link );
					}
				}
			}
		}

		$output = self::restore_raw_styles( $dom->saveHTML(), $raw_styles );
		$output = preg_replace( '/^<\?xml[^>]+>\s*/i', '', (string) $output );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return trim( $output );
	}

	/**
	 * Build an ASCII-only placeholder that cannot collide with source content.
	 *
	 * DOMDocument serializes Unicode inside style elements as HTML entities,
	 * even though style is a raw-text element and browsers do not decode those
	 * entities as CSS. Placeholders let the surrounding HTML be serialized while
	 * the already-sanitized CSS is restored byte-for-byte afterward.
	 *
	 * @param string $html Source HTML.
	 * @param array  $markers Existing marker map.
	 * @param int    $index Style position.
	 * @return string
	 */
	private static function raw_style_marker( $html, $markers, $index ) {
		$attempt = 0;

		do {
			$marker = 'FTW_STYLE_RAW_' . md5( $html . '|' . absint( $index ) . '|' . $attempt ) . '_END';
			++$attempt;
		} while ( false !== strpos( $html, $marker ) || isset( $markers[ $marker ] ) );

		return $marker;
	}

	/**
	 * Restore sanitized CSS after DOMDocument has serialized the HTML document.
	 *
	 * @param string $html Serialized HTML.
	 * @param array  $raw_styles Marker-to-CSS map.
	 * @return string Empty when a marker cannot be restored safely.
	 */
	private static function restore_raw_styles( $html, $raw_styles ) {
		$html = is_string( $html ) ? $html : '';

		foreach ( $raw_styles as $marker => $css ) {
			if ( false !== stripos( $css, '</style' ) ) {
				return '';
			}

			$count = 0;
			$html  = str_replace( $marker, $css, $html, $count );

			if ( 1 !== $count ) {
				return '';
			}
		}

		return $html;
	}

	/**
	 * Mirror HTML attributes, srcsets, and CSS URLs into the bundle.
	 *
	 * @param string $html HTML.
	 * @param string $viewer_url Viewer URL.
	 * @param string $bundle_dir Temporary bundle directory.
	 * @param string $bundle_url Final public bundle URL.
	 * @param array  $mirrored URL map.
	 * @param bool   $complete Whether every required FileToWeb asset was mirrored.
	 * @return string
	 */
	private static function mirror_assets( $html, $viewer_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
		$html = preg_replace_callback(
			'~\b(?P<attr>src|href|poster)\s*=\s*(?:(?P<quote>["\'])(?P<quoted>[^"\']+)(?P=quote)|(?P<unquoted>[^\s>]+))~i',
			function ( $match ) use ( $viewer_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
				$value  = isset( $match['quoted'] ) && '' !== $match['quoted'] ? $match['quoted'] : $match['unquoted'];
				$quote  = isset( $match['quote'] ) && $match['quote'] ? $match['quote'] : '"';
				$remote = self::resolve_asset_url( $value, $viewer_url );
				$local  = $remote ? self::mirror_asset( $remote, $bundle_dir, $bundle_url, $mirrored, $complete ) : '';

				if ( $remote && ! $local ) {
					$complete = false;
				}

				return $local ? $match['attr'] . '=' . $quote . esc_url_raw( $local ) . $quote : $match[0];
			},
			$html
		);

		$html = preg_replace_callback(
			'~\bsrcset\s*=\s*(?:(?P<quote>["\'])(?P<quoted>[^"\']+)(?P=quote)|(?P<unquoted>[^\s>]+))~i',
			function ( $match ) use ( $viewer_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
				$value = isset( $match['quoted'] ) && '' !== $match['quoted'] ? $match['quoted'] : $match['unquoted'];
				$quote = isset( $match['quote'] ) && $match['quote'] ? $match['quote'] : '"';
				$items = explode( ',', $value );
				foreach ( $items as &$item ) {
					$parts  = preg_split( '/\s+/', trim( $item ), 2 );
					$remote = self::resolve_asset_url( $parts[0], $viewer_url );
					$local  = $remote ? self::mirror_asset( $remote, $bundle_dir, $bundle_url, $mirrored, $complete ) : '';
					if ( $local ) {
						$item = $local . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
					} elseif ( $remote ) {
						$complete = false;
					}
				}

				return 'srcset=' . $quote . implode( ', ', $items ) . $quote;
			},
			$html
		);

		return self::rewrite_css_urls( $html, $viewer_url, $bundle_dir, $bundle_url, $mirrored, $complete );
	}

	/**
	 * Mirror one FileToWeb bundle asset.
	 *
	 * @param string $remote_url Remote asset URL.
	 * @param string $bundle_dir Bundle directory.
	 * @param string $bundle_url Bundle URL.
	 * @param array  $mirrored URL map.
	 * @param bool   $complete Whether every required FileToWeb asset was mirrored.
	 * @return string
	 */
	private static function mirror_asset( $remote_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
		if ( isset( $mirrored[ $remote_url ] ) ) {
			return $mirrored[ $remote_url ];
		}

		$path     = (string) parse_url( $remote_url, PHP_URL_PATH );
		$basename = sanitize_file_name( basename( rawurldecode( $path ) ) );
		$ext      = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

		if ( ! $basename || ! in_array( $ext, array( 'css', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf' ), true ) ) {
			return '';
		}

		$filename = substr( hash( 'sha256', $remote_url ), 0, 16 ) . '-' . $basename;
		$dir      = trailingslashit( $bundle_dir ) . 'assets';
		$path     = trailingslashit( $dir ) . $filename;
		$url      = trailingslashit( $bundle_url ) . 'assets/' . rawurlencode( $filename );

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$mirrored[ $remote_url ] = $url;
		$response = wp_remote_get(
			$remote_url,
			array(
				'timeout'            => 20,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			unset( $mirrored[ $remote_url ] );
			return '';
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || '' === $body || strlen( $body ) > 5 * 1024 * 1024 ) {
			unset( $mirrored[ $remote_url ] );
			return '';
		}

		if ( 'css' === $ext ) {
			$body = self::rewrite_css_urls( $body, $remote_url, $bundle_dir, $bundle_url, $mirrored, $complete );
			$body = self::sanitize_css( $body );
		}

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			unset( $mirrored[ $remote_url ] );
			return '';
		}

		return $url;
	}

	/**
	 * Rewrite CSS url() values that point to FileToWeb bundle assets.
	 *
	 * @param string $css CSS or HTML containing CSS.
	 * @param string $base_url URL used for relative resolution.
	 * @param string $bundle_dir Bundle directory.
	 * @param string $bundle_url Bundle URL.
	 * @param array  $mirrored URL map.
	 * @param bool   $complete Whether every required FileToWeb asset was mirrored.
	 * @return string
	 */
	private static function rewrite_css_urls( $css, $base_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
		return preg_replace_callback(
			'~url\(\s*(?P<quote>["\']?)(?P<value>[^)"\']+)(?P=quote)\s*\)~i',
			function ( $match ) use ( $base_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
				$remote = self::resolve_asset_url( trim( $match['value'] ), $base_url );
				$local  = $remote ? self::mirror_asset( $remote, $bundle_dir, $bundle_url, $mirrored, $complete ) : '';

				if ( $remote && ! $local ) {
					$complete = false;
				}

				return $local ? 'url("' . esc_url_raw( $local ) . '")' : $match[0];
			},
			$css
		);
	}

	/**
	 * Reject any FileToWeb asset dependency that survived mirroring/sanitization.
	 *
	 * @param string $html Sanitized HTML.
	 * @param string $viewer_url FileToWeb viewer URL.
	 * @return bool
	 */
	private static function contains_filetoweb_asset_reference( $html, $viewer_url ) {
		if ( preg_match_all( '~\b(?:src|href|poster)\s*=\s*(["\'])(.*?)\1~is', $html, $matches ) ) {
			foreach ( $matches[2] as $value ) {
				if ( self::resolve_asset_url( $value, $viewer_url ) ) {
					return true;
				}
			}
		}

		if ( preg_match_all( '~\bsrcset\s*=\s*(["\'])(.*?)\1~is', $html, $srcsets ) ) {
			foreach ( $srcsets[2] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $item ) {
					$parts = preg_split( '/\s+/', trim( $item ), 2 );
					if ( ! empty( $parts[0] ) && self::resolve_asset_url( $parts[0], $viewer_url ) ) {
						return true;
					}
				}
			}
		}

		if ( preg_match_all( '~url\(\s*["\']?([^\)"\']+)["\']?\s*\)~i', $html, $css_urls ) ) {
			foreach ( $css_urls[1] as $value ) {
				if ( self::resolve_asset_url( trim( $value ), $viewer_url ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build a complete, deterministic local/remote artifact manifest.
	 *
	 * @param string $bundle_dir Bundle directory.
	 * @param string $uploads_basedir Upload base directory.
	 * @param string $uploads_baseurl Upload base URL.
	 * @param array  $storage Resolved storage context.
	 * @return array
	 */
	private static function artifact_manifest( $bundle_dir, $uploads_basedir, $uploads_baseurl, $storage = array() ) {
		$manifest = array();
		$storage  = $storage ? $storage : self::local_storage_context( $uploads_baseurl );

		foreach ( self::files_in_directory( $bundle_dir ) as $path ) {
			$key = ltrim( str_replace( wp_normalize_path( $uploads_basedir ), '', wp_normalize_path( $path ) ), '/' );

			if ( 0 !== strpos( $key, self::BUNDLE_ROOT . '/' ) ) {
				continue;
			}

			$manifest[] = array(
				'artifact_key' => self::storage_artifact_key( $key, $storage ),
				'artifact_url' => self::storage_artifact_url( $key, $storage ),
			);
		}

		usort(
			$manifest,
			function ( $left, $right ) {
				return strcmp( $left['artifact_key'], $right['artifact_key'] );
			}
		);

		return $manifest;
	}

	/**
	 * Resolve the exact WP Stateless namespace and public host for a bundle.
	 *
	 * WP Stateless 4.4.1 reduces a non-media path to basename() when use_root is
	 * enabled. We let WP Stateless resolve its configured tenant/date root using
	 * a probe filename, then append FileToWeb's complete nested key ourselves.
	 *
	 * @param string $uploads_basedir Upload base directory.
	 * @param string $uploads_baseurl Upload base URL.
	 * @param string $local_artifact_key Relative artifact key.
	 * @return array
	 */
	private static function storage_context( $uploads_basedir, $uploads_baseurl, $local_artifact_key ) {
		if ( ! function_exists( 'has_action' ) || ! has_action( 'sm:sync::syncFile' ) ) {
			return self::local_storage_context( $uploads_baseurl );
		}

		try {
			$stateless = ud_get_stateless_media();
		} catch ( \Throwable $exception ) {
			return array();
		}

		if (
			! is_object( $stateless )
			|| ! is_callable( array( $stateless, 'get_client' ) )
			|| ! is_callable( array( $stateless, 'get_gs_host' ) )
		) {
			return array();
		}

		$client = $stateless->get_client();
		$host   = esc_url_raw( untrailingslashit( $stateless->get_gs_host() ) );

		if (
			! is_object( $client )
			|| ! is_callable( array( $client, 'media_exists' ) )
			|| ! $host
			|| 'https' !== strtolower( (string) parse_url( $host, PHP_URL_SCHEME ) )
		) {
			return array();
		}

		$object_name = self::stateless_object_name( $uploads_basedir, $local_artifact_key );

		if ( ! $object_name ) {
			$probe       = 'filetoweb-storage-root-probe';
			$resolved    = trim( (string) apply_filters( 'wp_stateless_file_name', $probe, true, '', '' ), '/' );
			$root        = '.' === dirname( $resolved ) ? '' : trim( dirname( $resolved ), '/' );
			$object_name = ltrim( ( $root ? $root . '/' : '' ) . ltrim( $local_artifact_key, '/' ), '/' );
		}

		$local_artifact_key = ltrim( (string) $local_artifact_key, '/' );
		if ( ! $object_name || substr( $object_name, -strlen( $local_artifact_key ) ) !== $local_artifact_key ) {
			return array();
		}

		return array(
			'stateless'     => true,
			'object_prefix' => substr( $object_name, 0, -strlen( $local_artifact_key ) ),
			'public_baseurl' => $host,
			'client'        => $client,
		);
	}

	/**
	 * Build the standard local WordPress storage context.
	 *
	 * @param string $uploads_baseurl Upload base URL.
	 * @return array
	 */
	private static function local_storage_context( $uploads_baseurl ) {
		return array(
			'stateless'     => false,
			'object_prefix' => '',
			'public_baseurl' => untrailingslashit( $uploads_baseurl ),
			'client'        => null,
		);
	}

	/**
	 * Map a WordPress-local key to its durable storage key.
	 *
	 * @param string $local_key WordPress-local artifact key.
	 * @param array  $storage Storage context.
	 * @return string
	 */
	private static function storage_artifact_key( $local_key, $storage ) {
		$prefix = isset( $storage['object_prefix'] ) ? trim( (string) $storage['object_prefix'], '/' ) : '';

		return ltrim( ( $prefix ? $prefix . '/' : '' ) . ltrim( (string) $local_key, '/' ), '/' );
	}

	/**
	 * Map a WordPress-local key to its public storage URL.
	 *
	 * @param string $local_key WordPress-local artifact key or directory.
	 * @param array  $storage Storage context.
	 * @return string
	 */
	private static function storage_artifact_url( $local_key, $storage ) {
		$key  = self::storage_artifact_key( $local_key, $storage );
		$host = isset( $storage['public_baseurl'] ) ? untrailingslashit( $storage['public_baseurl'] ) : '';

		return esc_url_raw( trailingslashit( $host ) . str_replace( '%2F', '/', rawurlencode( $key ) ) );
	}

	/**
	 * Resolve and constrain a FileToWeb asset URL.
	 *
	 * @param string $value URL value.
	 * @param string $base_url Base URL.
	 * @return string
	 */
	private static function resolve_asset_url( $value, $base_url ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value || '#' === substr( $value, 0, 1 ) || preg_match( '~^(data|blob|mailto|tel|javascript):~i', $value ) ) {
			return '';
		}

		$base = parse_url( $base_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}

		$parts = parse_url( $value );
		if ( ! empty( $parts['scheme'] ) ) {
			$url = $value;
		} elseif ( 0 === strpos( $value, '//' ) ) {
			$url = $base['scheme'] . ':' . $value;
		} elseif ( 0 === strpos( $value, '/' ) ) {
			$url = $base['scheme'] . '://' . $base['host'] . $value;
		} else {
			$base_path = isset( $base['path'] ) ? preg_replace( '~/[^/]*$~', '/', $base['path'] ) : '/';
			$url       = $base['scheme'] . '://' . $base['host'] . $base_path . $value;
		}

		$url  = Security::sanitize_filetoweb_url( $url );
		$path = $url ? (string) parse_url( $url, PHP_URL_PATH ) : '';

		return $url && preg_match( '~^/d/[^/]+/assets/.+~', $path ) ? $url : '';
	}

	/**
	 * Strip active CSS constructs while preserving presentation CSS.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function sanitize_css( $css ) {
		$css = is_string( $css ) ? $css : '';
		$css = preg_replace( '~@import\s+[^;]+;?~i', '', $css );
		$css = preg_replace( '~expression\s*\([^)]*\)~i', '', $css );
		$css = preg_replace( '~url\(\s*["\']?\s*(?:javascript|vbscript):[^)]*\)~i', 'none', $css );
		$css = preg_replace( '~(?:behavior|-moz-binding)\s*:[^;}]*(?:;|(?=}))~i', '', $css );

		return trim( $css );
	}

	/**
	 * Validate a URL that can remain in the static document.
	 *
	 * @param string $value URL.
	 * @param bool   $is_link Whether mailto/tel are allowed.
	 * @return bool
	 */
	private static function safe_embedded_url( $value, $is_link ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value || '#' === substr( $value, 0, 1 ) || '/' === substr( $value, 0, 1 ) || './' === substr( $value, 0, 2 ) || '../' === substr( $value, 0, 3 ) ) {
			return true;
		}

		if ( 0 === stripos( $value, 'data:image/' ) ) {
			return true;
		}

		$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
		if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return true;
		}

		return $is_link && in_array( $scheme, array( 'mailto', 'tel' ), true );
	}

	/**
	 * Synchronize every bundle file through WP Stateless when installed.
	 *
	 * @param string $bundle_dir Bundle directory.
	 * @param string $uploads_basedir Upload base directory.
	 * @param string $uploads_baseurl Upload base URL.
	 * @param array  $storage Resolved storage context.
	 * @return bool
	 */
	private static function sync_bundle_with_stateless( $bundle_dir, $uploads_basedir, $uploads_baseurl, $storage = array() ) {
		if ( ! function_exists( 'has_action' ) || ! has_action( 'sm:sync::syncFile' ) ) {
			return true;
		}

		$files = self::files_in_directory( $bundle_dir );
		if ( empty( $files ) ) {
			return false;
		}

		if ( empty( $storage ) ) {
			$first_name = ltrim( str_replace( wp_normalize_path( $uploads_basedir ), '', wp_normalize_path( $files[0] ) ), '/' );
			$storage    = self::storage_context( $uploads_basedir, $uploads_baseurl, $first_name );
		}

		if ( empty( $storage ) || empty( $storage['stateless'] ) ) {
			return false;
		}

		foreach ( $files as $path ) {
			$name        = ltrim( str_replace( wp_normalize_path( $uploads_basedir ), '', wp_normalize_path( $path ) ), '/' );
			$object_name = self::storage_artifact_key( $name, $storage );

			if ( ! self::sync_file_with_stateless( $name, $path, $object_name, $storage['client'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Synchronize one artifact while preserving its complete GCS object name.
	 *
	 * WP Stateless 4.4.1 reduces non-media names to basename() in true
	 * Stateless mode. Preview bundles contain nested, relative asset paths, so
	 * allowing that behavior updates the ACL for a different object and leaves
	 * the actual preview private. A scoped final filter restores the exact object
	 * name for this synchronous sync call without affecting other uploads.
	 *
	 * @param string $name Relative artifact name below the uploads directory.
	 * @param string $path Absolute artifact path.
	 * @param string $object_name Exact bucket-relative object name.
	 * @param object $client WP Stateless GCS client.
	 * @return bool
	 */
	private static function sync_file_with_stateless( $name, $path, $object_name, $client ) {
		$args = array(
			'ephemeral'      => false,
			'use_root'      => true,
			'source'         => 'filetoweb-integration',
			'source_version' => defined( 'FILETOWEB_INTEGRATION_VERSION' ) ? FILETOWEB_INTEGRATION_VERSION : '',
		);

		$marker = 'filetoweb-preserve-object-name';
		$filter = function ( $filtered_name, $use_root ) use ( $marker, $object_name ) {
			return $marker === $use_root || true === $use_root ? $object_name : $filtered_name;
		};

		$args['name_with_root'] = $marker;
		add_filter( 'wp_stateless_file_name', $filter, PHP_INT_MAX, 2 );

		try {
			do_action( 'sm:sync::syncFile', $name, $path, 2, $args );
		} finally {
			remove_filter( 'wp_stateless_file_name', $filter, PHP_INT_MAX );
		}

		if ( ! is_object( $client ) || ! is_callable( array( $client, 'media_exists' ) ) ) {
			return false;
		}

		$remote = $client->media_exists( $object_name );

		return ! is_wp_error( $remote ) && (bool) $remote;
	}

	/**
	 * Build the exact bucket-relative object name for a gs:// uploads directory.
	 *
	 * @param string $uploads_basedir WordPress uploads base directory.
	 * @param string $name Relative artifact name below the uploads directory.
	 * @return string Empty outside true WP Stateless mode.
	 */
	private static function stateless_object_name( $uploads_basedir, $name ) {
		$uploads_basedir = wp_normalize_path( (string) $uploads_basedir );
		$parts           = parse_url( $uploads_basedir );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'] ) || 'gs' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}

		$root = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';

		return ltrim( ( $root ? $root . '/' : '' ) . ltrim( (string) $name, '/' ), '/' );
	}

	/**
	 * Return files recursively through local or stream-wrapper directories.
	 *
	 * @param string $dir Directory.
	 * @return array
	 */
	private static function files_in_directory( $dir ) {
		$files  = array();
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			return $files;
		}

		while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '.' === $name || '..' === $name ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $name;

			if ( is_dir( $path ) ) {
				$files = array_merge( $files, self::files_in_directory( $path ) );
			} elseif ( is_file( $path ) ) {
				$files[] = $path;
			}
		}

		closedir( $handle );

		return $files;
	}

	/**
	 * Remove a temporary directory recursively.
	 *
	 * @param string $dir Directory.
	 */
	private static function remove_directory( $dir ) {
		$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $handle ) {
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '.' === $name || '..' === $name ) {
					continue;
				}

				$path = trailingslashit( $dir ) . $name;

				if ( is_dir( $path ) ) {
					self::remove_directory( $path );
				} elseif ( is_file( $path ) ) {
					unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}

			closedir( $handle );
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_rmdir
		}
	}

	/**
	 * Preserve the legacy pointer for older ProudCity/plugin rendering paths.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $path Artifact path.
	 * @param string $token Token.
	 * @param string $viewer_url Viewer URL.
	 * @param string $fingerprint Fingerprint.
	 * @param string $published_at Publication time.
	 */
	private static function write_legacy_pointer( $post_id, $path, $token, $viewer_url, $fingerprint, $published_at ) {
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_PATH, $path );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_TOKEN, $token );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, $viewer_url );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, $fingerprint );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_UPDATED_AT, $published_at );
	}

	/**
	 * Set a default provider entry only when an administrator has not chosen one.
	 */
	private static function ensure_provider_state() {
		$providers = get_option( self::OPTION_PROVIDERS, array() );
		$providers = is_array( $providers ) ? $providers : array();

		if ( array_key_exists( self::PROVIDER, $providers ) ) {
			return;
		}

		$settings = Settings::settings();
		$enabled  = ! empty( $settings[ Settings::KEY_REPLACE_LINKS ] ) && '0' !== (string) $settings[ Settings::KEY_REPLACE_LINKS ];

		$providers[ self::PROVIDER ] = $enabled;
		update_option( self::OPTION_PROVIDERS, $providers, false );
	}

	/**
	 * Determine whether a record already points to the current artifact.
	 *
	 * @param array  $record Record.
	 * @param string $source_url Source URL.
	 * @param string $fingerprint Fingerprint.
	 * @param string $artifact_key Artifact key.
	 * @return bool
	 */
	private static function record_matches( $record, $source_url, $fingerprint, $artifact_key ) {
		return is_array( $record )
			&& self::SCHEMA_VERSION <= (int) ( isset( $record['version'] ) ? $record['version'] : 0 )
			&& $source_url === ( isset( $record['source_url'] ) ? $record['source_url'] : '' )
			&& $fingerprint === ( isset( $record['source_fingerprint'] ) ? $record['source_fingerprint'] : '' )
			&& $artifact_key === ( isset( $record['artifact_key'] ) ? $record['artifact_key'] : '' )
			&& ! empty( $record['token'] );
	}

	/**
	 * Validate a legacy local cache path for migration.
	 *
	 * @param mixed $path Path.
	 * @return string
	 */
	private static function safe_legacy_path( $path ) {
		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? realpath( trailingslashit( $uploads['basedir'] ) . 'filetoweb-integration' ) : false;
		$path    = is_string( $path ) ? realpath( $path ) : false;

		return $path && $basedir && 0 === strpos( $path, trailingslashit( $basedir ) ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * Normalize a fingerprint for storage/path use.
	 *
	 * @param mixed $fingerprint Fingerprint.
	 * @return string
	 */
	private static function sanitize_fingerprint( $fingerprint ) {
		$fingerprint = is_scalar( $fingerprint ) ? sanitize_text_field( (string) $fingerprint ) : '';

		return substr( $fingerprint, 0, 255 );
	}

	/**
	 * Return a deterministic path-safe fingerprint segment.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return string
	 */
	private static function fingerprint_slug( $fingerprint ) {
		$slug = substr( preg_replace( '/[^a-zA-Z0-9]/', '', $fingerprint ), 0, 48 );

		return $slug ? strtolower( $slug ) : substr( hash( 'sha256', $fingerprint ), 0, 48 );
	}

	/**
	 * Build a deterministic bundle segment for a PDF source and optional HTML revision.
	 *
	 * Explicit refreshes use a content suffix so editor-only changes publish to
	 * a new immutable path even though the source PDF fingerprint is unchanged.
	 *
	 * @param string $fingerprint Source fingerprint.
	 * @param string $html Prepared HTML.
	 * @param bool   $content_versioned Include the HTML revision in the path.
	 * @return string
	 */
	private static function bundle_slug( $fingerprint, $html, $content_versioned ) {
		$slug = self::fingerprint_slug( $fingerprint );

		if ( ! $content_versioned ) {
			return $slug;
		}

		return $slug . '-' . substr( hash( 'sha256', (string) $html ), 0, 16 );
	}
}
