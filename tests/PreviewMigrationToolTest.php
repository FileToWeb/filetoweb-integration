<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Operations\Preview_Migration_Repository;
use FileToWeb\Integration\Operations\Preview_Migration_Write_Gate;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static $commands = array();

		public static function add_command( $name, $callable, $args = array() ) {
			self::$commands[ $name ] = array(
				'callable' => $callable,
				'args'     => $args,
			);
		}
	}
}

require_once __DIR__ . '/../tools/filetoweb-preview-migration.php';

class FtwPreviewMigrationWpdb {
	public $postmeta = 'wp_postmeta';
	public $prepared_query = '';
	public $prepared_args  = array();
	public $rows           = array();

	public function prepare( $query, ...$args ) {
		$this->prepared_query = $query;
		$this->prepared_args  = $args;

		return 'prepared-preview-migration-query';
	}

	public function get_results( $query, $format ) {
		if ( 'prepared-preview-migration-query' !== $query || ARRAY_A !== $format ) {
			return array();
		}

		return $this->rows;
	}
}

class FtwPreviewMigrationStorageClient {
	public $objects = array();
	public $errors  = array();
	public $checked = array();

	public function media_exists( $key ) {
		$this->checked[] = $key;
		if ( ! empty( $this->errors[ $key ] ) ) {
			return new FtwPreviewMigrationStorageError();
		}

		return ! empty( $this->objects[ $key ] ) ? (object) array( 'id' => $key ) : false;
	}
}

class FtwPreviewMigrationStorageError {}

class PreviewMigrationToolTest extends TestCase {
	private $wpdb;
	private $client;
	private $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb       = new FtwPreviewMigrationWpdb();
		$this->client     = new FtwPreviewMigrationStorageClient();
		$this->repository = new Preview_Migration_Repository( $this->wpdb, $this->client );

