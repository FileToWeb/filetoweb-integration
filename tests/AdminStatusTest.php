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

	/**
	 * Additional FileToWeb metadata returned by post meta.
	 *
	 * @var array
	 */
	private $meta = array();

	/**
	 * Whether the integration is configured and the current user can sync.
	 *
	 * @var bool
	 */
	private $can_sync = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->status = 'ready';
		$this->meta    = array();
		$this->can_sync = false;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'absint' )->alias(
				function ( $value ) {
					return abs( intval( $value ) );
				}
			);
			Functions\when( 'current_user_can' )->alias(
				function () {
					return $this->can_sync;
				}
			);
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
			Functions\when( 'admin_url' )->alias(
				function ( $path = '' ) {
					return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' );
				}
			);
			Functions\when( 'wp_nonce_url' )->alias(
				function ( $url ) {
					return $url . '&_wpnonce=test';
				}
			);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => $this->can_sync ? '1' : '0',
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => $this->can_sync ? 'ftw_api_test' : '',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $default;
			}
		);
			Functions\when( 'get_post_meta' )->alias(
				function ( $post_id, $key ) {
					if ( Document_State::META_STATUS === $key ) {
						return $this->status;
					}

					return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
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

	public function test_failed_document_shows_safe_reference_and_retry_action(): void {
		$this->status   = 'failed';
		$this->can_sync = true;
		$this->meta     = array(
			Document_State::META_DOCUMENT_ID     => 'doc-123',
			Document_State::META_LAST_ERROR      => 'FileToWeb could not finish processing this PDF. Please try again.',
			Document_State::META_ERROR_REFERENCE => 'FTW-A31C82F4D019',
		);

		$post            = new stdClass();
		$post->ID        = 123;
		$post->post_type = 'page';

		ob_start();
		Admin::render_status_meta_box( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Retry processing', $html );
		$this->assertStringContainsString( 'FTW-A31C82F4D019', $html );
		$this->assertStringContainsString( 'filetoweb_integration_retry_processing', $html );
		$this->assertStringNotContainsString( 'pdf_generator', $html );
		$this->assertStringNotContainsString( 'Vertex', $html );
		$this->assertStringNotContainsString( 'Gemini', $html );
	}

	public function test_connection_notice_names_the_workspace_and_folder(): void {
		$notice = Admin::format_connection_notice(
			array(
				'ok'   => true,
				'body' => array(
					'account' => array( 'name' => 'Delaware County' ),
					'project' => array( 'name' => 'Website PDFs' ),
					'scopes'  => array( 'documents:read', 'documents:write' ),
				),
			)
		);

		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( 'Delaware County', $notice['message'] );
		$this->assertStringContainsString( 'Website PDFs', $notice['message'] );
	}

	public function test_connection_notice_reports_api_errors_without_a_key(): void {
		$notice = Admin::format_connection_notice(
			array(
				'ok'    => false,
				'error' => 'Invalid API key',
			)
		);

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'Invalid API key', $notice['message'] );
		$this->assertStringNotContainsString( 'ftw_api_', $notice['message'] );
	}

	public function test_connection_notice_rejects_a_read_only_key(): void {
		$notice = Admin::format_connection_notice(
			array(
				'ok'   => true,
				'body' => array(
					'account' => array( 'name' => 'Delaware County' ),
					'project' => array( 'name' => 'Website PDFs' ),
					'scopes'  => array( 'documents:read' ),
				),
			)
		);

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'read and write permissions', $notice['message'] );
	}
}
