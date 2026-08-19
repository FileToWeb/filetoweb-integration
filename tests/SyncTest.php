<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use FileToWeb\Integration\Sync;
use PHPUnit\Framework\TestCase;

class SyncTest extends TestCase {
	private $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

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

				if ( array_key_exists( $name, $this->options ) ) {
					return $this->options[ $name ];
				}

				return $default;
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cron_retry_does_not_discover_never_synced_attachments(): void {
		$captured_args = array();

		Functions\expect( 'get_posts' )
			->twice()
			->andReturnUsing(
				function ( $args ) use ( &$captured_args ) {
					$captured_args[] = $args;
					return array();
				}
			);

		$counts = Sync::retry_pending_syncs( 25 );

		$this->assertSame( array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 ), $counts );
		$this->assertArrayHasKey( 'meta_query', $captured_args[0] );
		$this->assertTrue( $this->meta_query_contains_compare( $captured_args[0]['meta_query'], 'NOT EXISTS' ) );
		$this->assertTrue( $this->meta_query_contains_value( $captured_args[0]['meta_query'], 'pending' ) );
		$this->assertTrue( $this->meta_query_contains_key( $captured_args[0]['meta_query'], Document_State::META_DOCUMENT_ID ) );
		$this->assertTrue( $this->meta_query_contains_key( $captured_args[0]['meta_query'], Document_State::META_STATUS ) );
		$this->assertSame( Document_State::META_NEXT_POLL_AT, $captured_args[1]['meta_key'] );
	}

	public function test_poll_pending_includes_marked_pdf_to_page_drafts(): void {
		$calls = array();

		Functions\expect( 'get_posts' )
			->times( 4 )
			->andReturnUsing(
				function ( $args ) use ( &$calls ) {
					$calls[] = $args;
					return array();
				}
			);

		$counts = Sync::poll_pending( 25 );

		$this->assertSame( array( 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'updated' => 0 ), $counts );
		$this->assertSame( 'attachment', $calls[0]['post_type'] );
		$this->assertSame( 'ID', $calls[0]['orderby'] );
		$this->assertTrue( $this->meta_query_contains_compare( $calls[0]['meta_query'], 'NOT EXISTS' ) );
		$this->assertSame( 'attachment', $calls[1]['post_type'] );
		$this->assertSame( Document_State::META_NEXT_POLL_AT, $calls[1]['meta_key'] );
		$this->assertSame( 'meta_value_num', $calls[1]['orderby'] );
		$this->assertSame( 'ASC', $calls[1]['order'] );
		$this->assertTrue( $this->meta_query_contains_compare( $calls[1]['meta_query'], '<=' ) );
		$this->assertSame( array( 'attachment', 'document', 'page' ), $calls[2]['post_type'] );
		$this->assertSame( 'ID', $calls[2]['orderby'] );
		$this->assertSame( 'ASC', $calls[2]['order'] );
		$this->assertTrue( $this->meta_query_contains_compare( $calls[2]['meta_query'], 'NOT EXISTS' ) );
		$this->assertSame( array( 'attachment', 'document', 'page' ), $calls[3]['post_type'] );
		$this->assertSame( Document_State::META_NEXT_POLL_AT, $calls[3]['meta_key'] );
		$this->assertSame( 'meta_value_num', $calls[3]['orderby'] );
		$this->assertTrue( $this->meta_query_contains_compare( $calls[3]['meta_query'], '<=' ) );
	}

	public function test_retry_work_is_counted_against_the_shared_poll_batch(): void {
		$calls = array();

		Functions\expect( 'get_posts' )
			->times( 4 )
			->andReturnUsing(
				function ( $args ) use ( &$calls ) {
					$calls[] = $args;

					return 1 === count( $calls ) ? array( 101 ) : array();
				}
			);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		Functions\when( 'get_attached_file' )->justReturn( false );

		$counts = Sync::poll_pending( 5 );

		$this->assertSame( array( 'queued' => 0, 'skipped' => 1, 'failed' => 0, 'updated' => 0 ), $counts );
		$this->assertSame( 3, $calls[0]['posts_per_page'] );
		$this->assertSame( 3, $calls[1]['posts_per_page'] );
		$this->assertSame( 4, $calls[2]['posts_per_page'] );
		$this->assertSame( 4, $calls[3]['posts_per_page'] );
	}

	public function test_one_item_recovery_batches_alternate_with_scheduled_work(): void {
		$method = new \ReflectionMethod( Sync::class, 'merge_recovery_and_due' );
		$first  = $method->invoke( null, array( 101 ), array( 202 ), 1, 'test_recovery_cursor' );
		$second = $method->invoke( null, array( 101 ), array( 202 ), 1, 'test_recovery_cursor' );

		$this->assertSame( array( 101 ), $first );
		$this->assertSame( array( 202 ), $second );
		$this->assertSame( 0, $this->options['test_recovery_cursor'] );
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
		$this->assertSame( 1, $stored[ Document_State::META_POLL_ATTEMPTS ] );
		$this->assertGreaterThanOrEqual( time() + 119, $stored[ Document_State::META_NEXT_POLL_AT ] );
		$this->assertLessThanOrEqual( time() + 141, $stored[ Document_State::META_NEXT_POLL_AT ] );
	}

	public function test_processing_poll_moves_item_behind_other_due_work(): void {
		$stored  = array();
		$post_id = 654;
		$before  = time();

		Functions\when( 'get_post_meta' )->alias(
			function ( $requested_post_id, $key ) use ( $post_id ) {
				if ( $post_id !== $requested_post_id ) {
					return '';
				}

				if ( Document_State::META_DOCUMENT_ID === $key ) {
					return 'doc-654';
				}

				if ( Document_State::META_POLL_ATTEMPTS === $key ) {
					return 1;
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
		$this->mock_successful_document_response( 'processing' );

		$this->assertSame( 'updated', Sync::poll_post( $post_id ) );
		$this->assertSame( 2, $stored[ Document_State::META_POLL_ATTEMPTS ] );
		$this->assertSame( 'processing', $stored[ Document_State::META_STATUS ] );
		$this->assertGreaterThanOrEqual( $before + 300, $stored[ Document_State::META_NEXT_POLL_AT ] );
		$this->assertLessThanOrEqual( time() + 320, $stored[ Document_State::META_NEXT_POLL_AT ] );
	}

	public function test_ready_poll_removes_item_from_active_queue(): void {
		$stored       = array();
		$deleted_keys = array();
		$post_id      = 987;

		Functions\when( 'get_post_meta' )->alias(
			function ( $requested_post_id, $key ) use ( $post_id ) {
				if ( $post_id === $requested_post_id && Document_State::META_DOCUMENT_ID === $key ) {
					return 'doc-987';
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
		Functions\when( 'delete_post_meta' )->alias(
			function ( $requested_post_id, $key ) use ( &$deleted_keys, $post_id ) {
				$this->assertSame( $post_id, $requested_post_id );
				$deleted_keys[] = $key;
				return true;
			}
		);
		$this->mock_successful_document_response( 'ready' );

		$this->assertSame( 'updated', Sync::poll_post( $post_id ) );
		$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
		$this->assertContains( Document_State::META_NEXT_POLL_AT, $deleted_keys );
	}

	private function mock_successful_document_response( $status ) {
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->justReturn(
			array(
				'code' => 200,
				'body' => json_encode(
					array(
						'document' => array(
							'id'             => 'doc-test',
							'status'         => $status,
							'html_url'       => 'https://filetoweb.com/d/doc-test/1',
							'continuous_url' => 'https://filetoweb.com/d/doc-test/continuous',
							'editor_url'     => 'https://filetoweb.com/home/documents/doc-test',
							'page_count'     => 3,
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
