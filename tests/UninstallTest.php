<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\PDF_To_Page;
use FileToWeb\Integration\Proud_HTML_Preview;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['filetoweb_test_cleanup_queue_results'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_uninstall_removes_options_meta_and_cron(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$deleted_options = array();
		$cleared_hooks   = array();
		$GLOBALS['filetoweb_test_cleanup_queue'] = array();
		$GLOBALS['filetoweb_test_cleanup_queue_results'] = array( true, false, true );

		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted_options ) {
				$deleted_options[] = $name;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Proud_HTML_Preview::OPTION_PROVIDERS === $name ) {
					return array( 'filetoweb' => true, 'another-provider' => true );
				}

				return $default;
			}
		);
		$updated_options = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$updated_options ) {
				$updated_options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'maybe_unserialize' )->alias(
			function ( $value ) {
				return unserialize( $value );
			}
		);
		Functions\when( 'absint' )->alias( function ( $value ) { return abs( intval( $value ) ); } );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\expect( 'do_action' )->never();
			Functions\when( 'wp_clear_scheduled_hook' )->alias(
				function ( $hook ) use ( &$cleared_hooks ) {
					$cleared_hooks[] = $hook;
					return true;
				}
			);
			Functions\when( 'wp_upload_dir' )->justReturn(
				array(
					'basedir' => sys_get_temp_dir(),
					'baseurl' => 'https://city.example/wp-content/uploads',
				)
			);
			Functions\when( 'trailingslashit' )->alias(
				function ( $value ) {
					return rtrim( (string) $value, '/' ) . '/';
				}
			);

		$GLOBALS['wpdb'] = new class() {
			public $postmeta = 'wp_postmeta';
			public $deleted_meta_keys = array();
			public $deleted_meta_ids = array();

			public function prepare( $query, $value ) {
				return str_replace( '%s', "'" . $value . "'", $query );
			}

			public function get_results() {
				return array(
					(object) array(
						'meta_id'    => 17,
						'meta_value' => serialize(
							array(
								'version'      => Proud_HTML_Preview::CORE_SCHEMA_VERSION,
								Proud_HTML_Preview::RECORD_STORAGE_SCHEMA => Proud_HTML_Preview::SCHEMA_VERSION,
								'provider'     => 'filetoweb',
								'artifact_key' => 'oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/index.html',
								'artifact_url' => 'https://storage.googleapis.com/proudcity/oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/index.html',
								'artifacts'    => array(
									array(
										'artifact_key' => 'oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/assets/image.png',
										'artifact_url' => 'https://storage.googleapis.com/proudcity/oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/assets/image.png',
									),
									array(
										'artifact_key' => 'oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/index.html',
										'artifact_url' => 'https://storage.googleapis.com/proudcity/oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/index.html',
									),
								),
								'superseded_artifacts' => array(
									array(
										'artifact_key' => 'oakwoodoh/2026/07/filetoweb-integration/previews/1/fp/index.html',
										'artifact_url' => 'https://storage.googleapis.com/proudcity/oakwoodoh/2026/07/filetoweb-integration/previews/1/fp/index.html',
									),
								),
								'legacy_artifacts' => array(
									array(
										'artifact_key' => 'filetoweb-integration/previews/1/fp/assets/image.png',
										'artifact_url' => 'https://city.example/wp-content/uploads/filetoweb-integration/previews/1/fp/assets/image.png',
									),
									array(
										'artifact_key' => 'filetoweb-integration/previews/1/fp/index.html',
										'artifact_url' => 'https://city.example/wp-content/uploads/filetoweb-integration/previews/1/fp/index.html',
									),
								),
							)
						),
					),
					(object) array(
						'meta_id'    => 18,
						'meta_value' => serialize( array( 'provider' => 'another-provider' ) ),
					),
				);
			}

			public function delete( $table, $where ) {
				if ( 'wp_postmeta' === $table && isset( $where['meta_key'] ) ) {
					$this->deleted_meta_keys[] = $where['meta_key'];
				}
				if ( 'wp_postmeta' === $table && isset( $where['meta_id'] ) ) {
					$this->deleted_meta_ids[] = $where['meta_id'];
				}

				return true;
			}
		};

		require __DIR__ . '/../uninstall.php';

		$this->assertContains( Settings::OPTION_SETTINGS, $deleted_options );
			$this->assertContains( Settings::LEGACY_OPTION_API_KEY, $deleted_options );
			$this->assertContains( 'filetoweb_integration_bulk_queue', $deleted_options );
			$this->assertContains( PDF_To_Page::OPTION_JOBS, $deleted_options );
			$this->assertContains( Document_State::META_HTML_URL, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_LOCAL_HTML_PATH, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_PDF_TO_PAGE, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_NEXT_POLL_AT, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_LAST_POLLED_AT, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_POLL_ATTEMPTS, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_ERROR_CODE, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_ERROR_REFERENCE, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_ERROR_RETRYABLE, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_LAST_TRIGGER, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Proud_HTML_Preview::META_STORAGE_SCHEMA, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( 'filetoweb_integration_poll_schedule_version', $deleted_options );
			$this->assertContains( 'filetoweb_integration_poll_queue_cursor', $deleted_options );
			$this->assertContains( 'filetoweb_integration_post_recovery_cursor', $deleted_options );
			$this->assertContains( 'filetoweb_integration_retry_recovery_cursor', $deleted_options );
			$this->assertContains( PDF_To_Page::OPTION_RECOVERY_CURSOR, $deleted_options );
			$this->assertSame( array( 17 ), $GLOBALS['wpdb']->deleted_meta_ids );
			$this->assertCount( 3, $GLOBALS['filetoweb_test_cleanup_queue'] );
			$this->assertSame( 'oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/assets/image.png', $GLOBALS['filetoweb_test_cleanup_queue'][0][1] );
			$this->assertSame( 'oakwoodoh/2026/07/filetoweb-integration/previews/1/fp/index.html', $GLOBALS['filetoweb_test_cleanup_queue'][2][1] );
			$this->assertArrayHasKey( 'proud_html_preview_legacy_artifacts', $updated_options );
			$this->assertSame(
				array(
					'oakwoodoh/2026/08/filetoweb-integration/previews/1/fp/index.html',
					'filetoweb-integration/previews/1/fp/assets/image.png',
					'filetoweb-integration/previews/1/fp/index.html',
				),
				array_column( $updated_options['proud_html_preview_legacy_artifacts'], 'artifact_key' )
			);
			$this->assertSame( array( 'another-provider' => true ), $updated_options[ Proud_HTML_Preview::OPTION_PROVIDERS ] );
			$this->assertContains( 'filetoweb_integration_poll_pending', $cleared_hooks );
			$this->assertContains( 'filetoweb_integration_process_bulk_queue', $cleared_hooks );
			$this->assertContains( Proud_HTML_Preview::MIGRATION_HOOK, $cleared_hooks );
		}
}
