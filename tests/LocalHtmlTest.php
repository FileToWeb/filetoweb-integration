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

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads_dir = sys_get_temp_dir() . '/ftw-local-html-' . uniqid();
		$this->meta        = array(
			123 => array(
				Document_State::META_STATUS             => 'ready',
				Document_State::META_HTML_URL           => 'https://filetoweb.com/d/demo/1',
				Document_State::META_CONTINUOUS_URL     => 'https://filetoweb.com/d/demo/continuous',
				Document_State::META_SOURCE_FINGERPRINT => 'fingerprint-123',
			),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
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
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				return array(
					'basedir' => $this->uploads_dir,
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
				return rtrim( (string) $url, '/' ) . '/?' . http_build_query( $args );
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
				if ( 'filetoweb_integration_auto_create_native_page' === $tag ) {
					return false;
				}

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
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $default;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->assertSame( 'https://filetoweb.com/d/demo/continuous', $url );
				$this->assertNotEmpty( $args['reject_unsafe_urls'] );

				return array(
					'body' => '<!doctype html><html><head><script>bad()</script></head><body><div class="ftw-tabbar">filename.pdf</div><main>Converted content</main></body></html>',
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
		if ( $this->uploads_dir && is_dir( $this->uploads_dir ) ) {
			foreach ( glob( $this->uploads_dir . '/filetoweb-integration/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->uploads_dir . '/filetoweb-integration' );
			rmdir( $this->uploads_dir );
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_refresh_stores_local_html_without_scripts_and_public_url_is_local(): void {
		$this->assertSame( 'updated', Local_HTML::refresh_for_post( 123 ) );

		$path = $this->meta[123][ Document_State::META_LOCAL_HTML_PATH ];
		$this->assertFileExists( $path );

		$html = file_get_contents( $path );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'data-filetoweb-local-viewer', $html );
		$this->assertStringContainsString( 'Converted content', $html );

		$this->assertSame(
			'https://example.test/?filetoweb_local_html=123&ftw_token=token-123',
			Local_HTML::local_url( 123 )
		);
	}
}
