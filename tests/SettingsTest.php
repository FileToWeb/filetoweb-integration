<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	public function test_api_base_url_only_allows_filetoweb_https(): void {
		$this->assertTrue( Settings::is_api_base_url_allowed( 'https://filetoweb.com' ) );
		$this->assertFalse( Settings::is_api_base_url_allowed( 'http://filetoweb.com' ) );
		$this->assertFalse( Settings::is_api_base_url_allowed( 'https://evil.example' ) );
	}

	public function test_empty_api_key_preserves_previous_key(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return Settings::OPTION_API_KEY === $name ? 'ftw_api_existing' : $default;
			}
		);

		$this->assertSame( 'ftw_api_existing', Settings::sanitize_api_key( '' ) );
	}
}
