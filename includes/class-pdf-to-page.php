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
	const PAGE_SLUG     = 'filetoweb-pdf-to-page';
	const ACTION_UPLOAD = 'filetoweb_integration_pdf_to_page_upload';
	const MAX_BYTES     = 104857600; // 100 MB.

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_pages_submenu' ) );
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( __CLASS__, 'handle_upload' ) );
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

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Convert PDF to Page', 'filetoweb-integration' ); ?></h1>

			<?php if ( ! Settings::configured() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Configure FileToWeb before converting PDFs to pages.', 'filetoweb-integration' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Upload a PDF to create a draft WordPress Page. FileToWeb converts the PDF, then the plugin updates the draft with editable WordPress HTML when ready.', 'filetoweb-integration' ); ?></p>

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
				<?php submit_button( __( 'Create draft page', 'filetoweb-integration' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Recent PDF-to-Page drafts', 'filetoweb-integration' ); ?></h2>
			<?php self::render_recent_pages(); ?>
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

		$result = self::create_draft_from_validated_upload( $file );

		if ( ! $result['ok'] ) {
			Admin::set_notice( $result['error'] );

			if ( ! empty( $result['page_id'] ) ) {
				self::redirect_to_edit_page( $result['page_id'] );
			}

			self::redirect_to_admin_page();
		}

		Admin::set_notice( __( 'Draft page created. FileToWeb conversion is processing; the draft will update when ready.', 'filetoweb-integration' ) );
		self::redirect_to_edit_page( $result['page_id'] );
	}

	/**
	 * Create a draft Page from a validated PDF upload and hand it to FileToWeb.
	 *
	 * @param array $file Validated upload from validated_uploaded_pdf().
	 * @return array
	 */
	public static function create_draft_from_validated_upload( $file ) {
		$page_id = self::create_placeholder_page( $file['filename'] );

		if ( ! $page_id ) {
			return self::workflow_error( __( 'Draft page could not be created.', 'filetoweb-integration' ), 0 );
		}

		update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE, '1' );
		update_post_meta( $page_id, Document_State::META_PDF_TO_PAGE_FILENAME, $file['filename'] );
		update_post_meta( $page_id, Document_State::META_SOURCE_FINGERPRINT, $file['sha256'] );
		update_post_meta( $page_id, Document_State::META_SOURCE_FINGERPRINT_ALGORITHM, 'sha256' );
		update_post_meta( $page_id, Document_State::META_LAST_TRIGGER, 'pdf_to_page_upload' );
		self::store_notification_email( $page_id );

		$source   = self::source_for_page_upload( $page_id, $file );
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
					'wordpress_page_id' => $page_id,
					'wordpress_site'    => home_url(),
					'filetoweb_trigger' => 'pdf_to_page_upload',
					'plugin_version'    => defined( 'FILETOWEB_INTEGRATION_VERSION' ) ? FILETOWEB_INTEGRATION_VERSION : '',
				),
			)
		);

		if ( ! $response['ok'] || empty( $response['body']['document'] ) || ! is_array( $response['body']['document'] ) ) {
			Document_State::mark_failed( $page_id, $response['error'] );
			return self::workflow_error( sprintf( __( 'FileToWeb upload setup failed: %s', 'filetoweb-integration' ), $response['error'] ), $page_id );
		}

		$document = $response['body']['document'];
		Document_State::write_from_api( $page_id, $document, $source );

		if ( empty( $document['upload'] ) || ! is_array( $document['upload'] ) ) {
			Document_State::mark_failed( $page_id, __( 'FileToWeb did not return signed upload instructions.', 'filetoweb-integration' ) );
			return self::workflow_error( __( 'FileToWeb did not return signed upload instructions.', 'filetoweb-integration' ), $page_id );
		}

		$upload_response = Api_Client::upload_file( $document['upload'], $file['tmp_name'] );

		if ( ! $upload_response['ok'] ) {
			Document_State::mark_failed( $page_id, $upload_response['error'] );
			return self::workflow_error( sprintf( __( 'FileToWeb upload failed: %s', 'filetoweb-integration' ), $upload_response['error'] ), $page_id );
		}

		$complete_response = Api_Client::complete_upload(
			$document['id'],
			array(
				'bytes'  => $file['size'],
				'sha256' => $file['sha256'],
			)
		);

		if ( ! $complete_response['ok'] || empty( $complete_response['body']['document'] ) || ! is_array( $complete_response['body']['document'] ) ) {
			Document_State::mark_failed( $page_id, $complete_response['error'] );
			return self::workflow_error( sprintf( __( 'FileToWeb upload finalization failed: %s', 'filetoweb-integration' ), $complete_response['error'] ), $page_id );
		}

		Document_State::write_polled_state( $page_id, $complete_response['body']['document'] );
		self::maybe_update_ready_page( $page_id, $complete_response['body']['document'] );
		self::delete_temp_file( $file['tmp_name'] );

		return array(
			'ok'      => true,
			'error'   => '',
			'page_id' => $page_id,
		);
	}

	/**
	 * Update a PDF-to-Page draft when its FileToWeb document is ready.
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
	 * Render recent converter pages.
	 */
	private static function render_recent_pages() {
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

		if ( empty( $pages ) ) {
			echo '<p><em>' . esc_html__( 'No PDF-to-Page drafts yet.', 'filetoweb-integration' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'PDF', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'filetoweb-integration' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'filetoweb-integration' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $pages as $page ) {
			$edit_url  = get_edit_post_link( $page->ID, '' );
			$filename  = get_post_meta( $page->ID, Document_State::META_PDF_TO_PAGE_FILENAME, true );
			$status    = get_post_meta( $page->ID, Document_State::META_STATUS, true );
			$completed = get_post_meta( $page->ID, Document_State::META_PDF_TO_PAGE_COMPLETED_AT, true );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( get_the_title( $page ) ) . '</a></td>';
			echo '<td>' . esc_html( $filename ) . '</td>';
			echo '<td>' . Admin::status_badge( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $completed ? $completed . ' UTC' : __( 'Not ready yet', 'filetoweb-integration' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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
	 * @param int    $page_id Draft page ID if available.
	 * @return array
	 */
	private static function workflow_error( $message, $page_id ) {
		return array(
			'ok'      => false,
			'error'   => Security::sanitize_error( $message ),
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
	 * Create the initial draft Page.
	 *
	 * @param string $filename PDF filename.
	 * @return int
	 */
	private static function create_placeholder_page( $filename ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => self::title_from_filename( $filename ),
				'post_content' => self::placeholder_content(),
			)
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		return absint( $page_id );
	}

	/**
	 * Placeholder page content while FileToWeb is processing.
	 *
	 * @return string
	 */
	private static function placeholder_content() {
		return '<p>' . esc_html__( 'FileToWeb is converting this PDF. This draft page will update automatically when the HTML version is ready.', 'filetoweb-integration' ) . '</p>';
	}

	/**
	 * Build source metadata used by Document_State.
	 *
	 * @param int   $page_id Page ID.
	 * @param array $file Validated upload file.
	 * @return array
	 */
	private static function source_for_page_upload( $page_id, $file ) {
		return array(
			'external_id'           => self::external_id( $page_id ),
			'source_url'            => '',
			'filename'              => $file['filename'],
			'fingerprint'           => $file['sha256'],
			'fingerprint_algorithm' => 'sha256',
			'sync_post_id'          => absint( $page_id ),
		);
	}

	/**
	 * Stable external ID for the dedicated PDF-to-Page workflow.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	private static function external_id( $page_id ) {
		return 'wordpress:' . Source_Resolver::site_hash() . ':pdf-to-page:' . absint( $page_id );
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
