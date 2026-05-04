<?php
/**
 * Plugin bootstrap.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	/**
	 * Register WordPress hooks.
	 */
	public static function init() {
		load_plugin_textdomain(
			'filetoweb-integration',
			false,
			dirname( plugin_basename( FILETOWEB_INTEGRATION_FILE ) ) . '/languages'
		);

		Settings::init();
		Sync::init();
		Cron::init();
		Admin::init();
		Link_Rewriter::init();
		Document_Widget::init();
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		Cron::schedule();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		Cron::clear();
	}
}
