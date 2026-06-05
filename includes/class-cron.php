<?php
/**
 * FileToWeb cron hooks.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron {
	const HOOK_POLL_PENDING = 'filetoweb_integration_poll_pending';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK_POLL_PENDING, array( __CLASS__, 'poll_pending' ) );
	}

	/**
	 * Add five-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules['filetoweb_every_five_minutes'] ) ) {
			$schedules['filetoweb_every_five_minutes'] = array(
				'interval' => 5 * 60,
				'display'  => __( 'Every five minutes', 'filetoweb-integration' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule polling if configured.
	 */
	public static function schedule() {
		if ( ! Settings::configured() ) {
			self::clear();
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK_POLL_PENDING ) ) {
			wp_schedule_event( time() + 60, 'filetoweb_every_five_minutes', self::HOOK_POLL_PENDING );
		}
	}

	/**
	 * Clear polling hook.
	 */
		public static function clear() {
			wp_clear_scheduled_hook( self::HOOK_POLL_PENDING );
			wp_clear_scheduled_hook( Bulk_Queue::HOOK_PROCESS );
		}

	/**
	 * Poll pending documents.
	 */
	public static function poll_pending() {
		Sync::poll_pending( Settings::batch_size() );
	}
}
