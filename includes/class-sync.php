<?php
/**
 * Sync WordPress PDF sources to FileToWeb.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sync {
	const HOOK_SYNC_ITEM               = 'filetoweb_integration_sync_item';
	const OPTION_QUEUE_CURSOR          = 'filetoweb_integration_poll_queue_cursor';
	const OPTION_POST_RECOVERY_CURSOR  = 'filetoweb_integration_post_recovery_cursor';
	const OPTION_RETRY_RECOVERY_CURSOR = 'filetoweb_integration_retry_recovery_cursor';
	const POLL_DELAYS                  = array( 60, 120, 300, 600 );

	/**
	 * FileToWeb states that still need status polling.
	 *
	 * @return array
	 */
	private static function pending_statuses() {
		return array( 'awaiting_upload', 'uploaded', 'queued', 'pending', 'importing', 'processing', 'converting' );
	}

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'schedule_attachment_sync' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'schedule_attachment_sync' ) );
		add_action( 'save_post', array( __CLASS__, 'schedule_document_sync' ), 30, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'stop_sync_for_removed_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'stop_sync_for_removed_post' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'stop_sync_for_removed_post' ) );
		add_action( self::HOOK_SYNC_ITEM, array( __CLASS__, 'sync_item' ), 10, 3 );
	}

	/**
	 * Schedule attachment sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function schedule_attachment_sync( $attachment_id ) {
		if ( ! self::is_syncable_post( $attachment_id, 'attachment' ) ) {
			return;
		}

		self::schedule_sync( $attachment_id, 'attachment', 'attachment_save' );
	}

	/**
	 * Schedule Proud Document sync.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 */
	public static function schedule_document_sync( $post_id, $post ) {
		if ( ! is_object( $post ) || 'document' !== $post->post_type || ! self::is_syncable_status( isset( $post->post_status ) ? $post->post_status : '' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		self::schedule_sync( $post_id, 'document', 'document_save' );
	}

	/**
	 * Run a scheduled sync item.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind Source kind.
	 * @param string $trigger Sync trigger.
	 */
	public static function sync_item( $post_id, $kind, $trigger = 'scheduled' ) {
		if ( ! Settings::configured() ) {
			return;
		}

		if ( ! self::is_syncable_post( $post_id, $kind ) ) {
			self::clear_next_poll( $post_id );
			return;
		}

		if ( 'attachment' === $kind ) {
			self::sync_attachment_now( $post_id, $trigger );
			return;
		}

		if ( 'document' === $kind ) {
			self::sync_document_now( $post_id, $trigger );
		}
	}

	/**
	 * Sync an attachment immediately.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $trigger Sync trigger.
	 * @return array
	 */
	public static function sync_attachment_now( $attachment_id, $trigger = 'manual_sync' ) {
		if ( ! self::is_syncable_post( $attachment_id, 'attachment' ) ) {
			return array( 'status' => 'skipped' );
		}

		$source = Source_Resolver::for_attachment( $attachment_id );

		if ( ! $source ) {
			return array( 'status' => 'skipped' );
		}

		return self::sync_source_to_filetoweb( $attachment_id, $source, $trigger );
	}

	/**
	 * Sync a Proud Document immediately.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $trigger Sync trigger.
	 * @return array
	 */
	public static function sync_document_now( $post_id, $trigger = 'manual_sync' ) {
		if ( ! self::is_syncable_post( $post_id, 'document' ) ) {
			return array( 'status' => 'skipped' );
		}

		$source = Source_Resolver::for_document( $post_id );

		if ( ! $source ) {
			return array( 'status' => 'skipped' );
		}

		$sync_post_id = ! empty( $source['sync_post_id'] ) ? absint( $source['sync_post_id'] ) : absint( $post_id );
		$result       = self::sync_source_to_filetoweb( $sync_post_id, $source, $trigger );

		if ( $sync_post_id !== absint( $post_id ) ) {
			Document_State::copy_state( $sync_post_id, $post_id );
		}

		return $result;
	}

	/**
	 * Poll one post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function poll_post( $post_id ) {
		if ( ! Settings::configured() ) {
			return 'skipped';
		}

		if ( ! self::is_syncable_post( $post_id ) ) {
			self::clear_next_poll( $post_id );
			return 'skipped';
		}

		$document_id = get_post_meta( $post_id, Document_State::META_DOCUMENT_ID, true );

		if ( ! $document_id ) {
			return 'skipped';
		}

		$attempts = self::record_poll_attempt( $post_id );
		$response = Api_Client::get_document( $document_id );

		if ( ! $response['ok'] ) {
			$retryable = ! empty( $response['retryable'] ) || Api_Client::is_retryable_error( $response['error'] );
			if ( $retryable ) {
				Document_State::mark_pending_retry(
					$post_id,
					$response['error'],
					isset( $response['error_code'] ) ? $response['error_code'] : '',
					isset( $response['reference'] ) ? $response['reference'] : '',
					true
				);
				self::schedule_next_poll( $post_id, $attempts );
				return 'updated';
			}

			Document_State::mark_failed(
				$post_id,
				$response['error'],
				isset( $response['error_code'] ) ? $response['error_code'] : '',
				isset( $response['reference'] ) ? $response['reference'] : '',
				false
			);
			self::clear_next_poll( $post_id );
			return 'failed';
		}

		if ( isset( $response['body']['document'] ) && is_array( $response['body']['document'] ) ) {
			$document = $response['body']['document'];
			$status   = Security::sanitize_status( isset( $document['status'] ) ? $document['status'] : '' );

			Document_State::write_polled_state( $post_id, $document );
			do_action( 'filetoweb_integration_after_poll_post', $post_id, $document );

			if ( in_array( $status, self::pending_statuses(), true ) ) {
				self::schedule_next_poll( $post_id, $attempts );
			} else {
				self::clear_next_poll( $post_id );
			}

			return 'updated';
		}

		self::schedule_next_poll( $post_id, $attempts );
		return 'skipped';
	}

	/**
	 * Poll pending documents.
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	public static function poll_pending( $limit ) {
		$limit  = max( 1, min( 100, absint( $limit ) ) );
		$counts = self::empty_counts();

		if ( ! Settings::configured() ) {
			$counts['skipped'] = $limit;
			return $counts;
		}

		$remaining = $limit;
		$stages    = array( 'jobs', 'retries', 'posts' );
		$cursor    = absint( get_option( self::OPTION_QUEUE_CURSOR, 0 ) ) % count( $stages );
		$stages    = array_merge( array_slice( $stages, $cursor ), array_slice( $stages, 0, $cursor ) );

		update_option( self::OPTION_QUEUE_CURSOR, ( $cursor + 1 ) % count( $stages ), false );

		foreach ( $stages as $index => $stage ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$stages_left  = count( $stages ) - $index;
			$stage_limit  = min( $remaining, max( 1, (int) ceil( $remaining / $stages_left ) ) );
			$stage_counts = self::poll_stage( $stage, $stage_limit );

			self::merge_counts( $counts, $stage_counts );
			$remaining -= self::count_work( $stage_counts );
		}

		return $counts;
	}

	/**
	 * Run one independently bounded polling queue.
	 *
	 * @param string $stage Queue name.
	 * @param int    $limit Maximum work for this queue.
	 * @return array
	 */
	private static function poll_stage( $stage, $limit ) {
		if ( 'jobs' === $stage ) {
			return class_exists( __NAMESPACE__ . '\\PDF_To_Page' )
				? PDF_To_Page::poll_pending_jobs( $limit )
				: self::empty_counts();
		}

		if ( 'retries' === $stage ) {
			return self::retry_pending_syncs( $limit );
		}

		$counts = self::empty_counts();

		foreach ( self::pending_poll_posts( $limit ) as $post_id ) {
			$result = self::poll_post( $post_id );

			if ( 'updated' === $result ) {
				++$counts['updated'];
			} elseif ( 'failed' === $result ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		return $counts;
	}

	/**
	 * Return pending FileToWeb posts in fair next-due order.
	 *
	 * Posts created before queue metadata existed are selected by oldest ID as a
	 * recovery sweep. Once checked, every non-terminal item receives a future
	 * due time and moves behind older work.
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	private static function pending_poll_posts( $limit ) {
		$status_query = array(
			array(
				'key'     => Document_State::META_DOCUMENT_ID,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => Document_State::META_STATUS,
				'value'   => self::pending_statuses(),
				'compare' => 'IN',
			),
		);

		$recovery_posts = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array_merge(
					$status_query,
					array(
						array(
							'key'     => Document_State::META_NEXT_POLL_AT,
							'compare' => 'NOT EXISTS',
						),
					)
				),
			)
		);

		$due_posts = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => Document_State::META_NEXT_POLL_AT,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array_merge(
					$status_query,
					array(
						array(
							'key'     => Document_State::META_NEXT_POLL_AT,
							'value'   => time(),
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
					)
				),
			)
		);

		return self::merge_recovery_and_due( $recovery_posts, $due_posts, $limit, self::OPTION_POST_RECOVERY_CURSOR );
	}

	/**
	 * Record one API status check.
	 *
	 * @param int $post_id Post ID.
	 * @return int Updated attempt count.
	 */
	private static function record_poll_attempt( $post_id ) {
		$attempts = absint( get_post_meta( $post_id, Document_State::META_POLL_ATTEMPTS, true ) ) + 1;

		update_post_meta( $post_id, Document_State::META_POLL_ATTEMPTS, $attempts );
		update_post_meta( $post_id, Document_State::META_LAST_POLLED_AT, time() );

		return $attempts;
	}

	/**
	 * Move a non-terminal item to its next backoff window.
	 *
	 * @param int $post_id Post ID.
	 * @param int $attempts Completed poll attempts.
	 */
	private static function schedule_next_poll( $post_id, $attempts = 0 ) {
		$attempts    = max( 0, absint( $attempts ) );
		$delay_index = min( $attempts, count( self::POLL_DELAYS ) - 1 );
		$delay       = self::POLL_DELAYS[ $delay_index ];
		$delay       = max( 30, absint( apply_filters( 'filetoweb_integration_poll_delay_seconds', $delay, $post_id, $attempts ) ) );
		$hash        = sprintf( '%u', crc32( absint( $post_id ) . ':' . $attempts ) );
		$jitter      = absint( $hash ) % 21;

		update_post_meta( $post_id, Document_State::META_NEXT_POLL_AT, time() + $delay + $jitter );
	}

	/**
	 * Remove an item from the active polling queue.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function clear_next_poll( $post_id ) {
		delete_post_meta( $post_id, Document_State::META_NEXT_POLL_AT );
	}

	/**
	 * Remove trashed or deleted content from FileToWeb's local work queues.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function stop_sync_for_removed_post( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return;
		}

		foreach ( array( 'attachment', 'document' ) as $kind ) {
			$trigger = 'attachment' === $kind ? 'attachment_save' : 'document_save';
			wp_clear_scheduled_hook( self::HOOK_SYNC_ITEM, array( $post_id, $kind, $trigger ) );
		}

		self::clear_next_poll( $post_id );
	}

	/**
	 * Whether a WordPress post still represents content eligible for syncing.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $expected_kind Optional expected source kind.
	 * @return bool
	 */
	private static function is_syncable_post( $post_id, $expected_kind = '' ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		$post_type = get_post_type( $post_id );

		if ( $expected_kind && $expected_kind !== $post_type ) {
			return false;
		}

		return (bool) $post_type && self::is_syncable_status( get_post_status( $post_id ) );
	}

	/**
	 * Whether a WordPress status represents retained content.
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	private static function is_syncable_status( $status ) {
		$status = sanitize_key( (string) $status );

		return '' !== $status && ! in_array( $status, array( 'trash', 'auto-draft' ), true );
	}

	/**
	 * Retry recently missed or transiently failed PDF attachment syncs.
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	public static function retry_pending_syncs( $limit ) {
		$limit  = max( 1, min( 25, absint( $limit ) ) );
		$counts = self::empty_counts();

		if ( ! Settings::configured() ) {
			$counts['skipped'] = $limit;
			return $counts;
		}

		$retry_query = array(
			'relation' => 'OR',
			array(
				'relation' => 'AND',
				array(
					'key'   => Document_State::META_STATUS,
					'value' => 'pending',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => Document_State::META_DOCUMENT_ID,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Document_State::META_DOCUMENT_ID,
						'value'   => '',
						'compare' => '=',
					),
				),
			),
			array(
				'relation' => 'AND',
				array(
					'key'   => Document_State::META_STATUS,
					'value' => 'failed',
				),
				array(
					'key'     => Document_State::META_LAST_ERROR,
					'value'   => 'timed out',
					'compare' => 'LIKE',
				),
			),
		);

		$recovery_posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					$retry_query,
					array(
						'key'     => Document_State::META_NEXT_POLL_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$due_posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => Document_State::META_NEXT_POLL_AT,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'AND',
					$retry_query,
					array(
						'key'     => Document_State::META_NEXT_POLL_AT,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$posts = self::merge_recovery_and_due( $recovery_posts, $due_posts, $limit, self::OPTION_RETRY_RECOVERY_CURSOR );

		foreach ( $posts as $post_id ) {
			$attempts = self::record_poll_attempt( $post_id );
			$result = self::sync_attachment_now( $post_id, 'cron_retry' );

			if ( isset( $result['status'] ) && ! in_array( $result['status'], array( 'failed', 'skipped' ), true ) ) {
				++$counts['queued'];
			} elseif ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
				++$counts['failed'];
			} else {
				self::schedule_next_poll( $post_id, $attempts );
				++$counts['skipped'];
			}
		}

		return $counts;
	}

	/**
	 * Run a bounded backfill batch.
	 *
	 * @param int $limit Max items.
	 * @return array
	 */
	public static function run_backfill( $limit ) {
		$limit  = max( 1, min( 100, absint( $limit ) ) );
		$counts = self::empty_counts();

		if ( ! Settings::configured() ) {
			$counts['skipped'] = $limit;
			return $counts;
		}

		$items       = array();
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		foreach ( $attachments as $attachment_id ) {
			$items[] = array(
				'id'   => absint( $attachment_id ),
				'kind' => 'attachment',
			);
		}

		if ( count( $items ) < $limit ) {
			$documents = get_posts(
				array(
					'post_type'      => 'document',
					'post_status'    => 'any',
					'posts_per_page' => $limit - count( $items ),
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);

			foreach ( $documents as $document_id ) {
				$items[] = array(
					'id'   => absint( $document_id ),
					'kind' => 'document',
				);
			}
		}

		foreach ( $items as $item ) {
			$result = 'attachment' === $item['kind']
				? self::sync_attachment_now( $item['id'], 'manual_backfill' )
				: self::sync_document_now( $item['id'], 'manual_backfill' );

			if ( isset( $result['status'] ) && ! in_array( $result['status'], array( 'failed', 'skipped' ), true ) ) {
				++$counts['queued'];
			} elseif ( 'failed' === $result['status'] ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		return $counts;
	}

	/**
	 * Schedule a sync event.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind Source kind.
	 * @param string $trigger Sync trigger.
	 */
	private static function schedule_sync( $post_id, $kind, $trigger ) {
		if ( ! Settings::configured() ) {
			return;
		}

		$post_id = absint( $post_id );
		$trigger = self::sanitize_trigger( $trigger );

		if ( 'attachment' === $kind && self::attachment_looks_like_pdf( $post_id ) ) {
			Document_State::mark_scheduled( $post_id, $trigger );
		}

		$args = array( $post_id, $kind, $trigger );

		if ( ! wp_next_scheduled( self::HOOK_SYNC_ITEM, $args ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_SYNC_ITEM, $args );
		}

		self::spawn_cron();
	}

	/**
	 * Nudge WP-Cron so upload-triggered syncs run on low-traffic demo sites.
	 */
	private static function spawn_cron() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		$url = site_url( 'wp-cron.php?doing_wp_cron=' . rawurlencode( microtime( true ) ) );

		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Send source to FileToWeb.
	 *
	 * @param int   $post_id Post ID that owns the state.
	 * @param array  $source Source.
	 * @param string $trigger Sync trigger.
	 * @return array
	 */
	private static function sync_source_to_filetoweb( $post_id, $source, $trigger ) {
		if ( ! Settings::configured() ) {
			return array( 'status' => 'skipped' );
		}

		$trigger = self::sanitize_trigger( $trigger );

		$payload = array(
			'external_id' => $source['external_id'],
			'filename'    => $source['filename'],
			'source'      => array(
				'type'         => 'url',
				'url'          => $source['source_url'],
				'fingerprint'  => array(
					'value'     => $source['fingerprint'],
					'algorithm' => $source['fingerprint_algorithm'],
				),
				'content_type' => 'application/pdf',
			),
			'metadata'    => array(
				'wordpress_post_id' => absint( $post_id ),
				'wordpress_site'    => home_url(),
				'filetoweb_trigger' => $trigger,
				'plugin_version'    => defined( 'FILETOWEB_INTEGRATION_VERSION' ) ? FILETOWEB_INTEGRATION_VERSION : '',
			),
		);

		$response = Api_Client::upsert_document( $payload );

		if ( ! $response['ok'] ) {
			if ( ! empty( $response['retryable'] ) || Api_Client::is_retryable_error( $response['error'] ) ) {
				Document_State::mark_pending_retry(
					$post_id,
					$response['error'],
					isset( $response['error_code'] ) ? $response['error_code'] : '',
					isset( $response['reference'] ) ? $response['reference'] : '',
					true
				);
				self::schedule_next_poll(
					$post_id,
					absint( get_post_meta( $post_id, Document_State::META_POLL_ATTEMPTS, true ) )
				);
				return array(
					'status' => 'pending',
					'error'  => $response['error'],
				);
			}

			Document_State::mark_failed(
				$post_id,
				$response['error'],
				isset( $response['error_code'] ) ? $response['error_code'] : '',
				isset( $response['reference'] ) ? $response['reference'] : '',
				! empty( $response['retryable'] )
			);
			self::clear_next_poll( $post_id );
			return array(
				'status' => 'failed',
				'error'  => $response['error'],
			);
		}

		if ( isset( $response['body']['document'] ) && is_array( $response['body']['document'] ) ) {
			$document = $response['body']['document'];
			$status   = Security::sanitize_status( isset( $document['status'] ) ? $document['status'] : '' );

			Document_State::write_from_api( $post_id, $document, $source );
			update_post_meta( $post_id, Document_State::META_POLL_ATTEMPTS, 0 );
			update_post_meta( $post_id, Document_State::META_LAST_POLLED_AT, 0 );

			if ( in_array( $status, self::pending_statuses(), true ) ) {
				self::schedule_next_poll( $post_id );
			} else {
				self::clear_next_poll( $post_id );
			}

			do_action( 'filetoweb_integration_after_sync_post', $post_id, $source, $document );

			return array(
				'status' => $status,
			);
		}

		self::schedule_next_poll(
			$post_id,
			absint( get_post_meta( $post_id, Document_State::META_POLL_ATTEMPTS, true ) )
		);

		return array( 'status' => 'queued' );
	}

	/**
	 * Merge result counters.
	 *
	 * @param array $target Target counters.
	 * @param array $source Source counters.
	 */
	private static function merge_counts( &$target, $source ) {
		foreach ( (array) $source as $key => $value ) {
			if ( isset( $target[ $key ] ) ) {
				$target[ $key ] += absint( $value );
			}
		}
	}

	/**
	 * Count API work represented by a result counter set.
	 *
	 * @param array $counts Result counters.
	 * @return int
	 */
	private static function count_work( $counts ) {
		return absint( isset( $counts['queued'] ) ? $counts['queued'] : 0 )
			+ absint( isset( $counts['skipped'] ) ? $counts['skipped'] : 0 )
			+ absint( isset( $counts['failed'] ) ? $counts['failed'] : 0 )
			+ absint( isset( $counts['updated'] ) ? $counts['updated'] : 0 );
	}

	/**
	 * Combine upgrade-recovery and normally scheduled work without starving either.
	 *
	 * When both queues are populated, at least one fifth of the batch is reserved
	 * for recovery. Unused capacity is immediately returned to the other queue.
	 *
	 * @param array  $recovery_posts Posts that predate queue metadata.
	 * @param array  $due_posts Normally scheduled due posts.
	 * @param int    $limit Maximum selected posts.
	 * @param string $cursor_option Persistent cursor used for one-item batches.
	 * @return array
	 */
	private static function merge_recovery_and_due( $recovery_posts, $due_posts, $limit, $cursor_option ) {
		$limit          = max( 0, absint( $limit ) );
		$recovery_posts = array_values( array_unique( array_map( 'absint', (array) $recovery_posts ) ) );
		$due_posts      = array_values( array_unique( array_map( 'absint', (array) $due_posts ) ) );

		if ( empty( $recovery_posts ) ) {
			return array_slice( $due_posts, 0, $limit );
		}

		if ( empty( $due_posts ) ) {
			return array_slice( $recovery_posts, 0, $limit );
		}

		if ( 1 === $limit ) {
			$cursor          = absint( get_option( $cursor_option, 0 ) ) % 2;
			$recovery_quota  = 0 === $cursor ? 1 : 0;
			update_option( $cursor_option, ( $cursor + 1 ) % 2, false );
		} else {
			$recovery_quota = min( count( $recovery_posts ), max( 1, (int) floor( $limit / 5 ) ), $limit - 1 );
		}

		$selected       = array_slice( $recovery_posts, 0, $recovery_quota );
		$due_quota      = min( count( $due_posts ), $limit - count( $selected ) );
		$selected       = array_merge( $selected, array_slice( $due_posts, 0, $due_quota ) );

		if ( count( $selected ) < $limit ) {
			$selected = array_merge(
				$selected,
				array_slice( $recovery_posts, $recovery_quota, $limit - count( $selected ) )
			);
		}

		if ( count( $selected ) < $limit ) {
			$selected = array_merge( $selected, array_slice( $due_posts, $due_quota, $limit - count( $selected ) ) );
		}

		return array_slice( array_values( array_unique( $selected ) ), 0, $limit );
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

	/**
	 * Does an attachment look like a PDF without doing network fingerprinting?
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function attachment_looks_like_pdf( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return false;
		}

		$mime     = get_post_mime_type( $attachment_id );
		$url      = Source_Resolver::original_attachment_url( $attachment_id );
		$file     = get_attached_file( $attachment_id );
		$filename = $file ? basename( $file ) : basename( (string) parse_url( $url, PHP_URL_PATH ) );

		return $url && Source_Resolver::is_pdf_source( $url, $filename, $mime ) && Security::is_safe_source_url( $url );
	}

	/**
	 * Sanitize a sync trigger name for API metadata.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	private static function sanitize_trigger( $trigger ) {
		$trigger = sanitize_key( $trigger );

		return $trigger ? substr( $trigger, 0, 64 ) : 'manual_sync';
	}
}
