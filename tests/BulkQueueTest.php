<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Bulk_Queue;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class BulkQueueTest extends TestCase {
	private $options    = array();
	private $meta       = array();
	private $batch_size = 1;
	private $filters    = array();
	private $calls      = array();
	private $scheduled  = array();
	private $cleared    = array();
	private $saves      = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options    = array();
		$this->batch_size = 1;
		$this->filters    = array();
		$this->calls      = array();
		$this->scheduled  = array();
		$this->cleared    = array();
		$this->saves      = array();
		$this->meta       = array(
			55 => array(
				'agenda_attachment'  => 101,
				'minutes_attachment' => 102,
			),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://city.example' );
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-05 12:00:00' );
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				return isset( $this->scheduled[ $hook ] ) ? $this->scheduled[ $hook ] : false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->calls[]              = 'schedule:' . $hook;
				$this->scheduled[ $hook ] = $timestamp;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->calls[]   = 'clear:' . $hook;
				$this->cleared[] = $hook;
				unset( $this->scheduled[ $hook ] );
				return 1;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => '1',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_test',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_BATCH_SIZE    => $this->batch_size,
					);
				}

				return isset( $this->options[ $name ] ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( Bulk_Queue::OPTION_QUEUE === $name ) {
					$this->calls[]   = 'save';
					$this->saves[]   = $value;
				}

				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return array_key_exists( $tag, $this->filters ) ? $this->filters[ $tag ] : $value;
			}
		);
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				return isset( $this->meta[ $post_id ][ $key ] ) ? $this->meta[ $post_id ][ $key ] : '';
			}
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			function ( $attachment_id ) {
				return 'https://example.test/wp-content/uploads/material-' . (int) $attachment_id . '.pdf';
			}
		);
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'get_attached_file' )->justReturn( __FILE__ );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_queue_meeting_pdfs_deduplicates_materials(): void {
		Functions\when( 'get_posts' )->justReturn( array( 55 ) );

		$state = Bulk_Queue::queue_meeting_pdfs();

		$this->assertSame( 'meeting_pdfs', $state['type'] );
		$this->assertSame( 2, $state['total'] );
		$this->assertSame( 2, count( $state['items'] ) );
	}

	public function test_process_next_batch_schedules_the_next_run_before_syncing_items(): void {
		$this->seed_queue( 4 );
		$this->batch_size = 2;
		$this->skip_every_item();

		Bulk_Queue::process_next_batch();

		$this->assertSame( 'schedule:' . Bulk_Queue::HOOK_PROCESS, $this->calls[0] );
		$this->assertLessThan(
			array_search( 'sync:901', $this->calls, true ),
			array_search( 'schedule:' . Bulk_Queue::HOOK_PROCESS, $this->calls, true )
		);
	}

	public function test_process_next_batch_keeps_a_run_scheduled_when_a_later_item_is_interrupted(): void {
		$this->seed_queue( 4 );
		$this->batch_size = 2;
		$this->skip_every_item();

		$interrupted = false;

		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) use ( &$interrupted ) {
				$this->calls[] = 'sync:' . (int) $post_id;

				if ( 902 === (int) $post_id ) {
					$interrupted = true;
					throw new RuntimeException( 'worker terminated' );
				}

				return 'post';
			}
		);

		try {
			Bulk_Queue::process_next_batch();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$this->assertTrue( $interrupted );
		$this->assertNotFalse( wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ) );

		$state = get_option( Bulk_Queue::OPTION_QUEUE );

		$this->assertSame( 1, $state['processed'] );
		$this->assertSame( 3, count( $state['items'] ) );
	}

	public function test_process_next_batch_persists_progress_after_each_item(): void {
		$this->seed_queue( 3 );
		$this->batch_size = 3;
		$this->skip_every_item();

		Bulk_Queue::process_next_batch();

		$processed = array();

		foreach ( $this->saves as $save ) {
			$processed[] = $save['processed'];
		}

		$this->assertSame( array( 1, 2, 3 ), $processed );
		$this->assertSame( 0, count( $this->saves[2]['items'] ) );
	}

	public function test_process_next_batch_stops_at_the_batch_time_budget(): void {
		$this->seed_queue( 5 );
		$this->batch_size                                             = 5;
		$this->filters['filetoweb_integration_bulk_batch_timeout'] = 0;
		$this->skip_every_item();

		$counts = Bulk_Queue::process_next_batch();
		$state  = get_option( Bulk_Queue::OPTION_QUEUE );

		$this->assertSame( 1, $counts['skipped'] );
		$this->assertSame( 1, $state['processed'] );
		$this->assertSame( 4, count( $state['items'] ) );
	}

	public function test_process_next_batch_clears_the_scheduled_run_when_the_queue_drains(): void {
		$this->seed_queue( 1 );
		$this->batch_size = 5;
		$this->skip_every_item();

		Bulk_Queue::process_next_batch();

		$this->assertContains( Bulk_Queue::HOOK_PROCESS, $this->cleared );
		$this->assertFalse( wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ) );
	}

	public function test_maybe_recover_queue_reschedules_an_abandoned_run(): void {
		$this->seed_queue( 3, gmdate( 'Y-m-d H:i:s', time() - 900 ) );

		$this->assertTrue( Bulk_Queue::maybe_recover_queue() );
		$this->assertNotFalse( wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ) );
	}

	public function test_maybe_recover_queue_leaves_a_recent_run_alone(): void {
		$this->seed_queue( 3, gmdate( 'Y-m-d H:i:s', time() - 30 ) );

		$this->assertFalse( Bulk_Queue::maybe_recover_queue() );
		$this->assertFalse( wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ) );
	}

	public function test_maybe_recover_queue_leaves_a_scheduled_run_alone(): void {
		$this->seed_queue( 3, gmdate( 'Y-m-d H:i:s', time() - 900 ) );
		$this->scheduled[ Bulk_Queue::HOOK_PROCESS ] = time() + 60;

		$this->assertFalse( Bulk_Queue::maybe_recover_queue() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_maybe_recover_queue_ignores_a_drained_queue(): void {
		$this->seed_queue( 0, gmdate( 'Y-m-d H:i:s', time() - 900 ) );

		$this->assertFalse( Bulk_Queue::maybe_recover_queue() );
		$this->assertFalse( wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ) );
	}

	/**
	 * Store a queue whose items all sync as skips.
	 *
	 * @param int    $count Item count.
	 * @param string $updated_at Queue timestamp.
	 */
	private function seed_queue( $count, $updated_at = '2026-06-05 12:00:00' ) {
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = array(
				'id'   => 901 + $i,
				'kind' => 'attachment',
			);
		}

		$this->options[ Bulk_Queue::OPTION_QUEUE ] = array(
			'type'       => 'meeting_pdfs',
			'items'      => $items,
			'total'      => $count,
			'processed'  => 0,
			'queued'     => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'updated_at' => $updated_at,
		);
	}

	/**
	 * Make every queued item resolve as an unsyncable post, so no network work runs.
	 */
	private function skip_every_item() {
		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) {
				$this->calls[] = 'sync:' . (int) $post_id;

				return 'post';
			}
		);
	}
}
