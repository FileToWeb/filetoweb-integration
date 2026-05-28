<?php
/**
 * FileToWeb document metadata.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Document_State {
	const META_EXTERNAL_ID                  = '_filetoweb_external_id';
	const META_DOCUMENT_ID                  = '_filetoweb_document_id';
	const META_SOURCE_FINGERPRINT           = '_filetoweb_source_fingerprint';
	const META_SOURCE_FINGERPRINT_ALGORITHM = '_filetoweb_source_fingerprint_algorithm';
	const META_STATUS                       = '_filetoweb_status';
	const META_HTML_URL                     = '_filetoweb_html_url';
	const META_CONTINUOUS_URL               = '_filetoweb_continuous_url';
	const META_EDITOR_URL                   = '_filetoweb_editor_url';
	const META_PAGE_COUNT                   = '_filetoweb_page_count';
	const META_LAST_ERROR                   = '_filetoweb_last_error';
	const META_LAST_SYNCED_AT               = '_filetoweb_last_synced_at';
	const META_ORIGINAL_URL                 = '_filetoweb_original_url';

	/**
	 * Return all metadata keys owned by the plugin.
	 *
	 * @return array
	 */
	public static function meta_keys() {
		return array(
			self::META_EXTERNAL_ID,
			self::META_DOCUMENT_ID,
			self::META_SOURCE_FINGERPRINT,
			self::META_SOURCE_FINGERPRINT_ALGORITHM,
			self::META_STATUS,
			self::META_HTML_URL,
			self::META_CONTINUOUS_URL,
			self::META_EDITOR_URL,
			self::META_PAGE_COUNT,
			self::META_LAST_ERROR,
			self::META_LAST_SYNCED_AT,
			self::META_ORIGINAL_URL,
		);
	}

	/**
	 * Persist state returned by the FileToWeb API after upsert.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $document API document.
	 * @param array $source Resolved source.
	 */
	public static function write_from_api( $post_id, $document, $source ) {
		if ( ! is_array( $document ) || ! is_array( $source ) ) {
			return;
		}

		$external_id = isset( $document['external_id'] ) ? $document['external_id'] : $source['external_id'];

		update_post_meta( $post_id, self::META_EXTERNAL_ID, self::sanitize_external_id( $external_id ) );
		update_post_meta( $post_id, self::META_DOCUMENT_ID, Security::sanitize_identifier( self::array_get( $document, 'id' ) ) );
		update_post_meta( $post_id, self::META_SOURCE_FINGERPRINT, self::sanitize_fingerprint( self::array_get( $source, 'fingerprint' ) ) );
		update_post_meta( $post_id, self::META_SOURCE_FINGERPRINT_ALGORITHM, sanitize_key( self::array_get( $source, 'fingerprint_algorithm' ) ) );
		update_post_meta( $post_id, self::META_STATUS, Security::sanitize_status( self::array_get( $document, 'status' ) ) );
		update_post_meta( $post_id, self::META_HTML_URL, Security::sanitize_filetoweb_url( self::array_get( $document, 'html_url' ) ) );
		update_post_meta( $post_id, self::META_CONTINUOUS_URL, Security::sanitize_filetoweb_url( self::array_get( $document, 'continuous_url' ) ) );
		update_post_meta( $post_id, self::META_EDITOR_URL, Security::sanitize_filetoweb_url( self::array_get( $document, 'editor_url' ) ) );
		update_post_meta( $post_id, self::META_PAGE_COUNT, absint( self::array_get( $document, 'page_count' ) ) );
		update_post_meta( $post_id, self::META_LAST_ERROR, Security::sanitize_error( self::array_get( $document, 'error' ) ) );
		update_post_meta( $post_id, self::META_ORIGINAL_URL, esc_url_raw( self::array_get( $source, 'source_url' ) ) );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, current_time( 'mysql', true ) );
	}

	/**
	 * Persist state returned by a poll.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $document API document.
	 */
	public static function write_polled_state( $post_id, $document ) {
		if ( ! is_array( $document ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_STATUS, Security::sanitize_status( self::array_get( $document, 'status' ) ) );
		update_post_meta( $post_id, self::META_HTML_URL, Security::sanitize_filetoweb_url( self::array_get( $document, 'html_url' ) ) );
		update_post_meta( $post_id, self::META_CONTINUOUS_URL, Security::sanitize_filetoweb_url( self::array_get( $document, 'continuous_url' ) ) );
		update_post_meta( $post_id, self::META_EDITOR_URL, Security::sanitize_filetoweb_url( self::array_get( $document, 'editor_url' ) ) );
		update_post_meta( $post_id, self::META_PAGE_COUNT, absint( self::array_get( $document, 'page_count' ) ) );
		update_post_meta( $post_id, self::META_LAST_ERROR, Security::sanitize_error( self::array_get( $document, 'error' ) ) );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, current_time( 'mysql', true ) );
	}

	/**
	 * Copy FileToWeb metadata between attachment/document records.
	 *
	 * @param int $from_post_id Source post ID.
	 * @param int $to_post_id Target post ID.
	 */
	public static function copy_state( $from_post_id, $to_post_id ) {
		foreach ( self::meta_keys() as $key ) {
			update_post_meta( $to_post_id, $key, get_post_meta( $from_post_id, $key, true ) );
		}
	}

	/**
	 * Return ready generated HTML URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function ready_html_url( $post_id ) {
		if ( 'ready' !== get_post_meta( $post_id, self::META_STATUS, true ) ) {
			return '';
		}

		return Security::sanitize_filetoweb_url( get_post_meta( $post_id, self::META_HTML_URL, true ) );
	}

	/**
	 * Return ready continuous generated URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function ready_continuous_url( $post_id ) {
		if ( 'ready' !== get_post_meta( $post_id, self::META_STATUS, true ) ) {
			return '';
		}

		return Security::sanitize_filetoweb_url( get_post_meta( $post_id, self::META_CONTINUOUS_URL, true ) );
	}

	/**
	 * Mark a post sync as failed.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $error Error.
	 */
	public static function mark_failed( $post_id, $error ) {
		update_post_meta( $post_id, self::META_STATUS, 'failed' );
		update_post_meta( $post_id, self::META_LAST_ERROR, Security::sanitize_error( $error ) );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, current_time( 'mysql', true ) );
	}

	/**
	 * Mark a post for retry after a transient API/network error.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $error Error.
	 */
	public static function mark_pending_retry( $post_id, $error ) {
		update_post_meta( $post_id, self::META_STATUS, 'pending' );
		update_post_meta( $post_id, self::META_LAST_ERROR, Security::sanitize_error( $error ) );
		update_post_meta( $post_id, self::META_LAST_SYNCED_AT, current_time( 'mysql', true ) );
	}

	/**
	 * Array getter.
	 *
	 * @param array  $array Array.
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function array_get( $array, $key ) {
		return is_array( $array ) && array_key_exists( $key, $array ) ? $array[ $key ] : '';
	}

	/**
	 * Sanitize external ID without weakening the stable id format.
	 *
	 * @param mixed $value External ID.
	 * @return string
	 */
	private static function sanitize_external_id( $value ) {
		$value = Security::sanitize_identifier( $value );

		return preg_replace( '/[^a-zA-Z0-9:_\-.]/', '', $value );
	}

	/**
	 * Sanitize source fingerprint.
	 *
	 * @param mixed $value Fingerprint.
	 * @return string
	 */
	private static function sanitize_fingerprint( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return substr( $value, 0, 255 );
	}
}
