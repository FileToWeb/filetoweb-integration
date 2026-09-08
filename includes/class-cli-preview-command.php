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
	 * @return int[]
	 */
	public static function source_ids() {
		$ids = get_posts(
			array(
				'post_type'              => array_values( get_post_types( array(), 'names' ) ),
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_key'               => Proud_HTML_Preview::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'absint', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Source posts whose preview record is not on shared storage.
	 *
	 * @return int[]
	 */
	public static function stale_ids() {
		$stale = array();

		foreach ( self::source_ids() as $post_id ) {
			if ( ! Proud_HTML_Preview::is_durable_record( Proud_HTML_Preview::record_for_post( $post_id ) ) ) {
				$stale[] = $post_id;
			}
		}

		return $stale;
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
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! is_object( $post ) ) {
			return array(
				'result'  => 'skipped',
				'reason'  => 'no-post',
				'durable' => false,
			);
		}

		if ( 'ready' !== (string) get_post_meta( $post_id, Document_State::META_STATUS, true ) ) {
			return array(
				'result'  => 'skipped',
				'reason'  => 'not-ready',
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
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function repair( $args, $assoc_args ) {
		unset( $args );

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$sleep   = isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 0;
		$limit   = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		if ( isset( $assoc_args['post'] ) ) {
			$targets = array_values(
				array_filter(
					array_map( 'absint', explode( ',', (string) $assoc_args['post'] ) )
				)
			);
		} elseif ( ! empty( $assoc_args['all'] ) ) {
			$targets = self::source_ids();
		} else {
			$targets = self::stale_ids();
		}

		if ( $limit > 0 ) {
			$targets = array_slice( $targets, 0, $limit );
		}

		if ( empty( $targets ) ) {
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
				'%d stale preview %s to republish.',
				count( $targets ),
				1 === count( $targets ) ? 'record' : 'records'
			)
		);

		if ( $dry_run ) {
			foreach ( $targets as $post_id ) {
				$row = self::inspect( $post_id );
				\WP_CLI::log( sprintf( '  would repair %d (%s) %s', $row['id'], $row['post_type'], $row['title'] ) );
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

		if ( $counts['failed'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d preview record(s) failed to republish.', $counts['failed'] ) );
			return;
		}

		\WP_CLI::success( 'Preview records republished.' );
	}
}
