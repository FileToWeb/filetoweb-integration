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
	/**
	 * Locks held by the current request, keyed by advisory lock name.
	 *
	 * @var array
	 */
	private static $held_locks = array();

	const HOOK_POLL_PENDING = 'filetoweb_integration_poll_pending';
	const SCHEDULE_POLL     = 'filetoweb_every_minute';
	const OPTION_SCHEDULE   = 'filetoweb_integration_poll_schedule_version';
	const SCHEDULE_VERSION  = '2';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK_POLL_PENDING, array( __CLASS__, 'poll_pending' ) );
	}

	/**
	 * Add one-minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function add_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_POLL ] ) ) {
			$schedules[ self::SCHEDULE_POLL ] = array(
				'interval' => 60,
				'display'  => __( 'Every minute', 'filetoweb-integration' ),
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

		if ( self::SCHEDULE_VERSION !== (string) get_option( self::OPTION_SCHEDULE, '' ) ) {
			wp_clear_scheduled_hook( self::HOOK_POLL_PENDING );
			update_option( self::OPTION_SCHEDULE, self::SCHEDULE_VERSION, false );
		}

		if ( ! wp_next_scheduled( self::HOOK_POLL_PENDING ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE_POLL, self::HOOK_POLL_PENDING );
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
		return self::with_poll_lock(
			function () {
				return Sync::poll_pending( Settings::batch_size() );
			},
			array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 )
		);
	}

	/**
	 * Run polling work while holding the site-wide polling lock.
	 *
	 * @param callable $callback Work to run after acquiring the lock.
	 * @param mixed    $busy_result Value returned when another worker owns it.
	 * @return mixed
	 */
	public static function with_poll_lock( $callback, $busy_result = null ) {
		return self::with_named_lock( 'poll', $callback, $busy_result, false );
	}

	/**
	 * Run one document operation while holding its cross-pod database lock.
	 *
	 * @param int      $post_id Attachment or post that owns FileToWeb state.
	 * @param callable $callback Work to run after acquiring the lock.
	 * @param mixed    $busy_result Value returned when another worker owns it.
	 * @return mixed
	 */
	public static function with_item_lock( $post_id, $callback, $busy_result = null ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return $busy_result;
		}

		$scope = self::database_supports_multiple_locks() ? 'item:' . $post_id : 'poll';

		return self::with_named_lock( $scope, $callback, $busy_result, true );
	}

	/**
	 * Run work while holding one connection-scoped MySQL advisory lock.
	 *
	 * @param string   $scope Lock scope below the current WordPress site.
	 * @param callable $callback Work to run after acquiring the lock.
	 * @param mixed    $busy_result Value returned when another worker owns it.
	 * @param bool     $allow_without_database Whether to run when no WordPress database object is available.
	 * @return mixed
	 */
	private static function with_named_lock( $scope, $callback, $busy_result, $allow_without_database ) {
		if ( ! is_callable( $callback ) ) {
			return $busy_result;
		}

		if ( ! self::database_supports_locks() ) {
			return $allow_without_database ? call_user_func( $callback ) : $busy_result;
		}

		$lock_name = self::lock_name( $scope );

		if ( isset( self::$held_locks[ $lock_name ] ) ) {
			return call_user_func( $callback );
		}

		$lock_name = self::acquire_lock( $scope );

		if ( ! $lock_name ) {
			return $busy_result;
		}

		self::$held_locks[ $lock_name ] = true;

		try {
			return call_user_func( $callback );
		} finally {
			unset( self::$held_locks[ $lock_name ] );
			self::release_lock( $lock_name );
		}
	}

	/**
	 * Claim a connection-scoped MySQL advisory lock for this site.
	 *
	 * The database releases the lock when the request connection closes, so a
	 * crashed worker cannot leave stale state and a slow healthy worker cannot
	 * be overlapped by an expiring time-based lease.
	 *
	 * @param string $scope Lock scope.
	 * @return string Lock name, or an empty string when another worker owns it.
	 */
	private static function acquire_lock( $scope ) {
		global $wpdb;

		$lock_name = self::lock_name( $scope );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

		return '1' === (string) $acquired ? $lock_name : '';
	}

	/**
	 * Build a site-scoped advisory lock name.
	 *
	 * @param string $scope Lock scope.
	 * @return string
	 */
	private static function lock_name( $scope ) {
		return 'filetoweb_' . md5( home_url( '/' ) . ':' . sanitize_key( (string) $scope ) );
	}

	/**
	 * Release the current connection's polling lock.
	 *
	 * @param string $lock_name Lock name.
	 */
	private static function release_lock( $lock_name ) {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Whether the current WordPress database connection can use advisory locks.
	 *
	 * @return bool
	 */
	private static function database_supports_locks() {
		global $wpdb;

		return is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' );
	}

	/**
	 * Whether one database connection can hold more than one named lock.
	 *
	 * MySQL added this in 5.7.5. On older or unknown servers, item operations
	 * deliberately reuse the fleet poll lock so acquiring an item lock cannot
	 * release the outer lock and allow overlapping work.
	 *
	 * @return bool
	 */
	private static function database_supports_multiple_locks() {
		global $wpdb;

		if ( ! self::database_supports_locks() || ! method_exists( $wpdb, 'db_version' ) ) {
			return false;
		}

		return version_compare( (string) $wpdb->db_version(), '5.7.5', '>=' );
	}
}
