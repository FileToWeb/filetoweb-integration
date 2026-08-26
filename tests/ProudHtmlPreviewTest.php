<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Bulk_Queue;
use FileToWeb\Integration\Cron;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Plugin;
use FileToWeb\Integration\Proud_HTML_Preview;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Minimal directory stream wrapper that proxies a cloud-style URL to disk.
 */
class FtwGsDirectoryStreamWrapper {
	public $context;
	public static $root = '';
	private $directory_handle;
	private $stream_handle;

	public function dir_opendir( $path, $options ) {
		unset( $options );
		$this->directory_handle = @opendir( self::local_path( $path ) );

		return (bool) $this->directory_handle;
	}

	public function dir_readdir() {
		return readdir( $this->directory_handle );
	}

	public function dir_rewinddir() {
		return rewinddir( $this->directory_handle );
	}

	public function dir_closedir() {
		closedir( $this->directory_handle );
		return true;
	}

	public function url_stat( $path, $flags ) {
		unset( $flags );
		$stat = @stat( self::local_path( $path ) );

		return $stat ? $stat : false;
	}

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		unset( $options );
		$local_path = self::local_path( $path );

		if ( preg_match( '/[waxc+]/', $mode ) && ! is_dir( dirname( $local_path ) ) ) {
			mkdir( dirname( $local_path ), 0777, true );
		}

		$this->stream_handle = fopen( $local_path, $mode );
		$opened_path         = $path;

		return (bool) $this->stream_handle;
	}

	public function stream_read( $count ) {
		return fread( $this->stream_handle, $count );
	}

	public function stream_write( $data ) {
		return fwrite( $this->stream_handle, $data );
	}

	public function stream_tell() {
		return ftell( $this->stream_handle );
	}

	public function stream_eof() {
		return feof( $this->stream_handle );
	}

	public function stream_seek( $offset, $whence = SEEK_SET ) {
		return 0 === fseek( $this->stream_handle, $offset, $whence );
	}

	public function stream_flush() {
		return fflush( $this->stream_handle );
	}

	public function stream_close() {
		fclose( $this->stream_handle );
	}

	public function stream_stat() {
		return fstat( $this->stream_handle );
	}

	public function mkdir( $path, $mode, $options ) {
		return mkdir( self::local_path( $path ), $mode, (bool) ( $options & STREAM_MKDIR_RECURSIVE ) );
	}

	public function rename( $from, $to ) {
		return rename( self::local_path( $from ), self::local_path( $to ) );
	}

	public function unlink( $path ) {
		return unlink( self::local_path( $path ) );
	}

	public function rmdir( $path, $options ) {
		unset( $options );
		return rmdir( self::local_path( $path ) );
	}

	private static function local_path( $path ) {
		$parts = parse_url( $path );
		$host  = isset( $parts['host'] ) ? $parts['host'] : '';
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';

		return rtrim( self::$root, '/' ) . '/' . $host . '/' . ltrim( $path, '/' );
	}
}

