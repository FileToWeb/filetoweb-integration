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
			Functions\when( 'sanitize_key' )->alias(
				function ( $value ) {
					return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
				}
			);
			Functions\when( 'apply_filters' )->alias(
				function ( $tag, $value ) {
					return $value;
				}
			);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

		public function test_editor_like_user_can_sync_editable_post(): void {
			Functions\when( 'current_user_can' )->alias(
				function ( $capability, $post_id = null ) {
					if ( 'edit_post' === $capability && 123 === $post_id ) {
						return true;
					}

					return 'edit_others_posts' === $capability;
				}
			);

		$this->assertTrue( Admin::can_sync_post( 123 ) );
	}

		public function test_user_cannot_sync_without_sync_capability(): void {
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
					return 'edit_others_posts' === $capability;
				}
			);

		$this->assertFalse( Admin::can_sync_post( 456 ) );
	}

		public function test_activate_plugins_can_manage_global_settings(): void {
			Functions\when( 'current_user_can' )->alias(
				function ( $capability ) {
					return 'activate_plugins' === $capability;
				}
			);

		$this->assertTrue( Admin::can_manage_settings() );
	}
}
