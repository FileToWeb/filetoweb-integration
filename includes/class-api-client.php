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
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array(
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
			return self::error( self::extract_error_message( $decoded, $raw_body ) );
		}

		return array(
			'ok'    => true,
			'error' => '',
			'body'  => is_array( $decoded ) ? $decoded : array(),
		);
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
	 * @return string
	 */
	private static function extract_error_message( $decoded, $raw_body ) {
		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) && isset( $decoded['error']['message'] ) ) {
			return $decoded['error']['message'];
		}

		return $raw_body ? $raw_body : __( 'FileToWeb API request failed.', 'filetoweb-integration' );
	}
}
