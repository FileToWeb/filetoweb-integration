<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Accessibility_Attribution;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class AccessibilityAttributionTest extends TestCase {
	/**
	 * Whether FileToWeb is enabled for the current test.
	 *
	 * @var string
	 */
	private $enabled = '1';

	/**
	 * Current page slug being requested.
	 *
	 * @var string
	 */
	private $page_slug = 'accessibility-statement';

	/**
	 * Whether current request is admin.
	 *
	 * @var bool
	 */
	private $is_admin = false;

	/**
	 * Whether current request is feed.
	 *
	 * @var bool
	 */
	private $is_feed = false;

	/**
	 * Whether current request is JSON/REST.
	 *
	 * @var bool
	 */
	private $is_json_request = false;

	/**
	 * Disable attribution through the filter.
	 *
	 * @var bool
	 */
	private $disable_attribution = false;

	/**
	 * Custom attribution HTML.
	 *
	 * @var string
	 */
	private $custom_html = '';

	/**
	 * Custom accessibility slugs.
	 *
	 * @var array|null
	 */
	private $custom_slugs = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->enabled             = '1';
		$this->page_slug           = 'accessibility-statement';
		$this->is_admin            = false;
		$this->is_feed             = false;
		$this->is_json_request     = false;
		$this->disable_attribution = false;
		$this->custom_html         = '';
		$this->custom_slugs        = null;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			function ( $value ) {
				$value = strtolower( trim( (string) $value ) );
				$value = preg_replace( '/[^a-z0-9_\-]+/', '-', $value );
				return trim( $value, '-' );
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'is_admin' )->alias(
			function () {
				return $this->is_admin;
			}
		);
		Functions\when( 'is_feed' )->alias(
			function () {
				return $this->is_feed;
			}
		);
		Functions\when( 'wp_is_json_request' )->alias(
			function () {
				return $this->is_json_request;
			}
		);
		Functions\when( 'is_page' )->alias(
			function ( $slug ) {
				return $this->page_slug === $slug;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( Settings::OPTION_SETTINGS === $name ) {
					return array(
						Settings::KEY_ENABLED       => $this->enabled,
						Settings::KEY_API_BASE_URL  => 'https://filetoweb.com',
						Settings::KEY_API_KEY       => 'ftw_api_test',
						Settings::KEY_REPLACE_LINKS => '1',
						Settings::KEY_EPUB_DOWNLOAD => '0',
						Settings::KEY_BATCH_SIZE    => 25,
					);
				}

				return $default;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( 'filetoweb_integration_show_accessibility_attribution' === $tag ) {
					return ! $this->disable_attribution;
				}

				if ( 'filetoweb_integration_accessibility_statement_slugs' === $tag && null !== $this->custom_slugs ) {
					return $this->custom_slugs;
				}

				if ( 'filetoweb_integration_accessibility_attribution_html' === $tag && $this->custom_html ) {
					return $this->custom_html;
				}

				return $value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_inserts_attribution_after_pdf_documents_section(): void {
		$content = '<p>Intro</p><h2 class="h3">PDF documents</h2><p>Some PDFs require plug-ins.</p><ul><li><a href="https://get.adobe.com/reader/">Download Acrobat Reader</a></li></ul><h2 class="h3">Accessibility links</h2>';

		$rewritten = Accessibility_Attribution::filter_content( $content );

		$this->assertStringContainsString( 'filetoweb-accessibility-attribution', $rewritten );
		$this->assertStringContainsString( 'https://filetoweb.com/', $rewritten );
		$this->assertStringContainsString( 'different screen sizes', $rewritten );
		$this->assertLessThan(
			strpos( $rewritten, 'filetoweb-accessibility-attribution' ),
			strpos( $rewritten, 'Download Acrobat Reader' )
		);
		$this->assertLessThan(
			strpos( $rewritten, 'Accessibility links' ),
			strpos( $rewritten, 'filetoweb-accessibility-attribution' )
		);
	}

	public function test_appends_attribution_when_pdf_section_shape_is_unknown(): void {
		$content = '<p>Accessibility statement without expected PDF section markup.</p>';

		$rewritten = Accessibility_Attribution::filter_content( $content );

		$this->assertStringEndsWith( '</p>', $rewritten );
		$this->assertStringContainsString( $content . "\n" . '<p class="filetoweb-accessibility-attribution">', $rewritten );
	}

	public function test_does_not_insert_outside_accessibility_statement_page(): void {
		$this->page_slug = 'about';
		$content         = '<h2>PDF documents</h2><p>PDF text.</p><ul><li>Reader</li></ul>';

		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );
	}

	public function test_does_not_insert_when_disabled_or_non_public_context(): void {
		$content = '<h2>PDF documents</h2><p>PDF text.</p><ul><li>Reader</li></ul>';

		$this->enabled = '0';
		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );

		$this->enabled  = '1';
		$this->is_admin = true;
		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );

		$this->is_admin = false;
		$this->is_feed  = true;
		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );

		$this->is_feed         = false;
		$this->is_json_request = true;
		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );
	}

	public function test_does_not_duplicate_existing_attribution(): void {
		$content = '<p>Already mentions <a href="https://filetoweb.com/">FileToWeb</a>.</p>';

		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );
	}

	public function test_respects_disable_custom_slug_and_custom_html_filters(): void {
		$content = '<h2>PDF documents</h2><p>PDF text.</p><ul><li>Reader</li></ul>';

		$this->disable_attribution = true;
		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );

		$this->disable_attribution = false;
		$this->custom_slugs        = array( 'accessibility' );
		$this->assertSame( $content, Accessibility_Attribution::filter_content( $content ) );

		$this->page_slug   = 'accessibility';
		$this->custom_html = '<p class="custom-ftw">Custom FileToWeb attribution.</p>';
		$rewritten         = Accessibility_Attribution::filter_content( $content );

		$this->assertStringContainsString( '<p class="custom-ftw">Custom FileToWeb attribution.</p>', $rewritten );
	}
}
