<?php
/**
 * Shared validation helpers.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Security {
	/**
	 * Validate a source URL before using it in HEAD requests or sending it to FileToWeb.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_safe_source_url( $url ) {
		$url    = esc_url_raw( $url );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! $host ) {
			return false;
		}

		$user = parse_url( $url, PHP_URL_USER );
		$pass = parse_url( $url, PHP_URL_PASS );

		if ( ( null !== $user && false !== $user ) || ( null !== $pass && false !== $pass ) ) {
			return false;
		}

		if ( self::is_private_or_local_host( $host ) ) {
			return false;
		}

		$resolved_ips = self::resolve_host_ips( $host );

		if ( is_array( $resolved_ips ) ) {
			foreach ( $resolved_ips as $ip ) {
				if ( self::is_private_or_local_ip( $ip ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Sanitize a FileToWeb-owned URL for storage/output.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function sanitize_filetoweb_url( $url ) {
		$url = is_scalar( $url ) ? esc_url_raw( trim( (string) $url ) ) : '';

		if ( ! $url || ! Settings::is_filetoweb_url_allowed( $url ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Sanitize FileToWeb document status.
	 *
	 * @param mixed $status Status.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$status = is_scalar( $status ) ? sanitize_key( $status ) : '';

		$allowed = array(
			'awaiting_upload',
			'uploaded',
			'queued',
			'pending',
			'importing',
			'processing',
			'converting',
			'ready',
			'failed',
			'error',
			'unknown',
		);

		return in_array( $status, $allowed, true ) ? $status : 'unknown';
	}

	/**
	 * Sanitize a short API identifier.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_identifier( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return substr( $value, 0, 255 );
	}

	/**
	 * Sanitize API error text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_error( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return substr( $value, 0, 1000 );
	}

	/**
	 * Remove provider and pipeline details from customer-facing errors.
	 *
	 * @param mixed $value Raw error.
	 * @return string
	 */
	public static function sanitize_public_error( $value ) {
		$value = self::sanitize_error( $value );

		foreach ( array( 'gemini', 'vertex', 'pdf_generator', 'call_gemini', 'generator_v2', 'aiplatform', 'openrouter', 'traceback', 'stack trace', 'requests.', 'httpx.', '.py:' ) as $internal_term ) {
			if ( false !== stripos( $value, $internal_term ) ) {
				return __( 'FileToWeb could not finish processing this PDF. Please try again.', 'filetoweb-integration' );
			}
		}

		return $value;
	}

	/**
	 * Normalize a public URL for map lookup.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_public_url_key( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
		$url = esc_url_raw( $url );

		return untrailingslashit( $url );
	}

	/**
	 * Is a host explicitly local/private?
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_private_or_local_host( $host ) {
		$host = strtolower( trim( $host, " \t\n\r\0\x0B[]" ) );

		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return true;
		}

		if ( preg_match( '/(^|\.)local$/', $host ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_private_or_local_ip( $host );
		}

		return false;
	}

	/**
	 * Resolve IPv4 and IPv6 host addresses.
	 *
	 * @param string $host Host.
	 * @return array
	 */
	private static function resolve_host_ips( $host ) {
		$ips  = array();
		$host = strtolower( trim( $host, " \t\n\r\0\x0B[]" ) );

		$ipv4_records = gethostbynamel( $host );

		if ( is_array( $ipv4_records ) ) {
			$ips = array_merge( $ips, $ipv4_records );
		}

		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$ipv6_records = dns_get_record( $host, DNS_AAAA );

			if ( is_array( $ipv6_records ) ) {
				foreach ( $ipv6_records as $record ) {
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			self::debug_log( 'No DNS records found for source host: ' . $host );
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Is an IP local/private/reserved?
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_private_or_local_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

		return false === filter_var( $ip, FILTER_VALIDATE_IP, $flags );
	}

	/**
	 * Log validation diagnostics only when WordPress debugging is active.
	 *
	 * @param string $message Message.
	 */
	private static function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[FileToWeb] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
