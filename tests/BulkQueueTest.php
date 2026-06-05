<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Bulk_Queue;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class BulkQueueTest extends TestCase {
	private $options = array();
	private $meta    = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$this->meta    = array(
			55 => array(
				'agenda_attachment'  => 101,
				'minutes_attachment' => 102,
			),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
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
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => '1',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_test',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_BATCH_SIZE    => 1,
					);
				}

				return isset( $this->options[ $name ] ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
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
}
