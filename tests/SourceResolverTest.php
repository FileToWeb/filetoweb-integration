<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Source_Resolver;
use PHPUnit\Framework\TestCase;

class SourceResolverTest extends TestCase {
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

	public function test_parse_document_meta_accepts_json_and_arrays(): void {
		$this->assertSame( array( 'mime' => 'application/pdf' ), Source_Resolver::parse_document_meta( '{"mime":"application/pdf"}' ) );
		$this->assertSame( array( 'fid' => 123 ), Source_Resolver::parse_document_meta( array( 'fid' => 123 ) ) );
	}

	public function test_parse_document_meta_rejects_invalid_values(): void {
		$this->assertNull( Source_Resolver::parse_document_meta( '' ) );
		$this->assertNull( Source_Resolver::parse_document_meta( '{"broken"' ) );
	}

	public function test_preview_owner_uses_proud_document_attachment_id(): void {
		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) {
				return 10171 === $post_id ? 'document' : ( 10172 === $post_id ? 'attachment' : '' );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				if ( 10171 !== $post_id ) {
					return '';
				}

				if ( 'document' === $key ) {
					return 'https://storage.googleapis.com/proudcity/delawarecountyin/document.pdf';
				}

				return 'document_meta' === $key ? '{"fid":10172,"mime":"application/pdf"}' : '';
			}
		);

		$this->assertSame( 10172, Source_Resolver::preview_owner_post_id( 10171 ) );
	}

	public function test_preview_owner_accepts_alternate_attachment_id_key(): void {
		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) {
				return 10171 === $post_id ? 'document' : ( 10172 === $post_id ? 'attachment' : '' );
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '{"attachment_id":10172,"mime":"application/pdf"}' );

		$this->assertSame( 10172, Source_Resolver::preview_owner_post_id( 10171 ) );
	}

	public function test_preview_owner_keeps_attachmentless_document(): void {
		Functions\when( 'get_post_type' )->alias(
			function ( $post_id ) {
				return 88 === $post_id ? 'attachment' : 'document';
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				return 'document' === $key ? 'https://example.com/document.pdf' : '{"mime":"application/pdf"}';
			}
		);
		Functions\when( 'attachment_url_to_postid' )->justReturn( 88 );

		$this->assertSame( 77, Source_Resolver::preview_owner_post_id( 77 ) );
	}

	public function test_preview_owner_keeps_non_document_source(): void {
		Functions\when( 'get_post_type' )->justReturn( 'attachment' );

		$this->assertSame( 44, Source_Resolver::preview_owner_post_id( 44 ) );
	}
}
