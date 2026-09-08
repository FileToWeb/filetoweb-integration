<?php
/**
 * WP-CLI commands for published HTML previews.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspect and repair the HTML preview records FileToWeb publishes.
 *
 * A preview record is *durable* when it points at shared storage (WP Stateless)
 * rather than a single container's uploads directory. Records published before
 * shared-storage support, or on a request where WP Stateless had not booted,
 * stay pinned to a pod-local file. That file disappears with the pod, and the
 * viewer then has nothing to serve. Nothing republishes those records on its
 * own: the poll schedule only revisits sources that are still converting, so a
 * source that reached `ready` is never looked at again.
 *
 * These commands find those records and republish them.
 */
class Preview_Command {
	/**
	 * Maximum source records fetched by one WordPress query.
	 */
	const SOURCE_QUERY_BATCH_SIZE = 100;

	/**
	 * Maximum recovery ID file size read into memory.
	 */
	const POST_FILE_MAX_BYTES = 1048576;

	/**
	 * Test seam for the refresh call.
	 *
	 * @var callable|null
	 */
	private static $refresher = null;

	/**
	 * Replace the refresh callable. Intended for tests.
	 *
	 * @param callable|null $refresher Callable receiving a post ID and returning a refresh result.
	 */
	public static function set_refresher( $refresher ) {
		self::$refresher = is_callable( $refresher ) ? $refresher : null;
	}

	/**
	 * Republish one source's preview.
	 *
	 * @param int $post_id Source post ID.
	 * @return string Refresh result.
	 */
	private static function refresh( $post_id ) {
		if ( self::$refresher ) {
			return call_user_func( self::$refresher, $post_id );
		}

		return Local_HTML::refresh_for_post( $post_id, null, true );
	}

	/**
	 * Every source post that carries a preview record.
	 *
	 * Preview records are published against the source that owns the PDF, which
	 * is frequently an attachment, so this cannot rely on `post_type => any`.
	 *
	 * @param int $limit Maximum number of source IDs to return. Zero means all.
	 * @return int[]
	 */
	public static function source_ids( $limit = 0 ) {
		return iterator_to_array( self::source_id_iterator( $limit ), false );
	}

