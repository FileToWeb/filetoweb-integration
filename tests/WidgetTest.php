<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Document_Widget;
use FileToWeb\Integration\Proud_HTML_Preview;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase {
	private $uploads_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uploads_dir = sys_get_temp_dir() . '/ftw-widget-' . uniqid();
		mkdir( $this->uploads_dir . '/filetoweb-integration', 0777, true );

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
			Functions\when( 'trailingslashit' )->alias(
				function ( $value ) {
					return rtrim( (string) $value, '/' ) . '/';
				}
			);
			Functions\when( 'wp_upload_dir' )->alias(
				function () {
					return array(
						'basedir' => $this->uploads_dir,
					);
				}
			);
			Functions\when( 'home_url' )->alias(
				function ( $path = '' ) {
					return 'https://example.test' . $path;
				}
			);
			Functions\when( 'add_query_arg' )->alias(
				function ( $args, $url ) {
					return rtrim( (string) $url, '/' ) . '/?' . http_build_query( $args );
				}
			);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );
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
		if ( $this->uploads_dir && is_dir( $this->uploads_dir ) ) {
			foreach ( glob( $this->uploads_dir . '/filetoweb-integration/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->uploads_dir . '/filetoweb-integration' );
			rmdir( $this->uploads_dir );
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_widget_renders_ready_local_html_url(): void {
		$local_path = $this->local_html_file( 123 );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( $local_path ) {
				if ( 123 !== $post_id ) {
					return '';
				}

				if ( Document_State::META_STATUS === $key ) {
					return 'ready';
				}

				if ( Document_State::META_HTML_URL === $key ) {
					return 'https://filetoweb.com/d/demo/1';
				}

				if ( Document_State::META_LOCAL_HTML_PATH === $key ) {
					return $local_path;
				}

				if ( Document_State::META_LOCAL_HTML_TOKEN === $key ) {
					return 'token-123';
				}

				return '';
			}
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.test/wp-content/uploads/file.pdf' );

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

		$this->assertStringContainsString( 'href="https://example.test/?filetoweb_local_html=123&ftw_token=token-123"', $html );
		$this->assertStringContainsString( 'Fees &lt;PDF&gt;', $html );
	}

	public function test_widget_can_embed_ready_local_html(): void {
		$local_path = $this->local_html_file( 123 );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( $local_path ) {
				if ( 123 !== $post_id ) {
					return '';
				}

				$values = array(
					Document_State::META_STATUS           => 'ready',
					Document_State::META_HTML_URL         => 'https://filetoweb.com/d/demo/1',
					Document_State::META_LOCAL_HTML_PATH  => $local_path,
					Document_State::META_LOCAL_HTML_TOKEN => 'token-123',
				);

				return isset( $values[ $key ] ) ? $values[ $key ] : '';
			}
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.test/wp-content/uploads/file.pdf' );
		Functions\when( 'esc_attr' )->returnArg();

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
				'item_ref'      => 'attachment:123',
				'title'         => 'Agenda',
				'display_mode'  => 'embed',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringContainsString( 'src="https://example.test/?filetoweb_local_html=123&ftw_token=token-123"', $html );
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

	public function test_widget_falls_back_to_original_pdf_when_public_preview_is_paused(): void {
		$local_path = $this->local_html_file( 123 );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) use ( $local_path ) {
				$values = array(
					Document_State::META_STATUS            => 'ready',
					Document_State::META_HTML_URL          => 'https://filetoweb.com/d/demo/1',
					Document_State::META_LOCAL_HTML_PATH   => $local_path,
					Document_State::META_LOCAL_HTML_TOKEN  => 'token-123',
					Proud_HTML_Preview::META_PUBLIC_PAUSED => '1',
				);

				return 123 === $post_id && isset( $values[ $key ] ) ? $values[ $key ] : '';
			}
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.test/wp-content/uploads/file.pdf' );

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
				'item_ref'     => 'attachment:123',
				'title'        => 'Agenda',
				'display_mode' => 'embed',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'href="https://example.test/wp-content/uploads/file.pdf"', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	private function local_html_file( $post_id ): string {
		$path = $this->uploads_dir . '/filetoweb-integration/' . (int) $post_id . '-local.html';
		file_put_contents( $path, '<!doctype html><html><body>Local</body></html>' );

		return $path;
	}
}
