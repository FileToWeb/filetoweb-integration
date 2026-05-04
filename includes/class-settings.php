<?php
/**
 * FileToWeb settings.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	const OPTION_ENABLED       = 'filetoweb_integration_enabled';
	const OPTION_API_BASE_URL  = 'filetoweb_integration_api_base_url';
	const OPTION_API_KEY       = 'filetoweb_integration_api_key';
	const OPTION_REPLACE_LINKS = 'filetoweb_integration_replace_links';
	const OPTION_BATCH_SIZE    = 'filetoweb_integration_batch_size';

	const DEFAULT_API_BASE_URL = 'https://filetoweb.com';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register plugin-owned settings.
	 */
	public static function register_settings() {
		register_setting( 'filetoweb_integration', self::OPTION_ENABLED, array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'filetoweb_integration', self::OPTION_API_BASE_URL, array( __CLASS__, 'sanitize_api_base_url' ) );
		register_setting( 'filetoweb_integration', self::OPTION_API_KEY, array( __CLASS__, 'sanitize_api_key' ) );
		register_setting( 'filetoweb_integration', self::OPTION_REPLACE_LINKS, array( __CLASS__, 'sanitize_checkbox' ) );
		register_setting( 'filetoweb_integration', self::OPTION_BATCH_SIZE, array( __CLASS__, 'sanitize_batch_size' ) );
	}

	/**
	 * Is FileToWeb enabled?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return get_option( self::OPTION_ENABLED, '1' ) === '1';
	}

	/**
	 * Should public links be replaced when a generated page is ready?
	 *
	 * @return bool
	 */
	public static function replace_links_enabled() {
		return self::enabled() && get_option( self::OPTION_REPLACE_LINKS, '1' ) === '1';
	}

	/**
	 * Return a validated API base URL.
	 *
	 * @return string
	 */
	public static function api_base_url() {
		$value = get_option( self::OPTION_API_BASE_URL, self::DEFAULT_API_BASE_URL );
		$value = self::normalize_url( $value );

		if ( ! self::is_api_base_url_allowed( $value ) ) {
			return self::DEFAULT_API_BASE_URL;
		}

		return untrailingslashit( $value );
	}

	/**
	 * Return the stored API key.
	 *
	 * @return string
	 */
	public static function api_key() {
		return trim( (string) get_option( self::OPTION_API_KEY, '' ) );
	}

	/**
	 * Is the integration configured enough to call FileToWeb?
	 *
	 * @return bool
	 */
	public static function configured() {
		return self::enabled() && self::api_base_url() && self::api_key();
	}

	/**
	 * Return the configured bounded batch size.
	 *
	 * @return int
	 */
	public static function batch_size() {
		return max( 1, min( 100, absint( get_option( self::OPTION_BATCH_SIZE, 25 ) ) ) );
	}

	/**
	 * Hosts that may receive the FileToWeb API key.
	 *
	 * @return array
	 */
	public static function allowed_api_hosts() {
		$hosts = array( 'filetoweb.com' );

		/**
		 * Filters FileToWeb API hosts. Authorization headers are only sent to
		 * these hosts, and HTTPS is required.
		 *
		 * @param array $hosts Allowed API hosts.
		 */
		$hosts = apply_filters( 'filetoweb_integration_allowed_api_hosts', $hosts );

		return self::normalize_host_list( $hosts );
	}

	/**
	 * Hosts that may be stored or rendered as FileToWeb result/editor URLs.
	 *
	 * @return array
	 */
	public static function allowed_filetoweb_hosts() {
		$hosts = array(
			'filetoweb.com',
			'www.filetoweb.com',
			'app.filetoweb.com',
			'bundle-canary.filetoweb.com',
		);

		$api_host = parse_url( self::api_base_url(), PHP_URL_HOST );

		if ( $api_host ) {
			$hosts[] = $api_host;
		}

		/**
		 * Filters hosts that are trusted for FileToWeb result and editor URLs.
		 *
		 * @param array $hosts Allowed FileToWeb hosts.
		 */
		$hosts = apply_filters( 'filetoweb_integration_allowed_filetoweb_hosts', $hosts );

		return self::normalize_host_list( $hosts );
	}

	/**
	 * Validate an API base URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_api_base_url_allowed( $url ) {
		$url    = self::normalize_url( $url );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return 'https' === $scheme && $host && in_array( $host, self::allowed_api_hosts(), true );
	}

	/**
	 * Validate a FileToWeb result/editor URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_filetoweb_url_allowed( $url ) {
		$url    = self::normalize_url( $url );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return 'https' === $scheme && $host && in_array( $host, self::allowed_filetoweb_hosts(), true );
	}

	/**
	 * Sanitize checkbox option values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_checkbox( $value ) {
		return ! empty( $value ) && '0' !== (string) $value ? '1' : '0';
	}

	/**
	 * Sanitize API base URL.
	 *
	 * @param mixed $value Raw URL.
	 * @return string
	 */
	public static function sanitize_api_base_url( $value ) {
		$value = self::normalize_url( $value );

		if ( self::is_api_base_url_allowed( $value ) ) {
			return untrailingslashit( $value );
		}

		add_settings_error(
			self::OPTION_API_BASE_URL,
			'filetoweb_invalid_api_base_url',
			__( 'FileToWeb API URL must be HTTPS and use an allowed FileToWeb host.', 'filetoweb-integration' )
		);

		$previous = get_option( self::OPTION_API_BASE_URL, self::DEFAULT_API_BASE_URL );

		return self::is_api_base_url_allowed( $previous ) ? untrailingslashit( $previous ) : self::DEFAULT_API_BASE_URL;
	}

	/**
	 * Sanitize API key.
	 *
	 * Empty values preserve the existing key unless the clear checkbox was used.
	 *
	 * @param mixed $value Raw key.
	 * @return string
	 */
	public static function sanitize_api_key( $value ) {
		$clear_key = false;

		if ( isset( $_POST['filetoweb_integration_clear_api_key'] ) ) {
			$clear_key = ! empty( $_POST['filetoweb_integration_clear_api_key'] );
		}

		if ( $clear_key ) {
			return '';
		}

		$value = is_scalar( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
		$value = trim( $value );

		if ( '' === $value ) {
			return self::api_key();
		}

		return $value;
	}

	/**
	 * Sanitize batch size.
	 *
	 * @param mixed $value Raw batch size.
	 * @return int
	 */
	public static function sanitize_batch_size( $value ) {
		return max( 1, min( 100, absint( $value ) ) );
	}

	/**
	 * Normalize URL input.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		$url = is_scalar( $url ) ? trim( (string) wp_unslash( $url ) ) : '';
		$url = esc_url_raw( $url );

		return $url;
	}

	/**
	 * Normalize host allowlist values.
	 *
	 * @param array $hosts Hosts.
	 * @return array
	 */
	private static function normalize_host_list( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $hosts as $host ) {
			$host = strtolower( trim( (string) $host ) );
			$host = preg_replace( '/^https?:\/\//i', '', $host );
			$host = preg_replace( '/[:\/].*$/', '', $host );

			if ( $host ) {
				$normalized[] = $host;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
