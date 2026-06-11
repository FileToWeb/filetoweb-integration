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
		Functions\when( '__' )->returnArg();
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	public function test_registers_one_settings_option_row(): void {
		Functions\expect( 'register_setting' )
			->once()
			->with(
				'filetoweb_integration',
				Settings::OPTION_SETTINGS,
				\Mockery::on(
					function ( $args ) {
						return is_array( $args )
							&& 'array' === $args['type']
							&& array( Settings::class, 'sanitize_settings' ) === $args['sanitize_callback'];
					}
				)
			);

		Settings::register_settings();
		$this->addToAssertionCount( 1 );
	}

	public function test_api_base_url_only_allows_filetoweb_https(): void {
		$this->assertTrue( Settings::is_api_base_url_allowed( 'https://filetoweb.com' ) );
		$this->assertFalse( Settings::is_api_base_url_allowed( 'http://filetoweb.com' ) );
		$this->assertFalse( Settings::is_api_base_url_allowed( 'https://evil.example' ) );
	}

	public function test_epub_download_is_disabled_by_default(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array();
				}

				return $default;
			}
		);

		$this->assertFalse( Settings::epub_download_enabled() );
	}

	public function test_settings_are_sanitized_into_one_option_array(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => '1',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_existing',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_EPUB_DOWNLOAD => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $default;
			}
		);

		$settings = Settings::sanitize_settings(
			array(
				Settings::KEY_ENABLED       => '1',
				Settings::KEY_API_BASE_URL  => 'https://evil.example',
				Settings::KEY_API_KEY       => '',
				Settings::KEY_REPLACE_LINKS => '0',
				Settings::KEY_EPUB_DOWNLOAD => '0',
				Settings::KEY_BATCH_SIZE    => 500,
			)
		);

		$this->assertSame( '1', $settings[ Settings::KEY_ENABLED ] );
		$this->assertSame( 'https://filetoweb.com', $settings[ Settings::KEY_API_BASE_URL ] );
		$this->assertSame( 'ftw_api_existing', $settings[ Settings::KEY_API_KEY ] );
		$this->assertSame( '0', $settings[ Settings::KEY_REPLACE_LINKS ] );
		$this->assertSame( '0', $settings[ Settings::KEY_EPUB_DOWNLOAD ] );
		$this->assertSame( 100, $settings[ Settings::KEY_BATCH_SIZE ] );
	}

	public function test_empty_api_key_preserves_previous_key(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array( Settings::KEY_API_KEY => 'ftw_api_existing' );
				}

				return $default;
			}
		);

		$this->assertSame( 'ftw_api_existing', Settings::sanitize_api_key( '' ) );
	}

	public function test_clear_api_key_checkbox_removes_key(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array( Settings::KEY_API_KEY => 'ftw_api_existing' );
				}

				return $default;
			}
		);
		$_POST['filetoweb_integration_clear_api_key'] = '1';

		$this->assertSame( '', Settings::sanitize_api_key( '' ) );
	}

	public function test_array_clear_api_key_checkbox_is_ignored(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array( Settings::KEY_API_KEY => 'ftw_api_existing' );
				}

				return $default;
			}
		);
		$_POST['filetoweb_integration_clear_api_key'] = array( '1' );

		$this->assertSame( 'ftw_api_existing', Settings::sanitize_api_key( '' ) );
	}

	public function test_legacy_options_migrate_into_one_settings_row(): void {
		$updated = array();
		$deleted = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$values = array(
					Settings::OPTION_SETTINGS              => null,
					Settings::LEGACY_OPTION_ENABLED       => '1',
					Settings::LEGACY_OPTION_API_BASE_URL  => 'https://filetoweb.com',
					Settings::LEGACY_OPTION_API_KEY       => 'ftw_api_legacy',
					Settings::LEGACY_OPTION_REPLACE_LINKS => '0',
					Settings::LEGACY_OPTION_BATCH_SIZE    => '17',
				);

				return array_key_exists( $name, $values ) ? $values[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$updated ) {
				$updated[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;
				return true;
			}
		);

		Settings::migrate_legacy_options();

		$this->assertSame( 'ftw_api_legacy', $updated[ Settings::OPTION_SETTINGS ][ Settings::KEY_API_KEY ] );
		$this->assertSame( 17, $updated[ Settings::OPTION_SETTINGS ][ Settings::KEY_BATCH_SIZE ] );
		$this->assertContains( Settings::LEGACY_OPTION_API_KEY, $deleted );
	}

	public function test_epub_default_off_migration_disables_existing_epub_once(): void {
		$updated = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				$values = array(
					Settings::OPTION_EPUB_DEFAULT_OFF_MIGRATED => '',
					Settings::OPTION_SETTINGS                  => array(
						Settings::KEY_ENABLED       => '1',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_existing',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_EPUB_DOWNLOAD => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					),
				);

				return array_key_exists( $name, $values ) ? $values[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$updated ) {
				$updated[ $name ] = $value;
				return true;
			}
		);

		Settings::migrate_epub_default_off();

		$this->assertSame( '0', $updated[ Settings::OPTION_SETTINGS ][ Settings::KEY_EPUB_DOWNLOAD ] );
		$this->assertSame( '1', $updated[ Settings::OPTION_EPUB_DEFAULT_OFF_MIGRATED ] );
	}

	public function test_epub_default_off_migration_does_not_rerun_after_marker(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_EPUB_DEFAULT_OFF_MIGRATED === $name ) {
					return '1';
				}

				return $default;
			}
		);
		Functions\expect( 'update_option' )->never();

		Settings::migrate_epub_default_off();
		$this->addToAssertionCount( 1 );
	}
}
