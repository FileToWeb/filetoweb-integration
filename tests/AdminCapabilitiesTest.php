<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Admin;
use PHPUnit\Framework\TestCase;

class AdminCapabilitiesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( intval( $value ) );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_author_like_user_can_sync_owned_upload(): void {
		Functions\when( 'current_user_can' )->alias(
			function ( $capability, $post_id = null ) {
				if ( 'edit_post' === $capability && 123 === $post_id ) {
					return true;
				}

				return 'upload_files' === $capability;
			}
		);

		$this->assertTrue( Admin::can_sync_post( 123 ) );
	}

	public function test_user_cannot_sync_without_upload_capability(): void {
		Functions\when( 'current_user_can' )->alias(
			function ( $capability, $post_id = null ) {
				return 'edit_post' === $capability && 123 === $post_id;
			}
		);

		$this->assertFalse( Admin::can_sync_post( 123 ) );
	}

	public function test_user_cannot_sync_post_they_cannot_edit(): void {
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return 'upload_files' === $capability;
			}
		);

		$this->assertFalse( Admin::can_sync_post( 456 ) );
	}

	public function test_only_manage_options_can_manage_global_settings(): void {
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return 'manage_options' === $capability;
			}
		);

		$this->assertTrue( Admin::can_manage_settings() );
	}
}
