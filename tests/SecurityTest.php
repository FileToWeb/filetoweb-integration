<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Security;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
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
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_API_BASE_URL === $name ) {
					return 'https://filetoweb.com';
				}

				return $default;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_source_url_rejects_localhost(): void {
		$this->assertFalse( Security::is_safe_source_url( 'http://localhost/file.pdf' ) );
		$this->assertFalse( Security::is_safe_source_url( 'http://127.0.0.1/file.pdf' ) );
		$this->assertFalse( Security::is_safe_source_url( 'http://10.0.0.1/file.pdf' ) );
	}

	public function test_filetoweb_url_requires_allowed_https_host(): void {
		$this->assertSame( 'https://filetoweb.com/d/demo/1', Security::sanitize_filetoweb_url( 'https://filetoweb.com/d/demo/1' ) );
		$this->assertSame( '', Security::sanitize_filetoweb_url( 'http://filetoweb.com/d/demo/1' ) );
		$this->assertSame( '', Security::sanitize_filetoweb_url( 'https://evil.example/d/demo/1' ) );
	}
}
