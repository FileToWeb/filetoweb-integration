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
		Functions\when( 'esc_html_e' )->alias(
			function ( $value ) {
				echo $value;
			}
		);
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
		Functions\when( 'trailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/' ) . '/';
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
		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => sys_get_temp_dir(),
			)
		);
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'wp_nonce_url' )->alias(
			function ( $url, $action ) {
				return $url . '&_wpnonce=' . rawurlencode( $action );
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
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

	public function test_inline_upload_control_renders_for_meeting_pdf_field(): void {
		$this->meeting_meta[55] = array(
			'agenda_attachment'                 => 101,
			Document_State::META_STATUS         => '',
		);

		$this->meeting_meta[101] = array(
			Document_State::META_STATUS      => 'ready',
			Document_State::META_DOCUMENT_ID => 'doc-101',
			Document_State::META_HTML_URL    => 'https://filetoweb.com/d/demo/1',
			Document_State::META_EDITOR_URL  => 'https://app.filetoweb.com/home/demo',
		);

		$this->attachment_urls[101]  = 'https://example.test/wp-content/uploads/agenda.pdf';
		$this->attachment_mimes[101] = 'application/pdf';
		$this->attachment_files[101] = __FILE__;

		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'meeting',
			)
		);

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			101,
			'https://example.test/wp-content/uploads/agenda.pdf',
			array(
				'#name' => 'meeting_agenda',
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'filetoweb-integration-inline-meeting-sync', $output );
		$this->assertStringContainsString( 'clear:both;display:block;margin:6px 0 0 150px;', $output );
		$this->assertStringContainsString( 'Ready', $output );
		$this->assertStringContainsString( 'Sync this PDF', $output );
		$this->assertStringContainsString( 'Poll status', $output );
		$this->assertLessThan( strpos( $output, 'Ready' ), strpos( $output, 'Sync this PDF' ) );
		$this->assertStringContainsString( 'slot=agenda', $output );
		$this->assertStringContainsString( 'Original PDF', $output );
		$this->assertStringContainsString( 'Generated HTML', $output );
		$this->assertStringContainsString( 'Edit in FileToWeb', $output );
	}

	public function test_inline_upload_control_uses_proudcity_packet_and_minutes_field_names(): void {
		$this->meeting_meta[55] = array(
			'agenda_packet_attachment' => 102,
			'minutes_attachment'       => 103,
		);

		foreach ( array( 102, 103 ) as $attachment_id ) {
			$this->attachment_urls[ $attachment_id ]  = 'https://example.test/wp-content/uploads/material-' . $attachment_id . '.pdf';
			$this->attachment_mimes[ $attachment_id ] = 'application/pdf';
			$this->attachment_files[ $attachment_id ] = __FILE__;
		}

		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'meeting',
			)
		);

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			102,
			'https://example.test/wp-content/uploads/material-102.pdf',
			array(
				'#name' => 'meeting_agenda_packet',
			)
		);
		$packet_output = ob_get_clean();

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			103,
			'https://example.test/wp-content/uploads/material-103.pdf',
			array(
				'#name' => 'meeting_minutes',
			)
		);
		$minutes_output = ob_get_clean();

		$this->assertStringContainsString( 'slot=agenda_packet', $packet_output );
		$this->assertStringContainsString( 'slot=minutes', $minutes_output );
	}

	public function test_inline_upload_control_uses_generated_proudform_field_names(): void {
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

		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'meeting',
			)
		);

		$cases = array(
			array(
				'attachment_id' => 101,
				'name'          => 'form-meeting_agenda[1][agenda_attachment]',
				'expected_slot' => 'agenda',
			),
			array(
				'attachment_id' => 102,
				'name'          => 'form-meeting_agenda_packet[1][agenda_packet_attachment]',
				'expected_slot' => 'agenda_packet',
			),
			array(
				'attachment_id' => 103,
				'name'          => 'form-meeting_minutes[1][minutes_attachment]',
				'expected_slot' => 'minutes',
			),
		);

		foreach ( $cases as $case ) {
			ob_start();
			Meeting_Materials::render_inline_upload_control(
				$case['attachment_id'],
				$this->attachment_urls[ $case['attachment_id'] ],
				array(
					'#name' => $case['name'],
				)
			);
			$output = ob_get_clean();

			$this->assertStringContainsString( 'filetoweb-integration-inline-meeting-sync', $output );
			$this->assertStringContainsString( 'slot=' . $case['expected_slot'], $output );
		}
	}

	public function test_inline_upload_control_uses_generated_proudform_field_ids(): void {
		$this->meeting_meta[55] = array(
			'agenda_packet_attachment' => 102,
			'minutes_attachment'       => 103,
		);

		foreach ( array( 102, 103 ) as $attachment_id ) {
			$this->attachment_urls[ $attachment_id ]  = 'https://example.test/wp-content/uploads/material-' . $attachment_id . '.pdf';
			$this->attachment_mimes[ $attachment_id ] = 'application/pdf';
			$this->attachment_files[ $attachment_id ] = __FILE__;
		}

		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'meeting',
			)
		);

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			102,
			$this->attachment_urls[102],
			array(
				'#id' => 'form-meeting_agenda_packet-1-agenda_packet_attachment',
			)
		);
		$packet_output = ob_get_clean();

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			103,
			$this->attachment_urls[103],
			array(
				'#id' => 'form-meeting_minutes-1-minutes_attachment',
			)
		);
		$minutes_output = ob_get_clean();

		$this->assertStringContainsString( 'slot=agenda_packet', $packet_output );
		$this->assertStringNotContainsString( 'slot=agenda&amp;', $packet_output );
		$this->assertStringContainsString( 'slot=minutes', $minutes_output );
	}

	public function test_inline_upload_control_does_not_render_outside_meetings(): void {
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'post',
			)
		);

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			101,
			'https://example.test/wp-content/uploads/agenda.pdf',
			array(
				'#name' => 'meeting_agenda',
			)
		);
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_inline_upload_control_does_not_render_for_unknown_or_empty_fields(): void {
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'meeting',
			)
		);

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			101,
			'https://example.test/wp-content/uploads/agenda.pdf',
			array(
				'#name' => 'unrelated_upload',
			)
		);
		$unknown_output = ob_get_clean();

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			'',
			'',
			array(
				'#name' => 'meeting_agenda',
			)
		);
		$empty_output = ob_get_clean();

		$this->assertSame( '', $unknown_output );
		$this->assertSame( '', $empty_output );
	}

	public function test_inline_upload_control_does_not_render_for_non_pdf_attachment(): void {
		$this->attachment_urls[101]  = 'https://example.test/wp-content/uploads/agenda.docx';
		$this->attachment_mimes[101] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
		$this->attachment_files[101] = __FILE__;

		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 55,
				'post_type' => 'meeting',
			)
		);

		ob_start();
		Meeting_Materials::render_inline_upload_control(
			101,
			'https://example.test/wp-content/uploads/agenda.docx',
			array(
				'#name' => 'meeting_agenda',
			)
		);
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
