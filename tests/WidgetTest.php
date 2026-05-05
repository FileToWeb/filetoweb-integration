<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Document_Widget;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->alias(
			function ( $value ) {
				return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
			}
		);
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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_widget_renders_ready_filetoweb_url(): void {
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

				return '';
			}
		);

		$widget = new Document_Widget();

		ob_start();
		$widget->widget(
			array(
				'before_widget' => '<section>',
				'after_widget'  => '</section>',
				'before_title'  => '<h2>',
				'after_title'   => '</h2>',
			),
			array(
				'item_ref' => 'attachment:123',
				'title'    => 'Fees <PDF>',
				'heading'  => 'Documents',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'href="https://filetoweb.com/d/demo/1"', $html );
		$this->assertStringContainsString( 'Fees &lt;PDF&gt;', $html );
	}

	public function test_widget_falls_back_to_original_pdf_when_not_ready(): void {
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				if ( Document_State::META_STATUS === $key ) {
					return 'processing';
				}

				return '';
			}
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.test/wp-content/uploads/file.pdf' );
		Functions\when( 'get_the_title' )->justReturn( 'Original PDF' );

		$widget = new Document_Widget();

		ob_start();
		$widget->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			array(
				'item_ref' => 'attachment:123',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'href="https://example.test/wp-content/uploads/file.pdf"', $html );
		$this->assertStringContainsString( 'Original PDF', $html );
	}
}
