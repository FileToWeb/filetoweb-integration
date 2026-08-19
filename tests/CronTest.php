<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Cron;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase {
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->previous_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
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
		Functions\when( 'home_url' )->justReturn( 'https://city.example' );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_one_minute_worker_schedule(): void {
		$schedules = Cron::add_cron_schedule( array() );

		$this->assertArrayHasKey( Cron::SCHEDULE_POLL, $schedules );
		$this->assertSame( 60, $schedules[ Cron::SCHEDULE_POLL ]['interval'] );
	}

	public function test_schedule_replaces_the_legacy_frequency_once(): void {
		$scheduled = array();

		$this->mock_settings(
			function ( $name, $default ) {
				if ( Cron::OPTION_SCHEDULE === $name ) {
					return '1';
				}

				return $default;
			}
		);
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Cron::HOOK_POLL_PENDING )->andReturn( true );
		Functions\expect( 'update_option' )->once()->with( Cron::OPTION_SCHEDULE, Cron::SCHEDULE_VERSION, false )->andReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->andReturnUsing(
				function ( $timestamp, $schedule, $hook ) use ( &$scheduled ) {
					$scheduled = compact( 'timestamp', 'schedule', 'hook' );
					return true;
				}
			);

		Cron::schedule();

		$this->assertSame( Cron::SCHEDULE_POLL, $scheduled['schedule'] );
		$this->assertSame( Cron::HOOK_POLL_PENDING, $scheduled['hook'] );
	}

	public function test_poll_worker_skips_when_another_worker_holds_the_lock(): void {
		$this->mock_settings( function ( $name, $default ) { return $default; } );
		$GLOBALS['wpdb'] = $this->fake_wpdb( false );

		$this->assertSame(
			array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 ),
			Cron::poll_pending()
		);
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'GET_LOCK', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_poll_worker_releases_its_connection_scoped_lock(): void {
		$this->mock_settings(
			function ( $name, $default ) {
				if ( 'filetoweb_integration_pdf_to_page_jobs' === $name ) {
					return array();
				}

				return $default;
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		$GLOBALS['wpdb'] = $this->fake_wpdb( true );

		$this->assertSame(
			array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 ),
			Cron::poll_pending()
		);
		$this->assertCount( 2, $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'GET_LOCK', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $GLOBALS['wpdb']->queries[1] );
	}

	private function fake_wpdb( $lock_available ) {
		return new class( $lock_available ) {
			public $queries = array();
			private $lock_available;

			public function __construct( $lock_available ) {
				$this->lock_available = $lock_available;
			}

			public function prepare( $query, $value ) {
				return str_replace( '%s', "'" . addslashes( $value ) . "'", $query );
			}

			public function get_var( $query ) {
				$this->queries[] = $query;

				if ( false !== strpos( $query, 'GET_LOCK' ) ) {
					return $this->lock_available ? '1' : '0';
				}

				return '1';
			}
		};
	}

	private function mock_settings( $fallback ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $fallback ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => '1',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_test',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $fallback( $name, $default );
			}
		);
	}
}
