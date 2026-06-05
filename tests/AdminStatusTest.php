<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Admin;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class AdminStatusTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'absint' )->alias(
				function ( $value ) {
					return abs( intval( $value ) );
				}
			);
			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\when( 'apply_filters' )->alias(
				function ( $tag, $value ) {
					return $value;
				}
			);
			Functions\when( 'trailingslashit' )->alias(
				function ( $value ) {
					return rtrim( (string) $value, '/' ) . '/';
				}
			);
			Functions\when( 'wp_upload_dir' )->justReturn(
				array(
					'basedir' => sys_get_temp_dir(),
				)
			);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => '0',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => '',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $default;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				return Document_State::META_STATUS === $key ? 'ready' : '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ready_status_renders_prominent_admin_summary(): void {
		$post            = new stdClass();
		$post->ID        = 123;
		$post->post_type = 'page';

		ob_start();
		Admin::render_status_meta_box( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'filetoweb-status-alert', $html );
		$this->assertStringContainsString( 'Ready', $html );
		$this->assertStringContainsString( 'Generated HTML is ready for public replacement.', $html );
	}
}
