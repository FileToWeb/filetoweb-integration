<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Api_Client;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
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

	public function test_requests_reject_unsafe_urls(): void {
		$response = array( 'body' => '{}' );

		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://filetoweb.com/v1/documents/demo',
				\Mockery::on(
					function ( $args ) {
						return is_array( $args )
							&& ! empty( $args['reject_unsafe_urls'] )
							&& 20 === $args['timeout']
							&& 'GET' === $args['method'];
					}
				)
			)
			->andReturn( $response );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$result = Api_Client::get_document( 'demo' );

		$this->assertTrue( $result['ok'] );
	}

	public function test_initial_url_import_allows_the_bounded_45_second_window(): void {
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://filetoweb.com/v1/documents',
				\Mockery::on(
					function ( $args ) {
						return 'POST' === $args['method'] && 45 === $args['timeout'];
					}
				)
			)
			->andReturn( array( 'body' => '{"document":{"status":"processing"}}' ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"document":{"status":"processing"}}' );

		$result = Api_Client::upsert_document( array( 'external_id' => 'wordpress:test:attachment:1' ) );

		$this->assertTrue( $result['ok'] );
	}

	public function test_auth_context_uses_the_stored_bearer_key(): void {
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://filetoweb.com/v1/auth/context',
				\Mockery::on(
					function ( $args ) {
						return is_array( $args )
							&& 'GET' === $args['method']
							&& 'Bearer ftw_api_test' === $args['headers']['Authorization']
							&& ! empty( $args['reject_unsafe_urls'] );
					}
				)
			)
			->andReturn( array( 'body' => '{"account":{"name":"Pilot"},"project":{"name":"WordPress"}}' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"account":{"name":"Pilot"},"project":{"name":"WordPress"}}' );

		$result = Api_Client::get_auth_context();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Pilot', $result['body']['account']['name'] );
		$this->assertSame( 'WordPress', $result['body']['project']['name'] );
	}

	public function test_html_api_errors_are_reduced_to_the_http_status(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '<!doctype html><html><body>Not Found</body></html>' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$result = Api_Client::get_auth_context();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'FileToWeb API returned HTTP 404.', $result['error'] );
		$this->assertStringNotContainsString( '<html', $result['error'] );
	}

	public function test_reprocess_document_calls_explicit_retry_endpoint(): void {
		Functions\expect( 'wp_remote_request' )
			->once()
			->with(
				'https://filetoweb.com/v1/documents/doc-123/reprocess',
				\Mockery::on(
					function ( $args ) {
						return is_array( $args )
							&& 'POST' === $args['method']
							&& '[]' === $args['body'];
					}
				)
			)
			->andReturn( array() );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"document":{"status":"processing"}}' );

		$result = Api_Client::reprocess_document( 'doc-123' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'processing', $result['body']['document']['status'] );
	}

	public function test_structured_server_error_preserves_safe_support_fields_only(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'{"error":{"code":"service_unavailable","message":"FileToWeb could not complete this request. Please try again.","reference":"FTW-01AB23CD45EF","retryable":true}}'
		);

		$result = Api_Client::get_document( 'doc-123' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'service_unavailable', $result['error_code'] );
		$this->assertSame( 'FTW-01AB23CD45EF', $result['reference'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertStringNotContainsString( 'gemini', strtolower( $result['error'] ) );
		$this->assertStringNotContainsString( 'vertex', strtolower( $result['error'] ) );
	}

	public function test_legacy_internal_error_is_replaced_before_admin_storage(): void {
		Functions\expect( 'wp_remote_request' )->once()->andReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'{"error":{"message":"pdf_generator_v2 call_gemini failed in Vertex"}}'
		);

		$result = Api_Client::get_document( 'doc-123' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'FileToWeb could not complete this request. Please try again later.', $result['error'] );
		$this->assertSame( 'service_unavailable', $result['error_code'] );
		$this->assertTrue( $result['retryable'] );
	}
}
