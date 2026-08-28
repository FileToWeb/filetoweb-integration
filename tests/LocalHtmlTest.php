<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Local_HTML;
use FileToWeb\Integration\Proud_HTML_Preview;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class LocalHtmlTest extends TestCase {
	private $uploads_dir = '';
	private $meta        = array();
	private $post_types  = array();
	private $requests    = array();
	private $scheduled   = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads_dir = sys_get_temp_dir() . '/ftw-local-html-' . uniqid();
		$this->requests    = array();
		$this->scheduled   = array();
		$this->post_types  = array();
		$GLOBALS['filetoweb_test_preview_url_calls'] = array();
		$GLOBALS['filetoweb_test_preview_urls']      = array();
		$this->meta        = array(
			123 => array(
				Document_State::META_STATUS             => 'ready',
				Document_State::META_HTML_URL           => 'https://filetoweb.com/d/demo/1',
				Document_State::META_CONTINUOUS_URL     => 'https://filetoweb.com/d/demo/continuous',
				Document_State::META_SOURCE_FINGERPRINT => 'fingerprint-123',
				Document_State::META_ORIGINAL_URL        => 'https://example.test/wp-content/uploads/agenda.pdf',
			),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_file_name' )->alias(
			function ( $value ) {
				return preg_replace( '/[^A-Za-z0-9_.-]/', '-', (string) $value );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-05 12:00:00' );
		Functions\when( 'trailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' ) . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			function ( $value ) {
				return str_replace( '\\', '/', (string) $value );
			}
		);
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				return array(
					'basedir' => $this->uploads_dir,
					'baseurl' => 'https://example.test/wp-content/uploads',
				);
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			function ( $dir ) {
				return is_dir( $dir ) || mkdir( $dir, 0777, true );
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'token-123' );
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $url ) {
				$separator = false === strpos( (string) $url, '?' ) ? '?' : '&';

				return (string) $url . $separator . http_build_query( $args );
			}
		);
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				return isset( $this->meta[ $post_id ][ $key ] ) ? $this->meta[ $post_id ][ $key ] : '';
			}
		);
		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) {
				return isset( $this->post_types[ $post_id ] ) ? $this->post_types[ $post_id ] : '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ $post_id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $post_id ) {
				return isset( $this->meta[ $post_id ] ) ? (object) array( 'ID' => $post_id, 'post_status' => 'publish' ) : null;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook, $args = array() ) {
				$key = $hook . ':' . serialize( $args );
				return isset( $this->scheduled[ $key ] ) ? $this->scheduled[ $key ] : false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook, $args = array() ) {
				$this->scheduled[ $hook . ':' . serialize( $args ) ] = $when;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook, $args = array() ) {
				unset( $this->scheduled[ $hook . ':' . serialize( $args ) ] );
				return true;
			}
		);
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( \FileToWeb\Integration\Proud_HTML_Preview::OPTION_PROVIDERS === $name ) {
					return array( 'filetoweb' => true );
				}

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
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->requests[] = $url;
				$this->assertNotEmpty( $args['reject_unsafe_urls'] );

				if ( 'https://filetoweb.com/d/demo/continuous?chrome=0' === $url ) {
					$this->assertSame( 45, $args['timeout'] );
					return array(
						'body' => '<!doctype html><html><head><script>bad()</script></head><body><div class="ftw-tabbar">filename.pdf</div><main><img src="/d/demo/assets/page-1/logo.png" alt="Logo">Converted content</main></body></html>',
					);
				}

				if ( 'https://filetoweb.com/d/demo/assets/page-1/logo.png' === $url ) {
					return array(
						'body' => 'PNGDATA',
					);
				}

				return array(
					'body' => '',
				);
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return $response['body'];
			}
		);
	}

	protected function tearDown(): void {
		Local_HTML::clear_poll_refresh_result( 123 );
		unset( $GLOBALS['filetoweb_test_preview_url_calls'], $GLOBALS['filetoweb_test_preview_urls'] );

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

	public function test_refresh_stores_local_html_without_scripts_and_public_url_is_local(): void {
		Functions\expect( 'wp_insert_post' )->never();

		$this->assertSame(
			'updated',
			Local_HTML::refresh_for_post( 123 ),
			isset( $this->meta[123][ Document_State::META_LAST_ERROR ] ) ? $this->meta[123][ Document_State::META_LAST_ERROR ] : ''
		);

		$path = $this->meta[123][ Document_State::META_LOCAL_HTML_PATH ];
		$this->assertFileExists( $path );

		$html = file_get_contents( $path );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'data-filetoweb-local-viewer', $html );
		$this->assertStringContainsString( '.ftw-generated-root{box-sizing:border-box!important;max-width:100%!important;min-width:0!important}', $html );
		$this->assertStringContainsString( 'Converted content', $html );
		$this->assertStringNotContainsString( 'src="/d/demo/assets/page-1/logo.png"', $html );
		$this->assertStringContainsString( 'src="https://example.test/wp-content/uploads/filetoweb-integration/previews/123/fingerprint123/assets/', $html );
		$assets = glob( $this->uploads_dir . '/filetoweb-integration/previews/123/fingerprint123/assets/*.png' );
		$this->assertCount( 1, $assets );
		$this->assertFileExists( $assets[0] );
		$this->assertSame(
			array(
				'https://filetoweb.com/d/demo/continuous?chrome=0',
				'https://filetoweb.com/d/demo/assets/page-1/logo.png',
			),
			$this->requests
		);
		$this->assertSame( 'https://filetoweb.com/d/demo/continuous?chrome=0', $this->meta[123][ Document_State::META_LOCAL_HTML_SOURCE_URL ] );
		$this->assertSame( 'filetoweb', $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['provider'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/agenda.pdf', $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['source_url'] );

		$this->assertSame(
			'https://example.test/?filetoweb_local_html=123&ftw_token=token-123',
			Local_HTML::local_url( 123 )
		);
	}

	public function test_poll_refresh_result_tracks_preview_publication(): void {
		Local_HTML::clear_poll_refresh_result( 123 );
		$this->assertSame( '', Local_HTML::poll_refresh_result( 123 ) );

		Local_HTML::refresh_after_poll(
			123,
			array(
				'continuous_url' => 'https://filetoweb.com/d/demo/continuous',
			)
		);

		$this->assertSame( 'updated', Local_HTML::poll_refresh_result( 123 ) );
	}

	public function test_durable_preview_resolves_without_a_local_pod_copy(): void {
		$durable_url = 'https://storage.googleapis.com/proudcity/oakwoodoh/2026/08/filetoweb-integration/previews/123/hash/index.html';

		$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ] = array(
			'version'            => \FileToWeb\Integration\Proud_HTML_Preview::SCHEMA_VERSION,
			'provider'           => 'filetoweb',
			'storage_backend'    => \FileToWeb\Integration\Proud_HTML_Preview::STORAGE_BACKEND_STATELESS,
			'source_url'         => $this->meta[123][ Document_State::META_ORIGINAL_URL ],
			'source_fingerprint' => $this->meta[123][ Document_State::META_SOURCE_FINGERPRINT ],
			'artifact_key'       => 'oakwoodoh/2026/08/filetoweb-integration/previews/123/hash/index.html',
			'artifact_url'       => $durable_url,
			'artifacts'          => array(
				array(
					'artifact_key' => 'oakwoodoh/2026/08/filetoweb-integration/previews/123/hash/index.html',
					'artifact_url' => $durable_url,
				),
			),
		);
		$GLOBALS['filetoweb_test_preview_urls'][123] = $durable_url;

		$this->assertFalse( Local_HTML::has_local_html( 123 ) );
		$this->assertSame( $durable_url, Local_HTML::local_url( 123, false ) );
		$this->assertSame( Proud_HTML_Preview::SCHEMA_VERSION, $this->meta[123][ Proud_HTML_Preview::META_KEY ]['version'] );
		$this->assertSame( $durable_url, Local_HTML::local_url( 123 ) );
		$this->assertSame(
			\FileToWeb\Integration\Proud_HTML_Preview::CORE_SCHEMA_VERSION,
			$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['version']
		);
		$this->assertSame(
			\FileToWeb\Integration\Proud_HTML_Preview::SCHEMA_VERSION,
			$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ][ \FileToWeb\Integration\Proud_HTML_Preview::RECORD_STORAGE_SCHEMA ]
		);
		$this->assertSame(
			array(
				array( 123, $this->meta[123][ Document_State::META_ORIGINAL_URL ] ),
				array( 123, $this->meta[123][ Document_State::META_ORIGINAL_URL ] ),
			),
			$GLOBALS['filetoweb_test_preview_url_calls']
		);
	}

	public function test_attachment_backed_document_uses_the_attachment_preview_url(): void {
		$this->post_types[123] = 'attachment';
		$this->post_types[124] = 'document';
		$GLOBALS['filetoweb_test_preview_urls'][123] = 'https://example.test/proud-preview/123';
		$this->meta[124]['document_meta'] = wp_json_encode( array( 'fid' => 123 ) );
		$this->meta[123][ Document_State::META_ORIGINAL_URL ] = 'https://example.test/uploads/current.pdf';
		$this->meta[123][ Proud_HTML_Preview::META_KEY ] = array(
			'version'                         => Proud_HTML_Preview::CORE_SCHEMA_VERSION,
			Proud_HTML_Preview::RECORD_STORAGE_SCHEMA => Proud_HTML_Preview::SCHEMA_VERSION,
			'provider'                        => Proud_HTML_Preview::PROVIDER,
			'storage_backend'                 => Proud_HTML_Preview::STORAGE_BACKEND_STATELESS,
			'source_url'                      => 'https://example.test/uploads/current.pdf',
			'source_fingerprint'              => 'current-fingerprint',
			'artifact_key'                    => 'oakwoodohio/2026/08/filetoweb-integration/previews/123/fingerprint/index.html',
			'artifact_url'                    => 'https://storage.googleapis.com/proudcity/oakwoodohio/2026/08/filetoweb-integration/previews/123/fingerprint/index.html',
			'artifacts'                       => array(
				array(
					'artifact_key' => 'oakwoodohio/2026/08/filetoweb-integration/previews/123/fingerprint/index.html',
					'artifact_url' => 'https://storage.googleapis.com/proudcity/oakwoodohio/2026/08/filetoweb-integration/previews/123/fingerprint/index.html',
				),
			),
			'token'                           => 'attachment-token',
			'published_at'                    => '2026-08-21 11:52:50',
		);

		$this->assertSame( 'https://example.test/proud-preview/123', Local_HTML::public_url_for_post( 124 ) );
	}

	public function test_legacy_preview_without_a_local_copy_falls_back_to_the_pdf(): void {
		$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ] = array(
			'version'            => 1,
			'provider'           => 'filetoweb',
			'source_url'         => $this->meta[123][ Document_State::META_ORIGINAL_URL ],
			'source_fingerprint' => $this->meta[123][ Document_State::META_SOURCE_FINGERPRINT ],
			'artifact_url'       => 'https://example.test/wp-content/uploads/filetoweb-integration/previews/123/hash/index.html',
		);
		$GLOBALS['filetoweb_test_preview_urls'][123] = $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['artifact_url'];

		$this->assertSame( '', Local_HTML::local_url( 123 ) );
		$this->assertSame( array(), $GLOBALS['filetoweb_test_preview_url_calls'] );
	}

	public function test_current_local_storage_preview_without_a_local_copy_falls_back_to_the_pdf(): void {
		$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ] = array(
			'version'            => \FileToWeb\Integration\Proud_HTML_Preview::SCHEMA_VERSION,
			'provider'           => 'filetoweb',
			'storage_backend'    => \FileToWeb\Integration\Proud_HTML_Preview::STORAGE_BACKEND_LOCAL,
			'source_url'         => $this->meta[123][ Document_State::META_ORIGINAL_URL ],
			'source_fingerprint' => $this->meta[123][ Document_State::META_SOURCE_FINGERPRINT ],
			'artifact_url'       => 'https://example.test/wp-content/uploads/filetoweb-integration/previews/123/hash/index.html',
		);
		$GLOBALS['filetoweb_test_preview_urls'][123] = $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['artifact_url'];

		$this->assertSame( '', Local_HTML::local_url( 123 ) );
		$this->assertSame( array(), $GLOBALS['filetoweb_test_preview_url_calls'] );
	}

	public function test_explicit_poll_refresh_fetches_and_publishes_editor_only_html_changes(): void {
		$this->assertSame( 'updated', Local_HTML::refresh_for_post( 123 ) );
		$original_path = $this->meta[123][ Document_State::META_LOCAL_HTML_PATH ];

		$this->requests = array();
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->requests[] = $url;
				$this->assertSame( 'no-cache', $args['headers']['Cache-Control'] );
				$this->assertSame( 'no-cache', $args['headers']['Pragma'] );

				return array(
					'body' => '<!doctype html><html><head></head><body><main>Updated content without the logo</main></body></html>',
				);
			}
		);

		$this->assertSame( 'current', Local_HTML::refresh_for_post( 123 ) );
		$this->assertSame( array(), $this->requests );

		Local_HTML::refresh_after_poll(
			123,
			array(
				'continuous_url' => 'https://filetoweb.com/d/demo/continuous',
			),
			true
		);

		$this->assertSame( 'updated', Local_HTML::poll_refresh_result( 123 ) );
		$this->assertSame( array( 'https://filetoweb.com/d/demo/continuous?chrome=0' ), $this->requests );

		$updated_path = $this->meta[123][ Document_State::META_LOCAL_HTML_PATH ];
		$this->assertNotSame( $original_path, $updated_path );
		$this->assertStringContainsString( 'fingerprint123-', $updated_path );
		$this->assertStringContainsString( 'Updated content without the logo', file_get_contents( $updated_path ) );
		$this->assertStringNotContainsString( '<img', file_get_contents( $updated_path ) );

		$record = $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ];
		$this->assertStringContainsString( 'fingerprint123-', $record['artifact_key'] );
		$this->assertCount( 1, $record['artifacts'] );
	}

	public function test_refresh_preserves_the_preview_publication_stage_error(): void {
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				if ( 'https://filetoweb.com/d/demo/continuous?chrome=0' === $url ) {
					return array(
						'code' => 200,
						'body' => '<html><body><img src="/d/demo/assets/missing.png"></body></html>',
					);
				}

				return array(
					'code' => 404,
					'body' => '',
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['code'];
			}
		);

		$this->assertSame( 'failed', Local_HTML::refresh_for_post( 123 ) );
		$this->assertSame(
			'A FileToWeb preview asset could not be published: missing.png (the download returned HTTP 404).',
			$this->meta[123][ Document_State::META_LAST_ERROR ]
		);
	}

	public function test_timed_out_ready_preview_retries_without_resubmitting_pdf(): void {
		$error = new class {
			public function get_error_message() {
				return 'cURL error 28: Operation timed out after 20002 milliseconds with 0 bytes received';
			}
		};

		Functions\when( 'wp_remote_get' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $response ) use ( $error ) {
				return $response === $error;
			}
		);

		Local_HTML::refresh_after_poll(
			123,
			array( 'continuous_url' => 'https://filetoweb.com/d/demo/continuous' )
		);

		$this->assertSame( 'failed', Local_HTML::poll_refresh_result( 123 ) );
		$this->assertSame( 1, $this->meta[123][ Document_State::META_PREVIEW_RETRY_ATTEMPTS ] );
		$this->assertArrayHasKey( Document_State::META_NEXT_PREVIEW_RETRY_AT, $this->meta[123] );
		$this->assertArrayHasKey( Local_HTML::HOOK_RETRY_PREVIEW . ':' . serialize( array( 123 ) ), $this->scheduled );
		$this->assertSame( 'ready', $this->meta[123][ Document_State::META_STATUS ] );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				if ( 'https://filetoweb.com/d/demo/continuous?chrome=0' === $url ) {
					return array( 'body' => '<html><body><main>Recovered preview</main></body></html>' );
				}

				return array( 'body' => '' );
			}
		);

		Local_HTML::retry_preview( 123 );

		$this->assertArrayNotHasKey( Document_State::META_PREVIEW_RETRY_ATTEMPTS, $this->meta[123] );
		$this->assertArrayNotHasKey( Document_State::META_NEXT_PREVIEW_RETRY_AT, $this->meta[123] );
		$this->assertSame( '', $this->meta[123][ Document_State::META_LAST_ERROR ] );
		$this->assertSame( array(), $this->scheduled );
		$record = $this->meta[123][ Proud_HTML_Preview::META_KEY ];
		$this->assertStringContainsString( 'Recovered preview', file_get_contents( $this->uploads_dir . '/' . $record['artifact_key'] ) );
	}

	public function test_failed_editor_refresh_retry_fetches_fresh_html_instead_of_accepting_stale_cache(): void {
		$this->assertSame( 'updated', Local_HTML::refresh_for_post( 123 ) );

		$error = new class {
			public function get_error_message() {
				return 'cURL error 28: Operation timed out';
			}
		};
		Functions\when( 'wp_remote_get' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $response ) use ( $error ) {
				return $response === $error;
			}
		);

		Local_HTML::refresh_after_poll( 123, array( 'continuous_url' => 'https://filetoweb.com/d/demo/continuous' ), true );
		$this->assertSame( 'failed', Local_HTML::poll_refresh_result( 123 ) );
		$this->assertNotEmpty( $this->scheduled );

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->assertSame( 'no-cache', $args['headers']['Cache-Control'] );
				return array( 'body' => '<html><body><main>Editor-only replacement</main></body></html>' );
			}
		);

		Local_HTML::retry_preview( 123 );

		$record = $this->meta[123][ Proud_HTML_Preview::META_KEY ];
		$html   = file_get_contents( $this->uploads_dir . '/' . $record['artifact_key'] );
		$this->assertStringContainsString( 'Editor-only replacement', $html );
		$this->assertStringNotContainsString( 'Converted content', $html );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_permanent_asset_failure_does_not_schedule_preview_retry(): void {
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				if ( 'https://filetoweb.com/d/demo/continuous?chrome=0' === $url ) {
					return array(
						'code' => 200,
						'body' => '<html><body><img src="/d/demo/assets/missing.png"></body></html>',
					);
				}

				return array( 'code' => 404, 'body' => '' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['code'];
			}
		);

		Local_HTML::refresh_after_poll( 123, array( 'continuous_url' => 'https://filetoweb.com/d/demo/continuous' ), true );

		$this->assertSame( 'failed', Local_HTML::poll_refresh_result( 123 ) );
		$this->assertSame( array(), $this->scheduled );
		$this->assertArrayNotHasKey( Document_State::META_PREVIEW_RETRY_ATTEMPTS, $this->meta[123] );
	}

	public function test_preview_retry_is_bounded_and_deleted_posts_are_removed_from_queue(): void {
		$error = new class {
			public function get_error_message() {
				return 'cURL error 28: Operation timed out';
			}
		};

		Functions\when( 'wp_remote_get' )->justReturn( $error );
		Functions\when( 'is_wp_error' )->alias(
			function ( $response ) use ( $error ) {
				return $response === $error;
			}
		);

		for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
			wp_clear_scheduled_hook( Local_HTML::HOOK_RETRY_PREVIEW, array( 123 ) );
			Local_HTML::refresh_after_poll( 123, array( 'continuous_url' => 'https://filetoweb.com/d/demo/continuous' ) );
			$this->assertSame( min( $attempt, 4 ), $this->meta[123][ Document_State::META_PREVIEW_RETRY_ATTEMPTS ] );
		}

		$this->assertArrayNotHasKey( Local_HTML::HOOK_RETRY_PREVIEW . ':' . serialize( array( 123 ) ), $this->scheduled );
		Local_HTML::stop_preview_retry( 123 );
		$this->assertArrayNotHasKey( Document_State::META_PREVIEW_RETRY_ATTEMPTS, $this->meta[123] );
		$this->assertArrayNotHasKey( Document_State::META_NEXT_PREVIEW_RETRY_AT, $this->meta[123] );
	}

	public function test_current_legacy_cache_preserves_failed_preview_migration_error(): void {
		$this->assertSame( 'updated', Local_HTML::refresh_for_post( 123 ) );

		unset( $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ] );

		Functions\when( 'has_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		$client         = new FtwTestStatelessClient();
		$client->exists = false;
		$stateless      = new FtwTestStatelessBootstrap( $client );
		Functions\when( 'ud_get_stateless_media' )->justReturn( $stateless );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, $use_root = false ) {
				return 'wp_stateless_file_name' === $tag && true === $use_root
					? 'example/2026/08/' . basename( $value )
					: $value;
			}
		);

		$this->assertSame( 'failed', Local_HTML::refresh_for_post( 123 ) );
		$this->assertSame(
			'FileToWeb preview files could not be verified in WordPress storage.',
			$this->meta[123][ Document_State::META_LAST_ERROR ]
		);
	}

	public function test_current_cache_upgrades_preview_record_without_artifact_manifest(): void {
		$this->assertSame( 'updated', Local_HTML::refresh_for_post( 123 ) );

		$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['artifacts'] = array();

		$this->assertSame( 'current', Local_HTML::refresh_for_post( 123 ) );
		$this->assertNotEmpty(
			$this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ]['artifacts']
		);
	}
}
