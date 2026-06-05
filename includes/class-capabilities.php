<?php
/**
 * WordPress capability helpers.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	/**
	 * Capability required to manage API settings.
	 *
	 * @return string
	 */
	public static function manage_settings_capability() {
		return self::filtered_capability( 'filetoweb_integration_manage_settings_capability', 'activate_plugins' );
	}

	/**
	 * Capability required to sync/poll PDFs.
	 *
	 * @return string
	 */
	public static function sync_capability() {
		return self::filtered_capability( 'filetoweb_integration_sync_capability', 'edit_others_posts' );
	}

	/**
	 * Capability required to create/approve local WordPress pages.
	 *
	 * @return string
	 */
	public static function native_page_capability() {
		return self::filtered_capability( 'filetoweb_integration_native_page_capability', 'publish_pages' );
	}

	/**
	 * Can the current user manage FileToWeb settings?
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_settings() {
		return current_user_can( self::manage_settings_capability() );
	}

	/**
	 * Can the current user sync a source post?
	 *
	 * @param int $post_id Optional post ID.
	 * @return bool
	 */
	public static function current_user_can_sync( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( ! current_user_can( self::sync_capability() ) ) {
			return false;
		}

		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Can the current user manage local WordPress page output?
	 *
	 * @param int $post_id Optional source post ID.
	 * @return bool
	 */
	public static function current_user_can_manage_native_page( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( ! current_user_can( self::native_page_capability() ) ) {
			return false;
		}

		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Return a filtered capability with a safe fallback.
	 *
	 * @param string $filter Filter name.
	 * @param string $default Default capability.
	 * @return string
	 */
	private static function filtered_capability( $filter, $default ) {
		$capability = apply_filters( $filter, $default );
		$capability = is_scalar( $capability ) ? sanitize_key( (string) $capability ) : '';

		return $capability ? $capability : $default;
	}
}
