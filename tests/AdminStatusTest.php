<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Admin;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class AdminStatusTest extends TestCase {
	/**
	 * Current FileToWeb status returned by post meta.
	 *
	 * @var string
	 */
	private $status = 'ready';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->status = 'ready';

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
					return Document_State::META_STATUS === $key ? $this->status : '';
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
		$this->assertStringNotContainsString( 'filetoweb-processing-help', $html );
		$this->assertStringNotContainsString( 'up to 10 minutes', $html );
	}

	public function test_processing_status_renders_processing_time_help(): void {
		$this->status = 'processing';

		$post            = new stdClass();
		$post->ID        = 123;
		$post->post_type = 'page';

		ob_start();
		Admin::render_status_meta_box( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Processing', $html );
		$this->assertStringContainsString( 'filetoweb-processing-help', $html );
		$this->assertStringContainsString( 'About FileToWeb processing time', $html );
		$this->assertStringContainsString( 'up to 10 minutes', $html );
		$this->assertStringContainsString( 'public links keep using the original PDF', $html );
	}

	public function test_status_badge_processing_help_only_for_processing_state(): void {
		$processing = Admin::status_badge( 'queued' );
		$ready      = Admin::status_badge( 'ready' );
		$failed     = Admin::status_badge( 'failed' );

		$this->assertStringContainsString( 'Processing', $processing );
		$this->assertStringContainsString( 'filetoweb-processing-help', $processing );
		$this->assertStringContainsString( 'up to 10 minutes', $processing );

		$this->assertStringNotContainsString( 'filetoweb-processing-help', $ready );
		$this->assertStringNotContainsString( 'filetoweb-processing-help', $failed );
	}
}