class ProudHtmlPreviewTest extends TestCase {
	private $uploads_dir = '';
	private $meta        = array();
	private $options     = array();
	private $requests    = array();
	private $use_stream_uploads = false;
	private $stateless_client;
	private $stateless;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads_dir = sys_get_temp_dir() . '/ftw-proud-preview-' . uniqid();
		$this->meta        = array();
		$this->requests    = array();
		$this->use_stream_uploads = false;
		$this->stateless_client    = new FtwTestStatelessClient();
		$this->stateless           = new FtwTestStatelessBootstrap( $this->stateless_client );
		$this->options     = array(
			Settings::OPTION_SETTINGS => array(
				Settings::KEY_ENABLED       => '1',
				Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
				Settings::KEY_API_KEY       => 'ftw_api_test',
				Settings::KEY_REPLACE_LINKS => '1',
				Settings::KEY_EPUB_DOWNLOAD => '0',
				Settings::KEY_BATCH_SIZE    => 25,
			),
			Proud_HTML_Preview::OPTION_PROVIDERS => array( 'filetoweb' => true ),
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $value ) { return abs( intval( $value ) ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( function ( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); } );
		Functions\when( 'sanitize_file_name' )->alias( function ( $value ) { return preg_replace( '/[^A-Za-z0-9_.-]/', '-', (string) $value ); } );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'untrailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'trailingslashit' )->alias( function ( $value ) { return rtrim( (string) $value, '/' ) . '/'; } );
		Functions\when( 'wp_normalize_path' )->alias( function ( $value ) { return str_replace( '\\', '/', (string) $value ); } );
		Functions\when( 'wp_is_stream' )->alias( function ( $value ) { return false !== strpos( (string) $value, '://' ); } );
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				if ( $this->use_stream_uploads ) {
					return array(
						'basedir' => 'ftwgs://proudcity/delawarecountyin',
						'baseurl' => 'https://storage.googleapis.com/proudcity/delawarecountyin',
					);
				}

				return array(
					'basedir' => $this->uploads_dir,
					'baseurl' => 'https://city.example/wp-content/uploads',
				);
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias( function ( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); } );
		Functions\when( 'wp_generate_password' )->justReturn( 'preview-token-123' );
		Functions\when( 'current_time' )->justReturn( '2026-07-20 12:00:00' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
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
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ $post_id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, $use_root = false ) {
				if ( 'wp_stateless_file_name' === $tag && true === $use_root ) {
					return 'oakwoodohio/2026/08/' . basename( $value );
				}

				return $value;
			}
		);
		Functions\when( 'ud_get_stateless_media' )->alias(
			function () {
				return $this->stateless;
			}
		);
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( function ( $response ) { return $response['code']; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( function ( $response ) { return $response['body']; } );
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) {
				$this->requests[] = $url;

				$bodies = array(
					'https://filetoweb.com/d/demo/assets/layout.css' => '.page{background:url("font.woff2")} @import "bad.css";',
					'https://filetoweb.com/d/demo/assets/font.woff2' => 'FONT',
					'https://filetoweb.com/d/demo/assets/hero.png' => 'PNG',
				);

				return array(
					'code' => isset( $bodies[ $url ] ) ? 200 : 404,
					'body' => isset( $bodies[ $url ] ) ? $bodies[ $url ] : '',
				);
			}
		);
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->uploads_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_publish_is_sanitized_complete_and_idempotent(): void {
		$html = '<!doctype html><html><head><link rel="stylesheet" href="/d/demo/assets/layout.css"><script>bad()</script></head><body onload="bad()"><form><input></form><svg><a><animate attributeName="href" values="javascript:bad()"></animate></a></svg><math><mtext>bad</mtext></math><img src=/d/demo/assets/hero.png onerror="bad()"><a href="javascript:bad()">Bad</a><main>Agenda</main></body></html>';

		$record = Proud_HTML_Preview::publish(
			44,
			$html,
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/agenda.pdf',
			'fingerprint-123'
		);

		$this->assertIsArray( $record );
		$this->assertSame( 'filetoweb', $record['provider'] );
		$this->assertSame( Proud_HTML_Preview::SCHEMA_VERSION, $record['version'] );
		$this->assertSame( Proud_HTML_Preview::STORAGE_BACKEND_LOCAL, $record['storage_backend'] );
		$this->assertArrayHasKey( 'source_fingerprint_algorithm', $record );
		$this->assertCount( 4, $record['artifacts'] );
		$this->assertSame( $record['artifact_key'], $record['artifacts'][3]['artifact_key'] );
		$this->assertFileExists( $this->uploads_dir . '/' . $record['artifact_key'] );

		$stored = file_get_contents( $this->uploads_dir . '/' . $record['artifact_key'] );
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringNotContainsString( 'onload=', $stored );
		$this->assertStringNotContainsString( 'onerror=', $stored );
		$this->assertStringNotContainsString( '<form', $stored );
		$this->assertStringNotContainsString( '<svg', $stored );
		$this->assertStringNotContainsString( '<math', $stored );
		$this->assertStringNotContainsString( '<animate', $stored );
		$this->assertStringNotContainsString( 'javascript:', $stored );
		$this->assertStringContainsString( '/filetoweb-integration/previews/44/fingerprint123/assets/', $stored );

		$requests = $this->requests;
		$second   = Proud_HTML_Preview::publish(
			44,
			$html,
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/agenda.pdf',
			'fingerprint-123'
		);

		$this->assertSame( $record, $second );
		$this->assertSame( $requests, $this->requests );
	}

	public function test_sanitize_html_preserves_raw_style_text_without_decoding_literal_entities(): void {
		$html = '<!doctype html><html><head><style>.symbols::before{content:"• → — © ✓ ★ é 中 🔒"}.literal::before{content:"&bull;"}@import "bad.css";.bad{background:url("javascript:bad()")}</style></head><body><main>Training</main></body></html>';

		$sanitized = Proud_HTML_Preview::sanitize_html( $html );

		$this->assertStringContainsString( 'content:"• → — © ✓ ★ é 中 🔒"', $sanitized );
		$this->assertStringContainsString( 'content:"&bull;"', $sanitized );
		$this->assertStringNotContainsString( 'content:"&rarr;', $sanitized );
		$this->assertStringNotContainsString( '&#10003;', $sanitized );
		$this->assertStringNotContainsString( '@import', $sanitized );
		$this->assertStringNotContainsString( 'javascript:', $sanitized );
	}

	public function test_public_preview_can_pause_and_restore_without_losing_bundle(): void {
		$record = Proud_HTML_Preview::publish(
			91,
			'<html><body><main>Minutes</main></body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/minutes.pdf',
			'fingerprint-91'
		);

		$this->meta[91][ Document_State::META_STATUS ]             = 'ready';
		$this->meta[91][ Document_State::META_SOURCE_FINGERPRINT ] = 'fingerprint-91';
		$this->meta[91][ Document_State::META_ORIGINAL_URL ]       = 'https://city.example/wp-content/uploads/minutes.pdf';

		$this->assertTrue( Proud_HTML_Preview::pause_public( 91 ) );
		$this->assertSame( '1', $this->meta[91][ Proud_HTML_Preview::META_PUBLIC_PAUSED ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_KEY, $this->meta[91] );
		$this->assertSame( $record, $this->meta[91][ Proud_HTML_Preview::META_PAUSED_RECORD ] );
		$this->assertSame( $record, Proud_HTML_Preview::record_for_post( 91 ) );

		$this->assertTrue( Proud_HTML_Preview::restore_public( 91 ) );
		$this->assertSame( $record, $this->meta[91][ Proud_HTML_Preview::META_KEY ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_PUBLIC_PAUSED, $this->meta[91] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_PAUSED_RECORD, $this->meta[91] );
	}

	public function test_publish_while_paused_refreshes_private_record_only(): void {
		Proud_HTML_Preview::publish(
			92,
			'<html><body><main>Agenda v1</main></body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/agenda.pdf',
			'fingerprint-v1'
		);

		Proud_HTML_Preview::pause_public( 92 );

		$updated = Proud_HTML_Preview::publish(
			92,
			'<html><body><main>Agenda v2</main></body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/agenda.pdf',
			'fingerprint-v2'
		);

		$this->assertSame( 'fingerprint-v2', $updated['source_fingerprint'] );
		$this->assertSame( $updated, $this->meta[92][ Proud_HTML_Preview::META_PAUSED_RECORD ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_KEY, $this->meta[92] );
		$this->assertSame( '1', $this->meta[92][ Proud_HTML_Preview::META_PUBLIC_PAUSED ] );
	}

	public function test_restore_fails_closed_when_pdf_fingerprint_changed(): void {
		Proud_HTML_Preview::publish(
			93,
			'<html><body><main>Budget</main></body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/budget.pdf',
			'fingerprint-old'
		);

		Proud_HTML_Preview::pause_public( 93 );
		$this->meta[93][ Document_State::META_STATUS ]             = 'ready';
		$this->meta[93][ Document_State::META_SOURCE_FINGERPRINT ] = 'fingerprint-new';

		$this->assertFalse( Proud_HTML_Preview::restore_public( 93 ) );
		$this->assertSame( '1', $this->meta[93][ Proud_HTML_Preview::META_PUBLIC_PAUSED ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_KEY, $this->meta[93] );
	}

	public function test_restore_fails_closed_when_paused_record_is_missing(): void {
		$local_path = $this->uploads_dir . '/filetoweb-integration/95-local.html';
		mkdir( dirname( $local_path ), 0777, true );
		file_put_contents( $local_path, '<html><body>Local</body></html>' );

		$this->meta[95] = array(
			Proud_HTML_Preview::META_PUBLIC_PAUSED       => '1',
			Document_State::META_STATUS                  => 'ready',
			Document_State::META_SOURCE_FINGERPRINT      => 'fingerprint-95',
			Document_State::META_LOCAL_HTML_SOURCE_FP    => 'fingerprint-95',
			Document_State::META_LOCAL_HTML_PATH         => $local_path,
		);

		$this->assertFalse( Proud_HTML_Preview::restore_public( 95 ) );
		$this->assertSame( '1', $this->meta[95][ Proud_HTML_Preview::META_PUBLIC_PAUSED ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_KEY, $this->meta[95] );
	}

	public function test_pause_does_not_overwrite_another_preview_provider(): void {
		$other_record = array(
			'provider'           => 'another-provider',
			'source_url'         => 'https://city.example/wp-content/uploads/shared.pdf',
			'source_fingerprint' => 'other-fingerprint',
		);
		$this->meta[94][ Proud_HTML_Preview::META_KEY ] = $other_record;

		$this->assertFalse( Proud_HTML_Preview::pause_public( 94 ) );
		$this->assertSame( $other_record, $this->meta[94][ Proud_HTML_Preview::META_KEY ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_PUBLIC_PAUSED, $this->meta[94] );
	}

	public function test_stateless_sync_runs_only_when_hook_is_available(): void {
		$synced = array();
		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'do_action' )->alias(
			function ( $hook, $name ) use ( &$synced ) {
				if ( 'sm:sync::syncFile' === $hook ) {
					$synced[] = $name;
				}
			}
		);

		$record = Proud_HTML_Preview::publish(
			45,
			'<html><body><img src="/d/demo/assets/hero.png"></body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/packet.pdf',
			'fingerprint-456'
		);

		$this->assertCount( 2, $synced );
		$this->assertContains( 'filetoweb-integration/previews/45/fingerprint456/index.html', $synced );
		$this->assertSame(
			'oakwoodohio/2026/08/filetoweb-integration/previews/45/fingerprint456/index.html',
			$this->meta[45][ Proud_HTML_Preview::META_KEY ]['artifact_key']
		);
		$this->assertContains(
			'oakwoodohio/2026/08/filetoweb-integration/previews/45/fingerprint456/index.html',
			$this->stateless_client->checked
		);
		$stored = file_get_contents( $this->uploads_dir . '/' . $record['local_artifact_key'] );
		$this->assertStringContainsString(
			'https://storage.googleapis.com/proudcity/oakwoodohio/2026/08/filetoweb-integration/previews/45/fingerprint456/assets/',
			$stored
		);
		$this->assertStringNotContainsString( 'https://city.example/wp-content/uploads/', $stored );
	}

	public function test_manifest_and_cleanup_support_stateless_stream_directories(): void {
		if ( ! in_array( 'ftwgs', stream_get_wrappers(), true ) ) {
			$this->assertTrue( stream_wrapper_register( 'ftwgs', FtwGsDirectoryStreamWrapper::class ) );
		}

		FtwGsDirectoryStreamWrapper::$root = $this->uploads_dir;

		$relative_bundle = 'filetoweb-integration/previews/72/fingerprint72';
		$local_bundle    = $this->uploads_dir . '/proudcity/delawarecountyin/' . $relative_bundle;
		$remote_bundle   = 'ftwgs://proudcity/delawarecountyin/' . $relative_bundle;

		mkdir( $local_bundle . '/assets', 0777, true );
		file_put_contents( $local_bundle . '/index.html', '<html><body>Preview</body></html>' );
		file_put_contents( $local_bundle . '/assets/layout.css', 'body { color: black; }' );

		// PHP glob does not enumerate custom stream-wrapper directories.
		$this->assertSame( array(), glob( $remote_bundle . '/*' ) );

		$manifest_method = new ReflectionMethod( Proud_HTML_Preview::class, 'artifact_manifest' );
		$cleanup_method  = new ReflectionMethod( Proud_HTML_Preview::class, 'remove_directory' );
		if ( PHP_VERSION_ID < 80100 ) {
			$manifest_method->setAccessible( true );
			$cleanup_method->setAccessible( true );
		}

		$manifest = $manifest_method->invoke(
			null,
			$remote_bundle,
			'ftwgs://proudcity/delawarecountyin',
			'https://storage.googleapis.com/proudcity/delawarecountyin'
		);

		$this->assertSame(
			array(
				$relative_bundle . '/assets/layout.css',
				$relative_bundle . '/index.html',
			),
			array_column( $manifest, 'artifact_key' )
		);

		$cleanup_method->invoke( null, $remote_bundle );
		$this->assertDirectoryDoesNotExist( $local_bundle );
	}

	public function test_publish_completes_on_stateless_stream_directory(): void {
		if ( ! in_array( 'ftwgs', stream_get_wrappers(), true ) ) {
			$this->assertTrue( stream_wrapper_register( 'ftwgs', FtwGsDirectoryStreamWrapper::class ) );
		}

		FtwGsDirectoryStreamWrapper::$root = $this->uploads_dir;
		$this->use_stream_uploads           = true;

		$record = Proud_HTML_Preview::publish(
			73,
			'<html><body>Stateless preview</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://storage.googleapis.com/proudcity/delawarecountyin/document.pdf',
			'stateless-fingerprint'
		);

		$this->assertIsArray( $record, Proud_HTML_Preview::last_publish_error() );
		$this->assertSame(
			'filetoweb-integration/previews/73/statelessfingerprint/index.html',
			$record['artifact_key']
		);
		$this->assertSame( $record['artifact_key'], $record['artifacts'][0]['artifact_key'] );
		$this->assertFileExists(
			$this->uploads_dir . '/proudcity/delawarecountyin/' . $record['artifact_key']
		);
	}

	public function test_private_stateless_stream_preview_uses_authenticated_storage_verification(): void {
		if ( ! in_array( 'ftwgs', stream_get_wrappers(), true ) ) {
			$this->assertTrue( stream_wrapper_register( 'ftwgs', FtwGsDirectoryStreamWrapper::class ) );
		}

		FtwGsDirectoryStreamWrapper::$root = $this->uploads_dir;
		$this->use_stream_uploads           = true;

		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\expect( 'wp_safe_remote_head' )->never();

		$record = Proud_HTML_Preview::publish(
			74,
			'<html><body>Private Stateless preview</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://storage.googleapis.com/proudcity/delawarecountyin/private.pdf',
			'private-stateless-fingerprint'
		);

		$this->assertIsArray( $record, Proud_HTML_Preview::last_publish_error() );
		$this->assertSame(
			'oakwoodohio/2026/08/filetoweb-integration/previews/74/privatestatelessfingerprint/index.html',
			$record['artifact_key']
		);
	}

	public function test_windows_upload_path_still_verifies_remote_stateless_artifact(): void {
		$bundle_dir = $this->uploads_dir . '/windows-bundle';
		mkdir( $bundle_dir, 0777, true );
		file_put_contents( $bundle_dir . '/index.html', '<html><body>Windows preview</body></html>' );

		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\expect( 'wp_safe_remote_head' )->never();

		$method = new ReflectionMethod( Proud_HTML_Preview::class, 'sync_bundle_with_stateless' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertTrue(
			$method->invoke(
				null,
				$bundle_dir,
				'C:/inetpub/wwwroot/wp-content/uploads',
				'https://cdn.example.org/uploads'
			)
		);
	}

	public function test_stateless_sync_preserves_nested_gcs_object_name(): void {
		$filter_callback = null;
		$resolved_name   = '';
		$sync_args       = array();
		$filter_removed  = false;

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority, $accepted_args ) use ( &$filter_callback ) {
				$this->assertSame( 'wp_stateless_file_name', $hook );
				$this->assertSame( PHP_INT_MAX, $priority );
				$this->assertSame( 2, $accepted_args );
				$filter_callback = $callback;
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( $hook, $callback, $priority ) use ( &$filter_callback, &$filter_removed ) {
				$this->assertSame( 'wp_stateless_file_name', $hook );
				$this->assertSame( $filter_callback, $callback );
				$this->assertSame( PHP_INT_MAX, $priority );
				$filter_removed = true;
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( $hook, $name, $path, $forced, $args ) use ( &$filter_callback, &$resolved_name, &$sync_args ) {
				$this->assertSame( 'sm:sync::syncFile', $hook );
				$this->assertSame( '/tmp/index.html', $path );
				$this->assertSame( 2, $forced );
				$this->assertIsCallable( $filter_callback );

				$sync_args     = $args;
				$default_name  = 'delawarecountyin/2026/08/' . basename( $name );
				$resolved_name = $filter_callback( $default_name, $args['name_with_root'] );
			}
		);

		$method = new ReflectionMethod( Proud_HTML_Preview::class, 'sync_file_with_stateless' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke(
			null,
			'filetoweb-integration/previews/10154/fingerprint/index.html',
			'/tmp/index.html',
			'delawarecountyin/2026/08/filetoweb-integration/previews/10154/fingerprint/index.html',
			$this->stateless_client
		);

		$this->assertSame(
			'delawarecountyin/2026/08/filetoweb-integration/previews/10154/fingerprint/index.html',
			$resolved_name
		);
		$this->assertSame( 'filetoweb-preserve-object-name', $sync_args['name_with_root'] );
		$this->assertTrue( $sync_args['use_root'] );
		$this->assertFalse( $sync_args['ephemeral'] );
		$this->assertTrue( $filter_removed );
	}

	public function test_stateless_storage_prefixes_prevent_cross_tenant_bundle_collisions(): void {
		$tenant = 'delawarecountyin';
		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value, $use_root = false ) use ( &$tenant ) {
				return 'wp_stateless_file_name' === $tag && true === $use_root
					? $tenant . '/2026/08/' . basename( $value )
					: $value;
			}
		);

		$method = new ReflectionMethod( Proud_HTML_Preview::class, 'storage_context' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$key      = 'filetoweb-integration/previews/10030/shared-hash/index.html';
		$delaware = $method->invoke( null, $this->uploads_dir, 'https://city.example/wp-content/uploads', $key );
		$tenant   = 'somervillenj';
		$somerville = $method->invoke( null, $this->uploads_dir, 'https://city.example/wp-content/uploads', $key );

		$this->assertSame( 'delawarecountyin/2026/08/', $delaware['object_prefix'] );
		$this->assertSame( 'somervillenj/2026/08/', $somerville['object_prefix'] );
		$this->assertNotSame( $delaware['object_prefix'] . $key, $somerville['object_prefix'] . $key );
		$this->assertStringEndsWith( $key, $delaware['object_prefix'] . $key );
		$this->assertStringEndsWith( $key, $somerville['object_prefix'] . $key );
	}

	public function test_true_stateless_upload_directory_preserves_its_exact_gcs_root(): void {
		Functions\when( 'has_action' )->justReturn( 1 );

		$method = new ReflectionMethod( Proud_HTML_Preview::class, 'storage_context' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$key     = 'filetoweb-integration/previews/74/fingerprint/index.html';
		$storage = $method->invoke(
			null,
			'gs://proudcity/oakwoodoh/2026/08',
			'https://storage.googleapis.com/proudcity/oakwoodoh/2026/08',
			$key
		);

		$this->assertSame( 'oakwoodoh/2026/08/', $storage['object_prefix'] );
		$this->assertSame( 'https://storage.googleapis.com/proudcity', $storage['public_baseurl'] );
	}

	public function test_stateless_sync_removes_object_name_filter_after_exception(): void {
		$filter_callback = null;
		$filter_removed  = false;

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$filter_callback ) {
				$this->assertSame( 'wp_stateless_file_name', $hook );
				$filter_callback = $callback;
				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			function ( $hook, $callback ) use ( &$filter_callback, &$filter_removed ) {
				$this->assertSame( 'wp_stateless_file_name', $hook );
				$this->assertSame( $filter_callback, $callback );
				$filter_removed = true;
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function () {
				throw new RuntimeException( 'Stateless sync failed.' );
			}
		);

		$method = new ReflectionMethod( Proud_HTML_Preview::class, 'sync_file_with_stateless' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		try {
			$method->invoke(
				null,
				'filetoweb-integration/previews/10154/fingerprint/index.html',
				'/tmp/index.html',
				'delawarecountyin/2026/08/filetoweb-integration/previews/10154/fingerprint/index.html',
				$this->stateless_client
			);
			$this->fail( 'Expected the WP Stateless exception to be rethrown.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Stateless sync failed.', $exception->getMessage() );
		}

		$this->assertTrue( $filter_removed );
	}

	public function test_publish_fails_when_a_required_asset_cannot_be_mirrored(): void {
		$record = Proud_HTML_Preview::publish(
			48,
			'<html><body><img src="/d/demo/assets/missing.png"></body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/missing.pdf',
			'missing-asset-fingerprint'
		);

		$this->assertFalse( $record );
		$this->assertSame( 'One or more FileToWeb preview assets could not be written to WordPress storage.', Proud_HTML_Preview::last_publish_error() );
		$this->assertArrayNotHasKey( 48, $this->meta );
		$this->assertFileDoesNotExist( $this->uploads_dir . '/filetoweb-integration/previews/48/missingassetfingerprint/index.html' );
	}

	public function test_stateless_failure_does_not_publish_the_pointer(): void {
		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		$this->stateless_client->exists = false;

		$record = Proud_HTML_Preview::publish(
			49,
			'<html><body>Not yet durable</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/not-durable.pdf',
			'not-durable-fingerprint'
		);

		$this->assertFalse( $record );
		$this->assertSame( 'FileToWeb preview files could not be verified in WordPress storage.', Proud_HTML_Preview::last_publish_error() );
		$this->assertArrayNotHasKey( 49, $this->meta );
	}

	public function test_publish_replaces_an_incomplete_fingerprint_bundle(): void {
		$bundle_dir = $this->uploads_dir . '/filetoweb-integration/previews/44/fingerprint123';
		mkdir( $bundle_dir, 0777, true );
		file_put_contents( $bundle_dir . '/partial.tmp', 'incomplete' );

		$record = Proud_HTML_Preview::publish(
			44,
			'<html><body>Complete replacement</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/agenda.pdf',
			'fingerprint-123'
		);

		$this->assertIsArray( $record );
		$this->assertFileDoesNotExist( $bundle_dir . '/partial.tmp' );
		$this->assertStringContainsString( 'Complete replacement', file_get_contents( $bundle_dir . '/index.html' ) );
	}

	public function test_existing_cache_migrates_without_remote_conversion(): void {
		$legacy_dir = $this->uploads_dir . '/filetoweb-integration';
		mkdir( $legacy_dir, 0777, true );
		$legacy_path = $legacy_dir . '/44-old.html';
		file_put_contents( $legacy_path, '<html><body>Existing cache</body></html>' );

		$this->meta[44] = array(
			Document_State::META_LOCAL_HTML_PATH      => $legacy_path,
			Document_State::META_LOCAL_HTML_SOURCE_URL => 'https://filetoweb.com/d/demo/continuous?chrome=0',
			Document_State::META_LOCAL_HTML_SOURCE_FP  => 'fingerprint-old',
			Document_State::META_ORIGINAL_URL          => 'https://city.example/wp-content/uploads/old.pdf',
		);

		$this->assertTrue( Proud_HTML_Preview::migrate_existing_post( 44 ) );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 'filetoweb', $this->meta[44][ Proud_HTML_Preview::META_KEY ]['provider'] );
		$this->assertStringContainsString( 'Existing cache', file_get_contents( $this->meta[44][ Document_State::META_LOCAL_HTML_PATH ] ) );
	}

	public function test_existing_record_without_manifest_is_upgraded_from_local_bundle(): void {
		Proud_HTML_Preview::publish(
			50,
			'<html><body>Existing durable preview</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/existing.pdf',
			'existing-fingerprint'
		);

		unset( $this->meta[50][ Proud_HTML_Preview::META_KEY ]['artifacts'] );
		$this->meta[50][ Document_State::META_ORIGINAL_URL ] = 'https://city.example/wp-content/uploads/existing.pdf';

		$this->assertTrue( Proud_HTML_Preview::migrate_existing_post( 50 ) );
		$this->assertNotEmpty( $this->meta[50][ Proud_HTML_Preview::META_KEY ]['artifacts'] );
	}

	public function test_schema_two_gcs_record_adds_storage_marker_without_resync(): void {
		$bundle = 'filetoweb-integration/previews/60/fingerprint';
		$asset  = $bundle . '/assets/logo.png';
		$index  = $bundle . '/index.html';
		$root   = 'oakwoodohio/2026/07/';
		$synced = array();

		$this->meta[60][ Proud_HTML_Preview::META_KEY ] = array(
			'version'            => 2,
			'provider'           => 'filetoweb',
			'source_url'         => 'https://city.example/wp-content/uploads/current.pdf',
			'source_fingerprint' => 'current-fingerprint',
			'artifact_key'       => $root . $index,
			'artifact_url'       => 'https://storage.googleapis.com/proudcity/' . $root . $index,
			'local_artifact_key' => $index,
			'artifacts'          => array(
				array(
					'artifact_key' => $root . $asset,
					'artifact_url' => 'https://storage.googleapis.com/proudcity/' . $root . $asset,
				),
				array(
					'artifact_key' => $root . $index,
					'artifact_url' => 'https://storage.googleapis.com/proudcity/' . $root . $index,
				),
			),
		);

		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'do_action' )->alias(
			function ( $hook, $name ) use ( &$synced ) {
				if ( 'sm:sync::syncFile' === $hook ) {
					$synced[] = $name;
				}
			}
		);

		$this->assertTrue( Proud_HTML_Preview::migrate_existing_post( 60 ) );

		$record = $this->meta[60][ Proud_HTML_Preview::META_KEY ];
		$this->assertSame( Proud_HTML_Preview::SCHEMA_VERSION, $record['version'] );
		$this->assertSame( Proud_HTML_Preview::STORAGE_BACKEND_STATELESS, $record['storage_backend'] );
		$this->assertSame( (string) Proud_HTML_Preview::SCHEMA_VERSION, $this->meta[60][ Proud_HTML_Preview::META_STORAGE_SCHEMA ] );
		$this->assertSame( array( $root . $asset, $root . $index ), $this->stateless_client->checked );
		$this->assertSame( array(), $synced );
		$this->assertSame( array(), $this->requests );
	}

	public function test_automatic_migration_handler_clears_legacy_event_without_querying_posts(): void {
		$this->options[ Proud_HTML_Preview::OPTION_MIGRATION_VERSION ] = '2';

		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Proud_HTML_Preview::MIGRATION_HOOK )->andReturn( true );

		Proud_HTML_Preview::migrate_legacy_batch();

		$this->assertSame( Proud_HTML_Preview::MIGRATION_VERSION, $this->options[ Proud_HTML_Preview::OPTION_MIGRATION_VERSION ] );
	}

	public function test_init_cleanup_reads_cron_without_querying_posts_or_rewriting_current_version(): void {
		$this->options[ Proud_HTML_Preview::OPTION_MIGRATION_VERSION ] = Proud_HTML_Preview::MIGRATION_VERSION;

		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_next_scheduled' )->once()->with( Proud_HTML_Preview::MIGRATION_HOOK )->andReturn( false );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		Functions\expect( 'update_option' )->never();

		Proud_HTML_Preview::disable_automatic_migration();

		$this->assertSame( Proud_HTML_Preview::MIGRATION_VERSION, $this->options[ Proud_HTML_Preview::OPTION_MIGRATION_VERSION ] );
	}

	public function test_legacy_scheduler_entrypoint_only_clears_existing_event(): void {
		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_next_scheduled' )->once()->with( Proud_HTML_Preview::MIGRATION_HOOK )->andReturn( 1700000000 );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Proud_HTML_Preview::MIGRATION_HOOK )->andReturn( true );

		Proud_HTML_Preview::schedule_migration();

		$this->assertSame( Proud_HTML_Preview::MIGRATION_VERSION, $this->options[ Proud_HTML_Preview::OPTION_MIGRATION_VERSION ] );
	}

	public function test_activation_force_clears_migration_without_reading_cron_array(): void {
		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();
		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Proud_HTML_Preview::MIGRATION_HOOK )->andReturn( true );

		Proud_HTML_Preview::activate();

		$this->assertSame( Proud_HTML_Preview::MIGRATION_VERSION, $this->options[ Proud_HTML_Preview::OPTION_MIGRATION_VERSION ] );
	}

	public function test_schema_one_bundle_recovers_from_gcs_without_filetoweb_reconversion(): void {
		$bundle = 'filetoweb-integration/previews/61/legacyfingerprint';
		$asset  = $bundle . '/assets/logo.png';
		$index  = $bundle . '/index.html';
		$synced = array();
		$this->meta[61] = array(
			Proud_HTML_Preview::META_KEY => array(
				'version'            => 1,
				'provider'           => 'filetoweb',
				'source_url'         => 'https://city.example/wp-content/uploads/legacy.pdf',
				'source_fingerprint' => 'legacy-fingerprint',
				'artifact_key'       => $index,
				'artifact_url'       => 'https://city.example/wp-content/uploads/' . $index,
				'artifacts'          => array(
					array(
						'artifact_key' => $asset,
						'artifact_url' => 'https://city.example/wp-content/uploads/' . $asset,
					),
					array(
						'artifact_key' => $index,
						'artifact_url' => 'https://city.example/wp-content/uploads/' . $index,
					),
				),
				'token'              => 'legacy-token',
				'published_at'       => '2026-08-21 11:52:50',
			),
			Document_State::META_LOCAL_HTML_SOURCE_URL => 'https://filetoweb.com/d/demo/continuous?chrome=0',
		);
		$this->stateless_client->objects = array(
			$asset => 'PNG',
			$index => '<html><body><img src="https://city.example/wp-content/uploads/' . $asset . '"></body></html>',
		);

		Functions\when( 'has_action' )->justReturn( 1 );
		Functions\when( 'do_action' )->alias(
			function ( $hook, $name ) use ( &$synced ) {
				if ( 'sm:sync::syncFile' === $hook ) {
					$synced[] = $name;
				}
			}
		);

		$this->assertTrue( Proud_HTML_Preview::migrate_existing_post( 61 ) );

		$record = $this->meta[61][ Proud_HTML_Preview::META_KEY ];
		$this->assertSame( Proud_HTML_Preview::SCHEMA_VERSION, $record['version'] );
		$this->assertSame( Proud_HTML_Preview::STORAGE_BACKEND_STATELESS, $record['storage_backend'] );
		$this->assertSame( $index, $record['local_artifact_key'] );
		$this->assertSame( 'oakwoodohio/2026/08/' . $index, $record['artifact_key'] );
		$this->assertSame( (string) Proud_HTML_Preview::SCHEMA_VERSION, $this->meta[61][ Proud_HTML_Preview::META_STORAGE_SCHEMA ] );
		$this->assertSame( array(), $this->requests );
		$this->assertEqualsCanonicalizing( array( $asset, $index ), $synced );
		$this->assertEqualsCanonicalizing(
			array(
				'oakwoodohio/2026/08/' . $asset,
				'oakwoodohio/2026/08/' . $index,
			),
			$this->stateless_client->checked
		);
		$this->assertSame( array( $asset, $index ), array_column( $record['legacy_artifacts'], 'artifact_key' ) );

		$recovered = file_get_contents( $this->uploads_dir . '/' . $index );
		$this->assertStringContainsString(
			'https://storage.googleapis.com/proudcity/oakwoodohio/2026/08/' . $asset,
			$recovered
		);
		$this->assertStringNotContainsString( 'https://city.example/wp-content/uploads/' . $asset, $recovered );
	}

	public function test_explicit_disable_changes_provider_but_deactivation_does_not(): void {
		Proud_HTML_Preview::settings_updated(
			$this->options[ Settings::OPTION_SETTINGS ],
			array( Settings::KEY_REPLACE_LINKS => '0' ),
			Settings::OPTION_SETTINGS
		);
		$this->assertFalse( $this->options[ Proud_HTML_Preview::OPTION_PROVIDERS ]['filetoweb'] );

		$this->options[ Proud_HTML_Preview::OPTION_PROVIDERS ]['filetoweb'] = true;
		Functions\expect( 'wp_next_scheduled' )->never();
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Cron::HOOK_POLL_PENDING )->andReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Bulk_Queue::HOOK_PROCESS )->andReturn( true );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Proud_HTML_Preview::MIGRATION_HOOK )->andReturn( true );
		Plugin::deactivate();

		$this->assertTrue( $this->options[ Proud_HTML_Preview::OPTION_PROVIDERS ]['filetoweb'] );
	}

	public function test_attachment_backed_document_reuses_attachment_preview_record(): void {
		$this->meta[44] = array(
			Document_State::META_STATUS => 'ready',
			Proud_HTML_Preview::META_KEY => array(
				'provider' => 'filetoweb',
				'token'    => 'attachment-token',
			),
		);

		Document_State::copy_state( 44, 99 );

		$this->assertSame( 'ready', $this->meta[99][ Document_State::META_STATUS ] );
		$this->assertArrayNotHasKey( Proud_HTML_Preview::META_KEY, $this->meta[99] );
		$this->assertSame( 'attachment-token', $this->meta[44][ Proud_HTML_Preview::META_KEY ]['token'] );
	}

	public function test_meeting_materials_publish_independent_records(): void {
		$agenda = Proud_HTML_Preview::publish(
			46,
			'<html><body>Agenda</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/agenda.pdf',
			'agenda-fingerprint'
		);
		$minutes = Proud_HTML_Preview::publish(
			47,
			'<html><body>Minutes</body></html>',
			'https://filetoweb.com/d/demo/continuous?chrome=0',
			'https://city.example/wp-content/uploads/minutes.pdf',
			'minutes-fingerprint'
		);

		$this->assertNotSame( $agenda['artifact_key'], $minutes['artifact_key'] );
		$this->assertStringContainsString( '/46/', $agenda['artifact_key'] );
		$this->assertStringContainsString( '/47/', $minutes['artifact_key'] );
	}

	private function remove_dir( $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( glob( rtrim( $dir, '/' ) . '/*' ) ?: array() as $path ) {
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
