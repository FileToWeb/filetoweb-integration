<?php
/**
 * Optional ProudCity Meeting material integration.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meeting_Materials {
	const ACTION_SYNC_MATERIAL = 'filetoweb_integration_sync_meeting_material';
	const ACTION_POLL_MATERIAL = 'filetoweb_integration_poll_meeting_material';
	const ACTION_SYNC_ALL      = 'filetoweb_integration_sync_meeting_all';

	/**
	 * Defer hook registration until post types have been registered.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_hooks' ), 20 );
	}

	/**
	 * Register hooks when the ProudCity Meeting post type is available.
	 */
	public static function register_hooks() {
		if ( ! self::enabled() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_meeting', array( __CLASS__, 'schedule_meeting_material_sync' ), 40, 3 );
		add_action( 'admin_post_' . self::ACTION_SYNC_MATERIAL, array( __CLASS__, 'handle_sync_material' ) );
		add_action( 'admin_post_' . self::ACTION_POLL_MATERIAL, array( __CLASS__, 'handle_poll_material' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC_ALL, array( __CLASS__, 'handle_sync_all' ) );
		add_action( 'proud_form_after_file_upload', array( __CLASS__, 'render_inline_upload_control' ), 10, 3 );
	}

	/**
	 * Whether meeting material support should run.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( ! apply_filters( 'filetoweb_integration_enable_meeting_materials', true ) ) {
			return false;
		}

		return ! function_exists( 'post_type_exists' ) || post_type_exists( 'meeting' );
	}

	/**
	 * ProudCity Meeting material fields.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'agenda'        => array(
				'label'    => __( 'Agenda', 'filetoweb-integration' ),
				'meta_key' => 'agenda_attachment',
			),
			'agenda_packet' => array(
				'label'    => __( 'Agenda Packet', 'filetoweb-integration' ),
				'meta_key' => 'agenda_packet_attachment',
			),
			'minutes'       => array(
				'label'    => __( 'Minutes', 'filetoweb-integration' ),
				'meta_key' => 'minutes_attachment',
			),
		);
	}

	/**
	 * Return all configured material attachment IDs for a meeting.
	 *
	 * @param int $meeting_id Meeting post ID.
	 * @return array
	 */
	public static function attachment_ids_for_meeting( $meeting_id ) {
		$meeting_id = absint( $meeting_id );
		$ids        = array();

		if ( ! $meeting_id ) {
			return $ids;
		}

		foreach ( self::fields() as $slot => $field ) {
			$attachment_id = absint( get_post_meta( $meeting_id, $field['meta_key'], true ) );

			if ( $attachment_id ) {
				$ids[ $slot ] = $attachment_id;
			}
		}

		return $ids;
	}

	/**
	 * Return one meeting material attachment ID.
	 *
	 * @param int    $meeting_id Meeting post ID.
	 * @param string $slot Field key.
	 * @return int
	 */
	public static function attachment_id_for_slot( $meeting_id, $slot ) {
		$slot = sanitize_key( $slot );
		$ids  = self::attachment_ids_for_meeting( $meeting_id );

		return isset( $ids[ $slot ] ) ? absint( $ids[ $slot ] ) : 0;
	}

	/**
	 * Return PDF-backed material attachment IDs for a meeting.
	 *
	 * @param int $meeting_id Meeting post ID.
	 * @return array
	 */
	public static function pdf_attachment_ids_for_meeting( $meeting_id ) {
		$ids = array();

		foreach ( self::attachment_ids_for_meeting( $meeting_id ) as $slot => $attachment_id ) {
			if ( self::is_pdf_attachment( $attachment_id ) ) {
				$ids[ $slot ] = $attachment_id;
			}
		}

		return $ids;
	}

	/**
	 * Add the meeting material metabox.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'filetoweb_integration_meeting_materials',
			__( 'FileToWeb meeting PDFs', 'filetoweb-integration' ),
			array( __CLASS__, 'render_meta_box' ),
			'meeting',
			'normal',
			'default'
		);
	}

	/**
	 * Render the meeting material metabox.
	 *
	 * @param \WP_Post $post Meeting post.
	 */
	public static function render_meta_box( $post ) {
		$meeting_id = absint( $post->ID );
		?>
		<p><?php esc_html_e( 'Each meeting PDF syncs independently so agendas, packets, and minutes can be added over time.', 'filetoweb-integration' ); ?></p>

		<?php if ( ! Settings::enabled() ) : ?>
			<p><em><?php esc_html_e( 'FileToWeb is disabled. Original meeting PDF links remain in use.', 'filetoweb-integration' ); ?></em></p>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Material', 'filetoweb-integration' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Attachment', 'filetoweb-integration' ); ?></th>
					<th scope="col"><?php esc_html_e( 'FileToWeb', 'filetoweb-integration' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Links', 'filetoweb-integration' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'filetoweb-integration' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( self::fields() as $slot => $field ) : ?>
					<?php self::render_material_row( $meeting_id, $slot, $field ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( Settings::configured() && self::can_manage_meeting( $meeting_id ) ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( self::admin_action_url( 'sync_all', $meeting_id ) ); ?>">
					<?php esc_html_e( 'Sync all meeting PDFs', 'filetoweb-integration' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Schedule sync for all attached meeting PDFs.
	 *
	 * @param int      $post_id Meeting post ID.
	 * @param \WP_Post $post Post.
	 * @param bool     $update Whether this is an existing post update.
	 */
	public static function schedule_meeting_material_sync( $post_id, $post, $update ) {
		unset( $update );

		if ( ! self::enabled() || ! is_object( $post ) || 'meeting' !== $post->post_type ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}

		foreach ( self::pdf_attachment_ids_for_meeting( $post_id ) as $attachment_id ) {
			Sync::schedule_attachment_sync( $attachment_id );
		}
	}

	/**
	 * Sync a single meeting material now.
	 *
	 * @param int    $meeting_id Meeting post ID.
	 * @param string $slot Material slot.
	 * @return array
	 */
	public static function sync_material_now( $meeting_id, $slot ) {
		$attachment_id = self::attachment_id_for_slot( $meeting_id, $slot );

		if ( ! $attachment_id || ! self::is_pdf_attachment( $attachment_id ) ) {
			return array( 'status' => 'skipped' );
		}

		return Sync::sync_attachment_now( $attachment_id, 'meeting_material_' . sanitize_key( $slot ) );
	}

	/**
	 * Poll a single meeting material now.
	 *
	 * @param int    $meeting_id Meeting post ID.
	 * @param string $slot Material slot.
	 * @return string
	 */
	public static function poll_material_now( $meeting_id, $slot ) {
		$attachment_id = self::attachment_id_for_slot( $meeting_id, $slot );

		if ( ! $attachment_id || ! self::is_pdf_attachment( $attachment_id ) ) {
			return 'skipped';
		}

		return Sync::poll_post( $attachment_id );
	}

	/**
	 * Sync all current meeting PDFs.
	 *
	 * @param int $meeting_id Meeting post ID.
	 * @return array
	 */
	public static function sync_all_now( $meeting_id ) {
		$counts = self::empty_counts();

		foreach ( self::fields() as $slot => $field ) {
			unset( $field );

			$result = self::sync_material_now( $meeting_id, $slot );

			if ( isset( $result['status'] ) && ! in_array( $result['status'], array( 'failed', 'skipped' ), true ) ) {
				++$counts['queued'];
			} elseif ( isset( $result['status'] ) && 'failed' === $result['status'] ) {
				++$counts['failed'];
			} else {
				++$counts['skipped'];
			}
		}

		return $counts;
	}

	/**
	 * Return a ready WordPress-local viewer URL for a meeting attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function ready_viewer_url_for_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$source_url    = $attachment_id ? Source_Resolver::original_attachment_url( $attachment_id ) : '';

		if ( ! $source_url || ! self::is_pdf_attachment( $attachment_id, $source_url ) ) {
			return '';
		}

		$html_url   = Document_State::ready_html_url( $attachment_id );
		$local_url  = Local_HTML::local_url( $attachment_id );

		if ( ! $html_url || ! $local_url ) {
			return '';
		}

		return $local_url;
	}

	/**
	 * Is an attachment a PDF source?
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Optional known source URL.
	 * @return bool
	 */
	public static function is_pdf_attachment( $attachment_id, $url = '' ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return false;
		}

		$url      = $url ? esc_url_raw( $url ) : Source_Resolver::original_attachment_url( $attachment_id );
		$mime     = get_post_mime_type( $attachment_id );
		$file     = get_attached_file( $attachment_id );
		$filename = $file ? basename( $file ) : basename( (string) parse_url( $url, PHP_URL_PATH ) );

		return $url && Source_Resolver::is_pdf_source( $url, $filename, $mime );
	}

	/**
	 * Render a compact sync control near ProudCity Meeting upload fields.
	 *
	 * @param mixed  $media_id Attachment ID from ProudCity.
	 * @param string $url Current attachment URL from ProudCity.
	 * @param array  $field ProudCity field configuration.
	 */
	public static function render_inline_upload_control( $media_id, $url, $field ) {
		if ( ! Settings::configured() || ! function_exists( 'get_post' ) ) {
			return;
		}

		$post = get_post();
		if ( ! is_object( $post ) || empty( $post->ID ) || 'meeting' !== $post->post_type ) {
			return;
		}

		$meeting_id = absint( $post->ID );
		if ( ! self::can_manage_meeting( $meeting_id ) ) {
			return;
		}

		$slot = self::slot_for_proud_upload_field( $field );
		if ( ! $slot ) {
			return;
		}

		$attachment_id = absint( $media_id );
		$url           = is_scalar( $url ) ? esc_url_raw( (string) $url ) : '';

		if ( ! $attachment_id && $url && function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = absint( attachment_url_to_postid( $url ) );
		}

		if ( ! $attachment_id || ! self::is_pdf_attachment( $attachment_id, $url ) ) {
			return;
		}

		$status      = get_post_meta( $attachment_id, Document_State::META_STATUS, true );
		$document_id = get_post_meta( $attachment_id, Document_State::META_DOCUMENT_ID, true );
		$html_url    = Security::sanitize_filetoweb_url( get_post_meta( $attachment_id, Document_State::META_HTML_URL, true ) );
		$editor_url  = Security::sanitize_filetoweb_url( get_post_meta( $attachment_id, Document_State::META_EDITOR_URL, true ) );
		$local_url   = Local_HTML::local_url( $attachment_id );
		?>
		<div class="filetoweb-integration-inline-meeting-sync" style="clear:both;display:block;margin:6px 0 0 150px;">
			<a class="button button-small" href="<?php echo esc_url( self::admin_action_url( 'sync_material', $meeting_id, $slot ) ); ?>"><?php esc_html_e( 'Sync this PDF', 'filetoweb-integration' ); ?></a>
			<?php if ( $document_id ) : ?>
				<a class="button button-small" href="<?php echo esc_url( self::admin_action_url( 'poll_material', $meeting_id, $slot ) ); ?>"><?php esc_html_e( 'Poll status', 'filetoweb-integration' ); ?></a>
			<?php endif; ?>
			<span style="display:inline-block;margin-left:6px;vertical-align:middle;"><?php echo self::status_badge( $status ); ?></span>
			<div style="font-size:12px;line-height:1.5;margin-top:4px;">
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Original PDF', 'filetoweb-integration' ); ?></a>
				<?php if ( $local_url ) : ?>
					<span aria-hidden="true"> | </span><a href="<?php echo esc_url( $local_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Local HTML copy', 'filetoweb-integration' ); ?></a>
				<?php endif; ?>
				<?php if ( $html_url ) : ?>
					<span aria-hidden="true"> | </span><a href="<?php echo esc_url( $html_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Generated HTML', 'filetoweb-integration' ); ?></a>
				<?php endif; ?>
				<?php if ( $editor_url ) : ?>
					<span aria-hidden="true"> | </span><a href="<?php echo esc_url( $editor_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit in FileToWeb', 'filetoweb-integration' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle single material sync action.
	 */
	public static function handle_sync_material() {
		self::handle_material_action( 'sync_material' );
	}

	/**
	 * Handle single material poll action.
	 */
	public static function handle_poll_material() {
		self::handle_material_action( 'poll_material' );
	}

	/**
	 * Handle current-meeting sync all action.
	 */
	public static function handle_sync_all() {
		$meeting_id = isset( $_GET['meeting_id'] ) ? absint( wp_unslash( $_GET['meeting_id'] ) ) : 0;

		if ( ! self::can_manage_meeting( $meeting_id ) ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_sync_all_' . $meeting_id );

		$counts = self::sync_all_now( $meeting_id );

		Admin::set_notice( self::format_counts( __( 'Meeting PDF sync', 'filetoweb-integration' ), $counts ) );
		self::redirect_to_meeting( $meeting_id );
	}

	/**
	 * Render one material row.
	 *
	 * @param int    $meeting_id Meeting post ID.
	 * @param string $slot Slot.
	 * @param array  $field Field config.
	 */
	private static function render_material_row( $meeting_id, $slot, $field ) {
		$attachment_id = self::attachment_id_for_slot( $meeting_id, $slot );
		$is_pdf        = $attachment_id && self::is_pdf_attachment( $attachment_id );
		$source_url    = $attachment_id ? Source_Resolver::original_attachment_url( $attachment_id ) : '';
		$status        = $attachment_id ? get_post_meta( $attachment_id, Document_State::META_STATUS, true ) : '';
		$document_id   = $attachment_id ? get_post_meta( $attachment_id, Document_State::META_DOCUMENT_ID, true ) : '';
		$page_count    = $attachment_id ? absint( get_post_meta( $attachment_id, Document_State::META_PAGE_COUNT, true ) ) : 0;
		$html_url      = $attachment_id ? Security::sanitize_filetoweb_url( get_post_meta( $attachment_id, Document_State::META_HTML_URL, true ) ) : '';
		$editor_url    = $attachment_id ? Security::sanitize_filetoweb_url( get_post_meta( $attachment_id, Document_State::META_EDITOR_URL, true ) ) : '';
		$filename      = $attachment_id ? self::attachment_filename( $attachment_id, $source_url ) : '';
		?>
		<tr>
			<td><strong><?php echo esc_html( $field['label'] ); ?></strong></td>
			<td>
				<?php if ( $attachment_id ) : ?>
					<?php echo esc_html( $filename ? $filename : sprintf( __( 'Attachment #%d', 'filetoweb-integration' ), $attachment_id ) ); ?>
					<?php if ( ! $is_pdf ) : ?>
						<br /><em><?php esc_html_e( 'Skipped: not a PDF.', 'filetoweb-integration' ); ?></em>
					<?php endif; ?>
				<?php else : ?>
					<em><?php esc_html_e( 'No attachment selected.', 'filetoweb-integration' ); ?></em>
				<?php endif; ?>
			</td>
			<td>
				<?php echo self::status_badge( $status ); ?>
				<?php if ( $page_count ) : ?>
					<br /><?php echo esc_html( sprintf( _n( '%d page', '%d pages', $page_count, 'filetoweb-integration' ), $page_count ) ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $source_url ) : ?>
					<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Original PDF', 'filetoweb-integration' ); ?></a><br />
				<?php endif; ?>
				<?php if ( $html_url ) : ?>
					<a href="<?php echo esc_url( $html_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Generated HTML', 'filetoweb-integration' ); ?></a><br />
				<?php endif; ?>
				<?php if ( $editor_url ) : ?>
					<a href="<?php echo esc_url( $editor_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit in FileToWeb', 'filetoweb-integration' ); ?></a>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( Settings::configured() && self::can_manage_meeting( $meeting_id ) && $attachment_id && $is_pdf ) : ?>
					<a class="button" href="<?php echo esc_url( self::admin_action_url( 'sync_material', $meeting_id, $slot ) ); ?>"><?php esc_html_e( 'Sync this PDF', 'filetoweb-integration' ); ?></a>
					<?php if ( $document_id ) : ?>
						<a class="button" href="<?php echo esc_url( self::admin_action_url( 'poll_material', $meeting_id, $slot ) ); ?>"><?php esc_html_e( 'Poll status', 'filetoweb-integration' ); ?></a>
					<?php endif; ?>
				<?php else : ?>
					<span aria-hidden="true">&mdash;</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle a per-material admin action.
	 *
	 * @param string $action Action key.
	 */
	private static function handle_material_action( $action ) {
		$meeting_id = isset( $_GET['meeting_id'] ) ? absint( wp_unslash( $_GET['meeting_id'] ) ) : 0;
		$slot       = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';

		if ( ! self::can_manage_meeting( $meeting_id ) || ! isset( self::fields()[ $slot ] ) ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_' . $action . '_' . $meeting_id . '_' . $slot );

		if ( 'sync_material' === $action ) {
			$result  = self::sync_material_now( $meeting_id, $slot );
			$message = sprintf( __( 'Meeting PDF sync %s.', 'filetoweb-integration' ), isset( $result['status'] ) ? $result['status'] : 'complete' );
		} else {
			$result  = self::poll_material_now( $meeting_id, $slot );
			$message = sprintf( __( 'Meeting PDF poll %s.', 'filetoweb-integration' ), $result );
		}

		Admin::set_notice( $message );
		self::redirect_to_meeting( $meeting_id );
	}

	/**
	 * Can the current user manage one meeting's FileToWeb material actions?
	 *
	 * @param int $meeting_id Meeting post ID.
	 * @return bool
	 */
	private static function can_manage_meeting( $meeting_id ) {
		$meeting_id = absint( $meeting_id );

		return $meeting_id && Capabilities::current_user_can_sync( $meeting_id );
	}

	/**
	 * Build a nonce-protected meeting admin action URL.
	 *
	 * @param string $action Action key.
	 * @param int    $meeting_id Meeting post ID.
	 * @param string $slot Optional material slot.
	 * @return string
	 */
	private static function admin_action_url( $action, $meeting_id, $slot = '' ) {
		$meeting_id = absint( $meeting_id );

		if ( 'sync_all' === $action ) {
			return wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION_SYNC_ALL . '&meeting_id=' . $meeting_id ),
				'filetoweb_integration_sync_all_' . $meeting_id
			);
		}

		$slot         = sanitize_key( $slot );
		$admin_action = 'poll_material' === $action ? self::ACTION_POLL_MATERIAL : self::ACTION_SYNC_MATERIAL;

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $admin_action . '&meeting_id=' . $meeting_id . '&slot=' . rawurlencode( $slot ) ),
			'filetoweb_integration_' . $action . '_' . $meeting_id . '_' . $slot
		);
	}

	/**
	 * Redirect back to the meeting edit screen.
	 *
	 * @param int $meeting_id Meeting post ID.
	 */
	private static function redirect_to_meeting( $meeting_id ) {
		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url( 'post.php?post=' . absint( $meeting_id ) . '&action=edit' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Build a small status label.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private static function status_badge( $status ) {
		$status = $status ? Security::sanitize_status( $status ) : '';

			$is_processing = false;

			if ( 'ready' === $status ) {
				$label = __( 'Ready', 'filetoweb-integration' );
				$color = '#008a20';
			} elseif ( in_array( $status, array( 'failed', 'error' ), true ) ) {
				$label = __( 'Failed', 'filetoweb-integration' );
				$color = '#b32d2e';
			} elseif ( in_array( $status, array( 'awaiting_upload', 'uploaded', 'queued', 'pending', 'importing', 'processing', 'converting' ), true ) ) {
				$label         = __( 'Processing', 'filetoweb-integration' );
				$color         = '#2271b1';
				$is_processing = true;
			} else {
				$label = __( 'Not synced', 'filetoweb-integration' );
				$color = '#646970';
			}

			$badge = '<span style="display:inline-block;border:1px solid ' . esc_attr( $color ) . ';border-radius:3px;color:' . esc_attr( $color ) . ';font-weight:600;padding:1px 6px;">' . esc_html( $label ) . '</span>';

			if ( $is_processing ) {
				$badge .= Admin::processing_help_icon();
			}

			return $badge;
		}

	/**
	 * Resolve a display filename.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_url Source URL.
	 * @return string
	 */
	private static function attachment_filename( $attachment_id, $source_url ) {
		$file = get_attached_file( $attachment_id );

		if ( $file ) {
			return basename( $file );
		}

		$path = $source_url ? parse_url( $source_url, PHP_URL_PATH ) : '';

		return $path ? basename( $path ) : '';
	}

	/**
	 * Map ProudCity upload field config to a Meeting material slot.
	 *
	 * @param array $field ProudCity field configuration.
	 * @return string
	 */
	private static function slot_for_proud_upload_field( $field ) {
		if ( ! is_array( $field ) ) {
			return '';
		}

		$names = array();
		foreach ( array( '#name', 'name', '#id', 'id' ) as $key ) {
			if ( isset( $field[ $key ] ) && is_scalar( $field[ $key ] ) ) {
				$value   = (string) $field[ $key ];
				$names[] = $value;
				$names[] = sanitize_key( $value );
			}
		}

		$map = array(
			'meeting_agenda_packet'   => 'agenda_packet',
			'meeting_agenda'          => 'agenda',
			'meeting_minutes'         => 'minutes',
			'agenda_packet_attachment' => 'agenda_packet',
			'agenda_attachment'       => 'agenda',
			'minutes_attachment'      => 'minutes',
		);

		foreach ( $names as $name ) {
			$name = is_scalar( $name ) ? (string) $name : '';

			foreach ( $map as $needle => $slot ) {
				if ( $name === $needle || false !== strpos( $name, $needle ) ) {
					return $slot;
				}
			}
		}

		return '';
	}

	/**
	 * Format sync counts for notices.
	 *
	 * @param string $label Label.
	 * @param array  $counts Counts.
	 * @return string
	 */
	private static function format_counts( $label, $counts ) {
		return sprintf(
			'%s complete. queued=%d skipped=%d failed=%d updated=%d',
			$label,
			absint( $counts['queued'] ),
			absint( $counts['skipped'] ),
			absint( $counts['failed'] ),
			absint( $counts['updated'] )
		);
	}

	/**
	 * Empty counts.
	 *
	 * @return array
	 */
	private static function empty_counts() {
		return array(
			'queued'  => 0,
			'skipped' => 0,
			'failed'  => 0,
			'updated' => 0,
		);
	}
}
