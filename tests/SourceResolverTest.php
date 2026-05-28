<?php

use FileToWeb\Integration\Source_Resolver;
use PHPUnit\Framework\TestCase;

class SourceResolverTest extends TestCase {
	public function test_parse_document_meta_accepts_json_and_arrays(): void {
		$this->assertSame( array( 'mime' => 'application/pdf' ), Source_Resolver::parse_document_meta( '{"mime":"application/pdf"}' ) );
		$this->assertSame( array( 'fid' => 123 ), Source_Resolver::parse_document_meta( array( 'fid' => 123 ) ) );
	}

	public function test_parse_document_meta_rejects_invalid_values(): void {
		$this->assertNull( Source_Resolver::parse_document_meta( '' ) );
		$this->assertNull( Source_Resolver::parse_document_meta( '{"broken"' ) );
	}
}
