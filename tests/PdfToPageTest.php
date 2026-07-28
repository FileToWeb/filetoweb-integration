<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\PDF_To_Page;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class PdfToPageTest extends TestCase {
	private $meta        = array();
	private $options     = array();
	private $uploads_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta        = array();
		$this->options     = array();
		$this->uploads_dir = sys_get_temp_dir() . '/ftw-pdf-to-page-' . uniqid();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $value ) {
				return preg_replace( '/[^A-Za-z0-9_.-]/', '-', (string) $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' ) . '/';
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-09 12:00:00' );
		Functions\when( 'home_url' )->justReturn( 'https://city.example' );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
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
						Settings::KEY_EPUB_DOWNLOAD => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				if ( isset( $this->options[ $name ] ) ) {
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
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				return isset( $this->meta[ $post_id ][ $key ] ) ? $this->meta[ $post_id ][ $key ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_FILES = array();

		if ( $this->uploads_dir && is_dir( $this->uploads_dir ) ) {
			$this->remove_dir( $this->uploads_dir );
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	private function remove_dir( $dir ): void {
		foreach ( glob( rtrim( $dir, '/' ) . '/*' ) as $path ) {
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	public function test_pages_submenu_uses_publish_pages_capability(): void {
		$this->addToAssertionCount( 1 );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'edit.php?post_type=page',
				'Convert PDF to Page',
				'Convert PDF to Page',
				'publish_pages',
				PDF_To_Page::PAGE_SLUG,
				\Mockery::type( 'array' )
			);

		PDF_To_Page::add_pages_submenu();
	}

	public function test_init_registers_auto_poll_ajax_hook(): void {
		$hooks = array();

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$hooks ) {
				$hooks[] = array( $hook, $callback );
				return true;
			}
		);

		PDF_To_Page::init();

		$this->assertContains(
			array(
				'wp_ajax_' . PDF_To_Page::ACTION_AJAX_POLL_JOBS,
				array( PDF_To_Page::class, 'handle_ajax_poll_jobs' ),
			),
			$hooks
		);
	}

	public function test_upload_validation_rejects_missing_non_pdf_and_oversized_files(): void {
		$_FILES = array();
		$this->assertFalse( PDF_To_Page::validated_uploaded_pdf()['ok'] );

		$tmp = tempnam( sys_get_temp_dir(), 'ftw-docx-' );
		file_put_contents( $tmp, 'not a pdf' );
		$_FILES['filetoweb_pdf'] = array(
			'name'     => 'notes.txt',
			'type'     => 'text/plain',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
		);
		$this->assertFalse( PDF_To_Page::validated_uploaded_pdf()['ok'] );
		unlink( $tmp );

		$tmp = tempnam( sys_get_temp_dir(), 'ftw-big-' );
		file_put_contents( $tmp, '%PDF-1.4' );
		$_FILES['filetoweb_pdf'] = array(
			'name'     => 'huge.pdf',
			'type'     => 'application/pdf',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => PDF_To_Page::MAX_BYTES + 1,
		);
		$this->assertFalse( PDF_To_Page::validated_uploaded_pdf()['ok'] );
		unlink( $tmp );
	}

	public function test_upload_flow_uses_signed_upload_without_creating_draft_page(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ftw-pdf-' );
		file_put_contents( $tmp, '%PDF-1.4 test pdf' );

		$requests = array();

		Functions\expect( 'wp_insert_post' )->never();
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-501' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'admin@example.test' ) );
		Functions\when( 'wp_json_encode' )->alias(
			function ( $value ) {
				return json_encode( $value );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$requests ) {
				$requests[] = array( $url, $args );

				if ( 'https://filetoweb.com/v1/documents' === $url ) {
					$body = json_decode( $args['body'], true );
					$this->assertSame( 'upload', $body['source']['type'] );
					$this->assertSame( 'wordpress:fe457a395a16:pdf-to-page:job-501', $body['external_id'] );

					return array(
						'code' => 200,
						'body' => json_encode(
							array(
								'document' => array(
									'id'             => 'doc-501',
									'external_id'    => $body['external_id'],
									'status'         => 'awaiting_upload',
									'html_url'       => 'https://filetoweb.com/d/doc501/1',
									'continuous_url' => 'https://filetoweb.com/d/doc501/continuous',
									'editor_url'     => 'https://app.filetoweb.com/home/city/ai-editor?documentId=doc-501',
									'page_count'     => 0,
									'upload'         => array(
										'method'  => 'PUT',
										'url'     => 'https://signed.example.test/source.pdf',
										'headers' => array( 'Content-Type' => 'application/pdf' ),
									),
								),
							)
						),
					);
				}

				if ( 'https://signed.example.test/source.pdf' === $url ) {
					$this->assertSame( 'PUT', $args['method'] );
					$this->assertSame( '%PDF-1.4 test pdf', $args['body'] );

					return array(
						'code' => 200,
						'body' => '',
					);
				}

				if ( 'https://filetoweb.com/v1/documents/doc-501/complete-upload' === $url ) {
					return array(
						'code' => 200,
						'body' => json_encode(
							array(
								'document' => array(
									'id'             => 'doc-501',
									'status'         => 'processing',
									'html_url'       => 'https://filetoweb.com/d/doc501/1',
									'continuous_url' => 'https://filetoweb.com/d/doc501/continuous',
									'editor_url'     => 'https://app.filetoweb.com/home/city/ai-editor?documentId=doc-501',
									'page_count'     => 0,
								),
							)
						),
					);
				}

				return array( 'code' => 500, 'body' => 'unexpected' );
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

		$result = PDF_To_Page::create_draft_from_validated_upload(
			array(
				'ok'       => true,
				'error'    => '',
				'filename' => 'Agenda-Packet.pdf',
				'tmp_name' => $tmp,
				'size'     => filesize( $tmp ),
				'sha256'   => hash_file( 'sha256', $tmp ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'job-501', $result['job_id'] );
		$this->assertSame( 0, $result['page_id'] );
		$this->assertArrayHasKey( PDF_To_Page::OPTION_JOBS, $this->options );
		$this->assertSame( 'doc-501', $this->options[ PDF_To_Page::OPTION_JOBS ]['job-501']['document_id'] );
		$this->assertSame( 'processing', $this->options[ PDF_To_Page::OPTION_JOBS ]['job-501']['status'] );
		$this->assertSame( 'admin@example.test', $this->options[ PDF_To_Page::OPTION_JOBS ]['job-501']['notify_email'] );
		$this->assertCount( 3, $requests );
		$this->assertFileDoesNotExist( $tmp );
	}

	public function test_ready_job_creates_draft_page_and_sends_one_email(): void {
		$page_id = 701;
		$job_id  = 'job-ready';

		$this->options[ PDF_To_Page::OPTION_JOBS ] = array(
			$job_id => array(
				'id'                    => $job_id,
				'filename'              => 'Ready-Agenda.pdf',
				'fingerprint'           => 'fp-ready',
				'fingerprint_algorithm' => 'sha256',
				'external_id'           => 'wordpress:fe457a395a16:pdf-to-page:' . $job_id,
				'document_id'           => 'doc-ready',
				'status'                => 'processing',
				'html_url'              => 'https://filetoweb.com/d/doc-ready/1',
				'continuous_url'        => 'https://filetoweb.com/d/doc-ready/continuous',
				'editor_url'            => 'https://app.filetoweb.com/home/city/ai-editor?documentId=doc-ready',
				'page_count'            => 0,
				'page_id'               => 0,
				'notify_email'          => 'admin@example.test',
				'error'                 => '',
				'created_at'            => '2026-06-09 11:00:00',
				'updated_at'            => '2026-06-09 11:00:00',
				'completed_at'          => '',
			),
		);

		$current_content = '';
		$inserted_posts  = array();
		$updated_posts   = array();
		$emails          = array();

		Functions\when( 'wp_insert_post' )->alias(
			function ( $postarr ) use ( &$inserted_posts, $page_id ) {
				$inserted_posts[] = $postarr;
				$this->assertSame( 'page', $postarr['post_type'] );
				$this->assertSame( 'draft', $postarr['post_status'] );
				$this->assertSame( 'Ready Agenda', $postarr['post_title'] );
				$this->assertSame( '', $postarr['post_content'] );

				return $page_id;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'get_post_field' )->alias(
			function () use ( &$current_content ) {
				return $current_content;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr ) use ( &$updated_posts, &$current_content ) {
				$updated_posts[] = $postarr;
				$current_content = $postarr['post_content'];
				return $postarr['ID'];
			}
		);
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				return array(
					'basedir' => $this->uploads_dir,
					'baseurl' => 'https://city.example/wp-content/uploads',
				);
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'token-ready' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return (string) $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url ) {
				$this->assertSame( 'https://filetoweb.com/v1/documents/doc-ready', $url );

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'             => 'doc-ready',
								'external_id'    => 'wordpress:fe457a395a16:pdf-to-page:job-ready',
								'status'         => 'ready',
								'html_url'       => 'https://filetoweb.com/d/doc-ready/1',
								'continuous_url' => 'https://filetoweb.com/d/doc-ready/continuous',
								'editor_url'     => 'https://app.filetoweb.com/home/city/ai-editor?documentId=doc-ready',
								'page_count'     => 3,
							),
						)
					),
				);
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 200,
				'body' => '<!doctype html><html><body><main>Ready job content</main></body></html>',
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
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://city.example/wp-admin/post.php?post=701&action=edit' );
		Functions\when( 'get_the_title' )->justReturn( 'Ready Agenda' );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return false !== strpos( $email, '@' );
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) use ( &$emails ) {
				$emails[] = array( $to, $subject, $message );
				return true;
			}
		);

		$this->assertSame( 'updated', PDF_To_Page::poll_job( $job_id ) );

		$this->assertCount( 1, $inserted_posts );
		$this->assertCount( 1, $updated_posts );
		$this->assertSame( $page_id, $this->options[ PDF_To_Page::OPTION_JOBS ][ $job_id ]['page_id'] );
		$this->assertSame( 'ready', $this->options[ PDF_To_Page::OPTION_JOBS ][ $job_id ]['status'] );
		$this->assertStringContainsString( 'Ready job content', $updated_posts[0]['post_content'] );
		$this->assertSame( '1', $this->meta[ $page_id ][ Document_State::META_PDF_TO_PAGE ] );
		$this->assertSame( 'doc-ready', $this->meta[ $page_id ][ Document_State::META_DOCUMENT_ID ] );
		$this->assertSame( '2026-06-09 12:00:00', $this->meta[ $page_id ][ Document_State::META_PDF_TO_PAGE_COMPLETED_AT ] );
		$this->assertCount( 1, $emails );
	}

	public function test_retryable_poll_failure_keeps_pdf_to_page_job_pending(): void {
		$job_id = 'job-temporary-failure';

		$this->options[ PDF_To_Page::OPTION_JOBS ] = array(
			$job_id => array(
				'id'          => $job_id,
				'document_id' => 'doc-temporary-failure',
				'status'      => 'processing',
				'error'       => '',
			),
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
							'reference' => 'FTW-ABCDEF123456',
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

		$this->assertSame( 'updated', PDF_To_Page::poll_job( $job_id ) );
		$job = $this->options[ PDF_To_Page::OPTION_JOBS ][ $job_id ];
		$this->assertSame( 'processing', $job['status'] );
		$this->assertSame( 'service_unavailable', $job['error_code'] );
		$this->assertSame( 'FTW-ABCDEF123456', $job['error_reference'] );
		$this->assertTrue( $job['error_retryable'] );
	}

	public function test_ajax_auto_poll_updates_recent_rows_when_job_becomes_ready(): void {
		$page_id = 771;
		$job_id  = 'job-ajax-ready';

		$this->options[ PDF_To_Page::OPTION_JOBS ] = array(
			$job_id => array(
				'id'                    => $job_id,
				'filename'              => 'Auto-Poll-Agenda.pdf',
				'fingerprint'           => 'fp-ajax-ready',
				'fingerprint_algorithm' => 'sha256',
				'external_id'           => 'wordpress:fe457a395a16:pdf-to-page:' . $job_id,
				'document_id'           => 'doc-ajax-ready',
				'status'                => 'processing',
				'html_url'              => 'https://filetoweb.com/d/doc-ajax-ready/1',
				'continuous_url'        => 'https://filetoweb.com/d/doc-ajax-ready/continuous',
				'editor_url'            => 'https://app.filetoweb.com/home/city/ai-editor?documentId=doc-ajax-ready',
				'page_count'            => 0,
				'page_id'               => 0,
				'notify_email'          => 'admin@example.test',
				'error'                 => '',
				'created_at'            => '2026-06-09 11:00:00',
				'updated_at'            => '2026-06-09 11:00:00',
				'completed_at'          => '',
			),
		);

		$current_content = '';
		$json_payload    = null;

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_send_json_error' )->alias(
			function () {
				throw new \RuntimeException( 'json-error' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $payload ) use ( &$json_payload ) {
				$json_payload = $payload;
				throw new \RuntimeException( 'json-success' );
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			function ( $postarr ) use ( $page_id ) {
				$this->assertSame( 'page', $postarr['post_type'] );
				$this->assertSame( 'draft', $postarr['post_status'] );
				$this->assertSame( 'Auto Poll Agenda', $postarr['post_title'] );

				return $page_id;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'get_post_field' )->alias(
			function () use ( &$current_content ) {
				return $current_content;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr ) use ( &$current_content ) {
				$current_content = $postarr['post_content'];
				return $postarr['ID'];
			}
		);
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				return array(
					'basedir' => $this->uploads_dir,
					'baseurl' => 'https://city.example/wp-content/uploads',
				);
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'token-ajax' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return (string) $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url ) {
				return $url . '&_wpnonce=test';
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://city.example/wp-admin/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://city.example/wp-admin/post.php?post=771&action=edit' );
		Functions\when( 'get_the_title' )->justReturn( 'Auto Poll Agenda' );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return false !== strpos( $email, '@' );
			}
		);
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url ) {
				$this->assertSame( 'https://filetoweb.com/v1/documents/doc-ajax-ready', $url );

				return array(
					'code' => 200,
					'body' => json_encode(
						array(
							'document' => array(
								'id'             => 'doc-ajax-ready',
								'external_id'    => 'wordpress:fe457a395a16:pdf-to-page:job-ajax-ready',
								'status'         => 'ready',
								'html_url'       => 'https://filetoweb.com/d/doc-ajax-ready/1',
								'continuous_url' => 'https://filetoweb.com/d/doc-ajax-ready/continuous',
								'editor_url'     => 'https://app.filetoweb.com/home/city/ai-editor?documentId=doc-ajax-ready',
								'page_count'     => 2,
							),
						)
					),
				);
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 200,
				'body' => '<!doctype html><html><body><main>Auto-polled content</main></body></html>',
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

		try {
			PDF_To_Page::handle_ajax_poll_jobs();
			$this->fail( 'Expected JSON response.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'json-success', $exception->getMessage() );
		}

		$this->assertIsArray( $json_payload );
		$this->assertFalse( $json_payload['has_pending'] );
		$this->assertSame( 1, $json_payload['counts']['updated'] );
		$this->assertStringContainsString( 'Auto-Poll-Agenda.pdf', $json_payload['html'] );
		$this->assertStringContainsString( 'Edit draft', $json_payload['html'] );
		$this->assertSame( $page_id, $this->options[ PDF_To_Page::OPTION_JOBS ][ $job_id ]['page_id'] );
		$this->assertStringContainsString( 'Auto-polled content', $current_content );
	}

	public function test_ready_poll_updates_same_draft_page_and_sends_one_email(): void {
		$page_id = 601;

		$this->meta[ $page_id ] = array(
			Document_State::META_PDF_TO_PAGE              => '1',
			Document_State::META_STATUS                   => 'ready',
			Document_State::META_CONTINUOUS_URL           => 'https://filetoweb.com/d/doc601/continuous',
			Document_State::META_SOURCE_FINGERPRINT       => 'fp-601',
			Document_State::META_PDF_TO_PAGE_NOTIFY_EMAIL => 'admin@example.test',
		);

		$current_content = '<p>placeholder</p>';
		$updated_posts   = array();
		$emails          = array();

		Functions\when( 'get_post_status' )->justReturn( 'draft' );
		Functions\when( 'get_post_field' )->alias(
			function () use ( &$current_content ) {
				return $current_content;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr ) use ( &$updated_posts, &$current_content ) {
				$updated_posts[] = $postarr;
				$current_content = $postarr['post_content'];
				return $postarr['ID'];
			}
		);
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				return array(
					'basedir' => $this->uploads_dir,
					'baseurl' => 'https://city.example/wp-content/uploads',
				);
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'token-601' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				return (string) $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn(
			array(
				'code' => 200,
				'body' => '<!doctype html><html><head><style>.doc{color:#111}</style></head><body><main>Ready WordPress content</main></body></html>',
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
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://city.example/wp-admin/post.php?post=601&action=edit' );
		Functions\when( 'get_the_title' )->justReturn( 'Ready Agenda' );
		Functions\when( 'is_email' )->alias(
			function ( $email ) {
				return false !== strpos( $email, '@' );
			}
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) use ( &$emails ) {
				$emails[] = array( $to, $subject, $message );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		PDF_To_Page::maybe_update_ready_page(
			$page_id,
			array(
				'continuous_url' => 'https://filetoweb.com/d/doc601/continuous',
				'html_url'       => 'https://filetoweb.com/d/doc601/1',
			)
		);
		PDF_To_Page::maybe_update_ready_page( $page_id );

		$this->assertCount( 1, $updated_posts );
		$this->assertStringContainsString( 'Ready WordPress content', $updated_posts[0]['post_content'] );
		$this->assertSame( '2026-06-09 12:00:00', $this->meta[ $page_id ][ Document_State::META_PDF_TO_PAGE_COMPLETED_AT ] );
		$this->assertSame( '2026-06-09 12:00:00', $this->meta[ $page_id ][ Document_State::META_PDF_TO_PAGE_NOTIFIED_AT ] );
		$this->assertCount( 1, $emails );
	}
}
