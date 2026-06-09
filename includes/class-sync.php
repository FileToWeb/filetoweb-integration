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
	const HOOK_SYNC_ITEM = 'filetoweb_integration_sync_item';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'schedule_attachment_sync' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'schedule_attachment_sync' ) );
		add_action( 'save_post', array( __CLASS__, 'schedule_document_sync' ), 30, 2 );
		add_action( self::HOOK_SYNC_ITEM, array( __CLASS__, 'sync_item' ), 10, 3 );
	}

	/**
	 * Schedule attachment sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function schedule_attachment_sync( $attachment_id ) {
		self::schedule_sync( $attachment_id, 'attachment', 'attachment_save' );
	}

	/**
	 * Schedule Proud Document sync.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 */
	public static function schedule_document_sync( $post_id, $post ) {
		if ( ! is_object( $post ) || 'document' !== $post->post_type ) {
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

		$document_id = get_post_meta( $post_id, Document_State::META_DOCUMENT_ID, true );

		if ( ! $document_id ) {
			return 'skipped';
		}

		$response = Api_Client::get_document( $document_id );

		if ( ! $response['ok'] ) {
			Document_State::mark_failed( $post_id, $response['error'] );
			return 'failed';
		}

		if ( isset( $response['body']['document'] ) && is_array( $response['body']['document'] ) ) {
			Document_State::write_polled_state( $post_id, $response['body']['document'] );
			do_action( 'filetoweb_integration_after_poll_post', $post_id, $response['body']['document'] );
			return 'updated';
		}

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

		$retry_counts = self::retry_pending_syncs( $limit );

		$posts = self::pending_poll_posts( $limit );

		foreach ( $posts as $post_id ) {
			$result = self::poll_post( $post_id );

			if ( 'updated' === $result ) {
				++$counts['updated'];
			} elseif ( 'failed' === $result ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		foreach ( $retry_counts as $key => $value ) {
			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] += absint( $value );
			}
		}

		return $counts;
	}

	/**
	 * Return pending FileToWeb posts, including explicit PDF-to-Page drafts.
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
				'value'   => array( 'awaiting_upload', 'uploaded', 'queued', 'pending', 'importing', 'processing', 'converting' ),
				'compare' => 'IN',
			),
		);

		$posts = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document' ),
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_query'     => $status_query,
			)
		);

		if ( count( $posts ) >= $limit ) {
			return $posts;
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'pending', 'publish', 'private' ),
				'posts_per_page' => $limit - count( $posts ),
				'fields'         => 'ids',
				'meta_query'     => array_merge(
					$status_query,
					array(
						array(
							'key'   => Document_State::META_PDF_TO_PAGE,
							'value' => '1',
						),
					)
				),
			)
		);

		return array_merge( $posts, $pages );
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

		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => Document_State::META_STATUS,
						'value' => 'pending',
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
				),
			)
		);

		foreach ( $posts as $post_id ) {
			$result = self::sync_attachment_now( $post_id, 'cron_retry' );

			if ( isset( $result['status'] ) && ! in_array( $result['status'], array( 'failed', 'skipped' ), true ) ) {
				++$counts['queued'];
			} elseif ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
				++$counts['failed'];
			} else {
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
			if ( Api_Client::is_retryable_error( $response['error'] ) ) {
				Document_State::mark_pending_retry( $post_id, $response['error'] );
				return array(
					'status' => 'pending',
					'error'  => $response['error'],
				);
			}

			Document_State::mark_failed( $post_id, $response['error'] );
			return array(
				'status' => 'failed',
				'error'  => $response['error'],
			);
		}

		if ( isset( $response['body']['document'] ) && is_array( $response['body']['document'] ) ) {
			Document_State::write_from_api( $post_id, $response['body']['document'], $source );

			do_action( 'filetoweb_integration_after_sync_post', $post_id, $source, $response['body']['document'] );

			return array(
				'status' => Security::sanitize_status( $response['body']['document']['status'] ),
			);
		}

		return array( 'status' => 'queued' );
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