	/**
	 * Iterate over source IDs in bounded database queries.
	 *
	 * WordPress's `post_status => any` explicitly excludes `inherit`, which is
	 * the normal status for Media Library attachments. Use every registered
	 * status except deleted/draft placeholders so attachment-owned preview
	 * records are included without selecting trashed content.
	 *
	 * @param int $limit Maximum number of source IDs to yield. Zero means all.
	 * @return \Generator<int>
	 */
	private static function source_id_iterator( $limit = 0 ) {
		$limit      = max( 0, (int) $limit );
		$page       = 1;
		$yielded    = 0;
		$post_types = array_values( get_post_types( array(), 'names' ) );
		$statuses   = array_values( get_post_stati( array(), 'names' ) );
		$statuses   = array_values( array_diff( $statuses, array( 'trash', 'auto-draft' ) ) );

		if ( ! in_array( 'inherit', $statuses, true ) ) {
			$statuses[] = 'inherit';
		}

		do {
			$page_size = self::SOURCE_QUERY_BATCH_SIZE;

			$ids = get_posts(
				array(
					'post_type'              => $post_types,
					'post_status'            => $statuses,
					'posts_per_page'         => $page_size,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'meta_key'               => Proud_HTML_Preview::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$ids = array_map( 'absint', is_array( $ids ) ? $ids : array() );

			foreach ( $ids as $post_id ) {
				yield $post_id;
				$yielded++;

				if ( $limit > 0 && $yielded >= $limit ) {
					return;
				}
			}

			$page++;
		} while ( count( $ids ) === $page_size );
	}

	/**
	 * Source posts whose preview record is not on shared storage.
	 *
	 * @param int $limit Maximum number of stale IDs to return. Zero means all.
	 * @return int[]
	 */
	public static function stale_ids( $limit = 0 ) {
		$limit = max( 0, (int) $limit );
		$stale = array();

		foreach ( self::source_id_iterator() as $post_id ) {
			if ( ! Proud_HTML_Preview::is_durable_record( Proud_HTML_Preview::record_for_post( $post_id ) ) ) {
				$stale[] = $post_id;

				if ( $limit > 0 && count( $stale ) >= $limit ) {
					break;
				}
			}
		}

		return $stale;
	}

	/**
	 * Parse post IDs separated by commas or whitespace.
	 *
	 * @param string $value Raw ID list.
	 * @return int[]
	 * @throws \InvalidArgumentException When a token is not a positive integer.
	 */
	private static function parse_post_ids( $value ) {
		$tokens = preg_split( '/[\s,]+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
		$ids    = array();

		foreach ( is_array( $tokens ) ? $tokens : array() as $token ) {
			if ( ! preg_match( '/^[1-9][0-9]*$/', $token ) ) {
				throw new \InvalidArgumentException( sprintf( 'Invalid post ID: %s', $token ) );
			}

			$ids[] = absint( $token );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Explain why a source cannot currently be repaired.
	 *
	 * @param int $post_id Source post ID.
	 * @return string Empty when the source is eligible for repair.
	 */
	private static function repair_ineligibility_reason( $post_id ) {
		$post_id = absint( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) ) {
			return 'no-post';
		}

		if ( 'ready' !== (string) get_post_meta( $post_id, Document_State::META_STATUS, true ) ) {
			return 'not-ready';
		}

		return '';
	}

	/**
	 * Describe one source's preview record.
	 *
	 * @param int $post_id Source post ID.
	 * @return array
	 */
	public static function inspect( $post_id ) {
		$post_id = absint( $post_id );
		$record  = Proud_HTML_Preview::record_for_post( $post_id );
		$post    = $post_id ? get_post( $post_id ) : null;

		return array(
			'id'           => $post_id,
			'post_type'    => is_object( $post ) && isset( $post->post_type ) ? $post->post_type : '',
			'title'        => $post_id ? (string) get_the_title( $post_id ) : '',
			'status'       => (string) get_post_meta( $post_id, Document_State::META_STATUS, true ),
			'storage'      => is_array( $record ) && isset( $record['storage_backend'] )
				? (string) $record['storage_backend']
				: Proud_HTML_Preview::STORAGE_BACKEND_LOCAL,
			'durable'      => Proud_HTML_Preview::is_durable_record( $record ) ? 'yes' : 'no',
			'artifact_url' => is_array( $record ) && isset( $record['artifact_url'] ) ? (string) $record['artifact_url'] : '',
		);
	}

	/**
	 * Republish one source and report what the record became.
	 *
	 * @param int $post_id Source post ID.
	 * @return array Result with keys result, reason and durable.
	 */
	public static function repair_post( $post_id ) {
		$post_id = absint( $post_id );
		$reason  = self::repair_ineligibility_reason( $post_id );

		if ( $reason ) {
			return array(
				'result'  => 'skipped',
				'reason'  => $reason,
				'durable' => false,
			);
		}

		$result = (string) self::refresh( $post_id );

		return array(
			'result'  => $result,
			'reason'  => '',
			'durable' => Proud_HTML_Preview::is_durable_record( Proud_HTML_Preview::record_for_post( $post_id ) ),
		);
	}

	/**
	 * List published preview records.
	 *
	 * ## OPTIONS
	 *
	 * [--stale]
	 * : Only list records that are not on shared storage.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns. Defaults to id,post_type,title,status,storage,durable.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Every source that has a published preview.
	 *     $ wp filetoweb preview list
	 *
	 *     # Just the ones that will break when the container restarts.
	 *     $ wp filetoweb preview list --stale --format=ids
	 *
	 * @subcommand list
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		unset( $args );

		$stale_only = ! empty( $assoc_args['stale'] );
		$fields     = isset( $assoc_args['fields'] )
			? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) )
			: array( 'id', 'post_type', 'title', 'status', 'storage', 'durable' );
		$format     = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$rows       = array();

		foreach ( self::source_ids() as $post_id ) {
			$row = self::inspect( $post_id );

			if ( $stale_only && 'yes' === $row['durable'] ) {
				continue;
			}

			$rows[] = $row;
		}

		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Summarise how many preview records are durable.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp filetoweb preview status
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$total = 0;
		$stale = 0;

		foreach ( self::source_ids() as $post_id ) {
			$total++;

			if ( ! Proud_HTML_Preview::is_durable_record( Proud_HTML_Preview::record_for_post( $post_id ) ) ) {
				$stale++;
			}
		}

		\WP_CLI::log( sprintf( 'Preview records: %d', $total ) );
		\WP_CLI::log( sprintf( 'On shared storage: %d', $total - $stale ) );
		\WP_CLI::log( sprintf( 'Pinned to this container: %d', $stale ) );

		if ( ! $stale ) {
			return;
		}

		if ( ! Proud_HTML_Preview::supports_durable_storage() ) {
			\WP_CLI::log( 'This site has no shared object storage, so local previews are expected here.' );
			return;
		}

		\WP_CLI::log( 'Run `wp filetoweb preview repair` to republish them.' );
	}

	/**
	 * Republish preview records that are pinned to a single container.
	 *
	 * Each repair re-fetches the already-converted HTML from FileToWeb and
	 * publishes it to shared storage. It does not reconvert the source PDF.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<ids>]
	 * : Comma-separated source post IDs. Defaults to every stale record.
	 *
	 * [--post-file=<path>]
	 * : File containing source post IDs separated by commas or whitespace. Use
	 *   this to repair records that were removed after their IDs were backed up.
	 *   Files larger than 1 MiB are rejected.
	 *
	 * [--all]
	 * : Republish every record, not only the stale ones.
	 *
	 * [--limit=<number>]
	 * : Stop after this many sources.
	 *
	 * [--sleep=<seconds>]
	 * : Wait between sources, to spare the API on large fleets.
	 *
	 * [--dry-run]
	 * : Report what would be republished and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what is broken before touching anything.
	 *     $ wp filetoweb preview repair --dry-run
	 *
	 *     # Republish every stale preview, pausing a second between each.
	 *     $ wp filetoweb preview repair --sleep=1
	 *
	 *     # Republish two known sources.
	 *     $ wp filetoweb preview repair --post=6104,5899
	 *
	 *     # Republish source IDs preserved during an earlier cleanup.
	 *     $ wp filetoweb preview repair --post-file=/tmp/filetoweb-preview-ids.txt --sleep=1
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function repair( $args, $assoc_args ) {
		unset( $args );

		$dry_run   = ! empty( $assoc_args['dry-run'] );
		$sleep     = isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 0;
		$limit     = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$selectors = array_filter(
			array(
				isset( $assoc_args['post'] ),
				isset( $assoc_args['post-file'] ),
				! empty( $assoc_args['all'] ),
			)
		);

		if ( count( $selectors ) > 1 ) {
			\WP_CLI::error( 'Use only one of --post, --post-file, or --all.' );
			return;
		}

		try {
			if ( isset( $assoc_args['post'] ) ) {
				$targets = self::parse_post_ids( $assoc_args['post'] );
			} elseif ( isset( $assoc_args['post-file'] ) ) {
				$path = (string) $assoc_args['post-file'];

				if ( ! is_readable( $path ) ) {
					\WP_CLI::error( sprintf( 'Post ID file is not readable: %s', $path ) );
					return;
				}

				$contents = file_get_contents( $path, false, null, 0, self::POST_FILE_MAX_BYTES + 1 );

				if ( false === $contents ) {
					\WP_CLI::error( sprintf( 'Post ID file could not be read: %s', $path ) );
					return;
				}

				if ( strlen( $contents ) > self::POST_FILE_MAX_BYTES ) {
					\WP_CLI::error( 'Post ID file is larger than the 1 MiB safety limit.' );
					return;
				}

				$targets = self::parse_post_ids( $contents );
			} elseif ( ! empty( $assoc_args['all'] ) ) {
				$targets = self::source_ids( $limit );
			} else {
				$targets = self::stale_ids( $limit );
			}
		} catch ( \InvalidArgumentException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
			return;
		}

		if ( $limit > 0 && ( isset( $assoc_args['post'] ) || isset( $assoc_args['post-file'] ) ) ) {
			$targets = array_slice( $targets, 0, $limit );
		}

		if ( empty( $targets ) ) {
			if ( isset( $assoc_args['post'] ) || isset( $assoc_args['post-file'] ) ) {
				\WP_CLI::error( 'No valid post IDs were supplied.' );
				return;
			}

			\WP_CLI::success( 'No stale preview records found.' );
			return;
		}

		if ( ! Proud_HTML_Preview::supports_durable_storage() ) {
			\WP_CLI::error(
				'This site has no shared object storage configured, so republishing would produce the same container-local records. Configure WP Stateless first.'
			);
			return;
		}

		\WP_CLI::log(
			sprintf(
				'%d preview %s selected for republishing.',
				count( $targets ),
				1 === count( $targets ) ? 'source' : 'sources'
			)
		);

		if ( $dry_run ) {
			$skipped = 0;

			foreach ( $targets as $post_id ) {
				$row    = self::inspect( $post_id );
				$reason = self::repair_ineligibility_reason( $post_id );

				if ( $reason ) {
					$skipped++;
					\WP_CLI::log( sprintf( '  would skip %d (%s)', $row['id'], $reason ) );
					continue;
				}

				\WP_CLI::log( sprintf( '  would repair %d (%s) %s', $row['id'], $row['post_type'], $row['title'] ) );
			}

			if ( $skipped > 0 ) {
				\WP_CLI::error( sprintf( 'Dry run incomplete: %d selected source(s) would be skipped.', $skipped ) );
				return;
			}

			\WP_CLI::success( 'Dry run complete. Nothing was changed.' );
			return;
		}

		$counts = array(
			'repaired' => 0,
			'current'  => 0,
			'failed'   => 0,
			'skipped'  => 0,
		);

		foreach ( $targets as $index => $post_id ) {
			if ( $sleep > 0 && $index > 0 ) {
				sleep( $sleep );
			}

			$outcome = self::repair_post( $post_id );

			if ( 'skipped' === $outcome['result'] ) {
				$counts['skipped']++;
				\WP_CLI::log( sprintf( '  %d skipped (%s)', $post_id, $outcome['reason'] ) );
				continue;
			}

			if ( 'failed' === $outcome['result'] ) {
				$counts['failed']++;
				\WP_CLI::warning(
					sprintf(
						'%d failed: %s',
						$post_id,
						(string) get_post_meta( $post_id, Document_State::META_LAST_ERROR, true )
					)
				);
				continue;
			}

			if ( ! $outcome['durable'] ) {
				$counts['failed']++;
				\WP_CLI::warning(
					sprintf(
						'%d refreshed but its record is still pinned to this container. Shared storage is unavailable to this site.',
						$post_id
					)
				);
				continue;
			}

			if ( 'current' === $outcome['result'] ) {
				$counts['current']++;
				\WP_CLI::log( sprintf( '  %d already current', $post_id ) );
				continue;
			}

			$counts['repaired']++;
			\WP_CLI::log( sprintf( '  %d repaired', $post_id ) );
		}

		\WP_CLI::log(
			sprintf(
				'Repaired %d, already current %d, failed %d, skipped %d.',
				$counts['repaired'],
				$counts['current'],
				$counts['failed'],
				$counts['skipped']
			)
		);

		if ( $counts['failed'] > 0 || $counts['skipped'] > 0 ) {
			\WP_CLI::error(
				sprintf(
					'Repair incomplete: %d preview record(s) failed and %d were skipped.',
					$counts['failed'],
					$counts['skipped']
				)
			);
			return;
		}

		\WP_CLI::success( 'Preview records republished.' );
	}
}
