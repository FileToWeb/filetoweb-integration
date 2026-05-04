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
		add_action( self::HOOK_SYNC_ITEM, array( __CLASS__, 'sync_item' ), 10, 2 );
	}

	/**
	 * Schedule attachment sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function schedule_attachment_sync( $attachment_id ) {
		self::schedule_sync( $attachment_id, 'attachment' );
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

		self::schedule_sync( $post_id, 'document' );
	}

	/**
	 * Run a scheduled sync item.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind Source kind.
	 */
	public static function sync_item( $post_id, $kind ) {
		if ( ! Settings::configured() ) {
			return;
		}

		if ( 'attachment' === $kind ) {
			self::sync_attachment_now( $post_id );
			return;
		}

		if ( 'document' === $kind ) {
			self::sync_document_now( $post_id );
		}
	}

	/**
	 * Sync an attachment immediately.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public static function sync_attachment_now( $attachment_id ) {
		$source = Source_Resolver::for_attachment( $attachment_id );

		if ( ! $source ) {
			return array( 'status' => 'skipped' );
		}

		return self::sync_source_to_filetoweb( $attachment_id, $source );
	}

	/**
	 * Sync a Proud Document immediately.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function sync_document_now( $post_id ) {
		$source = Source_Resolver::for_document( $post_id );

		if ( ! $source ) {
			return array( 'status' => 'skipped' );
		}

		$sync_post_id = ! empty( $source['sync_post_id'] ) ? absint( $source['sync_post_id'] ) : absint( $post_id );
		$result       = self::sync_source_to_filetoweb( $sync_post_id, $source );

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

		$posts = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document' ),
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => Document_State::META_DOCUMENT_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Document_State::META_STATUS,
						'value'   => array( 'awaiting_upload', 'uploaded', 'queued', 'pending', 'importing', 'processing', 'converting' ),
						'compare' => 'IN',
					),
				),
			)
		);

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
				? self::sync_attachment_now( $item['id'] )
				: self::sync_document_now( $item['id'] );

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
	 */
	private static function schedule_sync( $post_id, $kind ) {
		if ( ! Settings::configured() ) {
			return;
		}

		$args = array( absint( $post_id ), $kind );

		if ( ! wp_next_scheduled( self::HOOK_SYNC_ITEM, $args ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK_SYNC_ITEM, $args );
		}
	}

	/**
	 * Send source to FileToWeb.
	 *
	 * @param int   $post_id Post ID that owns the state.
	 * @param array $source Source.
	 * @return array
	 */
	private static function sync_source_to_filetoweb( $post_id, $source ) {
		if ( ! Settings::configured() ) {
			return array( 'status' => 'skipped' );
		}

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
			),
		);

		$response = Api_Client::upsert_document( $payload );

		if ( ! $response['ok'] ) {
			Document_State::mark_failed( $post_id, $response['error'] );
			return array(
				'status' => 'failed',
				'error'  => $response['error'],
			);
		}

		if ( isset( $response['body']['document'] ) && is_array( $response['body']['document'] ) ) {
			Document_State::write_from_api( $post_id, $response['body']['document'], $source );

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
}
