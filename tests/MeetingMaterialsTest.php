<?php

use Brain\Monkey;
use Brain\Monkey\Functions;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Meeting_Materials;
use FileToWeb\Integration\Settings;
use PHPUnit\Framework\TestCase;

class MeetingMaterialsTest extends TestCase {
	/**
	 * Meeting post meta by post ID.
	 *
	 * @var array
	 */
	private $meeting_meta = array();

	/**
	 * Attachment URLs by attachment ID.
	 *
	 * @var array
	 */
	private $attachment_urls = array();

	/**
	 * Attachment MIME types by attachment ID.
	 *
	 * @var array
	 */
	private $attachment_mimes = array();

	/**
	 * Attachment files by attachment ID.
	 *
	 * @var array
	 */
	private $attachment_files = array();

	/**
	 * Captured FileToWeb API request bodies.
	 *
	 * @var array
	 */
	private $request_bodies = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meeting_meta     = array();
		$this->attachment_urls  = array();
		$this->attachment_mimes = array();
		$this->attachment_files = array();
		$this->request_bodies   = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			function ( $value ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				return trim( strip_tags( (string) $value ) );
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
		Functions\when( 'wp_json_encode' )->alias(
			function ( $value ) {
				return json_encode( $value );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				return $value;
			}
		);
		Functions\when( '_n' )->alias(
			function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test' . $path;
			}
		);
		Functions\when( 'site_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-06-04 12:00:00' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'FileToWeb\Integration\gethostbynamel' )->alias(
			function ( $host ) {
				if ( 'example.test' === $host ) {
					return array( '93.184.216.34' );
				}

				return false;
			}
		);
		Functions\when( 'FileToWeb\Integration\dns_get_record' )->alias(
			function () {
				return array();
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
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key ) {
				if ( isset( $this->meeting_meta[ $post_id ][ $key ] ) ) {
					return $this->meeting_meta[ $post_id ][ $key ];
				}

				return '';
			}
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			function ( $attachment_id ) {
				return isset( $this->attachment_urls[ $attachment_id ] ) ? $this->attachment_urls[ $attachment_id ] : '';
			}
		);
		Functions\when( 'get_attached_file' )->alias(
			function ( $attachment_id ) {
				return isset( $this->attachment_files[ $attachment_id ] ) ? $this->attachment_files[ $attachment_id ] : '';
			}
		);
		Functions\when( 'get_post_mime_type' )->alias(
			function ( $attachment_id ) {
				return isset( $this->attachment_mimes[ $attachment_id ] ) ? $this->attachment_mimes[ $attachment_id ] : '';
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				unset( $url );

				$body                   = json_decode( $args['body'], true );
				$this->request_bodies[] = $body;

				return array(
					'body' => json_encode(
						array(
							'document' => array(
								'id'             => 'doc-' . count( $this->request_bodies ),
								'external_id'    => $body['external_id'],
								'status'         => 'queued',
								'html_url'       => 'https://filetoweb.com/d/test/1',
								'continuous_url' => 'https://filetoweb.com/d/test',
								'editor_url'     => 'https://app.filetoweb.com/home/test',
								'page_count'     => 0,
							),
						)
					),
				);
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '{}';
			}
		);
		Functions\when( 'update_post_meta' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_material_resolver_finds_agenda_packet_and_minutes_attachment_ids(): void {
		$this->meeting_meta[55] = array(
			'agenda_attachment'        => 101,
			'agenda_packet_attachment' => 102,
			'minutes_attachment'       => 103,
		);

		$this->assertSame(
			array(
				'agenda'        => 101,
				'agenda_packet' => 102,
				'minutes'       => 103,
			),
			Meeting_Materials::attachment_ids_for_meeting( 55 )
		);
	}

	public function test_meeting_save_schedules_all_present_pdf_attachments(): void {
		$this->meeting_meta[55] = array(
			'agenda_attachment'        => 101,
			'agenda_packet_attachment' => 102,
			'minutes_attachment'       => 103,
		);

		foreach ( array( 101, 102, 103 ) as $attachment_id ) {
			$this->attachment_urls[ $attachment_id ]  = 'https://example.test/wp-content/uploads/material-' . $attachment_id . '.pdf';
			$this->attachment_mimes[ $attachment_id ] = 'application/pdf';
			$this->attachment_files[ $attachment_id ] = __FILE__;
		}

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->times( 3 )->andReturn( true );
		Functions\expect( 'wp_remote_post' )->times( 3 )->andReturn( null );

		$post = (object) array(
			'ID'        => 55,
			'post_type' => 'meeting',
		);

		Meeting_Materials::schedule_meeting_material_sync( 55, $post, true );

		$this->addToAssertionCount( 1 );
	}

	public function test_single_row_sync_syncs_only_selected_attachment(): void {
		$this->meeting_meta[55] = array(
			'agenda_attachment'  => 101,
			'minutes_attachment' => 103,
		);

		$this->attachment_urls[101]  = 'https://example.test/wp-content/uploads/agenda.pdf';
		$this->attachment_urls[103]  = 'https://example.test/wp-content/uploads/minutes.pdf';
		$this->attachment_mimes[101] = 'application/pdf';
		$this->attachment_mimes[103] = 'application/pdf';
		$this->attachment_files[101] = __FILE__;
		$this->attachment_files[103] = __FILE__;

		$result = Meeting_Materials::sync_material_now( 55, 'minutes' );

		$this->assertSame( 'queued', $result['status'] );
		$this->assertCount( 1, $this->request_bodies );
		$this->assertStringEndsWith( ':attachment:103', $this->request_bodies[0]['external_id'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/minutes.pdf', $this->request_bodies[0]['source']['url'] );
	}

	public function test_sync_all_syncs_pdfs_and_skips_missing_or_non_pdf_materials(): void {
		$this->meeting_meta[55] = array(
			'agenda_attachment'        => 101,
			'agenda_packet_attachment' => 102,
		);

		$this->attachment_urls[101]  = 'https://example.test/wp-content/uploads/agenda.pdf';
		$this->attachment_urls[102]  = 'https://example.test/wp-content/uploads/packet.docx';
		$this->attachment_mimes[101] = 'application/pdf';
		$this->attachment_mimes[102] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$this->attachment_files[101] = __FILE__;
		$this->attachment_files[102] = __FILE__;

		$counts = Meeting_Materials::sync_all_now( 55 );

		$this->assertSame( 1, $counts['queued'] );
		$this->assertSame( 2, $counts['skipped'] );
		$this->assertSame( 0, $counts['failed'] );
		$this->assertCount( 1, $this->request_bodies );
		$this->assertStringEndsWith( ':attachment:101', $this->request_bodies[0]['external_id'] );
	}
}
