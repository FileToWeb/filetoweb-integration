<?php
/**
 * Explicit, bounded WP-CLI migration for legacy FileToWeb preview bundles.
 *
 * Load with:
 * wp --url=<site> --require=/path/to/filetoweb-preview-migration.php \
 *   filetoweb preview-migration audit
 *
 * This file is intentionally not loaded by the FileToWeb plugin. It registers
 * a command only when an operator explicitly supplies it to WP-CLI.
 *
 * @package FileToWeb\Integration\Operations
 */

namespace FileToWeb\Integration\Operations;

use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Cron;
use FileToWeb\Integration\Local_HTML;
use FileToWeb\Integration\Proud_HTML_Preview;
use FileToWeb\Integration\Sync;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Read and classify a bounded page of FileToWeb preview records.
 */
class Preview_Migration_Repository {
	const ACTIVE_META_KEY = '_proud_html_preview';
	const PAUSED_META_KEY = '_filetoweb_paused_html_preview';
	const BUNDLE_ROOT     = 'filetoweb-integration/previews';

	/**
	 * WordPress database connection.
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * WP Stateless client, when available.
	 *
	 * @var object|null
	 */
	private $stateless_client;

	/**
	 * @param object      $wpdb WordPress database connection.
	 * @param object|null $stateless_client Optional WP Stateless client.
	 */
	public function __construct( $wpdb, $stateless_client = null ) {
		$this->wpdb             = $wpdb;
		$this->stateless_client = $stateless_client;
	}