		Functions\when( 'maybe_unserialize' )->alias(
			function ( $value ) {
				if ( ! is_string( $value ) ) {
					return $value;
				}

				$unserialized = @unserialize( $value );
				return false === $unserialized && 'b:0;' !== $value ? $value : $unserialized;
			}
		);
		Functions\when( 'wp_normalize_path' )->alias( function ( $value ) { return str_replace( '\\', '/', (string) $value ); } );
		Functions\when( 'trailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ) . '/'; } );
		Functions\when( 'untrailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( function ( $value ) { return $value instanceof FtwPreviewMigrationStorageError; } );
		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/filetoweb-preview-migration-uploads',
				'baseurl' => 'https://oakwood.example/wp-content/uploads',
			)
		);
		Functions\when( 'ud_get_stateless_media' )->alias(
			function () {
				return new FtwTestStatelessBootstrap( $this->client );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, $use_root = false ) {
				if ( 'wp_stateless_file_name' === $tag && true === $use_root ) {
					return 'oakwoodoh/2026/08/' . ltrim( (string) $value, '/' );
				}

				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_scan_uses_one_bounded_postmeta_query_without_joins_or_serialized_predicates(): void {
		$this->wpdb->rows = array(
			array(
				'meta_id'   => 42,
				'post_id'   => 61,
				'meta_key'  => '_proud_html_preview',
				'meta_value' => array(),
			),
		);

		$this->assertSame( $this->wpdb->rows, $this->repository->scan( 40, 500 ) );
		$this->assertStringContainsString( 'FROM wp_postmeta', $this->wpdb->prepared_query );
		$this->assertStringContainsString( 'meta_id > %d', $this->wpdb->prepared_query );
		$this->assertStringNotContainsString( 'JOIN', strtoupper( $this->wpdb->prepared_query ) );
		$this->assertStringNotContainsString( 'NOT LIKE', strtoupper( $this->wpdb->prepared_query ) );
		$this->assertStringNotContainsString( 'wp_posts', $this->wpdb->prepared_query );
		$this->assertSame(
			array( '_proud_html_preview', '_filetoweb_paused_html_preview', 40, 100 ),
			$this->wpdb->prepared_args
		);
	}

	public function test_tool_registers_only_as_an_explicit_after_wordpress_cli_command(): void {
		$this->assertArrayHasKey( 'filetoweb preview-migration', WP_CLI::$commands );
		$this->assertSame(
			array( 'when' => 'after_wp_load' ),
			WP_CLI::$commands['filetoweb preview-migration']['args']
		);
	}

	public function test_write_gate_does_not_run_work_when_shared_poll_lock_is_busy(): void {
		$work_ran = false;
		$gate     = new Preview_Migration_Write_Gate(
			function ( $callback, $busy_result ) {
				unset( $callback );
				return $busy_result;
			}
		);

		$outcome = $gate->run(
			function () use ( &$work_ran ) {
				$work_ran = true;
			}
		);

		$this->assertFalse( $outcome['acquired'] );
		$this->assertFalse( $work_ran );
	}

	public function test_write_gate_returns_bounded_work_result_after_shared_lock_is_acquired(): void {
		$gate = new Preview_Migration_Write_Gate(
			function ( $callback ) {
				return call_user_func( $callback );
			}
		);

		$outcome = $gate->run( function () { return array( 'migrated' => 5 ); } );

		$this->assertTrue( $outcome['acquired'] );
		$this->assertSame( array( 'migrated' => 5 ), $outcome['result'] );
	}

	public function test_rooted_record_with_all_objects_is_a_migration_candidate(): void {
		$record = $this->legacy_record();
		foreach ( $record['artifacts'] as $artifact ) {
			$this->client->objects[ $artifact['artifact_key'] ] = true;
		}

		$result = $this->repository->classify( $this->row( 10, 61, '_proud_html_preview', $record ), true );

		$this->assertSame( 'rooted', $result['status'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 0, $result['check_errors'] );
		$this->assertSame( 2, $result['artifacts'] );
		$this->assertSame( 1, $result['schema'] );
	}

	public function test_paused_record_is_reported_without_becoming_active(): void {
		$result = $this->repository->classify(
			$this->row( 11, 62, '_filetoweb_paused_html_preview', $this->legacy_record() ),
			false
		);

		$this->assertSame( 'paused', $result['record'] );
		$this->assertSame( 'rooted', $result['status'] );
	}

	public function test_missing_root_object_is_separated_for_explicit_refresh(): void {
		$record = $this->legacy_record();
		$this->client->objects[ $record['artifacts'][1]['artifact_key'] ] = true;

		$result = $this->repository->classify( $this->row( 12, 63, '_proud_html_preview', $record ), true );

		$this->assertSame( 'rooted_missing', $result['status'] );
		$this->assertSame( 1, $result['missing'] );
	}

	public function test_storage_check_error_is_not_misreported_as_a_missing_object(): void {
		$record = $this->legacy_record();
		$this->client->objects[ $record['artifacts'][1]['artifact_key'] ] = true;
		$this->client->errors[ $record['artifacts'][0]['artifact_key'] ] = true;

		$result = $this->repository->classify( $this->row( 16, 67, '_proud_html_preview', $record ), true );

		$this->assertSame( 'rooted_check_error', $result['status'] );
		$this->assertSame( 0, $result['missing'] );
		$this->assertSame( 1, $result['check_errors'] );
	}

	public function test_current_tenant_prefixed_manifest_is_idempotently_skipped(): void {
		$record = $this->legacy_record();
		$prefix = 'oakwoodoh/2026/08/';
		$record['artifact_key']             = $prefix . $record['artifact_key'];
		$record['artifact_url']             = 'https://storage.googleapis.com/proudcity/' . $record['artifact_key'];
		$record['filetoweb_storage_schema'] = 3;
		$record['storage_backend']           = 'wp-stateless';
		foreach ( $record['artifacts'] as &$artifact ) {
			$artifact['artifact_key'] = $prefix . $artifact['artifact_key'];
			$artifact['artifact_url'] = 'https://storage.googleapis.com/proudcity/' . $artifact['artifact_key'];
			$this->client->objects[ $artifact['artifact_key'] ] = true;
		}
		unset( $artifact );

		$result = $this->repository->classify( $this->row( 13, 64, '_proud_html_preview', $record ), true );

		$this->assertSame( 'tenant_prefixed', $result['status'] );
		$this->assertSame( 0, $result['missing'] );
	}

	public function test_mixed_tenant_manifest_is_not_trusted_as_current(): void {
		$record = $this->legacy_record();
		$record['artifact_key']             = 'oakwoodoh/2026/08/' . $record['artifact_key'];
		$record['filetoweb_storage_schema'] = 3;
		$record['storage_backend']           = 'wp-stateless';
		$record['artifacts'][0]['artifact_key'] = 'othercity/2026/08/' . $record['artifacts'][0]['artifact_key'];
		$record['artifacts'][1]['artifact_key'] = 'oakwoodoh/2026/08/' . $record['artifacts'][1]['artifact_key'];

		$result = $this->repository->classify( $this->row( 14, 65, '_proud_html_preview', $record ), false );

		$this->assertSame( 'nonstandard', $result['status'] );
	}

	public function test_consistent_wrong_tenant_record_is_not_trusted_as_current(): void {
		$record = $this->legacy_record();
		$prefix = 'othercity/2026/08/';
		$record['artifact_key']             = $prefix . $record['artifact_key'];
		$record['artifact_url']             = 'https://storage.googleapis.com/proudcity/' . $record['artifact_key'];
		$record['filetoweb_storage_schema'] = 3;
		$record['storage_backend']           = 'wp-stateless';
		foreach ( $record['artifacts'] as &$artifact ) {
			$artifact['artifact_key'] = $prefix . $artifact['artifact_key'];
			$artifact['artifact_url'] = 'https://storage.googleapis.com/proudcity/' . $artifact['artifact_key'];
		}
		unset( $artifact );

		$result = $this->repository->classify( $this->row( 18, 69, '_proud_html_preview', $record ), false );

		$this->assertSame( 'nonstandard', $result['status'] );
	}

	public function test_manifest_without_its_primary_index_is_not_migrated(): void {
		$record = $this->legacy_record();
		array_pop( $record['artifacts'] );

		$result = $this->repository->classify( $this->row( 17, 68, '_proud_html_preview', $record ), false );

		$this->assertSame( 'nonstandard', $result['status'] );
	}

	public function test_traversal_or_incomplete_manifest_fails_closed(): void {
		$record                 = $this->legacy_record();
		$record['artifact_key'] = '../' . $record['artifact_key'];

		$result = $this->repository->classify( $this->row( 15, 66, '_proud_html_preview', $record ), true );

		$this->assertSame( 'incomplete', $result['status'] );
		$this->assertSame( array(), $this->client->checked );
	}

	private function legacy_record() {
		$bundle = 'filetoweb-integration/previews/61/fingerprint';

		return array(
			'version'            => 1,
			'provider'           => 'filetoweb',
			'source_url'         => 'https://city.example/uploads/source.pdf',
			'source_fingerprint' => 'fingerprint',
			'artifact_key'       => $bundle . '/index.html',
			'artifact_url'       => 'https://storage.googleapis.com/proudcity/' . $bundle . '/index.html',
			'artifacts'          => array(
				array(
					'artifact_key' => $bundle . '/assets/logo.png',
					'artifact_url' => 'https://storage.googleapis.com/proudcity/' . $bundle . '/assets/logo.png',
				),
				array(
					'artifact_key' => $bundle . '/index.html',
					'artifact_url' => 'https://storage.googleapis.com/proudcity/' . $bundle . '/index.html',
				),
			),
		);
	}

	private function row( $meta_id, $post_id, $meta_key, $record ) {
		return array(
			'meta_id'   => $meta_id,
			'post_id'   => $post_id,
			'meta_key'  => $meta_key,
			'meta_value' => $record,
		);
	}
}
