<?php
/**
 * WP-CLI command registration.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers every FileToWeb WP-CLI command.
 *
 * Site operators run this plugin on fleets where the only reliable access is a
 * shell in a running container, so every maintenance action the admin screens
 * expose needs a scriptable equivalent.
 */
class CLI {
	const COMMAND = 'filetoweb';

	/**
	 * Register commands when the request is a WP-CLI invocation.
	 */
	public static function init() {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( self::COMMAND . ' preview', __NAMESPACE__ . '\\Preview_Command' );
	}
}
