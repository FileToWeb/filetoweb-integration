<?php
/**
 * Intentional PDF-to-WordPress Page workflow.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDF_To_Page {
	const PAGE_SLUG             = 'filetoweb-pdf-to-page';
	const ACTION_UPLOAD         = 'filetoweb_integration_pdf_to_page_upload';
	const ACTION_POLL_JOB       = 'filetoweb_integration_pdf_to_page_poll_job';
	const ACTION_RETRY_JOB      = 'filetoweb_integration_pdf_to_page_retry_job';
	const ACTION_AJAX_POLL_JOBS = 'filetoweb_integration_pdf_to_page_poll_jobs';
	const OPTION_JOBS           = 'filetoweb_integration_pdf_to_page_jobs';
	const MAX_BYTES             = 104857600; // 100 MB.
	const AUTO_POLL_INTERVAL_MS = 10000;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_pages_submenu' ) );
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_' . self::ACTION_POLL_JOB, array( __CLASS__, 'handle_poll_job' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY_JOB, array( __CLASS__, 'handle_retry_job' ) );
		add_action( 'wp_ajax_' . self::ACTION_AJAX_POLL_JOBS, array( __CLASS__, 'handle_ajax_poll_jobs' ) );
		add_action( 'filetoweb_integration_after_poll_post', array( __CLASS__, 'maybe_update_ready_page' ), 30, 2 );
	}

	/**
	 * Add Pages -> Convert PDF to Page.
	 */
	public static function add_pages_submenu() {
		add_submenu_page(
			'edit.php?post_type=page',
			__( 'Convert PDF to Page', 'filetoweb-integration' ),
			__( 'Convert PDF to Page', 'filetoweb-integration' ),
			Capabilities::native_page_capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render the converter admin screen.
	 */
	public static function render_admin_page() {
		if ( ! Capabilities::current_user_can_manage_native_page() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		$recent_rows = self::recent_rows();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Convert PDF to Page', 'filetoweb-integration' ); ?></h1>

			<?php if ( ! Settings::configured() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Configure FileToWeb before converting PDFs to pages.', 'filetoweb-integration' ); ?></p></div>
			<?php endif; ?>

				<p><?php esc_html_e( 'Upload a PDF for conversion. This screen tracks processing, which may take several minutes for larger PDFs, then creates an editable draft WordPress Page when the HTML is ready.', 'filetoweb-integration' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( self::ACTION_UPLOAD ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPLOAD ); ?>" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="filetoweb_pdf"><?php esc_html_e( 'PDF', 'filetoweb-integration' ); ?></label></th>
						<td>
							<input type="file" id="filetoweb_pdf" name="filetoweb_pdf" accept="application/pdf,.pdf" required />
							<p class="description"><?php echo esc_html( sprintf( __( 'Maximum file size: %d MB.', 'filetoweb-integration' ), self::MAX_BYTES / 1048576 ) ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Convert PDF', 'filetoweb-integration' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Recent PDF-to-Page conversions', 'filetoweb-integration' ); ?></h2>
			<div id="filetoweb-integration-pdf-to-page-recent" data-filetoweb-pdf-to-page-auto-poll="<?php echo esc_attr( self::has_pending_rows( $recent_rows ) ? '1' : '0' ); ?>">
				<?php self::render_recent_pages( $recent_rows ); ?>
			</div>
			<?php self::render_auto_poll_script( $recent_rows ); ?>
		</div>
		<?php
	}

	/**
	 * Handle the converter upload form.
	 */
	public static function handle_upload() {
		if ( ! Capabilities::current_user_can_manage_native_page() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( self::ACTION_UPLOAD );

		if ( ! Settings::configured() ) {
			Admin::set_notice( __( 'Configure FileToWeb before converting PDFs to pages.', 'filetoweb-integration' ) );
			self::redirect_to_admin_page();
		}

		$file = self::validated_uploaded_pdf();

		if ( ! $file['ok'] ) {
			Admin::set_notice( $file['error'] );
			self::redirect_to_admin_page();
		}

		$result = self::create_job_from_validated_upload( $file );

		if ( ! $result['ok'] ) {
			Admin::set_notice( $result['error'] );
			self::redirect_to_admin_page();
		}

		if ( ! empty( $result['page_id'] ) ) {
			Admin::set_notice( __( 'Conversion is ready. A draft Page was created.', 'filetoweb-integration' ) );
		} else {
			Admin::set_notice( __( 'FileToWeb conversion is processing. The draft Page will be created when the HTML is ready.', 'filetoweb-integration' ) );
		}

		self::redirect_to_admin_page();
	}

	/**
	 * Handle manual polling from the converter screen.
	 */
	public static function handle_poll_job() {
		if ( ! Capabilities::current_user_can_manage_native_page() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( self::ACTION_POLL_JOB . '_' . $job_id );

		$result = self::poll_job( $job_id );

		if ( 'updated' === $result ) {
			Admin::set_notice( __( 'PDF-to-Page status updated.', 'filetoweb-integration' ) );
		} elseif ( 'failed' === $result ) {
			Admin::set_notice( __( 'PDF-to-Page conversion failed. Check the status row for details.', 'filetoweb-integration' ) );
		} else {
			Admin::set_notice( __( 'PDF-to-Page conversion is still processing.', 'filetoweb-integration' ) );
		}

		self::redirect_to_admin_page();
	}

	/**
	 * Handle an explicit retry for a failed PDF-to-Page conversion.
	 */
	public static function handle_retry_job() {
		if ( ! Capabilities::current_user_can_manage_native_page() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		$job_id = isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '';
		check_admin_referer( self::ACTION_RETRY_JOB . '_' . $job_id );

		$job         = self::job( $job_id );
		$document_id = ! empty( $job['document_id'] ) ? Security::sanitize_identifier( $job['document_id'] ) : '';
		$response    = $document_id ? Api_Client::reprocess_document( $document_id ) : array(
			'ok'         => false,
			'error'      => __( 'FileToWeb document ID is missing.', 'filetoweb-integration' ),
			'error_code' => 'missing_document',
			'reference'  => '',
			'retryable'  => false,
		);

		if ( ! empty( $response['ok'] ) && ! empty( $response['body']['document'] ) && is_array( $response['body']['document'] ) ) {
			$document = $response['body']['document'];
			self::write_job_from_api( $job_id, $document, self::source_for_job( $job ) );
			self::maybe_complete_job( $job_id, $document );
			Admin::set_notice( __( 'FileToWeb processing has been queued again.', 'filetoweb-integration' ) );
		} else {
			self::mark_job_failed( $job_id, $response );
			Admin::set_notice( __( 'FileToWeb could not queue the retry. Review the error and try again.', 'filetoweb-integration' ) );
		}

		self::redirect_to_admin_page();
	}

	/**
	 * Poll pending PDF-to-Page jobs from the open admin screen.
	 */
	public static function handle_ajax_poll_jobs() {
		if ( ! Capabilities::current_user_can_manage_native_page() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unauthorized', 'filetoweb-integration' ),
				),
				403
			);
		}

		check_ajax_referer( self::ACTION_AJAX_POLL_JOBS );

		$counts = self::poll_pending_jobs( Settings::batch_size() );
		$rows   = self::recent_rows();

		ob_start();
		self::render_recent_pages( $rows );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'        => $html,
				'has_pending' => self::has_pending_rows( $rows ),
				'counts'      => $counts,
			)
		);
	}

	/**
	 * Create a conversion job from a validated PDF upload and hand it to FileToWeb.
	 *
	 * @param array $file Validated upload from validated_uploaded_pdf().
	 * @return array
	 */
	public static function create_job_from_validated_upload( $file ) {
		$job_id = self::new_job_id();

		$job = array(
			'id'                    => $job_id,
			'filename'              => $file['filename'],
			'fingerprint'           => $file['sha256'],
			'fingerprint_algorithm' => 'sha256',
			'external_id'           => self::external_id( $job_id ),
			'document_id'           => '',
			'status'                => 'awaiting_upload',
			'html_url'              => '',
			'continuous_url'        => '',
			'editor_url'            => '',
			'page_count'            => 0,
			'page_id'               => 0,
			'notify_email'          => self::current_user_email(),
			'error'                 => '',
			'created_at'            => current_time( 'mysql', true ),
			'updated_at'            => current_time( 'mysql', true ),
			'completed_at'          => '',
		);

		self::save_job( $job );

		$source   = self::source_for_job_upload( $job, $file );
		$response = Api_Client::upsert_document(
			array(
				'external_id' => $source['external_id'],
				'title'       => self::title_from_filename( $file['filename'] ),
				'filename'    => $file['filename'],
				'source'      => array(
					'type'         => 'upload',
					'content_type' => 'application/pdf',
					'fingerprint'  => array(
						'value'     => $file['sha256'],
						'algorithm' => 'sha256',
					),
				),
				'metadata'    => array(
					'wordpress_pdf_to_page_job_id' => $job_id,
					'wordpress_site'               => home_url(),
					'filetoweb_trigger'            => 'pdf_to_page_upload',
					'plugin_version'               => defined( 'FILETOWEB_INTEGRATION_VERSION' ) ? FILETOWEB_INTEGRATION_VERSION : '',
				),
			)
		);

		if ( ! $response['ok'] || empty( $response['body']['document'] ) || ! is_array( $response['body']['document'] ) ) {
			self::mark_job_failed( $job_id, $response );
			self::delete_temp_file( $file['tmp_name'] );
			return self::workflow_error( sprintf( __( 'FileToWeb upload setup failed: %s', 'filetoweb-integration' ), $response['error'] ), $job_id, 0 );
		}

		$document = $response['body']['document'];
		$job      = self::write_job_from_api( $job_id, $document, $source );

		if ( empty( $document['upload'] ) || ! is_array( $document['upload'] ) ) {
			self::mark_job_failed( $job_id, __( 'FileToWeb did not return signed upload instructions.', 'filetoweb-integration' ) );
			self::delete_temp_file( $file['tmp_name'] );
			return self::workflow_error( __( 'FileToWeb did not return signed upload instructions.', 'filetoweb-integration' ), $job_id, 0 );
		}

		$upload_response = Api_Client::upload_file( $document['upload'], $file['tmp_name'] );

		if ( ! $upload_response['ok'] ) {
			self::mark_job_failed( $job_id, $upload_response );
			self::delete_temp_file( $file['tmp_name'] );
			return self::workflow_error( sprintf( __( 'FileToWeb upload failed: %s', 'filetoweb-integration' ), $upload_response['error'] ), $job_id, 0 );
		}

		$complete_response = Api_Client::complete_upload(
			$document['id'],
			array(
				'bytes'  => $file['size'],
				'sha256' => $file['sha256'],
			)
		);

		if ( ! $complete_response['ok'] || empty( $complete_response['body']['document'] ) || ! is_array( $complete_response['body']['document'] ) ) {
			self::mark_job_failed( $job_id, $complete_response );
			self::delete_temp_file( $file['tmp_name'] );
			return self::workflow_error( sprintf( __( 'FileToWeb upload finalization failed: %s', 'filetoweb-integration' ), $complete_response['error'] ), $job_id, 0 );
		}

		$job = self::write_job_from_api( $job_id, $complete_response['body']['document'], $source );
		self::delete_temp_file( $file['tmp_name'] );
		self::maybe_complete_job( $job_id, $complete_response['body']['document'] );
		$job = self::job( $job_id );

		return array(
			'ok'      => true,
			'error'   => '',
			'job_id'  => $job_id,
			'page_id' => ! empty( $job['page_id'] ) ? absint( $job['page_id'] ) : 0,
		);
	}

	/**
	 * Backwards-compatible alias for older companion code/tests.
	 *
	 * @param array $file Validated upload from validated_uploaded_pdf().
	 * @return array
	 */
	public static function create_draft_from_validated_upload( $file ) {
		return self::create_job_from_validated_upload( $file );
	}

	/**
	 * Poll pending PDF-to-Page conversion jobs.
	 *
	 * @param int $limit Max jobs.
	 * @return array
	 */
	public static function poll_pending_jobs( $limit ) {
		$limit  = max( 0, min( 100, absint( $limit ) ) );
		$counts = array(
			'updated' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		if ( 0 === $limit || ! Settings::configured() ) {
			return $counts;
		}

		foreach ( self::jobs() as $job_id => $job ) {
			if ( $limit <= 0 ) {
				break;
			}

			$status = isset( $job['status'] ) ? Security::sanitize_status( $job['status'] ) : '';
			if ( ! self::is_pending_status( $status ) ) {
				continue;
			}

			$result = self::poll_job( $job_id );
			if ( isset( $counts[ $result ] ) ) {
				++$counts[ $result ];
			} else {
				++$counts['skipped'];
			}

			--$limit;
		}

		return $counts;
	}

	/**
	 * Poll one PDF-to-Page job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	public static function poll_job( $job_id ) {
		if ( ! Settings::configured() ) {
			return 'skipped';
		}

		$job = self::job( $job_id );

		if ( empty( $job['document_id'] ) ) {
			return 'skipped';
		}

			$response = Api_Client::get_document( $job['document_id'] );

			if ( ! $response['ok'] ) {
				$retryable = ! empty( $response['retryable'] ) || Api_Client::is_retryable_error( $response['error'] );
				if ( $retryable ) {
					self::mark_job_pending_retry( $job_id, $response );
					return 'updated';
				}

				self::mark_job_failed( $job_id, $response );
				return 'failed';
			}

		if ( empty( $response['body']['document'] ) || ! is_array( $response['body']['document'] ) ) {
			return 'skipped';
		}

		self::write_job_from_api( $job_id, $response['body']['document'], self::source_for_job( $job ) );
		self::maybe_complete_job( $job_id, $response['body']['document'] );

		return 'updated';
	}

	/**
	 * Create the draft Page when a PDF-to-Page job is ready.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $document API document.
	 */
	public static function maybe_complete_job( $job_id, $document = array() ) {
		$job = self::job( $job_id );

		if ( empty( $job ) || 'ready' !== Security::sanitize_status( $job['status'] ) ) {
			return;
		}

		if ( ! empty( $job['page_id'] ) ) {
			self::maybe_update_ready_page( absint( $job['page_id'] ), $document );
			return;
		}

		$page_id = self::create_ready_draft_page( $job['filename'] );

		if ( ! $page_id ) {
			self::mark_job_failed( $job_id, __( 'Draft page could not be created.', 'filetoweb-integration' ) );
			return;
		}

		self::write_job_to_page_meta( $page_id, $job );
		Local_HTML::refresh_for_post( $page_id, is_array( $document ) ? $document : self::document_from_job( $job ) );

		$content = Local_HTML::local_body_for_post( $page_id );

		if ( $content ) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $content,
				)
			);
		}

		$job['page_id']      = $page_id;
		$job['completed_at'] = current_time( 'mysql', true );
		$job['updated_at']   = current_time( 'mysql', true );
		self::save_job( $job );

		update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_COMPLETED_AT, $job['completed_at'] );
		self::send_ready_email_once( $page_id );
	}

	/**
	 * Update a legacy placeholder PDF-to-Page draft with ready content.
	 *
	 * Kept for Pages created by plugin versions 0.1.21-0.1.24.
	 *
	 * @param int   $post_id Page ID.
	 * @param array $document API document.
	 */
	public static function maybe_update_ready_page( $post_id, $document = array() ) {
		$post_id = absint( $post_id );

		if ( ! self::is_pdf_to_page( $post_id ) || 'ready' !== get_post_meta( $post_id, Document_State::META_STATUS, true ) ) {
			return;
		}

		if ( 'trash' === get_post_status( $post_id ) ) {
			return;
		}

		Local_HTML::refresh_for_post( $post_id, is_array( $document ) ? $document : null );

		$content = Local_HTML::local_body_for_post( $post_id );

		if ( ! $content ) {
			return;
		}

		$current = get_post_field( 'post_content', $post_id );

		if ( $content !== $current ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
				)
			);
		}

		update_post_meta( $post_id, Document_State::META_PDF_TO_PAGE_COMPLETED_AT, current_time( 'mysql', true ) );
		self::send_ready_email_once( $post_id );
	}

	/**
	 * Is this page owned by the PDF-to-Page workflow?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_pdf_to_page( $post_id ) {
		return '1' === get_post_meta( absint( $post_id ), Document_State::META_PDF_TO_PAGE, true );
	}

	/**
	 * Render recent converter jobs and legacy draft pages.
	 */
	private static function render_recent_pages( $rows = null ) {
		if ( null === $rows ) {
			$rows = self::recent_rows();
		}

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No PDF-to-Page conversions yet.', 'filetoweb-integration' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'PDF', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'filetoweb-integration' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . self::page_cell( $row ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $row['filename'] ) . '</td>';
			echo '<td>' . Admin::status_badge( $row['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $row['updated_at'] ? $row['updated_at'] . ' UTC' : __( 'Not updated yet', 'filetoweb-integration' ) ) . '</td>';
			echo '<td>' . self::actions_cell( $row ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the lightweight auto-poll script for pending PDF-to-Page jobs.
	 *
	 * @param array $rows Recent rows.
	 */
	private static function render_auto_poll_script( $rows ) {
		if ( ! Settings::configured() || ! self::has_pending_rows( $rows ) ) {
			return;
		}

		?>
		<script>
		(function() {
			var container = document.getElementById('filetoweb-integration-pdf-to-page-recent');
			if (!container || !window.ajaxurl || container.getAttribute('data-filetoweb-pdf-to-page-auto-poll') !== '1') {
				return;
			}

			var action = '<?php echo esc_js( self::ACTION_AJAX_POLL_JOBS ); ?>';
			var nonce = '<?php echo esc_js( wp_create_nonce( self::ACTION_AJAX_POLL_JOBS ) ); ?>';
			var interval = <?php echo absint( self::AUTO_POLL_INTERVAL_MS ); ?>;
			var inFlight = false;
			var stopped = false;

			function schedule() {
				if (!stopped) {
					window.setTimeout(poll, interval);
				}
			}

			function poll() {
				if (inFlight || stopped) {
					return;
				}

				inFlight = true;

				var xhr = new XMLHttpRequest();
				xhr.open('POST', window.ajaxurl, true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xhr.onreadystatechange = function() {
					if (xhr.readyState !== 4) {
						return;
					}

					inFlight = false;

					if (xhr.status >= 200 && xhr.status < 300) {
						try {
							var response = JSON.parse(xhr.responseText);
							if (response && response.success && response.data && response.data.html) {
								container.innerHTML = response.data.html;

								if (!response.data.has_pending) {
									stopped = true;
									container.setAttribute('data-filetoweb-pdf-to-page-auto-poll', '0');
									return;
								}
							}
						} catch (error) {}
					}

					schedule();
				};
				xhr.send('action=' + encodeURIComponent(action) + '&_ajax_nonce=' + encodeURIComponent(nonce));
			}

			schedule();
		}());
		</script>
		<?php
	}

	/**
	 * Does any recent row still need polling?
	 *
	 * @param array $rows Recent rows.
	 * @return bool
	 */
	private static function has_pending_rows( $rows ) {
		foreach ( $rows as $row ) {
			$status = isset( $row['status'] ) ? $row['status'] : '';

			if ( self::is_pending_status( $status ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is a FileToWeb status still actively converting or waiting to be checked?
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private static function is_pending_status( $status ) {
		return in_array( Security::sanitize_status( $status ), self::pending_statuses(), true );
	}

	/**
	 * Statuses that should continue polling.
	 *
	 * @return array
	 */
	private static function pending_statuses() {
		return array( 'awaiting_upload', 'uploaded', 'queued', 'pending', 'importing', 'processing', 'converting' );
	}

	/**
	 * Build rows for active jobs and legacy PDF-to-Page draft Pages.
	 *
	 * @return array
	 */
	private static function recent_rows() {
		$rows = array();

		foreach ( self::jobs() as $job ) {
			$rows[] = array(
				'type'            => 'job',
				'id'              => $job['id'],
				'page_id'         => absint( $job['page_id'] ),
				'title'           => self::title_from_filename( $job['filename'] ),
				'filename'        => $job['filename'],
				'status'          => Security::sanitize_status( $job['status'] ),
				'updated_at'      => $job['completed_at'] ? $job['completed_at'] : $job['updated_at'],
				'error'           => $job['error'],
				'error_reference' => $job['error_reference'],
				'document_id'     => $job['document_id'],
				'error_retryable' => ! empty( $job['error_retryable'] ),
			);
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'pending', 'publish', 'private' ),
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_key'       => Document_State::META_PDF_TO_PAGE,
				'meta_value'     => '1',
			)
		);

		foreach ( $pages as $page ) {
			if ( self::row_exists_for_page( $rows, $page->ID ) ) {
				continue;
			}

			$rows[] = array(
				'type'            => 'page',
				'id'              => '',
				'page_id'         => absint( $page->ID ),
				'title'           => get_the_title( $page ),
				'filename'        => get_post_meta( $page->ID, Document_State::META_PDF_TO_PAGE_FILENAME, true ),
				'status'          => get_post_meta( $page->ID, Document_State::META_STATUS, true ),
				'updated_at'      => get_post_meta( $page->ID, Document_State::META_PDF_TO_PAGE_COMPLETED_AT, true ),
				'error'           => Security::sanitize_public_error( get_post_meta( $page->ID, Document_State::META_LAST_ERROR, true ) ),
				'error_reference' => get_post_meta( $page->ID, Document_State::META_ERROR_REFERENCE, true ),
				'document_id'     => get_post_meta( $page->ID, Document_State::META_DOCUMENT_ID, true ),
				'error_retryable' => (bool) get_post_meta( $page->ID, Document_State::META_ERROR_RETRYABLE, true ),
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $b['updated_at'], (string) $a['updated_at'] );
			}
		);

		return array_slice( $rows, 0, 10 );
	}

	/**
	 * Does a row already point to a Page?
	 *
	 * @param array $rows Rows.
	 * @param int   $page_id Page ID.
	 * @return bool
	 */
	private static function row_exists_for_page( $rows, $page_id ) {
		foreach ( $rows as $row ) {
			if ( absint( $row['page_id'] ) === absint( $page_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the Page cell for a status row.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function page_cell( $row ) {
		$page_id = absint( $row['page_id'] );

		if ( $page_id ) {
			$edit_url = get_edit_post_link( $page_id, '' );

			if ( $edit_url ) {
				return '<a href="' . esc_url( $edit_url ) . '">' . esc_html( get_the_title( $page_id ) ) . '</a>';
			}
		}

		if ( ! empty( $row['error'] ) ) {
			return '<span aria-label="' . esc_attr( $row['error'] ) . '">' . esc_html__( 'Not created', 'filetoweb-integration' ) . '</span>';
		}

		return '<em>' . esc_html__( 'Draft will be created when ready', 'filetoweb-integration' ) . '</em>';
	}

	/**
	 * Render row actions.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function actions_cell( $row ) {
		$actions = array();
		$page_id = absint( $row['page_id'] );

		if ( $page_id ) {
			$edit_url = get_edit_post_link( $page_id, '' );

			if ( $edit_url ) {
				$actions[] = '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit draft', 'filetoweb-integration' ) . '</a>';
			}
		}

		if (
			'failed' === Security::sanitize_status( $row['status'] ) &&
			! empty( $row['id'] ) &&
			! empty( $row['document_id'] ) &&
			! empty( $row['error_retryable'] )
		) {
			$actions[] = '<a class="button button-small button-primary" href="' . esc_url( self::retry_job_url( $row['id'] ) ) . '">' . esc_html__( 'Retry processing', 'filetoweb-integration' ) . '</a>';
		} elseif ( ! $page_id && 'job' === $row['type'] && ! empty( $row['id'] ) ) {
			$actions[] = '<a class="button button-small" href="' . esc_url( self::poll_job_url( $row['id'] ) ) . '">' . esc_html__( 'Poll status', 'filetoweb-integration' ) . '</a>';
		}

		if ( ! empty( $row['error'] ) ) {
			$actions[] = '<span class="description">' . esc_html( Security::sanitize_public_error( $row['error'] ) ) . '</span>';
		}

		if ( ! empty( $row['error_reference'] ) && preg_match( '/^FTW-[A-F0-9]{12}$/', (string) $row['error_reference'] ) ) {
			$actions[] = '<span class="description">' . esc_html( sprintf( __( 'Support reference: %s', 'filetoweb-integration' ), $row['error_reference'] ) ) . '</span>';
		}

		return $actions ? implode( ' ', $actions ) : '&mdash;';
	}

	/**
	 * Build a poll URL for a PDF-to-Page job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private static function poll_job_url( $job_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_POLL_JOB,
					'job_id' => sanitize_key( $job_id ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_POLL_JOB . '_' . sanitize_key( $job_id )
		);
	}

	/**
	 * Build a retry URL for a failed PDF-to-Page job.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private static function retry_job_url( $job_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_RETRY_JOB,
					'job_id' => sanitize_key( $job_id ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_RETRY_JOB . '_' . sanitize_key( $job_id )
		);
	}

	/**
	 * Validate the uploaded PDF without adding it to the Media Library.
	 *
	 * @return array
	 */
	public static function validated_uploaded_pdf() {
		if ( empty( $_FILES['filetoweb_pdf'] ) || ! is_array( $_FILES['filetoweb_pdf'] ) ) {
			return self::upload_error( __( 'Choose a PDF to upload.', 'filetoweb-integration' ) );
		}

		$file = $_FILES['filetoweb_pdf']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$error = isset( $file['error'] ) ? absint( $file['error'] ) : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			return self::upload_error( self::php_upload_error_message( $error ) );
		}

		$name     = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size     = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		$type     = isset( $file['type'] ) ? sanitize_text_field( wp_unslash( $file['type'] ) ) : '';

		if ( ! $name || ! $tmp_name || ! is_readable( $tmp_name ) ) {
			return self::upload_error( __( 'Uploaded PDF could not be read.', 'filetoweb-integration' ) );
		}

		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return self::upload_error( __( 'PDF is empty or exceeds the FileToWeb upload limit.', 'filetoweb-integration' ) );
		}

		if ( ! self::is_pdf_file( $tmp_name, $name, $type ) ) {
			return self::upload_error( __( 'Only PDF files can be converted to pages.', 'filetoweb-integration' ) );
		}

		$sha256 = hash_file( 'sha256', $tmp_name );

		if ( ! $sha256 ) {
			return self::upload_error( __( 'Uploaded PDF fingerprint could not be computed.', 'filetoweb-integration' ) );
		}

		return array(
			'ok'       => true,
			'error'    => '',
			'filename' => $name,
			'tmp_name' => $tmp_name,
			'size'     => $size,
			'sha256'   => $sha256,
		);
	}

	/**
	 * Build an upload error response.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private static function upload_error( $message ) {
		return array(
			'ok'    => false,
			'error' => Security::sanitize_error( $message ),
		);
	}

	/**
	 * Build a workflow error response.
	 *
	 * @param string $message Message.
	 * @param string $job_id Job ID if available.
	 * @param int    $page_id Page ID if available.
	 * @return array
	 */
	private static function workflow_error( $message, $job_id = '', $page_id = 0 ) {
		return array(
			'ok'      => false,
			'error'   => Security::sanitize_error( $message ),
			'job_id'  => sanitize_key( $job_id ),
			'page_id' => absint( $page_id ),
		);
	}

	/**
	 * Does the uploaded file validate as a PDF?
	 *
	 * @param string $tmp_name Temporary path.
	 * @param string $name Filename.
	 * @param string $type Browser-reported content type.
	 * @return bool
	 */
	private static function is_pdf_file( $tmp_name, $name, $type ) {
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$checked = wp_check_filetype_and_ext(
				$tmp_name,
				$name,
				array(
					'pdf' => 'application/pdf',
				)
			);

			if ( ! empty( $checked['ext'] ) && 'pdf' === strtolower( $checked['ext'] ) ) {
				return true;
			}
		}

		return '.pdf' === strtolower( substr( $name, -4 ) ) && ( '' === $type || 'application/pdf' === strtolower( $type ) );
	}

	/**
	 * Create the ready draft Page.
	 *
	 * @param string $filename PDF filename.
	 * @return int
	 */
	private static function create_ready_draft_page( $filename ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => self::title_from_filename( $filename ),
				'post_content' => '',
			)
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		return absint( $page_id );
	}

	/**
	 * Build source metadata used by Document_State for a job.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function source_for_job( $job ) {
		return array(
			'external_id'           => $job['external_id'],
			'source_url'            => '',
			'filename'              => $job['filename'],
			'fingerprint'           => $job['fingerprint'],
			'fingerprint_algorithm' => $job['fingerprint_algorithm'],
			'sync_post_id'          => 0,
		);
	}

	/**
	 * Build source metadata used by Document_State for an upload job.
	 *
	 * @param array $job Job.
	 * @param array $file Validated upload file.
	 * @return array
	 */
	private static function source_for_job_upload( $job, $file ) {
		return array(
			'external_id'           => $job['external_id'],
			'source_url'            => '',
			'filename'              => $file['filename'],
			'fingerprint'           => $file['sha256'],
			'fingerprint_algorithm' => 'sha256',
			'sync_post_id'          => 0,
		);
	}

	/**
	 * Stable external ID for the dedicated PDF-to-Page workflow.
	 *
	 * @param string $job_id Job ID.
	 * @return string
	 */
	private static function external_id( $job_id ) {
		return 'wordpress:' . Source_Resolver::site_hash() . ':pdf-to-page:' . sanitize_key( $job_id );
	}

	/**
	 * Return the API document representation from a job.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function document_from_job( $job ) {
		return array(
			'id'             => $job['document_id'],
			'external_id'    => $job['external_id'],
			'status'         => $job['status'],
			'html_url'       => $job['html_url'],
			'continuous_url' => $job['continuous_url'],
			'editor_url'     => $job['editor_url'],
			'page_count'     => $job['page_count'],
			'error'          => $job['error'],
		);
	}

	/**
	 * Write job state to a ready draft Page.
	 *
	 * @param int   $page_id Page ID.
	 * @param array $job Job.
	 */
	private static function write_job_to_page_meta( $page_id, $job ) {
		update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE, '1' );
		update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_FILENAME, $job['filename'] );
		update_post_meta( $page_id, Document_State::META_EXTERNAL_ID, self::sanitize_external_id( $job['external_id'] ) );
		update_post_meta( $page_id, Document_State::META_DOCUMENT_ID, Security::sanitize_identifier( $job['document_id'] ) );
		update_post_meta( $page_id, Document_State::META_SOURCE_FINGERPRINT, self::sanitize_fingerprint( $job['fingerprint'] ) );
		update_post_meta( $page_id, Document_State::META_SOURCE_FINGERPRINT_ALGORITHM, sanitize_key( $job['fingerprint_algorithm'] ) );
		update_post_meta( $page_id, Document_State::META_STATUS, Security::sanitize_status( $job['status'] ) );
		update_post_meta( $page_id, Document_State::META_HTML_URL, Security::sanitize_filetoweb_url( $job['html_url'] ) );
		update_post_meta( $page_id, Document_State::META_CONTINUOUS_URL, Security::sanitize_filetoweb_url( $job['continuous_url'] ) );
		update_post_meta( $page_id, Document_State::META_EDITOR_URL, Security::sanitize_filetoweb_url( $job['editor_url'] ) );
		update_post_meta( $page_id, Document_State::META_PAGE_COUNT, absint( $job['page_count'] ) );
		update_post_meta( $page_id, Document_State::META_LAST_ERROR, Security::sanitize_error( $job['error'] ) );
		update_post_meta( $page_id, Document_State::META_ORIGINAL_URL, '' );
		update_post_meta( $page_id, Document_State::META_LAST_TRIGGER, 'pdf_to_page_upload' );
		update_post_meta( $page_id, Document_State::META_LAST_SYNCED_AT, current_time( 'mysql', true ) );

		if ( ! empty( $job['notify_email'] ) ) {
			update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_NOTIFY_EMAIL, sanitize_email( $job['notify_email'] ) );
		}
	}

	/**
	 * Create a new job ID.
	 *
	 * @return string
	 */
	private static function new_job_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return sanitize_key( wp_generate_uuid4() );
		}

		return sanitize_key( uniqid( 'pdf-to-page-', true ) );
	}

	/**
	 * Return current user email.
	 *
	 * @return string
	 */
	private static function current_user_email() {
		$user = wp_get_current_user();

		if ( ! is_object( $user ) || empty( $user->user_email ) ) {
			return '';
		}

		return sanitize_email( $user->user_email );
	}

	/**
	 * Return all PDF-to-Page jobs.
	 *
	 * @return array
	 */
	private static function jobs() {
		$jobs = get_option( self::OPTION_JOBS, array() );

		if ( ! is_array( $jobs ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $jobs as $job_id => $job ) {
			$job = self::normalize_job( is_array( $job ) ? $job : array(), $job_id );

			if ( ! empty( $job['id'] ) ) {
				$normalized[ $job['id'] ] = $job;
			}
		}

		return $normalized;
	}

	/**
	 * Return one PDF-to-Page job.
	 *
	 * @param string $job_id Job ID.
	 * @return array
	 */
	private static function job( $job_id ) {
		$job_id = sanitize_key( $job_id );
		$jobs   = self::jobs();

		return isset( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : array();
	}

	/**
	 * Save one PDF-to-Page job.
	 *
	 * @param array $job Job.
	 */
	private static function save_job( $job ) {
		$job = self::normalize_job( $job, isset( $job['id'] ) ? $job['id'] : '' );

		if ( empty( $job['id'] ) ) {
			return;
		}

		$jobs = self::jobs();
		$jobs[ $job['id'] ] = $job;

		update_option( self::OPTION_JOBS, $jobs, false );
	}

	/**
	 * Normalize a PDF-to-Page job row.
	 *
	 * @param array  $job Job.
	 * @param string $fallback_id Fallback ID.
	 * @return array
	 */
	private static function normalize_job( $job, $fallback_id = '' ) {
		$id = ! empty( $job['id'] ) ? $job['id'] : $fallback_id;

		return array(
			'id'                    => sanitize_key( $id ),
			'filename'              => isset( $job['filename'] ) ? sanitize_file_name( $job['filename'] ) : '',
			'fingerprint'           => isset( $job['fingerprint'] ) ? self::sanitize_fingerprint( $job['fingerprint'] ) : '',
			'fingerprint_algorithm' => isset( $job['fingerprint_algorithm'] ) ? sanitize_key( $job['fingerprint_algorithm'] ) : '',
			'external_id'           => isset( $job['external_id'] ) ? self::sanitize_external_id( $job['external_id'] ) : '',
			'document_id'           => isset( $job['document_id'] ) ? Security::sanitize_identifier( $job['document_id'] ) : '',
			'status'                => isset( $job['status'] ) ? Security::sanitize_status( $job['status'] ) : '',
			'html_url'              => isset( $job['html_url'] ) ? Security::sanitize_filetoweb_url( $job['html_url'] ) : '',
			'continuous_url'        => isset( $job['continuous_url'] ) ? Security::sanitize_filetoweb_url( $job['continuous_url'] ) : '',
			'editor_url'            => isset( $job['editor_url'] ) ? Security::sanitize_filetoweb_url( $job['editor_url'] ) : '',
			'page_count'            => isset( $job['page_count'] ) ? absint( $job['page_count'] ) : 0,
			'page_id'               => isset( $job['page_id'] ) ? absint( $job['page_id'] ) : 0,
			'notify_email'          => isset( $job['notify_email'] ) ? sanitize_email( $job['notify_email'] ) : '',
			'error'                 => isset( $job['error'] ) ? Security::sanitize_public_error( $job['error'] ) : '',
			'error_code'            => isset( $job['error_code'] ) ? sanitize_key( $job['error_code'] ) : '',
			'error_reference'       => isset( $job['error_reference'] ) && preg_match( '/^FTW-[A-F0-9]{12}$/', (string) $job['error_reference'] ) ? (string) $job['error_reference'] : '',
			'error_retryable'       => ! empty( $job['error_retryable'] ),
			'created_at'            => isset( $job['created_at'] ) ? sanitize_text_field( $job['created_at'] ) : '',
			'updated_at'            => isset( $job['updated_at'] ) ? sanitize_text_field( $job['updated_at'] ) : '',
			'completed_at'          => isset( $job['completed_at'] ) ? sanitize_text_field( $job['completed_at'] ) : '',
		);
	}

	/**
	 * Write FileToWeb API state into a job.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $document API document.
	 * @param array  $source Source.
	 * @return array
	 */
	private static function write_job_from_api( $job_id, $document, $source ) {
		$job = self::job( $job_id );

		if ( empty( $job ) || ! is_array( $document ) ) {
			return $job;
		}

		$job['external_id']           = isset( $document['external_id'] ) ? $document['external_id'] : $source['external_id'];
		$job['document_id']           = isset( $document['id'] ) ? $document['id'] : $job['document_id'];
		$job['fingerprint']           = $source['fingerprint'];
		$job['fingerprint_algorithm'] = $source['fingerprint_algorithm'];
		$job['status']                = isset( $document['status'] ) ? $document['status'] : $job['status'];
		$job['html_url']              = isset( $document['html_url'] ) ? $document['html_url'] : $job['html_url'];
		$job['continuous_url']        = isset( $document['continuous_url'] ) ? $document['continuous_url'] : $job['continuous_url'];
		$job['editor_url']            = isset( $document['editor_url'] ) ? $document['editor_url'] : $job['editor_url'];
		$job['page_count']            = isset( $document['page_count'] ) ? $document['page_count'] : $job['page_count'];
		$job['error']                 = isset( $document['error'] ) ? $document['error'] : '';
		$failure                      = isset( $document['failure'] ) && is_array( $document['failure'] ) ? $document['failure'] : array();
		$job['error_code']            = isset( $failure['code'] ) ? $failure['code'] : '';
		$job['error_reference']       = isset( $failure['reference'] ) ? $failure['reference'] : '';
		$job['error_retryable']       = ! empty( $failure['retryable'] );
		$job['updated_at']            = current_time( 'mysql', true );

		self::save_job( $job );

		return self::job( $job_id );
	}

	/**
	 * Mark a job failed.
	 *
	 * @param string $job_id Job ID.
	 * @param array|string $response API response or fallback error.
	 */
	private static function mark_job_failed( $job_id, $response ) {
		$job = self::job( $job_id );

		if ( empty( $job ) ) {
			return;
		}

		$response               = is_array( $response ) ? $response : array( 'error' => $response );
		$job['status']          = 'failed';
		$job['error']           = isset( $response['error'] ) ? $response['error'] : '';
		$job['error_code']      = isset( $response['error_code'] ) ? $response['error_code'] : '';
		$job['error_reference'] = isset( $response['reference'] ) ? $response['reference'] : '';
		$job['error_retryable'] = ! empty( $response['retryable'] );
		$job['updated_at']      = current_time( 'mysql', true );

		self::save_job( $job );
	}

	/**
	 * Keep a PDF-to-Page job pending after a temporary API failure.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $response API client response.
	 */
	private static function mark_job_pending_retry( $job_id, $response ) {
		$job = self::job( $job_id );

		if ( empty( $job ) ) {
			return;
		}

		$job['status']          = self::is_pending_status( $job['status'] ) ? $job['status'] : 'processing';
		$job['error']           = isset( $response['error'] ) ? $response['error'] : '';
		$job['error_code']      = isset( $response['error_code'] ) ? $response['error_code'] : '';
		$job['error_reference'] = isset( $response['reference'] ) ? $response['reference'] : '';
		$job['error_retryable'] = true;
		$job['updated_at']      = current_time( 'mysql', true );

		self::save_job( $job );
	}

	/**
	 * Sanitize external ID without weakening the stable id format.
	 *
	 * @param mixed $value External ID.
	 * @return string
	 */
	private static function sanitize_external_id( $value ) {
		$value = Security::sanitize_identifier( $value );

		return preg_replace( '/[^a-zA-Z0-9:_\-.]/', '', $value );
	}

	/**
	 * Sanitize source fingerprint.
	 *
	 * @param mixed $value Fingerprint.
	 * @return string
	 */
	private static function sanitize_fingerprint( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return substr( $value, 0, 255 );
	}

	/**
	 * Derive a readable Page title from a PDF filename.
	 *
	 * @param string $filename Filename.
	 * @return string
	 */
	private static function title_from_filename( $filename ) {
		$title = preg_replace( '/\.pdf$/i', '', (string) $filename );
		$title = str_replace( array( '-', '_' ), ' ', $title );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		return $title ? $title : __( 'Converted PDF', 'filetoweb-integration' );
	}

	/**
	 * Store the email address to notify when the draft is ready.
	 *
	 * @param int $page_id Page ID.
	 */
	private static function store_notification_email( $page_id ) {
		$user  = wp_get_current_user();
		$email = is_object( $user ) && ! empty( $user->user_email ) ? sanitize_email( $user->user_email ) : '';

		if ( $email ) {
			update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_NOTIFY_EMAIL, $email );
		}
	}

	/**
	 * Notify the uploading admin once when the draft is ready.
	 *
	 * @param int $page_id Page ID.
	 */
	private static function send_ready_email_once( $page_id ) {
		if ( get_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_NOTIFIED_AT, true ) ) {
			return;
		}

		$email = sanitize_email( get_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_NOTIFY_EMAIL, true ) );

		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		$edit_url = get_edit_post_link( $page_id, '' );

		if ( ! $edit_url ) {
			return;
		}

		$subject = sprintf( __( 'Your FileToWeb draft is ready: %s', 'filetoweb-integration' ), get_the_title( $page_id ) );
		$message = sprintf(
			/* translators: 1: Page title. 2: Edit URL. */
			__( "FileToWeb finished converting \"%1\$s\" into a WordPress draft page.\n\nReview and edit it here:\n%2\$s", 'filetoweb-integration' ),
			get_the_title( $page_id ),
			$edit_url
		);

		if ( wp_mail( $email, $subject, $message ) ) {
			update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_NOTIFIED_AT, current_time( 'mysql', true ) );
		}
	}

	/**
	 * Return a PHP upload error message.
	 *
	 * @param int $error Upload error code.
	 * @return string
	 */
	private static function php_upload_error_message( $error ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'The PDF exceeds the server upload limit.', 'filetoweb-integration' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The PDF exceeds the form upload limit.', 'filetoweb-integration' ),
			UPLOAD_ERR_PARTIAL    => __( 'The PDF upload did not complete.', 'filetoweb-integration' ),
			UPLOAD_ERR_NO_FILE    => __( 'Choose a PDF to upload.', 'filetoweb-integration' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'The server is missing a temporary upload directory.', 'filetoweb-integration' ),
			UPLOAD_ERR_CANT_WRITE => __( 'The uploaded PDF could not be written to disk.', 'filetoweb-integration' ),
			UPLOAD_ERR_EXTENSION  => __( 'A server extension stopped the PDF upload.', 'filetoweb-integration' ),
		);

		return isset( $messages[ $error ] ) ? $messages[ $error ] : __( 'The PDF upload failed.', 'filetoweb-integration' );
	}

	/**
	 * Delete upload temp file defensively after FileToWeb handoff.
	 *
	 * @param string $path Temporary path.
	 */
	private static function delete_temp_file( $path ) {
		if ( $path && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Redirect to the converter admin screen.
	 */
	private static function redirect_to_admin_page() {
		wp_safe_redirect( admin_url( 'edit.php?post_type=page&page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Redirect to the draft edit screen.
	 *
	 * @param int $page_id Page ID.
	 */
	private static function redirect_to_edit_page( $page_id ) {
		wp_safe_redirect( admin_url( 'post.php?post=' . absint( $page_id ) . '&action=edit' ) );
		exit;
	}
}
