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
	const OPTION_SETTINGS = 'filetoweb_integration_settings';
	const OPTION_EPUB_DEFAULT_OFF_MIGRATED = 'filetoweb_integration_epub_default_off_migrated';

	const KEY_ENABLED       = 'enabled';
	const KEY_API_BASE_URL  = 'api_base_url';
	const KEY_API_KEY       = 'api_key';
	const KEY_REPLACE_LINKS = 'replace_links';
	const KEY_EPUB_DOWNLOAD = 'epub_download';
	const KEY_BATCH_SIZE    = 'batch_size';

	const LEGACY_OPTION_ENABLED       = 'filetoweb_integration_enabled';
	const LEGACY_OPTION_API_BASE_URL  = 'filetoweb_integration_api_base_url';
	const LEGACY_OPTION_API_KEY       = 'filetoweb_integration_api_key';
	const LEGACY_OPTION_REPLACE_LINKS = 'filetoweb_integration_replace_links';
	const LEGACY_OPTION_BATCH_SIZE    = 'filetoweb_integration_batch_size';

	const DEFAULT_API_BASE_URL = 'https://filetoweb.com';

	/**
	 * Register hooks.
	 */
		public static function init() {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'init', array( __CLASS__, 'migrate_legacy_options' ), 5 );
			add_action( 'init', array( __CLASS__, 'migrate_epub_default_off' ), 6 );
			add_filter( 'option_page_capability_filetoweb_integration', array( __CLASS__, 'settings_page_capability' ) );
		}

		/**
		 * Capability required by options.php for this settings group.
		 *
		 * @return string
		 */
		public static function settings_page_capability() {
			return Capabilities::manage_settings_capability();
		}

	/**
	 * Register the plugin-owned settings row.
	 */
	public static function register_settings() {
		register_setting(
			'filetoweb_integration',
			self::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Return an HTML input name for a settings key.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	public static function field_name( $key ) {
		return self::OPTION_SETTINGS . '[' . sanitize_key( $key ) . ']';
	}

	/**
	 * Return a stable field id for a settings key.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	public static function field_id( $key ) {
		return self::OPTION_SETTINGS . '_' . sanitize_key( $key );
	}

	/**
	 * Return normalized plugin settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$settings = get_option( self::OPTION_SETTINGS, array() );

		return self::normalize_settings( $settings, self::defaults(), false, false );
	}

	/**
	 * Is FileToWeb enabled?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = self::settings();

		return '1' === $settings[ self::KEY_ENABLED ];
	}

	/**
	 * Should public links be replaced when a generated page is ready?
	 *
	 * @return bool
	 */
	public static function replace_links_enabled() {
		$settings = self::settings();

		return self::enabled() && '1' === $settings[ self::KEY_REPLACE_LINKS ];
	}

	/**
	 * Should ready Proud Document pages show an EPUB download link?
	 *
	 * @return bool
	 */
	public static function epub_download_enabled() {
		$settings = self::settings();

		return self::enabled() && '1' === $settings[ self::KEY_EPUB_DOWNLOAD ];
	}

	/**
	 * Return a validated API base URL.
	 *
	 * @return string
	 */
	public static function api_base_url() {
		$settings = self::settings();
		$value    = self::normalize_url( $settings[ self::KEY_API_BASE_URL ] );

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
		$settings = self::settings();

		return trim( (string) $settings[ self::KEY_API_KEY ] );
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
		$settings = self::settings();

		return max( 1, min( 100, absint( $settings[ self::KEY_BATCH_SIZE ] ) ) );
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
	 * Sanitize the complete settings row.
	 *
	 * @param mixed $value Raw settings array.
	 * @return array
	 */
	public static function sanitize_settings( $value ) {
		return self::normalize_settings( $value, self::settings(), true, true );
	}

	/**
	 * Sanitize API base URL.
	 *
	 * @param mixed $value Raw URL.
	 * @return string
	 */
	public static function sanitize_api_base_url( $value ) {
		$previous = self::settings();
		$value    = self::normalize_url( $value );

		if ( self::is_api_base_url_allowed( $value ) ) {
			return untrailingslashit( $value );
		}

		add_settings_error(
			self::OPTION_SETTINGS,
			'filetoweb_invalid_api_base_url',
			__( 'FileToWeb API URL must be HTTPS and use an allowed FileToWeb host.', 'filetoweb-integration' )
		);

		$previous_url = $previous[ self::KEY_API_BASE_URL ];

		return self::is_api_base_url_allowed( $previous_url ) ? untrailingslashit( $previous_url ) : self::DEFAULT_API_BASE_URL;
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
		$previous = self::settings();

		return self::sanitize_api_key_value( $value, $previous[ self::KEY_API_KEY ], true );
	}

	/**
	 * Migrate the pre-0.1.1 multi-option model into the single settings row.
	 */
	public static function migrate_legacy_options() {
		$existing = get_option( self::OPTION_SETTINGS, null );

		if ( is_array( $existing ) ) {
			return;
		}

		$legacy = array(
			self::KEY_ENABLED       => get_option( self::LEGACY_OPTION_ENABLED, null ),
			self::KEY_API_BASE_URL  => get_option( self::LEGACY_OPTION_API_BASE_URL, null ),
			self::KEY_API_KEY       => get_option( self::LEGACY_OPTION_API_KEY, null ),
			self::KEY_REPLACE_LINKS => get_option( self::LEGACY_OPTION_REPLACE_LINKS, null ),
			self::KEY_BATCH_SIZE    => get_option( self::LEGACY_OPTION_BATCH_SIZE, null ),
		);

		$has_legacy = false;

		foreach ( $legacy as $value ) {
			if ( null !== $value ) {
				$has_legacy = true;
				break;
			}
		}

		if ( ! $has_legacy ) {
			return;
		}

		update_option( self::OPTION_SETTINGS, self::normalize_settings( $legacy, self::defaults(), false, false ) );
		self::delete_legacy_options();
	}

	/**
	 * Hide the optional EPUB download by default on existing pilot installs.
	 *
	 * This one-time migration intentionally runs once so an administrator can
	 * re-enable EPUB later without the plugin flipping it back off.
	 */
	public static function migrate_epub_default_off() {
		if ( get_option( self::OPTION_EPUB_DEFAULT_OFF_MIGRATED, '' ) ) {
			return;
		}

		$settings = get_option( self::OPTION_SETTINGS, null );

		if ( is_array( $settings ) ) {
			$settings                            = self::normalize_settings( $settings, self::defaults(), false, false );
			$settings[ self::KEY_EPUB_DOWNLOAD ] = '0';

			update_option( self::OPTION_SETTINGS, $settings );
		}

		update_option( self::OPTION_EPUB_DEFAULT_OFF_MIGRATED, '1' );
	}

	/**
	 * Delete legacy individual option rows.
	 */
	public static function delete_legacy_options() {
		delete_option( self::LEGACY_OPTION_ENABLED );
		delete_option( self::LEGACY_OPTION_API_BASE_URL );
		delete_option( self::LEGACY_OPTION_API_KEY );
		delete_option( self::LEGACY_OPTION_REPLACE_LINKS );
		delete_option( self::LEGACY_OPTION_BATCH_SIZE );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			self::KEY_ENABLED       => '1',
			self::KEY_API_BASE_URL  => self::DEFAULT_API_BASE_URL,
			self::KEY_API_KEY       => '',
			self::KEY_REPLACE_LINKS => '1',
			self::KEY_EPUB_DOWNLOAD => '0',
			self::KEY_BATCH_SIZE    => 25,
		);
	}

	/**
	 * Normalize a complete settings array.
	 *
	 * @param mixed $value Raw value.
	 * @param array $previous Previous normalized settings.
	 * @param bool  $add_errors Whether to register Settings API validation errors.
	 * @param bool  $honor_clear_key Whether to honor the clear API key checkbox.
	 * @return array
	 */
	private static function normalize_settings( $value, $previous, $add_errors, $honor_clear_key ) {
		$value    = is_array( $value ) ? $value : array();
		$previous = is_array( $previous ) ? array_merge( self::defaults(), $previous ) : self::defaults();

		$settings = self::defaults();

		$settings[ self::KEY_ENABLED ]       = self::sanitize_checkbox(
			array_key_exists( self::KEY_ENABLED, $value ) ? $value[ self::KEY_ENABLED ] : $previous[ self::KEY_ENABLED ]
		);
		$settings[ self::KEY_REPLACE_LINKS ] = self::sanitize_checkbox(
			array_key_exists( self::KEY_REPLACE_LINKS, $value ) ? $value[ self::KEY_REPLACE_LINKS ] : $previous[ self::KEY_REPLACE_LINKS ]
		);
		$settings[ self::KEY_EPUB_DOWNLOAD ] = self::sanitize_checkbox(
			array_key_exists( self::KEY_EPUB_DOWNLOAD, $value ) ? $value[ self::KEY_EPUB_DOWNLOAD ] : $previous[ self::KEY_EPUB_DOWNLOAD ]
		);
		$settings[ self::KEY_BATCH_SIZE ]    = self::sanitize_batch_size(
			array_key_exists( self::KEY_BATCH_SIZE, $value ) ? $value[ self::KEY_BATCH_SIZE ] : $previous[ self::KEY_BATCH_SIZE ]
		);

		$api_base_url = array_key_exists( self::KEY_API_BASE_URL, $value ) ? $value[ self::KEY_API_BASE_URL ] : $previous[ self::KEY_API_BASE_URL ];
		$api_base_url = self::normalize_url( $api_base_url );

		if ( self::is_api_base_url_allowed( $api_base_url ) ) {
			$settings[ self::KEY_API_BASE_URL ] = untrailingslashit( $api_base_url );
		} else {
			if ( $add_errors ) {
				add_settings_error(
					self::OPTION_SETTINGS,
					'filetoweb_invalid_api_base_url',
					__( 'FileToWeb API URL must be HTTPS and use an allowed FileToWeb host.', 'filetoweb-integration' )
				);
			}

			$settings[ self::KEY_API_BASE_URL ] = self::is_api_base_url_allowed( $previous[ self::KEY_API_BASE_URL ] )
				? untrailingslashit( $previous[ self::KEY_API_BASE_URL ] )
				: self::DEFAULT_API_BASE_URL;
		}

		$api_key = array_key_exists( self::KEY_API_KEY, $value ) ? $value[ self::KEY_API_KEY ] : '';

		$settings[ self::KEY_API_KEY ] = self::sanitize_api_key_value( $api_key, $previous[ self::KEY_API_KEY ], $honor_clear_key );

		return $settings;
	}

	/**
	 * Sanitize API key input while preserving existing keys on blank saves.
	 *
	 * @param mixed  $value Raw key.
	 * @param string $previous Previous key.
	 * @param bool   $honor_clear_key Whether to honor the clear API key checkbox.
	 * @return string
	 */
	private static function sanitize_api_key_value( $value, $previous, $honor_clear_key ) {
		$clear_key = false;

		if ( $honor_clear_key && isset( $_POST['filetoweb_integration_clear_api_key'] ) ) {
			$raw_clear_key = wp_unslash( $_POST['filetoweb_integration_clear_api_key'] );
			$clear_key     = is_scalar( $raw_clear_key ) && '1' === sanitize_text_field( $raw_clear_key );
		}

		if ( $clear_key ) {
			return '';
		}

		$value = is_scalar( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
		$value = trim( $value );

		if ( '' === $value ) {
			return trim( (string) $previous );
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
