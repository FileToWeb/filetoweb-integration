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
			Meeting_Materials::init();
			Local_HTML::init();
			Native_Page::init();
			PDF_To_Page::init();
			Bulk_Queue::init();
			Link_Rewriter::init();
			Accessibility_Attribution::init();

		if ( apply_filters( 'filetoweb_integration_enable_widget', true ) ) {
			Document_Widget::init();
		}
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
