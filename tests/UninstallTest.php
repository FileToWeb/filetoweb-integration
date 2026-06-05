<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_uninstall_removes_options_meta_and_cron(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$deleted_options = array();
		$cleared_hooks   = array();

		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted_options ) {
				$deleted_options[] = $name;
				return true;
			}
		);
			Functions\when( 'wp_clear_scheduled_hook' )->alias(
				function ( $hook ) use ( &$cleared_hooks ) {
					$cleared_hooks[] = $hook;
					return true;
				}
			);
			Functions\when( 'wp_upload_dir' )->justReturn(
				array(
					'basedir' => sys_get_temp_dir(),
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

			public function delete( $table, $where ) {
				if ( 'wp_postmeta' === $table && isset( $where['meta_key'] ) ) {
					$this->deleted_meta_keys[] = $where['meta_key'];
				}

				return true;
			}
		};

		require __DIR__ . '/../uninstall.php';

		$this->assertContains( Settings::OPTION_SETTINGS, $deleted_options );
			$this->assertContains( Settings::LEGACY_OPTION_API_KEY, $deleted_options );
			$this->assertContains( 'filetoweb_integration_bulk_queue', $deleted_options );
			$this->assertContains( Document_State::META_HTML_URL, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( Document_State::META_LOCAL_HTML_PATH, $GLOBALS['wpdb']->deleted_meta_keys );
			$this->assertContains( 'filetoweb_integration_poll_pending', $cleared_hooks );
			$this->assertContains( 'filetoweb_integration_process_bulk_queue', $cleared_hooks );
		}
}
