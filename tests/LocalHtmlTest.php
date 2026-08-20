<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Local_HTML;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class LocalHtmlTest extends TestCase {
	private $uploads_dir = '';
	private $meta        = array();
	private $requests    = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads_dir = sys_get_temp_dir() . '/ftw-local-html-' . uniqid();
		$this->requests    = array();
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
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);
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
			'One or more FileToWeb preview assets could not be written to WordPress storage.',
			$this->meta[123][ Document_State::META_LAST_ERROR ]
		);
	}

	public function test_current_legacy_cache_preserves_failed_preview_migration_error(): void {
		$this->assertSame( 'updated', Local_HTML::refresh_for_post( 123 ) );

		unset( $this->meta[123][ \FileToWeb\Integration\Proud_HTML_Preview::META_KEY ] );

		Functions\when( 'has_action' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'wp_safe_remote_head' )->justReturn(
			array(
				'code' => 503,
			)
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return $response['code'];
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
