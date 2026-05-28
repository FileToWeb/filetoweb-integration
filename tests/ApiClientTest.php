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
}
