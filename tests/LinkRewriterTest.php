<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Link_Rewriter;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class LinkRewriterTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_rewriter_cache();

		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( strip_tags( (string) $value ) );
			}
		);
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
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test' . $path;
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
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.test/wp-content/uploads/file.pdf' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/file/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_rewrites_ready_pdf_links_and_builds_map_once(): void {
		$get_posts_calls = 0;

		Functions\when( 'get_posts' )->alias(
			function () use ( &$get_posts_calls ) {
				++$get_posts_calls;
				return array( 123 );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				if ( 123 !== $post_id ) {
					return '';
				}

				if ( Document_State::META_STATUS === $key ) {
					return 'ready';
				}

				if ( Document_State::META_HTML_URL === $key ) {
					return 'https://filetoweb.com/d/demo/1';
				}

				if ( Document_State::META_ORIGINAL_URL === $key ) {
					return 'https://example.test/wp-content/uploads/file.pdf';
				}

				return '';
			}
		);

		$content = '<p><a href="https://example.test/wp-content/uploads/file.pdf">PDF</a> <a href="https://example.test/services/">Services</a></p>';

		$rewritten = Link_Rewriter::filter_content_pdf_links( $content );
		Link_Rewriter::filter_content_pdf_links( $content );

		$this->assertStringContainsString( 'href="https://filetoweb.com/d/demo/1"', $rewritten );
		$this->assertStringContainsString( 'href="https://example.test/services/"', $rewritten );
		$this->assertSame( 1, $get_posts_calls );
	}

	public function test_non_ready_pdf_link_stays_original(): void {
		Functions\when( 'get_posts' )->justReturn( array( 123 ) );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				if ( Document_State::META_STATUS === $key ) {
					return 'processing';
				}

				if ( Document_State::META_ORIGINAL_URL === $key ) {
					return 'https://example.test/wp-content/uploads/file.pdf';
				}

				return '';
			}
		);

		$content = '<p><a href="https://example.test/wp-content/uploads/file.pdf">PDF</a></p>';

		$this->assertSame( $content, Link_Rewriter::filter_content_pdf_links( $content ) );
	}

	private function reset_rewriter_cache(): void {
		$reflection = new ReflectionClass( Link_Rewriter::class );

		foreach ( array( 'ready_url_map', 'preview_url_map', 'resolved_public_urls' ) as $property_name ) {
			$property = $reflection->getProperty( $property_name );

			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			$property->setValue( null, 'resolved_public_urls' === $property_name ? array() : null );
		}
	}
}
