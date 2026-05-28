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
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'FileToWeb\Integration\gethostbynamel' )->alias(
			function ( $host ) {
				if ( 'private-ipv4.example' === $host ) {
					return array( '10.0.0.5' );
				}

				if ( 'example.test' === $host || 'public.example' === $host || 'private-ipv6.example' === $host ) {
					return array( '93.184.216.34' );
				}

				return false;
			}
		);
		Functions\when( 'FileToWeb\Integration\dns_get_record' )->alias(
			function ( $host, $type ) {
				if ( defined( 'DNS_AAAA' ) && DNS_AAAA === $type && 'private-ipv6.example' === $host ) {
					return array(
						array(
							'ipv6' => '::1',
						),
					);
				}

				return array();
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'site_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test' . $path;
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
		$this->assertFalse( Security::is_safe_source_url( 'https://user:pass@example.test/file.pdf' ) );
	}

	public function test_source_url_allows_current_site_hostname(): void {
		$this->assertTrue( Security::is_safe_source_url( 'https://example.test/wp-content/uploads/file.pdf' ) );
	}

	public function test_source_url_rejects_private_dns_records(): void {
		$this->assertFalse( Security::is_safe_source_url( 'https://private-ipv4.example/file.pdf' ) );
		$this->assertFalse( Security::is_safe_source_url( 'https://private-ipv6.example/file.pdf' ) );
	}

	public function test_filetoweb_url_requires_allowed_https_host(): void {
		$this->assertSame( 'https://filetoweb.com/d/demo/1', Security::sanitize_filetoweb_url( 'https://filetoweb.com/d/demo/1' ) );
		$this->assertSame( '', Security::sanitize_filetoweb_url( 'http://filetoweb.com/d/demo/1' ) );
		$this->assertSame( '', Security::sanitize_filetoweb_url( 'https://evil.example/d/demo/1' ) );
	}
}
