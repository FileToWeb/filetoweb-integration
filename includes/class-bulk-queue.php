<?php
/**
 * Bounded bulk sync queue.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulk_Queue {
	const OPTION_QUEUE  = 'filetoweb_integration_bulk_queue';
	const HOOK_PROCESS  = 'filetoweb_integration_process_bulk_queue';
	const ACTION_DOCS   = 'filetoweb_integration_queue_documents';
	const ACTION_MEET   = 'filetoweb_integration_queue_meetings';
	const ACTION_RUN    = 'filetoweb_integration_run_bulk_queue';

	/**
	 * Seconds between one queue run and the next.
	 */
	const DEFAULT_BATCH_INTERVAL = 60;

	/**
	 * Wall-clock seconds one queue run may spend starting new items.
	 *
	 * A single item can spend minutes on remote calls, so a batch that starts
	 * every item it is allowed can outlive the request that owns it.
	 */
	const DEFAULT_BATCH_TIMEOUT = 45;

	/**
	 * Seconds without a saved item before an unscheduled queue counts as abandoned.
	 */
	const RECOVERY_STALE_SECONDS = 300;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process_next_batch' ) );
		add_action( Cron::HOOK_POLL_PENDING, array( __CLASS__, 'maybe_recover_queue' ), 5 );
		add_action( 'admin_post_' . self::ACTION_DOCS, array( __CLASS__, 'handle_queue_documents' ) );
		add_action( 'admin_post_' . self::ACTION_MEET, array( __CLASS__, 'handle_queue_meetings' ) );
		add_action( 'admin_post_' . self::ACTION_RUN, array( __CLASS__, 'handle_run_queue' ) );
	}

	/**
	 * Queue all Proud Documents.
	 *
	 * @return array
	 */
	public static function queue_documents() {
		$ids   = get_posts(
			array(
				'post_type'      => 'document',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$items = array();

		foreach ( $ids as $id ) {
			$items[] = array(
				'id'   => absint( $id ),
				'kind' => 'document',
			);
		}

		return self::replace_queue( $items, 'documents' );
	}

	/**
	 * Queue all ProudCity Meeting PDF materials.
	 *
	 * @return array
	 */
	public static function queue_meeting_pdfs() {
		$meeting_ids = get_posts(
			array(
				'post_type'      => 'meeting',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$items       = array();

		foreach ( $meeting_ids as $meeting_id ) {
			foreach ( Meeting_Materials::pdf_attachment_ids_for_meeting( $meeting_id ) as $attachment_id ) {
				$items[] = array(
					'id'   => absint( $attachment_id ),
					'kind' => 'attachment',
				);
			}
		}

		return self::replace_queue( $items, 'meeting_pdfs' );
	}

	/**
	 * Process the next bounded queue batch.
	 *
	 * @return array
	 */
	public static function process_next_batch() {
		$result = Cron::with_bulk_lock( array( __CLASS__, 'run_next_batch' ), null );

		// WP-Cron removes single events before invoking them. A competing run
		// must replace the event it consumed, even though it did no work.
		if ( null === $result ) {
			if ( Settings::configured() && ! empty( self::queue_state()['items'] ) ) {
				self::schedule_next_batch();
			}
			return self::empty_counts();
		}

		return $result;
	}

	/**
	 * Process the next bounded queue batch while holding the bulk queue lock.
	 *
	 * The next run is scheduled before any item is synced and progress is saved
	 * after every item, so a worker that dies mid-batch leaves both a scheduled
	 * run and an accurate remaining list behind it. A deploy rolling the pod, an
	 * execution-time limit and an exhausted memory limit all end a run this way.
	 *
	 * @return array
	 */
	public static function run_next_batch() {
		// A previous callback in this request may have cached the option before
		// another worker checkpointed it. Refresh this one row after locking.
		wp_cache_delete( self::OPTION_QUEUE, 'options' );
		$state  = self::queue_state();
		$limit  = Settings::batch_size();
		$counts = self::empty_counts();

		if ( empty( $state['items'] ) || ! Settings::configured() ) {
			// Disabling the integration pauses an existing queue; it does not
			// silently count the remaining documents as skipped and discard them.
			self::clear_next_batch();
			return $counts;
		}

		self::schedule_next_batch();

		$deadline = microtime( true ) + self::batch_timeout();

		for ( $processed = 0; $processed < $limit; $processed++ ) {
			if ( empty( $state['items'] ) ) {
				break;
			}

			if ( $processed > 0 && microtime( true ) >= $deadline ) {
				break;
			}

			$item        = $state['items'][0];
			$result      = self::sync_item( $item, $state['type'] );
			$item_counts = self::empty_counts();

			// A manual sync or the poller may own this document's lock. It has
			// not been processed by this queue yet; leave it available to retry.
			if ( ! empty( $result['busy'] ) ) {
				break;
			}

			array_shift( $state['items'] );

			if ( isset( $result['status'] ) && ! in_array( $result['status'], array( 'failed', 'skipped' ), true ) ) {
				++$item_counts['queued'];
			} elseif ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
				++$item_counts['failed'];
			} else {
				++$item_counts['skipped'];
			}

			$state = self::save_queue_state( $state, $item_counts );
			if ( null === $state ) {
				// Do not start another item after a failed durable checkpoint.
				self::schedule_next_batch();
				return $counts;
			}

			$counts['queued']  += $item_counts['queued'];
			$counts['failed']  += $item_counts['failed'];
			$counts['skipped'] += $item_counts['skipped'];
		}

		if ( empty( $state['items'] ) ) {
			self::clear_next_batch();
		} else {
			// Also cover a successor consumed during a long-running item.
			self::schedule_next_batch();
		}

		return $counts;
	}

	/**
	 * Re-arm a queue whose run was lost with the worker that owned it.
	 *
	 * Runs on the one-minute poll. A queue that still holds items, has no run
	 * scheduled, and has saved nothing recently cannot make progress on its
	 * own, because a run is only ever scheduled by another run.
	 *
	 * @return bool Whether a run was scheduled.
	 */
	public static function maybe_recover_queue() {
		$state = self::queue_state();

		if ( empty( $state['items'] ) || ! Settings::configured() ) {
			return false;
		}

		if ( wp_next_scheduled( self::HOOK_PROCESS ) ) {
			return false;
		}

		$updated = $state['updated_at'] ? strtotime( $state['updated_at'] . ' UTC' ) : 0;

		if ( $updated && ( time() - $updated ) < self::recovery_stale_seconds() ) {
			return false;
		}

		return self::schedule_next_batch( 0 );
	}

	/**
	 * Return queue state.
	 *
	 * @return array
	 */
	public static function queue_state() {
		$state = get_option( self::OPTION_QUEUE, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state = array_merge(
			array(
				'type'       => '',
				'items'      => array(),
				'total'      => 0,
				'processed'  => 0,
				'queued'     => 0,
				'skipped'    => 0,
				'failed'     => 0,
				'updated_at' => '',
			),
			$state
		);

		$state['items'] = is_array( $state['items'] ) ? $state['items'] : array();

		return $state;
	}

	/**
	 * Handle queue documents action.
	 */
	public static function handle_queue_documents() {
		self::handle_queue_action( 'documents' );
	}

	/**
	 * Handle queue meetings action.
	 */
	public static function handle_queue_meetings() {
		self::handle_queue_action( 'meeting_pdfs' );
	}

	/**
	 * Handle run next queue batch action.
	 */
	public static function handle_run_queue() {
		if ( ! Capabilities::current_user_can_sync() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( self::ACTION_RUN );

		$counts = self::process_next_batch();

		Admin::set_notice( Admin::format_counts( __( 'Bulk sync batch', 'filetoweb-integration' ), $counts ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . Admin::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle a queue creation action.
	 *
	 * @param string $type Queue type.
	 */
	private static function handle_queue_action( $type ) {
		if ( ! Capabilities::current_user_can_sync() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_queue_' . $type );

		$state = 'meeting_pdfs' === $type ? self::queue_meeting_pdfs() : self::queue_documents();

		if ( ! empty( $state['busy'] ) ) {
			Admin::set_notice( __( 'A bulk sync batch is running. The existing queue was not replaced; please wait for it to finish before creating a new queue.', 'filetoweb-integration' ) );
		} else {
			Admin::set_notice( sprintf( __( 'Bulk sync queued %d item(s).', 'filetoweb-integration' ), absint( $state['total'] ) ) );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . Admin::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Replace queue with items.
	 *
	 * @param array  $items Items.
	 * @param string $type Type.
	 * @return array
	 */
	private static function replace_queue( $items, $type ) {
		$result = Cron::with_bulk_lock(
			function () use ( $items, $type ) {
				return self::replace_queue_unlocked( $items, $type );
			},
			null
		);

		return null === $result ? array_merge( self::queue_state(), array( 'busy' => true ) ) : $result;
	}

	/**
	 * Replace queue while holding the same lock as its workers.
	 *
	 * @param array  $items Items.
	 * @param string $type Queue type.
	 * @return array
	 */
	private static function replace_queue_unlocked( $items, $type ) {
		$normalized = array();
		$seen       = array();

		foreach ( $items as $item ) {
			$id   = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$kind = isset( $item['kind'] ) ? sanitize_key( $item['kind'] ) : '';
			$key  = $kind . ':' . $id;

			if ( ! $id || ! in_array( $kind, array( 'attachment', 'document' ), true ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$normalized[] = array(
				'id'   => $id,
				'kind' => $kind,
			);
		}

		$state = array(
			'type'       => sanitize_key( $type ),
			'items'      => $normalized,
			'total'      => count( $normalized ),
			'processed'  => 0,
			'queued'     => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'updated_at' => current_time( 'mysql', true ),
		);

		update_option( self::OPTION_QUEUE, $state, false );
		self::schedule_next_batch();

		return $state;
	}

	/**
	 * Persist queue state after one processed item.
	 *
	 * @param array $state State.
	 * @param array $counts Counts.
	 * @return array|null Saved state, or null when the checkpoint failed.
	 */
	private static function save_queue_state( $state, $counts ) {
		$processed = absint( $counts['queued'] ) + absint( $counts['skipped'] ) + absint( $counts['failed'] );

		$state['processed']  = absint( $state['processed'] ) + $processed;
		$state['queued']     = absint( $state['queued'] ) + absint( $counts['queued'] );
		$state['skipped']    = absint( $state['skipped'] ) + absint( $counts['skipped'] );
		$state['failed']     = absint( $state['failed'] ) + absint( $counts['failed'] );
		$state['updated_at'] = current_time( 'mysql', true );

		if ( ! update_option( self::OPTION_QUEUE, $state, false ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * Schedule next queue batch.
	 *
	 * @param int|null $delay Seconds to wait, or null for the configured interval.
	 * @return bool Whether a continuation is scheduled.
	 */
	private static function schedule_next_batch( $delay = null ) {
		if ( wp_next_scheduled( self::HOOK_PROCESS ) ) {
			return true;
		}

		$delay = null === $delay ? self::batch_interval() : max( 0, absint( $delay ) );

		return (bool) wp_schedule_single_event( time() + $delay, self::HOOK_PROCESS );
	}

	/**
	 * Drop the scheduled run left behind by a drained queue.
	 */
	private static function clear_next_batch() {
		if ( wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_clear_scheduled_hook( self::HOOK_PROCESS );
		}
	}

	/**
	 * Seconds between one queue run and the next.
	 *
	 * @return int
	 */
	private static function batch_interval() {
		$seconds = apply_filters( 'filetoweb_integration_bulk_batch_interval', self::DEFAULT_BATCH_INTERVAL );
		$seconds = is_numeric( $seconds ) ? (int) $seconds : self::DEFAULT_BATCH_INTERVAL;

		return max( 0, min( 3600, $seconds ) );
	}

	/**
	 * Wall-clock seconds one run may spend starting new items.
	 *
	 * A run always starts one item, so a zero budget means one item per run.
	 *
	 * @return float
	 */
	private static function batch_timeout() {
		$seconds = apply_filters( 'filetoweb_integration_bulk_batch_timeout', self::DEFAULT_BATCH_TIMEOUT );
		$seconds = is_numeric( $seconds ) ? (float) $seconds : (float) self::DEFAULT_BATCH_TIMEOUT;

		return max( 0.0, min( 600.0, $seconds ) );
	}

	/**
	 * Seconds without a saved item before an unscheduled queue counts as abandoned.
	 *
	 * @return int
	 */
	private static function recovery_stale_seconds() {
		$seconds = apply_filters( 'filetoweb_integration_bulk_recovery_stale_seconds', self::RECOVERY_STALE_SECONDS );
		$seconds = is_numeric( $seconds ) ? (int) $seconds : self::RECOVERY_STALE_SECONDS;

		return max( 60, min( 86400, $seconds ) );
	}

	/**
	 * Sync one queue item.
	 *
	 * @param array  $item Item.
	 * @param string $queue_type Queue type.
	 * @return array
	 */
	private static function sync_item( $item, $queue_type = '' ) {
		$id         = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		$kind       = isset( $item['kind'] ) ? sanitize_key( $item['kind'] ) : '';
		$queue_type = sanitize_key( $queue_type );

		if ( ! $id ) {
			return array( 'status' => 'skipped' );
		}

		$trigger = $queue_type ? 'bulk_' . $queue_type : 'bulk_queue';

		return 'document' === $kind ? Sync::sync_document_now( $id, $trigger ) : Sync::sync_attachment_now( $id, $trigger );
	}

	/**
	 * Empty counts.
	 *
	 * @return array
	 */
	private static function empty_counts() {
		return array(
			'queued'  => 0,
			'skipped' => 0,
			'failed'  => 0,
			'updated' => 0,
		);
	}
}
