<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Native_Page;
use PHPUnit\Framework\TestCase;

class NativePageTest extends TestCase {
	private $meta        = array();
	private $uploads_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads_dir = sys_get_temp_dir() . '/ftw-native-page-' . uniqid();
		mkdir( $this->uploads_dir . '/filetoweb-integration', 0777, true );
		$local_path = $this->uploads_dir . '/filetoweb-integration/123-local.html';
		file_put_contents( $local_path, '<!doctype html><html><head><style>.doc{color:#111}</style></head><body><main>Converted content</main></body></html>' );

		$this->meta = array(
			123 => array(
				Document_State::META_SOURCE_FINGERPRINT => 'fp-123',
				Document_State::META_LOCAL_HTML_PATH    => $local_path,
				Document_State::META_LOCAL_HTML_TOKEN   => 'token-123',
			),
		);

		Functions\when( '__' )->returnArg();
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
		Functions\when( 'esc_url_raw' )->returnArg();
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
		Functions\when( 'get_post_status' )->alias(
			function ( $post_id ) {
				return 777 === (int) $post_id ? 'publish' : '';
			}
		);
		Functions\when( 'get_permalink' )->alias(
			function ( $post_id ) {
				return 777 === (int) $post_id ? 'https://example.test/native-page/' : '';
			}
		);
		Functions\when( 'get_the_title' )->justReturn( 'Converted agenda' );
		Functions\when( 'is_wp_error' )->justReturn( false );
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

	public function test_ensure_draft_creates_wordpress_page_from_local_html(): void {
		Functions\when( 'wp_insert_post' )->alias(
			function ( $postarr ) {
				$this->assertSame( 'draft', $postarr['post_status'] );
				$this->assertSame( 'page', $postarr['post_type'] );
				$this->assertStringContainsString( 'Converted content', $postarr['post_content'] );

				return 777;
			}
		);

		$this->assertSame( 777, Native_Page::ensure_draft_for_post( 123 ) );
		$this->assertSame( 777, $this->meta[123][ Document_State::META_LOCAL_PAGE_ID ] );
	}

	public function test_approved_page_url_requires_approval_and_published_page(): void {
		$this->meta[123][ Document_State::META_LOCAL_PAGE_ID ]       = 777;
		$this->meta[123][ Document_State::META_LOCAL_PAGE_APPROVED ] = '1';

		$this->assertSame( 'https://example.test/native-page/', Native_Page::approved_page_url( 123 ) );
	}
}
