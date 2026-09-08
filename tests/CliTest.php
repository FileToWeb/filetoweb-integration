<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\CLI;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Preview_Command;
use FileToWeb\Integration\Proud_HTML_Preview;
use PHPUnit\Framework\TestCase;

class CliTest extends TestCase {
	private $meta      = array();
	private $posts     = array();
	private $refreshed = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		FtwTestWpCli::reset();

		$this->meta      = array();
		$this->posts     = array();
		$this->refreshed = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				unset( $single );
				return isset( $this->meta[ $post_id ][ $key ] ) ? $this->meta[ $post_id ][ $key ] : '';
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $post_id ) {
				return isset( $this->posts[ $post_id ] ) ? (object) $this->posts[ $post_id ] : null;
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'document', 'attachment' ) );
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/uploads',
				'baseurl' => 'https://example.test/wp-content/uploads',
			)
		);
		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'ud_get_stateless_media' )->justReturn(
			new FtwTestStatelessBootstrap( new FtwTestStatelessClient() )
		);
		Functions\when( 'get_the_title' )->alias(
			function ( $post_id ) {
				return isset( $this->posts[ $post_id ]['post_title'] ) ? $this->posts[ $post_id ]['post_title'] : '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a preview record in the shape the plugin stores.
	 *
	 * @param bool $durable Whether the record points at shared storage.
	 * @return array
	 */
	private function record( $durable ) {
		$record = array(
			'version'      => 1,
			'provider'     => 'filetoweb',
			'source_url'   => 'https://storage.googleapis.com/bucket/tenant/uploads/budget.pdf',
			'artifact_key' => 'filetoweb-integration/previews/7/abc/index.html',
			'artifact_url' => 'https://example.test/wp-content/uploads/filetoweb-integration/previews/7/abc/index.html',
			'token'        => 'token-7',
		);

		if ( $durable ) {
			$record[ Proud_HTML_Preview::RECORD_STORAGE_SCHEMA ] = Proud_HTML_Preview::SCHEMA_VERSION;
			$record['storage_backend']                           = Proud_HTML_Preview::STORAGE_BACKEND_STATELESS;
			$record['artifact_key']                              = 'tenant/2026/09/filetoweb-integration/previews/7/abc/index.html';
			$record['artifact_url']                              = 'https://storage.googleapis.com/bucket/tenant/2026/09/filetoweb-integration/previews/7/abc/index.html';
			$record['artifacts']                                 = array(
				array(
					'artifact_key' => $record['artifact_key'],
					'artifact_url' => $record['artifact_url'],
				),
			);
		}

		return $record;
	}

	/**
	 * Register a source post carrying a preview record.
	 *
	 * @param int    $post_id Post ID.
	 * @param bool   $durable Whether its record is durable.
	 * @param string $status FileToWeb status.
	 * @param string $type Post type.
	 */
	private function seed_source( $post_id, $durable, $status = 'ready', $type = 'document' ) {
		$this->posts[ $post_id ] = array(
			'ID'          => $post_id,
			'post_type'   => $type,
			'post_status' => 'publish',
			'post_title'  => 'Document ' . $post_id,
		);

		$this->meta[ $post_id ] = array(
			Proud_HTML_Preview::META_KEY     => $this->record( $durable ),
			Document_State::META_STATUS      => $status,
			Document_State::META_HTML_URL    => 'https://filetoweb.com/d/abc/1',
		);
	}

	/**
	 * Return the given IDs from the preview meta query.
	 *
	 * @param array $ids Post IDs.
	 */
	private function expect_source_query( array $ids ) {
		Functions\expect( 'get_posts' )
			->once()
			->andReturnUsing(
				function ( $args ) use ( $ids ) {
					$this->assertSame( Proud_HTML_Preview::META_KEY, $args['meta_key'] );
					$this->assertSame( 'any', $args['post_status'] );
					$this->assertContains( 'attachment', $args['post_type'] );
					$this->assertSame( 'ids', $args['fields'] );
					$this->assertSame( -1, $args['posts_per_page'] );

					return $ids;
				}
			);
	}

	public function test_init_registers_the_command_only_under_wp_cli() {
		CLI::init();

		$this->assertSame(
			array(
				array(
					'name'  => 'filetoweb preview',
					'class' => Preview_Command::class,
				),
			),
			FtwTestWpCli::$commands
		);
	}

	public function test_inspect_reports_a_legacy_record_as_not_durable() {
		$this->seed_source( 7, false );

		$row = Preview_Command::inspect( 7 );

		$this->assertSame( 7, $row['id'] );
		$this->assertSame( 'document', $row['post_type'] );
		$this->assertSame( 'ready', $row['status'] );
		$this->assertSame( 'local', $row['storage'] );
		$this->assertSame( 'no', $row['durable'] );
		$this->assertSame(
			'https://example.test/wp-content/uploads/filetoweb-integration/previews/7/abc/index.html',
			$row['artifact_url']
		);
	}

	public function test_inspect_reports_a_shared_storage_record_as_durable() {
		$this->seed_source( 8, true );

		$row = Preview_Command::inspect( 8 );

		$this->assertSame( 'wp-stateless', $row['storage'] );
		$this->assertSame( 'yes', $row['durable'] );
	}

	public function test_source_ids_queries_every_registered_post_type() {
		$this->expect_source_query( array( 7, 8 ) );

		$this->assertSame( array( 7, 8 ), Preview_Command::source_ids() );
	}

	public function test_stale_ids_returns_only_non_durable_records() {
		$this->seed_source( 7, false );
		$this->seed_source( 8, true );
		$this->seed_source( 9, false );
		$this->expect_source_query( array( 7, 8, 9 ) );

		$this->assertSame( array( 7, 9 ), Preview_Command::stale_ids() );
	}

	public function test_repair_post_refreshes_and_reports_the_new_record_state() {
		$this->seed_source( 7, false );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;
				$this->meta[ $post_id ][ Proud_HTML_Preview::META_KEY ] = $this->record( true );

				return 'updated';
			}
		);

		$result = Preview_Command::repair_post( 7 );

		$this->assertSame( array( 7 ), $this->refreshed );
		$this->assertSame( 'updated', $result['result'] );
		$this->assertTrue( $result['durable'] );
	}

	public function test_repair_post_reports_a_refresh_that_did_not_become_durable() {
		$this->seed_source( 7, false );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;

				return 'updated';
			}
		);

		$result = Preview_Command::repair_post( 7 );

		$this->assertSame( 'updated', $result['result'] );
		$this->assertFalse( $result['durable'] );
	}

	public function test_repair_post_skips_a_source_that_is_not_ready() {
		$this->seed_source( 7, false, 'processing' );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;

				return 'updated';
			}
		);

		$result = Preview_Command::repair_post( 7 );

		$this->assertSame( array(), $this->refreshed );
		$this->assertSame( 'skipped', $result['result'] );
	}

	public function test_repair_post_reports_a_missing_post() {
		$result = Preview_Command::repair_post( 404 );

		$this->assertSame( 'skipped', $result['result'] );
		$this->assertSame( 'no-post', $result['reason'] );
	}

	public function test_repair_dry_run_reports_without_refreshing() {
		$this->seed_source( 7, false );
		$this->seed_source( 8, true );
		$this->expect_source_query( array( 7, 8 ) );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;

				return 'updated';
			}
		);

		$command = new Preview_Command();
		$command->repair( array(), array( 'dry-run' => true ) );

		$this->assertSame( array(), $this->refreshed );
		$this->assertStringContainsString( '1 stale preview', implode( "\n", FtwTestWpCli::$logs ) );
	}

	public function test_repair_honours_an_explicit_post_list() {
		$this->seed_source( 7, false );
		$this->seed_source( 9, false );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;
				$this->meta[ $post_id ][ Proud_HTML_Preview::META_KEY ] = $this->record( true );

				return 'updated';
			}
		);

		$command = new Preview_Command();
		$command->repair( array(), array( 'post' => '9' ) );

		$this->assertSame( array( 9 ), $this->refreshed );
	}

	public function test_repair_stops_at_the_requested_limit() {
		$this->seed_source( 7, false );
		$this->seed_source( 8, false );
		$this->seed_source( 9, false );
		$this->expect_source_query( array( 7, 8, 9 ) );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;
				$this->meta[ $post_id ][ Proud_HTML_Preview::META_KEY ] = $this->record( true );

				return 'updated';
			}
		);

		$command = new Preview_Command();
		$command->repair( array(), array( 'limit' => 2 ) );

		$this->assertSame( array( 7, 8 ), $this->refreshed );
	}

	public function test_repair_reports_a_failure_without_halting_the_run() {
		$this->seed_source( 7, false );
		$this->seed_source( 9, false );
		$this->expect_source_query( array( 7, 9 ) );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;

				if ( 7 === $post_id ) {
					return 'failed';
				}

				$this->meta[ $post_id ][ Proud_HTML_Preview::META_KEY ] = $this->record( true );

				return 'updated';
			}
		);

		$command = new Preview_Command();
		$command->repair( array(), array() );

		$this->assertSame( array( 7, 9 ), $this->refreshed );
		$this->assertNull( FtwTestWpCli::$error );

		$summary = implode( "\n", FtwTestWpCli::$logs ) . implode( "\n", FtwTestWpCli::$warnings );
		$this->assertStringContainsString( 'failed', $summary );
	}

	public function test_repair_refuses_to_run_without_shared_storage() {
		$this->seed_source( 7, false );
		$this->expect_source_query( array( 7 ) );
		Functions\when( 'has_action' )->justReturn( false );

		Preview_Command::set_refresher(
			function ( $post_id ) {
				$this->refreshed[] = $post_id;

				return 'updated';
			}
		);

		$command = new Preview_Command();
		$command->repair( array(), array() );

		$this->assertSame( array(), $this->refreshed );
		$this->assertStringContainsString( 'shared object storage', (string) FtwTestWpCli::$error );
	}

	public function test_status_explains_local_records_on_a_site_without_shared_storage() {
		$this->seed_source( 7, false );
		$this->expect_source_query( array( 7 ) );
		Functions\when( 'has_action' )->justReturn( false );

		$command = new Preview_Command();
		$command->status( array(), array() );

		$summary = implode( "\n", FtwTestWpCli::$logs );
		$this->assertStringContainsString( 'no shared object storage', $summary );
		$this->assertStringNotContainsString( 'preview repair', $summary );
	}

	public function test_list_outputs_every_record_by_default() {
		$this->seed_source( 7, false );
		$this->seed_source( 8, true );
		$this->expect_source_query( array( 7, 8 ) );

		$command = new Preview_Command();
		$command->list_( array(), array() );

		$this->assertCount( 2, FtwTestWpCli::$items );
		$this->assertSame( 7, FtwTestWpCli::$items[0]['id'] );
		$this->assertSame( 8, FtwTestWpCli::$items[1]['id'] );
	}

	public function test_list_can_filter_to_stale_records() {
		$this->seed_source( 7, false );
		$this->seed_source( 8, true );
		$this->expect_source_query( array( 7, 8 ) );

		$command = new Preview_Command();
		$command->list_( array(), array( 'stale' => true ) );

		$this->assertCount( 1, FtwTestWpCli::$items );
		$this->assertSame( 7, FtwTestWpCli::$items[0]['id'] );
	}

	public function test_status_summarises_the_site() {
		$this->seed_source( 7, false );
		$this->seed_source( 8, true );
		$this->seed_source( 9, false );
		$this->expect_source_query( array( 7, 8, 9 ) );

		$command = new Preview_Command();
		$command->status( array(), array() );

		$summary = implode( "\n", FtwTestWpCli::$logs );
		$this->assertStringContainsString( '3', $summary );
		$this->assertStringContainsString( '2', $summary );
	}
}
