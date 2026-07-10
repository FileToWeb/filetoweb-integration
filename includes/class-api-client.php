<?php
/**
 * FileToWeb API client.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api_Client {
	/**
	 * Verify the configured key and return its effective workspace context.
	 *
	 * @return array
	 */
	public static function get_auth_context() {
		return self::request( 'GET', '/auth/context', null );
	}

	/**
	 * Upsert a FileToWeb document.
	 *
	 * @param array $payload API payload.
	 * @return array
	 */
	public static function upsert_document( $payload ) {
		return self::request( 'POST', '/documents', $payload );
	}

	/**
	 * Fetch document status.
	 *
	 * @param string $document_id FileToWeb document ID.
	 * @return array
	 */
	public static function get_document( $document_id ) {
		return self::request( 'GET', '/documents/' . rawurlencode( $document_id ), null );
	}

	/**
	 * Finalize a signed upload and queue conversion.
	 *
	 * @param string $document_id FileToWeb document ID.
	 * @param array  $payload API payload.
	 * @return array
	 */
	public static function complete_upload( $document_id, $payload ) {
		return self::request( 'POST', '/documents/' . rawurlencode( $document_id ) . '/complete-upload', $payload );
	}

	/**
	 * Upload a local file to FileToWeb's signed upload URL.
	 *
	 * @param array  $upload Signed upload instructions.
	 * @param string $file_path Local PDF path.
	 * @return array
	 */
	public static function upload_file( $upload, $file_path ) {
		if ( ! is_array( $upload ) || empty( $upload['url'] ) || ! is_readable( $file_path ) ) {
			return self::error( __( 'FileToWeb upload instructions are missing.', 'filetoweb-integration' ) );
		}

		$url    = esc_url_raw( $upload['url'] );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		if ( 'https' !== $scheme ) {
			return self::error( __( 'FileToWeb signed upload URL must use HTTPS.', 'filetoweb-integration' ) );
		}

		$headers = isset( $upload['headers'] ) && is_array( $upload['headers'] ) ? $upload['headers'] : array();
		$method  = isset( $upload['method'] ) ? strtoupper( sanitize_key( $upload['method'] ) ) : 'PUT';
		$body    = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $body ) {
			return self::error( __( 'PDF upload file could not be read.', 'filetoweb-integration' ) );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'             => in_array( $method, array( 'PUT', 'POST' ), true ) ? $method : 'PUT',
				'timeout'            => 45,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => self::sanitize_upload_headers( $headers ),
				'body'               => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error( $response->get_error_message() );
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return self::error( sprintf( __( 'FileToWeb signed upload returned HTTP %d.', 'filetoweb-integration' ), $code ) );
		}

		return array(
			'ok'    => true,
			'error' => '',
			'body'  => array(),
		);
	}

	/**
	 * Make an authenticated FileToWeb API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path API path below /v1.
	 * @param array|null $body JSON body.
	 * @return array
	 */
	private static function request( $method, $path, $body ) {
		$base_url = Settings::api_base_url();

		if ( ! Settings::is_api_base_url_allowed( $base_url ) ) {
			return self::error( __( 'FileToWeb API URL is not allowed.', 'filetoweb-integration' ) );
		}

		$api_key = Settings::api_key();

		if ( '' === $api_key ) {
			return self::error( __( 'FileToWeb API key is missing.', 'filetoweb-integration' ) );
		}

		$url  = untrailingslashit( $base_url ) . '/v1' . $path;
		$args = array(
			'method'             => $method,
			'timeout'            => self::request_timeout( $method, $path ),
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return self::error( $response->get_error_message() );
		}

		$code     = absint( wp_remote_retrieve_response_code( $response ) );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			return self::error( self::extract_error_message( $decoded, $raw_body, $code ) );
		}

		return array(
			'ok'    => true,
			'error' => '',
			'body'  => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Should a failed request be retried instead of treated as a terminal conversion failure?
	 *
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function is_retryable_error( $message ) {
		$message = strtolower( (string) $message );

		foreach ( array( 'timed out', 'timeout', 'cURL error 28', 'failed to connect', 'connection reset', 'connection refused' ) as $needle ) {
			if ( false !== strpos( $message, strtolower( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Bounded API request timeout for admin/save flows.
	 *
	 * @param string $method HTTP method.
	 * @param string $path API path below /v1.
	 * @return int
	 */
	private static function request_timeout( $method, $path ) {
		$timeout = apply_filters( 'filetoweb_integration_api_timeout', 20, $method, $path );

		return max( 5, min( 45, absint( $timeout ) ) );
	}

	/**
	 * Sanitize signed-upload headers returned by FileToWeb.
	 *
	 * @param array $headers Raw headers.
	 * @return array
	 */
	private static function sanitize_upload_headers( $headers ) {
		$sanitized = array();

		foreach ( $headers as $key => $value ) {
			$key = sanitize_text_field( (string) $key );

			if ( '' === $key || preg_match( '/[\r\n]/', $key ) ) {
				continue;
			}

			if ( is_scalar( $value ) && ! preg_match( '/[\r\n]/', (string) $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private static function error( $message ) {
		return array(
			'ok'    => false,
			'error' => Security::sanitize_error( $message ),
			'body'  => array(),
		);
	}

	/**
	 * Extract API error text.
	 *
	 * @param mixed  $decoded Decoded JSON.
	 * @param string $raw_body Raw body.
	 * @param int    $status_code HTTP status code.
	 * @return string
	 */
	private static function extract_error_message( $decoded, $raw_body, $status_code ) {
		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) && isset( $decoded['error']['message'] ) ) {
			return $decoded['error']['message'];
		}

		$raw_body = trim( (string) $raw_body );

		if ( $raw_body && $raw_body === wp_strip_all_tags( $raw_body ) && strlen( $raw_body ) <= 300 ) {
			return $raw_body;
		}

		return sprintf( __( 'FileToWeb API returned HTTP %d.', 'filetoweb-integration' ), absint( $status_code ) );
	}
}
