<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use FileToWeb\Integration\Sync;
use PHPUnit\Framework\TestCase;

class SyncTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-08 12:00:00' );
		Functions\when( 'home_url' )->justReturn( 'https://city.example' );
		Functions\when( 'FileToWeb\Integration\gethostbynamel' )->justReturn( array( '93.184.216.34' ) );
		Functions\when( 'FileToWeb\Integration\dns_get_record' )->justReturn( array() );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => '1',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_test',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $default;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cron_retry_does_not_discover_never_synced_attachments(): void {
		$captured_args = array();

		Functions\expect( 'get_posts' )
			->once()
			->andReturnUsing(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return array();
				}
			);

		$counts = Sync::retry_pending_syncs( 25 );

		$this->assertSame( array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 ), $counts );
		$this->assertArrayHasKey( 'meta_query', $captured_args );
		$this->assertFalse( $this->meta_query_contains_compare( $captured_args['meta_query'], 'NOT EXISTS' ) );
		$this->assertTrue( $this->meta_query_contains_value( $captured_args['meta_query'], 'pending' ) );
	}

	public function test_poll_pending_includes_marked_pdf_to_page_drafts(): void {
		$calls = array();

		Functions\expect( 'get_posts' )
			->times( 3 )
			->andReturnUsing(
				function ( $args ) use ( &$calls ) {
					$calls[] = $args;
					return array();
				}
			);

		$counts = Sync::poll_pending( 25 );

		$this->assertSame( array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 ), $counts );
		$this->assertSame( 'attachment', $calls[0]['post_type'] );
		$this->assertSame( array( 'attachment', 'document' ), $calls[1]['post_type'] );
		$this->assertSame( 'page', $calls[2]['post_type'] );
		$this->assertTrue( $this->meta_query_contains_key( $calls[2]['meta_query'], Document_State::META_PDF_TO_PAGE ) );
		$this->assertTrue( $this->meta_query_contains_value( $calls[2]['meta_query'], '1' ) );
	}

	public function test_new_pdf_attachment_is_marked_and_scheduled_for_intentional_retry(): void {
		$stored          = array();
		$scheduled_args  = array();
		$attachment_id   = 123;
		$attachment_file = __DIR__ . '/fixtures/sample.pdf';

		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://cdn.example.org/uploads/sample.pdf' );
		Functions\when( 'get_attached_file' )->justReturn( $attachment_file );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'site_url' )->justReturn( 'https://city.example/wp-cron.php' );
		Functions\when( 'wp_remote_post' )->justReturn( true );
		Functions\expect( 'update_post_meta' )
			->atLeast()
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$stored ) {
					$stored[ $key ] = $value;
					return true;
				}
			);
		Functions\expect( 'wp_schedule_single_event' )
			->once()
			->andReturnUsing(
				function ( $timestamp, $hook, $args ) use ( &$scheduled_args ) {
					$scheduled_args = $args;
					return true;
				}
			);

		Sync::schedule_attachment_sync( $attachment_id );

		$this->assertSame( 'pending', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'attachment_save', $stored[ Document_State::META_LAST_TRIGGER ] );
		$this->assertSame( array( $attachment_id, 'attachment', 'attachment_save' ), $scheduled_args );
	}

	public function test_retryable_poll_failure_stays_pending_with_support_reference(): void {
		$stored  = array();
		$post_id = 321;

		Functions\when( 'get_post_meta' )->alias(
			function ( $requested_post_id, $key ) use ( $post_id ) {
				if ( $post_id === $requested_post_id && Document_State::META_DOCUMENT_ID === $key ) {
					return 'doc-321';
				}

				return '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $requested_post_id, $key, $value ) use ( &$stored, $post_id ) {
				$this->assertSame( $post_id, $requested_post_id );
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'code' => 503,
				'body' => json_encode(
					array(
						'error' => array(
							'code'      => 'service_unavailable',
							'message'   => 'FileToWeb could not complete this request. Please try again later.',
							'reference' => 'FTW-123456789ABC',
							'retryable' => true,
						),
					)
				),
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'];
			}
		);

		$this->assertSame( 'updated', Sync::poll_post( $post_id ) );
		$this->assertSame( 'pending', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'service_unavailable', $stored[ Document_State::META_ERROR_CODE ] );
		$this->assertSame( 'FTW-123456789ABC', $stored[ Document_State::META_ERROR_REFERENCE ] );
		$this->assertSame( '1', $stored[ Document_State::META_ERROR_RETRYABLE ] );
	}

	private function meta_query_contains_compare( $query, $compare ) {
		foreach ( (array) $query as $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['compare'] ) && $compare === $item['compare'] ) {
					return true;
				}

				if ( $this->meta_query_contains_compare( $item, $compare ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function meta_query_contains_value( $query, $value ) {
		foreach ( (array) $query as $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['value'] ) && $value === $item['value'] ) {
					return true;
				}

				if ( $this->meta_query_contains_value( $item, $value ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function meta_query_contains_key( $query, $key ) {
		foreach ( (array) $query as $item ) {
			if ( is_array( $item ) ) {
				if ( isset( $item['key'] ) && $key === $item['key'] ) {
					return true;
				}

				if ( $this->meta_query_contains_key( $item, $key ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