	/**
	 * Fetch one bounded page without a posts join or serialized-value predicate.
	 *
	 * @param int $after_meta_id Resume cursor.
	 * @param int $limit Maximum records.
	 * @return array
	 */
	public function scan( $after_meta_id, $limit ) {
		$after_meta_id = max( 0, (int) $after_meta_id );
		$limit         = max( 1, min( 100, (int) $limit ) );
		$sql           = $this->wpdb->prepare(
			"SELECT meta_id, post_id, meta_key, meta_value
			FROM {$this->wpdb->postmeta}
			WHERE meta_key IN (%s, %s)
			  AND meta_id > %d
			ORDER BY meta_id ASC
			LIMIT %d",
			self::ACTIVE_META_KEY,
			self::PAUSED_META_KEY,
			$after_meta_id,
			$limit
		);
		$rows          = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Convert a raw postmeta row into an operator-facing record classification.
	 *
	 * @param array $row Raw postmeta row.
	 * @param bool  $verify_objects Check every recorded GCS object.
	 * @return array
	 */
	public function classify( $row, $verify_objects = false ) {
		$meta_id  = isset( $row['meta_id'] ) ? (int) $row['meta_id'] : 0;
		$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$meta_key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
		$record   = isset( $row['meta_value'] ) ? maybe_unserialize( $row['meta_value'] ) : array();
		$result   = array(
			'meta_id'        => $meta_id,
			'post_id'        => $post_id,
			'record'         => self::PAUSED_META_KEY === $meta_key ? 'paused' : 'active',
			'status'         => 'invalid',
			'artifacts'      => 0,
			'missing'        => 0,
			'check_errors'   => 0,
			'schema'         => 0,
			'action'         => '',
			'artifact_key'   => '',
			'record_hash'    => hash( 'sha256', serialize( $record ) ),
		);

		if ( ! is_array( $record ) || 'filetoweb' !== ( isset( $record['provider'] ) ? (string) $record['provider'] : '' ) ) {
			return $result;
		}

		$key       = isset( $record['artifact_key'] ) ? self::normalize_key( $record['artifact_key'] ) : '';
		$artifacts = isset( $record['artifacts'] ) && is_array( $record['artifacts'] ) ? $record['artifacts'] : array();
		$schema    = isset( $record['filetoweb_storage_schema'] ) ? (int) $record['filetoweb_storage_schema'] : 1;
		$position  = strpos( $key, self::BUNDLE_ROOT . '/' );

		$result['artifact_key'] = $key;
		$result['artifacts']    = count( $artifacts );
		$result['schema']       = $schema;

		if ( ! $key || false === $position || empty( $artifacts ) ) {
			$result['status'] = 'incomplete';
			return $result;
		}

		$prefix = trim( substr( $key, 0, $position ), '/' );
		if ( ! $this->has_consistent_bundle( $artifacts, $key, $prefix ) ) {
			$result['status'] = 'nonstandard';
		} elseif ( '' === $prefix ) {
			$result['status'] = 'rooted';
		} elseif (
			Proud_HTML_Preview::SCHEMA_VERSION <= $schema
			&& Proud_HTML_Preview::STORAGE_BACKEND_STATELESS === ( isset( $record['storage_backend'] ) ? (string) $record['storage_backend'] : '' )
			&& $this->has_current_tenant_storage( $record, $artifacts )
		) {
			$result['status'] = 'tenant_prefixed';
		} else {
			$result['status'] = 'nonstandard';
		}

		if ( $verify_objects ) {
			$object_check           = $this->object_check( $artifacts );
			$result['missing']      = $object_check['missing'];
			$result['check_errors'] = $object_check['errors'];
			if ( 0 < $result['missing'] ) {
				$result['status'] .= '_missing';
			}
			if ( 0 < $result['check_errors'] ) {
				$result['status'] .= '_check_error';
			}
		}

		return $result;
	}

	/**
	 * Determine whether the manifest is complete and contained in one bundle.
	 *
	 * @param array  $artifacts Artifact manifest.
	 * @param string $record_key Primary artifact key.
	 * @param string $prefix Expected prefix.
	 * @return bool
	 */
	private function has_consistent_bundle( $artifacts, $record_key, $prefix ) {
		$needle       = ( $prefix ? trailingslashit( trim( $prefix, '/' ) ) : '' ) . self::BUNDLE_ROOT . '/';
		$record_local = self::local_key( $record_key );
		$bundle       = $record_local ? trailingslashit( dirname( $record_local ) ) : '';
		$found_index  = false;

		if ( ! $record_local || 'index.html' !== basename( $record_local ) ) {
			return false;
		}

		foreach ( $artifacts as $artifact ) {
			$key = isset( $artifact['artifact_key'] ) ? self::normalize_key( $artifact['artifact_key'] ) : '';
			$local_key = self::local_key( $key );
			if ( 0 !== strpos( $key, $needle ) || 0 !== strpos( $local_key, $bundle ) ) {
				return false;
			}

			$found_index = $found_index || $key === $record_key;
		}

		return $found_index;
	}

	/**
	 * Require the plugin's current-tenant and exact-GCS-URL trust contract.
	 *
	 * @param array $record Preview record.
	 * @param array $artifacts Artifact manifest.
	 * @return bool
	 */
	private function has_current_tenant_storage( $record, $artifacts ) {
		$candidates = array_merge( array( $record ), $artifacts );
		foreach ( $candidates as $candidate ) {
			$key = isset( $candidate['artifact_key'] ) ? self::normalize_key( $candidate['artifact_key'] ) : '';
			$url = isset( $candidate['artifact_url'] ) ? (string) $candidate['artifact_url'] : '';
			if ( ! $key || ! $url || empty( Proud_HTML_Preview::trusted_storage_base_urls( array(), $url, $key ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check manifest entries through the authenticated storage client.
	 *
	 * @param array $artifacts Artifact manifest.
	 * @return array
	 */
	private function object_check( $artifacts ) {
		if ( ! is_object( $this->stateless_client ) || ! is_callable( array( $this->stateless_client, 'media_exists' ) ) ) {
			return array(
				'missing' => 0,
				'errors'  => count( $artifacts ),
			);
		}

		$missing = 0;
		$errors  = 0;
		foreach ( $artifacts as $artifact ) {
			$key = isset( $artifact['artifact_key'] ) ? self::normalize_key( $artifact['artifact_key'] ) : '';
			if ( ! $key ) {
				++$missing;
				continue;
			}

			$exists = $this->stateless_client->media_exists( $key );
			if ( is_wp_error( $exists ) ) {
				++$errors;
			} elseif ( ! $exists ) {
				++$missing;
			}
		}

		return array(
			'missing' => $missing,
			'errors'  => $errors,
		);
	}

	/**
	 * Recover the WordPress-local portion of a preview key.
	 *
	 * @param mixed $key Storage key.
	 * @return string
	 */
	private static function local_key( $key ) {
		$key      = self::normalize_key( $key );
		$position = strpos( $key, self::BUNDLE_ROOT . '/' );

		return false === $position ? '' : substr( $key, $position );
	}

	/**
	 * @param mixed $key Storage key.
	 * @return string
	 */
	private static function normalize_key( $key ) {
		$key = ltrim( wp_normalize_path( (string) $key ), '/' );

		return false === strpos( $key, '../' ) && false === strpos( $key, '/..' ) ? $key : '';
	}
}

/**
 * Serialize write work with the plugin's normal polling worker.
 */
class Preview_Migration_Write_Gate {
	/**
	 * Callable compatible with Cron::with_poll_lock().
	 *
	 * @var callable
	 */
	private $lock_runner;

	/**
	 * @param callable|null $lock_runner Optional test runner.
	 */
	public function __construct( $lock_runner = null ) {
		$this->lock_runner = is_callable( $lock_runner ) ? $lock_runner : array( Cron::class, 'with_poll_lock' );
	}

	/**
	 * Run work only after obtaining the shared connection-scoped MySQL lock.
	 *
	 * @param callable $callback Bounded write work.
	 * @return array
	 */
	public function run( $callback ) {
		$busy_marker = new \stdClass();
		$result      = call_user_func( $this->lock_runner, $callback, $busy_marker );

		return array(
			'acquired' => $busy_marker !== $result,
			'result'   => $busy_marker !== $result ? $result : null,
		);
	}
}

/**
 * Manage a deliberately small, explicitly initiated preview migration.
 */
class Preview_Migration_Command {
	const DEFAULT_LIMIT = 10;
	const MAX_WRITE_LIMIT = 10;

	/**
	 * Audit a bounded page of preview records without changing WordPress or GCS.
	 *
	 * ## OPTIONS
	 *
	 * [--after=<meta-id>]
	 * : Resume after this postmeta row ID. Default: 0.
	 *
	 * [--limit=<number>]
	 * : Inspect at most this many records. Maximum: 100. Default: 10.
	 *
	 * [--verify-objects]
	 * : Check every manifest object through the authenticated WP Stateless client.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 */
	public function audit( $args, $assoc_args ) {
		unset( $args );
		$this->assert_runtime();
		$limit   = $this->read_limit( $assoc_args, 100 );
		$after   = isset( $assoc_args['after'] ) ? max( 0, (int) $assoc_args['after'] ) : 0;
		$verify  = isset( $assoc_args['verify-objects'] );
		$rows    = $this->repository()->scan( $after, $limit );
		$results = $this->classify_rows( $rows, $verify );

		$this->render_page( $results, $rows, $assoc_args );
	}

	/**
	 * Copy a bounded page of intact rooted bundles to tenant-prefixed storage.
	 *
	 * The command is a dry run unless --apply is supplied. It never deletes old
	 * objects and never reschedules itself.
	 *
	 * ## OPTIONS
	 *
	 * [--after=<meta-id>]
	 * : Resume after this postmeta row ID. Default: 0.
	 *
	 * [--limit=<number>]
	 * : Process at most this many records. Maximum: 10. Default: 10.
	 *
	 * [--apply]
	 * : Perform verified per-record migration. Omit for a dry run.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 */
	public function migrate( $args, $assoc_args ) {
		unset( $args );
		$this->assert_runtime();
		$limit = $this->read_limit( $assoc_args, self::MAX_WRITE_LIMIT );
		$after = isset( $assoc_args['after'] ) ? max( 0, (int) $assoc_args['after'] ) : 0;
		$apply = isset( $assoc_args['apply'] );
		$rows  = $this->repository()->scan( $after, $limit );

		if ( ! $apply ) {
			$results = $this->classify_rows( $rows, true );
			foreach ( $results as &$result ) {
				if ( 'rooted' === $result['status'] ) {
					$result['action'] = 'would_migrate';
				} elseif ( in_array( $result['status'], array( 'rooted_missing', 'tenant_prefixed_missing' ), true ) ) {
					$result['action'] = 'needs_refresh';
				} elseif ( false !== strpos( $result['status'], '_check_error' ) ) {
					$result['action'] = 'retry_audit';
				} else {
					$result['action'] = 'skip';
				}
			}
			unset( $result );
			$this->render_page( $results, $rows, $assoc_args );
			\WP_CLI::warning( 'Dry run only. Re-run the same bounded page with --apply to migrate intact rooted records.' );
			return;
		}

		$outcome = $this->write_gate()->run(
			function () use ( $rows ) {
				$results = array();
				foreach ( $rows as $row ) {
					$before           = $this->repository()->classify( $this->current_row( $row ), true );
					$before['action'] = 'skip';

					if ( 'rooted' === $before['status'] ) {
						$migrated  = Proud_HTML_Preview::migrate_existing_post( $before['post_id'] );
						$after_row = $this->current_row( $row );
						$verified  = $this->repository()->classify( $after_row, true );

						if ( $migrated ) {
							$before['action']       = 'tenant_prefixed' === $verified['status'] ? 'migrated' : 'migrated_unverified';
							$before['status']       = $verified['status'];
							$before['artifact_key'] = $verified['artifact_key'];
							$before['schema']       = $verified['schema'];
							$before['missing']      = $verified['missing'];
							$before['check_errors'] = $verified['check_errors'];
						} elseif ( 'tenant_prefixed' === $verified['status'] ) {
							$before['action']       = 'already_current';
							$before['status']       = $verified['status'];
							$before['artifact_key'] = $verified['artifact_key'];
							$before['schema']       = $verified['schema'];
							$before['missing']      = 0;
							$before['check_errors'] = 0;
						} else {
							$before['action'] = 'failed_unchanged';
						}
					} elseif ( in_array( $before['status'], array( 'rooted_missing', 'tenant_prefixed_missing' ), true ) ) {
						$before['action'] = 'needs_refresh';
					} elseif ( false !== strpos( $before['status'], '_check_error' ) ) {
						$before['action'] = 'retry_audit';
					}

					$results[] = $before;
				}

				return $results;
			}
		);

		if ( ! $outcome['acquired'] ) {
			\WP_CLI::error( 'The FileToWeb polling worker is active. No records were changed; retry this bounded page after it finishes.' );
		}
		$results = $outcome['result'];

		$this->render_page( $results, $rows, $assoc_args );
	}

	/**
	 * Re-fetch one missing preview from its existing completed FileToWeb record.
	 *
	 * This follows the manual Refresh embedded preview path. It does not submit a
	 * new conversion or call the FileToWeb reprocess endpoint.
	 *
	 * ## OPTIONS
	 *
	 * --post-id=<id>
	 * : Exact preview-owner post ID to refresh.
	 *
	 * [--apply]
	 * : Perform the refresh. Omit for a dry run.
	 */
	public function repair( $args, $assoc_args ) {
		unset( $args );
		$this->assert_runtime();
		$post_id = isset( $assoc_args['post-id'] ) ? absint( $assoc_args['post-id'] ) : 0;
		if ( ! $post_id ) {
			\WP_CLI::error( '--post-id is required.' );
		}

		$record = Proud_HTML_Preview::record_for_post( $post_id );
		if ( empty( $record ) ) {
			\WP_CLI::error( 'No FileToWeb preview record was found for that post.' );
		}

		if ( ! isset( $assoc_args['apply'] ) ) {
			\WP_CLI::warning( sprintf( 'Dry run only. Re-run with --post-id=%d --apply to invoke the existing completed-document refresh path.', $post_id ) );
			return;
		}

		$outcome = $this->write_gate()->run(
			function () use ( $post_id ) {
				Local_HTML::clear_poll_refresh_result( $post_id );
				$poll_result    = Sync::poll_post( $post_id, true );
				$refresh_result = Local_HTML::poll_refresh_result( $post_id );

				return array(
					'poll_result'    => $poll_result,
					'refresh_result' => $refresh_result,
				);
			}
		);

		if ( ! $outcome['acquired'] ) {
			\WP_CLI::error( 'The FileToWeb polling worker is active. The preview was not refreshed; retry after it finishes.' );
		}
		$poll_result    = $outcome['result']['poll_result'];
		$refresh_result = $outcome['result']['refresh_result'];

		if ( in_array( $refresh_result, array( 'updated', 'current' ), true ) && Proud_HTML_Preview::is_durable_record( Proud_HTML_Preview::record_for_post( $post_id ) ) ) {
			\WP_CLI::success( sprintf( 'Post %d was refreshed into durable tenant-prefixed storage.', $post_id ) );
			return;
		}

		$error = get_post_meta( $post_id, Document_State::META_LAST_ERROR, true );
		\WP_CLI::error(
			$error
				? sprintf( 'Post %d could not be refreshed: %s', $post_id, $error )
				: sprintf( 'Post %d could not be refreshed. Status check: %s.', $post_id, $poll_result )
		);
	}

	/**
	 * Verify one bounded page after migration.
	 *
	 * ## OPTIONS
	 *
	 * [--after=<meta-id>]
	 * : Resume after this postmeta row ID. Default: 0.
	 *
	 * [--limit=<number>]
	 * : Verify at most this many records. Maximum: 100. Default: 10.
	 *
	 * [--format=<format>]
	 * : table or json. Default: table.
	 */
	public function verify( $args, $assoc_args ) {
		$assoc_args['verify-objects'] = true;
		$this->audit( $args, $assoc_args );
	}

	/**
	 * @return Preview_Migration_Repository
	 */
	private function repository() {
		global $wpdb;

		return new Preview_Migration_Repository( $wpdb, $this->stateless_client() );
	}

	/**
	 * @return Preview_Migration_Write_Gate
	 */
	private function write_gate() {
		return new Preview_Migration_Write_Gate();
	}

	/**
	 * Resolve the authenticated WP Stateless client without deriving bucket URLs.
	 *
	 * @return object|null
	 */
	private function stateless_client() {
		if ( ! function_exists( 'ud_get_stateless_media' ) ) {
			return null;
		}

		try {
			$stateless = ud_get_stateless_media();
		} catch ( \Throwable $exception ) {
			return null;
		}

		return is_object( $stateless ) && is_callable( array( $stateless, 'get_client' ) ) ? $stateless->get_client() : null;
	}

	/**
	 * Re-read only the selected row after a write.
	 *
	 * @param array $row Original row.
	 * @return array
	 */
	private function current_row( $row ) {
		$post_id  = (int) $row['post_id'];
		$meta_key = (string) $row['meta_key'];

		$row['meta_value'] = get_post_meta( $post_id, $meta_key, true );
		return $row;
	}

	/**
	 * @param array $rows Raw rows.
	 * @param bool  $verify_objects Verify remote objects.
	 * @return array
	 */
	private function classify_rows( $rows, $verify_objects ) {
		$repository = $this->repository();
		$results    = array();
		foreach ( $rows as $row ) {
			$results[] = $repository->classify( $row, $verify_objects );
		}

		return $results;
	}

	/**
	 * Refuse to run without the exact runtime dependencies used by the migration.
	 */
	private function assert_runtime() {
		if ( ! class_exists( Proud_HTML_Preview::class ) || ! class_exists( Local_HTML::class ) || ! class_exists( Cron::class ) ) {
			\WP_CLI::error( 'FileToWeb Integration 0.1.50 or newer must be active.' );
		}

		if ( defined( 'FILETOWEB_INTEGRATION_VERSION' ) && version_compare( FILETOWEB_INTEGRATION_VERSION, '0.1.50', '<' ) ) {
			\WP_CLI::error( 'FileToWeb Integration 0.1.50 or newer is required.' );
		}

		if ( ! function_exists( 'ud_get_stateless_media' ) ) {
			\WP_CLI::error( 'WP Stateless must be active for this ProudCity migration.' );
		}

		$client = $this->stateless_client();
		if ( ! is_object( $client ) || ! is_callable( array( $client, 'media_exists' ) ) ) {
			\WP_CLI::error( 'The authenticated WP Stateless storage client is unavailable.' );
		}
	}

	/**
	 * @param array $assoc_args Command options.
	 * @param int   $maximum Maximum accepted limit.
	 * @return int
	 */
	private function read_limit( $assoc_args, $maximum ) {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : self::DEFAULT_LIMIT;
		if ( 1 > $limit || $maximum < $limit ) {
			\WP_CLI::error( sprintf( '--limit must be between 1 and %d.', $maximum ) );
		}

		return $limit;
	}

	/**
	 * @param array $results Classified records.
	 * @param array $assoc_args Command options.
	 */
	private function render_page( $results, $rows, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( '--format must be table or json.' );
		}

		$last        = end( $rows );
		$next_cursor = is_array( $last ) && isset( $last['meta_id'] )
			? (int) $last['meta_id']
			: ( isset( $assoc_args['after'] ) ? max( 0, (int) $assoc_args['after'] ) : 0 );
		if ( 'json' === $format ) {
			\WP_CLI::line(
				wp_json_encode(
					array(
						'records'     => $results,
						'next_cursor' => $next_cursor,
					)
				)
			);
			return;
		}

		$fields = array( 'meta_id', 'post_id', 'record', 'status', 'artifacts', 'missing', 'check_errors', 'schema', 'action', 'artifact_key', 'record_hash' );
		\WP_CLI\Utils\format_items( $format, $results, $fields );
		\WP_CLI::log( 'next_cursor=' . $next_cursor );
	}
}

if ( class_exists( '\\WP_CLI' ) ) {
	\WP_CLI::add_command(
		'filetoweb preview-migration',
		Preview_Migration_Command::class,
		array( 'when' => 'after_wp_load' )
	);
}
