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
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK_PROCESS, array( __CLASS__, 'process_next_batch' ) );
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
		$state  = self::queue_state();
		$limit  = Settings::batch_size();
		$counts = self::empty_counts();

		if ( empty( $state['items'] ) || ! Settings::configured() ) {
			$counts['skipped'] = count( $state['items'] );
			$state['items']    = array();
			self::save_queue_state( $state, $counts );
			return $counts;
		}

		$batch = array_splice( $state['items'], 0, $limit );

		foreach ( $batch as $item ) {
			$result = self::sync_item( $item, $state['type'] );

			if ( isset( $result['status'] ) && ! in_array( $result['status'], array( 'failed', 'skipped' ), true ) ) {
				++$counts['queued'];
			} elseif ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		self::save_queue_state( $state, $counts );

		if ( ! empty( $state['items'] ) ) {
			self::schedule_next_batch();
		}

		return $counts;
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

		Admin::set_notice( sprintf( __( 'Bulk sync queued %d item(s).', 'filetoweb-integration' ), absint( $state['total'] ) ) );
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
	 * Persist queue state after a processed batch.
	 *
	 * @param array $state State.
	 * @param array $counts Counts.
	 */
	private static function save_queue_state( $state, $counts ) {
		$processed = absint( $counts['queued'] ) + absint( $counts['skipped'] ) + absint( $counts['failed'] );

		$state['processed']  = absint( $state['processed'] ) + $processed;
		$state['queued']     = absint( $state['queued'] ) + absint( $counts['queued'] );
		$state['skipped']    = absint( $state['skipped'] ) + absint( $counts['skipped'] );
		$state['failed']     = absint( $state['failed'] ) + absint( $counts['failed'] );
		$state['updated_at'] = current_time( 'mysql', true );

		update_option( self::OPTION_QUEUE, $state, false );
	}

	/**
	 * Schedule next queue batch.
	 */
	private static function schedule_next_batch() {
		if ( ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK_PROCESS );
		}
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
