<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class DocumentStateTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
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
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-05-04 00:00:00' );
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
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_api_document_state_sanitizes_urls_before_storage(): void {
		$stored = array();

		Functions\expect( 'update_post_meta' )
			->atLeast()
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$stored ) {
					$stored[ $key ] = $value;
					return true;
				}
			);

		Document_State::write_from_api(
			123,
			array(
				'id'             => 'doc_123',
				'external_id'    => 'wordpress:test:attachment:123',
				'status'         => 'ready',
				'html_url'       => 'javascript:alert(1)',
				'continuous_url' => 'https://filetoweb.com/d/doc/continuous',
				'editor_url'     => 'https://evil.example/editor',
				'page_count'     => '3<script>',
				'error'          => '<script>alert(1)</script>',
			),
			array(
				'external_id'           => 'wordpress:test:attachment:123',
				'source_url'            => 'https://example.com/file.pdf',
				'fingerprint'           => 'abc123',
				'fingerprint_algorithm' => 'sha256',
			)
		);

		$this->assertSame( '', $stored[ Document_State::META_HTML_URL ] );
		$this->assertSame( 'https://filetoweb.com/d/doc/continuous', $stored[ Document_State::META_CONTINUOUS_URL ] );
		$this->assertSame( '', $stored[ Document_State::META_EDITOR_URL ] );
		$this->assertSame( 3, $stored[ Document_State::META_PAGE_COUNT ] );
		$this->assertSame( '', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_CODE ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_REFERENCE ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_RETRYABLE ] );
	}

	public function test_failed_document_stores_safe_structured_failure(): void {
		$stored = array();

		Functions\expect( 'update_post_meta' )
			->atLeast()
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$stored ) {
					$stored[ $key ] = $value;
					return true;
				}
			);

		Document_State::write_polled_state(
			123,
			array(
				'status'  => 'failed',
				'error'   => 'pdf_generator_v2 leaked internal detail',
				'failure' => array(
					'code'      => 'processing_incomplete',
					'message'   => 'FileToWeb could not finish processing this PDF. Please try again.',
					'reference' => 'FTW-A31C82F4D019',
					'retryable' => true,
				),
			)
		);

		$this->assertSame( 'FileToWeb could not finish processing this PDF. Please try again.', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( 'processing_incomplete', $stored[ Document_State::META_ERROR_CODE ] );
		$this->assertSame( 'FTW-A31C82F4D019', $stored[ Document_State::META_ERROR_REFERENCE ] );
		$this->assertSame( '1', $stored[ Document_State::META_ERROR_RETRYABLE ] );
		$this->assertStringNotContainsString( 'generator', $stored[ Document_State::META_LAST_ERROR ] );
	}

	public function test_successful_retry_clears_previous_failure_state(): void {
		$stored = array();

		Functions\expect( 'update_post_meta' )
			->atLeast()
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$stored ) {
					$stored[ $key ] = $value;
					return true;
				}
			);

		Document_State::write_polled_state(
			123,
			array(
				'status'     => 'processing',
				'page_count' => 24,
				'error'      => null,
				'failure'    => null,
			)
		);

		$this->assertSame( '', $stored[ Document_State::META_LAST_ERROR ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_CODE ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_REFERENCE ] );
		$this->assertSame( '', $stored[ Document_State::META_ERROR_RETRYABLE ] );
	}

	public function test_pending_retry_keeps_safe_support_fields(): void {
		$stored = array();

		Functions\expect( 'update_post_meta' )
			->atLeast()
			->once()
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$stored ) {
					$stored[ $key ] = $value;
					return true;
				}
			);

		Document_State::mark_pending_retry(
			123,
			'FileToWeb could not complete this request. Please try again later.',
			'service_unavailable',
			'FTW-01AB23CD45EF',
			true
		);

		$this->assertSame( 'pending', $stored[ Document_State::META_STATUS ] );
		$this->assertSame( 'service_unavailable', $stored[ Document_State::META_ERROR_CODE ] );
		$this->assertSame( 'FTW-01AB23CD45EF', $stored[ Document_State::META_ERROR_REFERENCE ] );
		$this->assertSame( '1', $stored[ Document_State::META_ERROR_RETRYABLE ] );
	}
}
