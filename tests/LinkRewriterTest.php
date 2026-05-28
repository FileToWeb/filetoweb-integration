<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Link_Rewriter;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class LinkRewriterTest extends TestCase {
	/**
	 * Test post type map.
	 *
	 * @var array
	 */
	private $post_types = array();

	/**
	 * Queried post ID for singular tests.
	 *
	 * @var int
	 */
	private $queried_object_id = 0;

	/**
	 * Whether the current request is a single Proud Document.
	 *
	 * @var bool
	 */
	private $is_document_singular = false;

	/**
	 * Replacement URL returned by the add-on filter.
	 *
	 * @var string
	 */
	private $replacement_url = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_rewriter_cache();
		$this->post_types           = array();
		$this->queried_object_id    = 0;
		$this->is_document_singular = false;
		$this->replacement_url      = '';

		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
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
				if ( 'filetoweb_integration_ready_replacement_url' === $tag && $this->replacement_url ) {
					return $this->replacement_url;
				}

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
		Functions\when( 'get_post_type' )->alias(
			function ( $post = null ) {
				$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;

				return isset( $this->post_types[ $post_id ] ) ? $this->post_types[ $post_id ] : 'attachment';
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $post_id ) {
				$post_id = (int) $post_id;

				if ( ! $post_id ) {
					return null;
				}

				return (object) array(
					'ID'        => $post_id,
					'post_type' => isset( $this->post_types[ $post_id ] ) ? $this->post_types[ $post_id ] : 'attachment',
				);
			}
		);
		Functions\when( 'is_singular' )->alias(
			function ( $post_type = '' ) {
				return 'document' === $post_type && $this->is_document_singular;
			}
		);
		Functions\when( 'get_queried_object_id' )->alias(
			function () {
				return $this->queried_object_id;
			}
		);
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

	public function test_ready_replacement_url_filter_can_override_pdf_links(): void {
		$this->replacement_url = 'https://example.test/native-page/';

		Functions\when( 'get_posts' )->justReturn( array( 123 ) );
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

		$content = '<p><a href="https://example.test/wp-content/uploads/file.pdf">PDF</a></p>';

		$this->assertStringContainsString(
			'href="https://example.test/native-page/"',
			Link_Rewriter::filter_content_pdf_links( $content )
		);
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

	public function test_rewrites_ready_proud_document_viewer_and_preserves_download(): void {
		$this->post_types[456]        = 'document';
		$this->queried_object_id      = 456;
		$this->is_document_singular   = true;
		$source_url                   = 'https://example.test/wp-content/uploads/agenda.pdf';
		$continuous_url               = 'https://filetoweb.com/d/demo';

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( $source_url, $continuous_url ) {
				if ( 456 !== $post_id ) {
					return '';
				}

				$values = array(
					'document'                         => $source_url,
					'document_filename'                => 'agenda.pdf',
					'document_meta'                    => '{"mime":"application/pdf","size":"200 KB"}',
					Document_State::META_STATUS        => 'ready',
					Document_State::META_HTML_URL      => 'https://filetoweb.com/d/demo/1',
					Document_State::META_CONTINUOUS_URL => $continuous_url,
				);

				return isset( $values[ $key ] ) ? $values[ $key ] : '';
			}
		);

		$html = '<a href="' . $source_url . '" class="btn btn-primary btn-sm" download="agenda.pdf">Download</a>'
			. '<iframe src="//docs.google.com/gview?url=' . rawurlencode( $source_url ) . '&amp;embedded=true" title="Agenda" id="doc-preview" style="width:100%; height:900px;" frameborder="0"></iframe>';

		$rewritten = Link_Rewriter::filter_document_viewer_output( $html );

		$this->assertStringContainsString( 'href="' . $source_url . '"', $rewritten );
		$this->assertStringContainsString( 'download="agenda.pdf"', $rewritten );
		$this->assertStringContainsString( 'src="' . $continuous_url . '"', $rewritten );
		$this->assertStringNotContainsString( 'docs.google.com/gview', $rewritten );
	}

	public function test_pending_proud_document_viewer_stays_original(): void {
		$this->post_types[456]      = 'document';
		$this->queried_object_id    = 456;
		$this->is_document_singular = true;
		$source_url                 = 'https://example.test/wp-content/uploads/agenda.pdf';

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( $source_url ) {
				if ( 456 !== $post_id ) {
					return '';
				}

				$values = array(
					'document'                  => $source_url,
					'document_filename'         => 'agenda.pdf',
					'document_meta'             => '{"mime":"application/pdf","size":"200 KB"}',
					Document_State::META_STATUS => 'processing',
				);

				return isset( $values[ $key ] ) ? $values[ $key ] : '';
			}
		);

		$html = '<iframe src="//docs.google.com/gview?url=' . rawurlencode( $source_url ) . '&amp;embedded=true" title="Agenda" id="doc-preview"></iframe>';

		$this->assertSame( $html, Link_Rewriter::filter_document_viewer_output( $html ) );
	}

	public function test_non_pdf_proud_document_viewer_stays_original(): void {
		$this->post_types[456]      = 'document';
		$this->queried_object_id    = 456;
		$this->is_document_singular = true;
		$source_url                 = 'https://example.test/wp-content/uploads/agenda.docx';

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( $source_url ) {
				if ( 456 !== $post_id ) {
					return '';
				}

				$values = array(
					'document'                         => $source_url,
					'document_filename'                => 'agenda.docx',
					'document_meta'                    => '{"mime":"application/vnd.openxmlformats-officedocument.wordprocessingml.document","size":"200 KB"}',
					Document_State::META_STATUS        => 'ready',
					Document_State::META_HTML_URL      => 'https://filetoweb.com/d/demo/1',
					Document_State::META_CONTINUOUS_URL => 'https://filetoweb.com/d/demo',
				);

				return isset( $values[ $key ] ) ? $values[ $key ] : '';
			}
		);

		$html = '<iframe src="//docs.google.com/gview?url=' . rawurlencode( $source_url ) . '&amp;embedded=true" title="Agenda" id="doc-preview"></iframe>';

		$this->assertSame( $html, Link_Rewriter::filter_document_viewer_output( $html ) );
	}

	public function test_single_proud_document_meta_preserves_pdf_for_template(): void {
		$this->post_types[456]      = 'document';
		$this->queried_object_id    = 456;
		$this->is_document_singular = true;

		$this->assertNull( Link_Rewriter::filter_document_meta( null, 456, 'document', true ) );
	}

	private function reset_rewriter_cache(): void {
		$reflection = new ReflectionClass( Link_Rewriter::class );

		foreach ( array( 'ready_url_map', 'preview_url_map', 'resolved_public_urls', 'document_viewer_post_id' ) as $property_name ) {
			$property = $reflection->getProperty( $property_name );

			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			if ( 'resolved_public_urls' === $property_name ) {
				$property->setValue( null, array() );
			} elseif ( 'document_viewer_post_id' === $property_name ) {
				$property->setValue( null, 0 );
			} else {
				$property->setValue( null, null );
			}
		}
	}
}
