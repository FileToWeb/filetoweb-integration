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
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'get_post_status' )->justReturn( 'inherit' );
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
		Functions\when( 'get_post_meta' )->justReturn( '' );
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

	public function test_trashed_pdf_attachment_is_not_scheduled(): void {
		Functions\when( 'get_post_status' )->justReturn( 'trash' );
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		Sync::schedule_attachment_sync( 123 );

		$this->addToAssertionCount( 1 );
	}

	public function test_scheduled_sync_rechecks_that_attachment_still_exists(): void {
		Functions\when( 'get_post_type' )->justReturn( false );
		Functions\when( 'get_post_status' )->justReturn( false );
		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'delete_post_meta' )
			->once()
			->with( 123, Document_State::META_NEXT_POLL_AT )
			->andReturn( true );

		Sync::sync_item( 123, 'attachment', 'attachment_save' );

		$this->addToAssertionCount( 1 );
	}

	public function test_removed_post_is_cleared_from_sync_and_poll_queues(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( Sync::HOOK_SYNC_ITEM, array( 123, 'attachment', 'attachment_save' ) )
			->andReturn( 1 );
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( Sync::HOOK_SYNC_ITEM, array( 123, 'document', 'document_save' ) )
			->andReturn( 1 );
		Functions\expect( 'delete_post_meta' )
			->once()
			->with( 123, Document_State::META_NEXT_POLL_AT )
			->andReturn( true );

		Sync::stop_sync_for_removed_post( 123 );

		$this->addToAssertionCount( 1 );
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

	public function test_ready_manual_poll_forwards_forced_preview_refresh_and_removes_item_from_active_queue(): void {
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
		Functions\expect( 'do_action' )
			->once()
			->with(
				'filetoweb_integration_after_poll_post',
				$post_id,
				\Mockery::on(
					function ( $document ) {
						return is_array( $document ) && 'ready' === $document['status'];
					}
				),
				true
			);

		$this->assertSame( 'updated', Sync::poll_post( $post_id, true ) );
		$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
		$this->assertContains( Document_State::META_NEXT_POLL_AT, $deleted_keys );
	}

	public function test_timed_out_import_recovers_by_external_id_without_a_second_post(): void {
		$stored        = array();
		$requests      = array();
		$attachment_id = 25963;
		$fingerprint   = hash_file( 'sha256', __FILE__ );

		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://city.example/uploads/july-financials.pdf' );
		Functions\when( 'get_attached_file' )->justReturn( __FILE__ );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored, $attachment_id ) {
				return $attachment_id === $post_id && array_key_exists( $key, $stored ) ? $stored[ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored, $attachment_id ) {
				$this->assertSame( $attachment_id, $post_id );
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $response ) {
				return is_object( $response ) && method_exists( $response, 'get_error_message' );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$requests, $fingerprint ) {
				$requests[] = array( $url, $args['method'] );

				if ( 1 === count( $requests ) ) {
					return new class() {
						public function get_error_message() {
							return 'cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received';
						}

						public function get_error_code() {
							return 'http_request_failed';
						}
					};
				}

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'          => 'doc-july',
								'external_id' => 'wordpress:fe457a395a16:attachment:25963',
								'status'      => 'ready',
								'html_url'    => 'https://filetoweb.com/d/AbCdEf1234567890GhIjKlMn/1',
								'page_count'  => 56,
								'source'      => array(
									'fingerprint'           => $fingerprint,
									'fingerprint_algorithm' => 'sha256',
								),
							),
						)
					),
				);
			}
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
		Functions\when( 'do_action' )->justReturn( null );

		$timed_out = Sync::sync_attachment_now( $attachment_id, 'attachment_save' );
		$recovered = Sync::sync_attachment_now( $attachment_id, 'cron_retry' );

		$this->assertSame( 'pending', $timed_out['status'] );
		$this->assertSame( 'ready', $recovered['status'] );
		$this->assertSame( 'wordpress:fe457a395a16:attachment:25963', $stored[ Document_State::META_EXTERNAL_ID ] );
		$this->assertSame( 'doc-july', $stored[ Document_State::META_DOCUMENT_ID ] );
		$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( '', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( array( 'https://filetoweb.com/v1/documents', 'POST' ), $requests[0] );
		$this->assertSame(
			array( 'https://filetoweb.com/v1/documents/by-external-id/wordpress%3Afe457a395a16%3Aattachment%3A25963', 'GET' ),
			$requests[1]
		);
		$this->assertCount( 2, $requests );
	}

	public function test_timed_out_import_does_not_recover_a_stale_external_id_conversion(): void {
		$stored        = array();
		$requests      = array();
		$attachment_id = 25963;
		$fingerprint   = hash_file( 'sha256', __FILE__ );

		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://city.example/uploads/july-financials.pdf' );
		Functions\when( 'get_attached_file' )->justReturn( __FILE__ );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored, $attachment_id ) {
				return $attachment_id === $post_id && array_key_exists( $key, $stored ) ? $stored[ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored, $attachment_id ) {
				$this->assertSame( $attachment_id, $post_id );
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $response ) {
				return is_object( $response ) && method_exists( $response, 'get_error_message' );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$requests, $fingerprint ) {
				$requests[] = array( $url, $args['method'] );

				if ( 1 === count( $requests ) ) {
					return new class() {
						public function get_error_message() {
							return 'cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received';
						}

						public function get_error_code() {
							return 'http_request_failed';
						}
					};
				}

				if ( 2 === count( $requests ) ) {
					return array(
						'code' => 200,
						'body' => json_encode(
							array(
								'document' => array(
									'id'          => 'doc-old-pdf',
									'external_id' => 'wordpress:fe457a395a16:attachment:25963',
									'status'      => 'ready',
									'html_url'    => 'https://filetoweb.com/d/OldConversion/1',
									'page_count'  => 12,
									'source'      => array(
										'fingerprint'           => str_repeat( 'a', 64 ),
										'fingerprint_algorithm' => 'sha256',
									),
								),
							)
						),
					);
				}

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'          => 'doc-current-pdf',
								'external_id' => 'wordpress:fe457a395a16:attachment:25963',
								'status'      => 'processing',
								'page_count'  => 56,
								'source'      => array(
									'fingerprint'           => $fingerprint,
									'fingerprint_algorithm' => 'sha256',
								),
							),
						)
					),
				);
			}
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
		Functions\when( 'do_action' )->justReturn( null );

		$timed_out = Sync::sync_attachment_now( $attachment_id, 'attachment_save' );
		$recovered = Sync::sync_attachment_now( $attachment_id, 'cron_retry' );

		$this->assertSame( 'pending', $timed_out['status'] );
		$this->assertSame( 'processing', $recovered['status'] );
		$this->assertSame( 'doc-current-pdf', $stored[ Document_State::META_DOCUMENT_ID ] );
		$this->assertSame( 'processing', $stored[ Document_State::META_STATUS ] );
		$this->assertSame(
			array(
				array( 'https://filetoweb.com/v1/documents', 'POST' ),
				array( 'https://filetoweb.com/v1/documents/by-external-id/wordpress%3Afe457a395a16%3Aattachment%3A25963', 'GET' ),
				array( 'https://filetoweb.com/v1/documents', 'POST' ),
			),
			$requests
		);
	}

	public function test_external_id_recovery_rejects_same_pdf_from_another_identity(): void {
		$attachment_id = 25963;
		$fingerprint   = hash_file( 'sha256', __FILE__ );
		$external_id   = 'wordpress:fe457a395a16:attachment:25963';
		$stored        = array(
			Document_State::META_EXTERNAL_ID        => $external_id,
			Document_State::META_SOURCE_FINGERPRINT => $fingerprint,
			Document_State::META_ERROR_RETRYABLE    => '1',
		);
		$requests = array();

		$this->mock_attachment_sync_environment(
			$stored,
			function ( $url, $args ) use ( &$requests, $fingerprint, $external_id ) {
				$requests[] = array( $url, $args['method'] );
				if ( 'GET' === $args['method'] ) {
					return array(
						'code' => 200,
						'body' => json_encode(
							array(
								'document' => array(
									'id'          => 'doc-foreign',
									'external_id' => 'wordpress:another-site:attachment:25963',
									'status'      => 'ready',
									'source'      => array(
										'fingerprint'           => $fingerprint,
										'fingerprint_algorithm' => 'sha256',
									),
								),
							)
						),
					);
				}

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'          => 'doc-local',
								'external_id' => $external_id,
								'status'      => 'processing',
								'source'      => array(
									'fingerprint'           => $fingerprint,
									'fingerprint_algorithm' => 'sha256',
								),
							),
						)
					),
				);
			}
		);

		$result = Sync::sync_attachment_now( $attachment_id, 'cron_retry' );

		$this->assertSame( 'processing', $result['status'] );
		$this->assertSame( 'doc-local', $stored[ Document_State::META_DOCUMENT_ID ] );
		$this->assertSame( $external_id, $stored[ Document_State::META_EXTERNAL_ID ] );
		$this->assertSame( array( 'GET', 'POST' ), array_column( $requests, 1 ) );
	}

	public function test_unavailable_external_id_recovery_falls_through_to_idempotent_upsert(): void {
		$attachment_id = 25963;
		$fingerprint   = hash_file( 'sha256', __FILE__ );
		$external_id   = 'wordpress:fe457a395a16:attachment:25963';
		$stored        = array(
			Document_State::META_EXTERNAL_ID        => $external_id,
			Document_State::META_SOURCE_FINGERPRINT => $fingerprint,
			Document_State::META_ERROR_RETRYABLE    => '1',
		);
		$requests = array();

		$this->mock_attachment_sync_environment(
			$stored,
			function ( $url, $args ) use ( &$requests, $fingerprint, $external_id ) {
				$requests[] = array( $url, $args['method'] );
				if ( 'GET' === $args['method'] ) {
					return array(
						'code' => 503,
						'body' => '{"error":{"code":"service_unavailable","message":"temporary","retryable":true}}',
					);
				}

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'          => 'doc-after-lookup-failure',
								'external_id' => $external_id,
								'status'      => 'processing',
								'source'      => array(
									'fingerprint'           => $fingerprint,
									'fingerprint_algorithm' => 'sha256',
								),
							),
						)
					),
				);
			}
		);

		$result = Sync::sync_attachment_now( $attachment_id, 'cron_retry' );

		$this->assertSame( 'processing', $result['status'] );
		$this->assertSame( 'doc-after-lookup-failure', $stored[ Document_State::META_DOCUMENT_ID ] );
		$this->assertSame( array( 'GET', 'POST' ), array_column( $requests, 1 ) );
	}

	public function test_unchanged_ready_preview_stays_ready_during_transient_sync_failure(): void {
		$attachment_id = 25963;
		$fingerprint   = hash_file( 'sha256', __FILE__ );
		$stored        = array(
			Document_State::META_DOCUMENT_ID       => 'doc-ready',
			Document_State::META_STATUS            => 'ready',
			Document_State::META_SOURCE_FINGERPRINT => $fingerprint,
		);
		$error = new class() {
			public function get_error_message() {
				return 'Connexion temporairement indisponible';
			}

			public function get_error_code() {
				return 'http_request_failed';
			}
		};

		$this->mock_attachment_sync_environment(
			$stored,
			function () use ( &$stored, $error ) {
				$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
				return $error;
			}
		);

		$result = Sync::sync_attachment_now( $attachment_id, 'attachment_save' );

		$this->assertSame( 'ready', $result['status'] );
		$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'Connexion temporairement indisponible', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( '1', $stored[ Document_State::META_ERROR_RETRYABLE ] );
	}

	public function test_processing_document_becomes_ready_and_clears_old_timeout(): void {
		$stored = array(
			Document_State::META_DOCUMENT_ID => 'doc-processing',
			Document_State::META_STATUS      => 'processing',
			Document_State::META_LAST_ERROR  => 'cURL error 28: Operation timed out',
		);
		$responses = array( 'processing', 'ready' );

		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				return array_key_exists( $key, $stored ) ? $stored[ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			function () use ( &$responses ) {
				$status = array_shift( $responses );
				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'         => 'doc-processing',
								'status'     => $status,
								'html_url'   => 'ready' === $status ? 'https://filetoweb.com/d/AbCdEf1234567890GhIjKlMn/1' : '',
								'page_count' => 56,
							),
						)
					),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) { return $response['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) { return $response['body']; } );
		Functions\when( 'do_action' )->justReturn( null );

		$this->assertSame( 'updated', Sync::poll_post( 25963 ) );
		$this->assertSame( 'processing', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'updated', Sync::poll_post( 25963 ) );
		$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( '', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_REFERENCE ] );
		$this->assertArrayNotHasKey( Document_State::META_NEXT_POLL_AT, $stored );
	}

	public function test_terminal_failure_can_be_retried_and_reconciled_to_ready(): void {
		$stored = array(
			Document_State::META_DOCUMENT_ID     => 'doc-provider-failure',
			Document_State::META_STATUS          => 'failed',
			Document_State::META_LAST_ERROR      => 'FileToWeb could not finish processing this PDF. Please try again.',
			Document_State::META_ERROR_REFERENCE => 'FTW-C588003D2859',
		);
		$responses = array( 'processing', 'ready' );

		Functions\when( 'untrailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				return array_key_exists( $key, $stored ) ? $stored[ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			function () use ( &$responses ) {
				$status = array_shift( $responses );
				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'         => 'doc-provider-failure',
								'status'     => $status,
								'html_url'   => 'ready' === $status ? 'https://filetoweb.com/d/AbCdEf1234567890GhIjKlMn/1' : '',
								'page_count' => 56,
							),
						)
					),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) { return $response['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) { return $response['body']; } );
		Functions\when( 'do_action' )->justReturn( null );

		$this->assertSame( 'processing', Sync::retry_processing( 25907 )['status'] );
		$this->assertSame( 'processing', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'updated', Sync::poll_post( 25907 ) );
		$this->assertSame( 'ready', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( '', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_REFERENCE ] );
	}

	public function test_retryable_mutation_conflict_never_reprocesses_from_cron_polling(): void {
		$stored = array(
			Document_State::META_DOCUMENT_ID => 'doc-locked',
			Document_State::META_STATUS      => 'failed',
		);

		Functions\when( 'untrailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				return array_key_exists( $key, $stored ) ? $stored[ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		$requests = array();
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$requests ) {
				$requests[] = $args['method'];

				if ( 1 === count( $requests ) ) {
					return array(
						'code' => 409,
						'body' => '{"error":{"code":"document_mutation_conflict","message":"locked","retryable":false}}',
					);
				}

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'     => 'doc-locked',
								'status' => 'failed',
							),
						)
					),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) { return $response['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) { return $response['body']; } );
		Functions\when( 'do_action' )->justReturn( null );

		$result = Sync::retry_processing( 25907 );

		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'pending', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'document_mutation_conflict', $stored[ Document_State::META_ERROR_CODE ] );
		$this->assertSame( '1', $stored[ Document_State::META_ERROR_RETRYABLE ] );
		$this->assertArrayHasKey( Document_State::META_NEXT_POLL_AT, $stored );

		for ( $index = 0; $index < 10; ++$index ) {
			$this->assertSame( 'updated', Sync::poll_post( 25907 ) );
		}

		$this->assertSame( 'failed', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 1, count( array_filter( $requests, function ( $method ) { return 'POST' === $method; } ) ) );
		$this->assertSame( 10, count( array_filter( $requests, function ( $method ) { return 'GET' === $method; } ) ) );
		$this->assertSame( 10, $stored[ Document_State::META_POLL_ATTEMPTS ] );
	}

	public function test_item_lock_prevents_overlapping_manual_and_cron_submission(): void {
		$previous_wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$stored        = array();

		$GLOBALS['wpdb'] = new class() {
			public function prepare( $query, $value ) {
				return str_replace( '%s', "'" . addslashes( $value ) . "'", $query );
			}

			public function get_var( $query ) {
				return false !== strpos( $query, 'GET_LOCK' ) ? '0' : '1';
			}
		};

		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://city.example/uploads/locked.pdf' );
		Functions\when( 'get_attached_file' )->justReturn( __FILE__ );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\expect( 'wp_remote_request' )->never();

		$result = Sync::sync_attachment_now( 25249, 'cron_retry' );

		$GLOBALS['wpdb'] = $previous_wpdb;

		$this->assertSame( 'pending', $result['status'] );
		$this->assertTrue( $result['busy'] );
		$this->assertArrayHasKey( Document_State::META_NEXT_POLL_AT, $stored );
	}

	public function test_polling_a_proud_document_uses_its_current_attachment_document_id(): void {
		$meta = array(
			456 => array(
				'document_meta'                  => '{"fid":457,"mime":"application/pdf"}',
				Document_State::META_DOCUMENT_ID => 'doc-june-stale',
				Document_State::META_STATUS      => 'processing',
			),
			457 => array(
				Document_State::META_DOCUMENT_ID => 'doc-july-current',
				Document_State::META_STATUS      => 'processing',
			),
		);
		$requested_url = '';

		Functions\when( 'untrailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) {
				return 456 === (int) $post_id ? 'document' : ( 457 === (int) $post_id ? 'attachment' : '' );
			}
		);
		Functions\when( 'get_post_status' )->alias(
			function ( $post_id ) {
				return 456 === (int) $post_id ? 'publish' : 'inherit';
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$meta ) {
				return isset( $meta[ $post_id ] ) && array_key_exists( $key, $meta[ $post_id ] ) ? $meta[ $post_id ][ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$meta ) {
				$meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$meta ) {
				unset( $meta[ $post_id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url ) use ( &$requested_url ) {
				$requested_url = $url;
				return array(
					'code' => 200,
					'body' => '{"document":{"id":"doc-july-current","status":"ready","html_url":"https://filetoweb.com/d/AbCdEf1234567890GhIjKlMn/1","page_count":56}}',
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) { return $response['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) { return $response['body']; } );
		Functions\when( 'do_action' )->justReturn( null );

		$this->assertSame( 'updated', Sync::poll_post( 456 ) );
		$this->assertSame( 'https://filetoweb.com/v1/documents/doc-july-current', $requested_url );
		$this->assertSame( 'ready', $meta[457][ Document_State::META_STATUS ] );
		$this->assertSame( 'doc-july-current', $meta[456][ Document_State::META_DOCUMENT_ID ] );
		$this->assertSame( 'ready', $meta[456][ Document_State::META_STATUS ] );
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

	private function mock_attachment_sync_environment( &$stored, $request_handler ) {
		Functions\when( 'untrailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_mime_type' )->justReturn( 'application/pdf' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://city.example/uploads/july-financials.pdf' );
		Functions\when( 'get_attached_file' )->justReturn( __FILE__ );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				return array_key_exists( $key, $stored ) ? $stored[ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$stored ) {
				$stored[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$stored ) {
				unset( $stored[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $response ) {
				return is_object( $response ) && method_exists( $response, 'get_error_message' );
			}
		);
		Functions\when( 'wp_remote_request' )->alias( $request_handler );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) { return $response['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) { return $response['body']; } );
		Functions\when( 'do_action' )->justReturn( null );
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
