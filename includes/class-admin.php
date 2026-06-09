<?php
/**
 * FileToWeb admin UI.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {
	const PAGE_SLUG = 'filetoweb-integration';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'admin_post_filetoweb_integration_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'admin_post_filetoweb_integration_poll_now', array( __CLASS__, 'handle_poll_now' ) );
		add_action( 'admin_post_filetoweb_integration_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'admin_post_filetoweb_integration_poll_pending', array( __CLASS__, 'handle_poll_pending' ) );
		add_filter( 'manage_document_posts_columns', array( __CLASS__, 'add_document_status_column' ) );
		add_action( 'manage_document_posts_custom_column', array( __CLASS__, 'render_document_status_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_document_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'add_document_row_actions' ), 10, 2 );
	}

	/**
	 * Add settings page.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'FileToWeb', 'filetoweb-integration' ),
			__( 'FileToWeb', 'filetoweb-integration' ),
			Capabilities::manage_settings_capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Add metaboxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'filetoweb_integration_status',
			__( 'FileToWeb', 'filetoweb-integration' ),
			array( __CLASS__, 'render_status_meta_box' ),
			'document',
			'side',
			'default'
		);

		add_meta_box(
			'filetoweb_integration_status',
			__( 'FileToWeb', 'filetoweb-integration' ),
			array( __CLASS__, 'render_status_meta_box' ),
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page() {
		if ( ! self::can_manage_settings() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		settings_errors( 'filetoweb_integration' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FileToWeb', 'filetoweb-integration' ); ?></h1>

			<?php if ( ! Settings::enabled() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'FileToWeb is disabled. PDF sync, polling, and public link replacement are inactive.', 'filetoweb-integration' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'filetoweb_integration' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'filetoweb-integration' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_ENABLED ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_ENABLED ) ); ?>" value="1" <?php checked( Settings::enabled() ); ?> />
								<?php esc_html_e( 'Enable FileToWeb sync and public replacement', 'filetoweb-integration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::field_id( Settings::KEY_API_BASE_URL ) ); ?>"><?php esc_html_e( 'API URL', 'filetoweb-integration' ); ?></label></th>
						<td>
							<input class="regular-text" type="url" id="<?php echo esc_attr( Settings::field_id( Settings::KEY_API_BASE_URL ) ); ?>" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_API_BASE_URL ) ); ?>" value="<?php echo esc_attr( Settings::api_base_url() ); ?>" />
							<p class="description"><?php esc_html_e( 'HTTPS FileToWeb API host. Authorization is only sent to allowed FileToWeb hosts.', 'filetoweb-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::field_id( Settings::KEY_API_KEY ) ); ?>"><?php esc_html_e( 'API key', 'filetoweb-integration' ); ?></label></th>
						<td>
							<input class="regular-text" type="password" id="<?php echo esc_attr( Settings::field_id( Settings::KEY_API_KEY ) ); ?>" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_API_KEY ) ); ?>" value="" autocomplete="off" />
							<p class="description">
								<?php echo Settings::api_key() ? esc_html__( 'An API key is saved. Leave blank to keep it, paste a new key to replace it.', 'filetoweb-integration' ) : esc_html__( 'Paste a scoped FileToWeb API key.', 'filetoweb-integration' ); ?>
							</p>
							<?php if ( Settings::api_key() ) : ?>
								<label>
									<input type="checkbox" name="filetoweb_integration_clear_api_key" value="1" />
									<?php esc_html_e( 'Clear saved API key', 'filetoweb-integration' ); ?>
								</label>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Public replacement', 'filetoweb-integration' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_REPLACE_LINKS ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_REPLACE_LINKS ) ); ?>" value="1" <?php checked( Settings::replace_links_enabled() ); ?> />
								<?php esc_html_e( 'Replace front-end PDF links with WordPress-local HTML or approved WordPress pages when ready', 'filetoweb-integration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'EPUB download', 'filetoweb-integration' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_EPUB_DOWNLOAD ) ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_EPUB_DOWNLOAD ) ); ?>" value="1" <?php checked( Settings::epub_download_enabled() ); ?> />
								<?php esc_html_e( 'Show an EPUB download option on ready Proud Document pages', 'filetoweb-integration' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The original PDF download remains available. The EPUB link points to FileToWeb and prepares the ebook file only when clicked.', 'filetoweb-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::field_id( Settings::KEY_BATCH_SIZE ) ); ?>"><?php esc_html_e( 'Batch size', 'filetoweb-integration' ); ?></label></th>
						<td>
							<input type="number" min="1" max="100" id="<?php echo esc_attr( Settings::field_id( Settings::KEY_BATCH_SIZE ) ); ?>" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_BATCH_SIZE ) ); ?>" value="<?php echo esc_attr( Settings::batch_size() ); ?>" />
							<p class="description"><?php esc_html_e( 'Manual backfill and polling batches are bounded to protect site performance and customer credit usage.', 'filetoweb-integration' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save FileToWeb settings', 'filetoweb-integration' ) ); ?>
			</form>

			<hr />

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
				<?php wp_nonce_field( 'filetoweb_integration_backfill' ); ?>
				<input type="hidden" name="action" value="filetoweb_integration_backfill" />
				<?php submit_button( __( 'Backfill batch', 'filetoweb-integration' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'filetoweb_integration_poll_pending' ); ?>
				<input type="hidden" name="action" value="filetoweb_integration_poll_pending" />
				<?php submit_button( __( 'Poll pending', 'filetoweb-integration' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Bulk sync queue', 'filetoweb-integration' ); ?></h2>
			<?php self::render_bulk_queue_status(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
				<?php wp_nonce_field( 'filetoweb_integration_queue_documents' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Bulk_Queue::ACTION_DOCS ); ?>" />
				<?php submit_button( __( 'Queue all Documents', 'filetoweb-integration' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
				<?php wp_nonce_field( 'filetoweb_integration_queue_meeting_pdfs' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Bulk_Queue::ACTION_MEET ); ?>" />
				<?php submit_button( __( 'Queue all Meeting PDFs', 'filetoweb-integration' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( Bulk_Queue::ACTION_RUN ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Bulk_Queue::ACTION_RUN ); ?>" />
				<?php submit_button( __( 'Run next queue batch', 'filetoweb-integration' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render status metabox.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_status_meta_box( $post ) {
		$status      = get_post_meta( $post->ID, Document_State::META_STATUS, true );
		$error       = get_post_meta( $post->ID, Document_State::META_LAST_ERROR, true );
		$html_url    = Security::sanitize_filetoweb_url( get_post_meta( $post->ID, Document_State::META_HTML_URL, true ) );
		$editor_url  = Security::sanitize_filetoweb_url( get_post_meta( $post->ID, Document_State::META_EDITOR_URL, true ) );
		$document_id = get_post_meta( $post->ID, Document_State::META_DOCUMENT_ID, true );
		$external_id = get_post_meta( $post->ID, Document_State::META_EXTERNAL_ID, true );
		$original    = Source_Resolver::admin_original_source_url( $post );
		$page_count  = absint( get_post_meta( $post->ID, Document_State::META_PAGE_COUNT, true ) );
		$last_synced = get_post_meta( $post->ID, Document_State::META_LAST_SYNCED_AT, true );

		self::render_status_summary( $status );

		if ( $page_count ) {
			echo '<p><strong>' . esc_html__( 'Pages:', 'filetoweb-integration' ) . '</strong> ' . esc_html( $page_count ) . '</p>';
		}

		if ( $last_synced ) {
			echo '<p><strong>' . esc_html__( 'Last synced:', 'filetoweb-integration' ) . '</strong><br />' . esc_html( $last_synced ) . ' UTC</p>';
		}

		if ( ! Settings::enabled() ) {
			echo '<p><em>' . esc_html__( 'FileToWeb is disabled. Original PDF links remain in use.', 'filetoweb-integration' ) . '</em></p>';
		}

		if ( $document_id ) {
			echo '<p><strong>' . esc_html__( 'Document:', 'filetoweb-integration' ) . '</strong><br /><code>' . esc_html( $document_id ) . '</code></p>';
		}

		if ( $external_id ) {
			echo '<p><strong>' . esc_html__( 'External ID:', 'filetoweb-integration' ) . '</strong><br /><code>' . esc_html( $external_id ) . '</code></p>';
		}

		if ( $error ) {
			echo '<p><strong>' . esc_html__( 'Error:', 'filetoweb-integration' ) . '</strong><br />' . esc_html( $error ) . '</p>';
		}

		if ( $original ) {
			echo '<p><a href="' . esc_url( $original ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View original PDF', 'filetoweb-integration' ) . '</a></p>';
		}

		if ( $html_url ) {
			echo '<p><a href="' . esc_url( $html_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View generated HTML', 'filetoweb-integration' ) . '</a></p>';
		}

		if ( $editor_url ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $editor_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Edit in FileToWeb', 'filetoweb-integration' ) . '</a></p>';
		}

		if ( Settings::configured() && self::can_sync_post( $post->ID ) ) {
			echo '<p>';
			echo '<a class="button" href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Sync PDF now', 'filetoweb-integration' ) . '</a> ';

			if ( $document_id ) {
				echo '<a class="button" href="' . esc_url( self::admin_action_url( 'poll_now', $post->ID ) ) . '">' . esc_html__( 'Poll status', 'filetoweb-integration' ) . '</a>';
			}

			echo '</p>';
		}

		Native_Page::render_admin_panel( $post );
	}

	/**
	 * Render a prominent status summary.
	 *
	 * @param string $status Raw status.
	 */
	private static function render_status_summary( $status ) {
		$status = $status ? Security::sanitize_status( $status ) : '';
		$state  = self::status_state( $status );

		$styles = array(
			'not_synced' => array( '#646970', '#f6f7f7', __( 'Not synced', 'filetoweb-integration' ), __( 'This PDF has not been submitted to FileToWeb yet.', 'filetoweb-integration' ) ),
			'processing' => array( '#2271b1', '#f0f6fc', __( 'Processing', 'filetoweb-integration' ), __( 'FileToWeb is processing this PDF. Poll status to check for updates.', 'filetoweb-integration' ) ),
			'ready'      => array( '#008a20', '#edfaef', __( 'Ready', 'filetoweb-integration' ), __( 'Generated HTML is ready for public replacement.', 'filetoweb-integration' ) ),
			'failed'     => array( '#b32d2e', '#fcf0f1', __( 'Failed', 'filetoweb-integration' ), __( 'Processing needs attention. Retry sync after reviewing the error.', 'filetoweb-integration' ) ),
		);

		$config = isset( $styles[ $state ] ) ? $styles[ $state ] : $styles['not_synced'];

		echo '<div class="filetoweb-status-alert" style="border-left:4px solid ' . esc_attr( $config[0] ) . ';background:' . esc_attr( $config[1] ) . ';padding:10px 12px;margin:0 0 12px;">';
		echo '<span style="display:inline-block;border:1px solid ' . esc_attr( $config[0] ) . ';border-radius:3px;color:' . esc_attr( $config[0] ) . ';font-weight:600;padding:2px 8px;margin-bottom:6px;">' . esc_html( $config[2] ) . '</span>';
		echo '<p style="margin:4px 0 0;">' . esc_html( $config[3] ) . '</p>';
		echo '</div>';
	}

	/**
	 * Normalize a FileToWeb status into an admin display state.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_state( $status ) {
		if ( 'ready' === $status ) {
			return 'ready';
		}

		if ( in_array( $status, array( 'failed', 'error' ), true ) ) {
			return 'failed';
		}

		if ( in_array( $status, array( 'awaiting_upload', 'uploaded', 'queued', 'pending', 'importing', 'processing', 'converting' ), true ) ) {
			return 'processing';
		}

		return 'not_synced';
	}

	/**
	 * Build a compact status badge.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function status_badge( $status ) {
		$status = $status ? Security::sanitize_status( $status ) : '';
		$state  = self::status_state( $status );

		$styles = array(
			'not_synced' => array( '#646970', __( 'Not synced', 'filetoweb-integration' ) ),
			'processing' => array( '#2271b1', __( 'Processing', 'filetoweb-integration' ) ),
			'ready'      => array( '#008a20', __( 'Ready', 'filetoweb-integration' ) ),
			'failed'     => array( '#b32d2e', __( 'Failed', 'filetoweb-integration' ) ),
		);

		$config = isset( $styles[ $state ] ) ? $styles[ $state ] : $styles['not_synced'];

		return '<span style="display:inline-block;border:1px solid ' . esc_attr( $config[0] ) . ';border-radius:3px;color:' . esc_attr( $config[0] ) . ';font-weight:600;padding:1px 6px;">' . esc_html( $config[1] ) . '</span>';
	}

	/**
	 * Handle single item sync.
	 */
	public static function handle_sync_now() {
		self::handle_post_action( 'sync_now' );
	}

	/**
	 * Handle single item poll.
	 */
	public static function handle_poll_now() {
		self::handle_post_action( 'poll_now' );
	}

	/**
	 * Handle manual backfill.
	 */
	public static function handle_backfill() {
		if ( ! self::can_manage_settings() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_backfill' );

		$counts = Sync::run_backfill( Settings::batch_size() );

		self::set_notice( self::format_counts( __( 'Backfill', 'filetoweb-integration' ), $counts ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle manual pending poll.
	 */
	public static function handle_poll_pending() {
		if ( ! self::can_manage_settings() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_poll_pending' );

		$counts = Sync::poll_pending( Settings::batch_size() );

		self::set_notice( self::format_counts( __( 'Poll', 'filetoweb-integration' ), $counts ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Render transient-backed admin notice.
	 */
	public static function render_admin_notice() {
		$notice = self::get_notice();

		if ( ! $notice ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	}

	/**
	 * Handle metabox admin action.
	 *
	 * @param string $action Action suffix.
	 */
	private static function handle_post_action( $action ) {
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;

		if ( ! self::can_sync_post( $post_id ) ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_' . $action . '_' . $post_id );

		if ( 'sync_now' === $action ) {
			$result  = 'attachment' === get_post_type( $post_id ) ? Sync::sync_attachment_now( $post_id, 'manual_sync' ) : Sync::sync_document_now( $post_id, 'manual_sync' );
			$message = sprintf( __( 'FileToWeb sync %s.', 'filetoweb-integration' ), isset( $result['status'] ) ? $result['status'] : 'complete' );
		} else {
			$result  = Sync::poll_post( $post_id );
			$message = sprintf( __( 'FileToWeb poll %s.', 'filetoweb-integration' ), $result );
		}

		self::set_notice( $message );

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Build a nonce-protected admin action URL.
	 *
	 * @param string $action Action.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	private static function admin_action_url( $action, $post_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=filetoweb_integration_' . $action . '&post_id=' . absint( $post_id ) ),
			'filetoweb_integration_' . $action . '_' . absint( $post_id )
		);
	}

	/**
	 * Can the current user sync or poll one PDF-backed post?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_sync_post( $post_id ) {
		$post_id = absint( $post_id );

		return $post_id && Capabilities::current_user_can_sync( $post_id );
	}

	/**
	 * Can the current user manage global FileToWeb settings and backfill?
	 *
	 * @return bool
	 */
	public static function can_manage_settings() {
		return Capabilities::current_user_can_manage_settings();
	}

	/**
	 * Add FileToWeb status to the Proud Document list table.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_document_status_column( $columns ) {
		$columns['filetoweb_status'] = __( 'FileToWeb', 'filetoweb-integration' );

		return $columns;
	}

	/**
	 * Render the FileToWeb status column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_document_status_column( $column, $post_id ) {
		if ( 'filetoweb_status' !== $column ) {
			return;
		}

		$status     = get_post_meta( $post_id, Document_State::META_STATUS, true );
		$page_count = absint( get_post_meta( $post_id, Document_State::META_PAGE_COUNT, true ) );
		$local_url  = Local_HTML::local_url( $post_id );

		echo self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $page_count ) {
			echo '<br />' . esc_html( sprintf( _n( '%d page', '%d pages', $page_count, 'filetoweb-integration' ), $page_count ) );
		}

		if ( $local_url ) {
			echo '<br /><a href="' . esc_url( $local_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Local HTML', 'filetoweb-integration' ) . '</a>';
		}
	}

	/**
	 * Add quick sync actions to Proud Document list rows.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function add_document_row_actions( $actions, $post ) {
		if ( ! is_object( $post ) || 'document' !== get_post_type( $post ) || ! Settings::configured() || ! self::can_sync_post( $post->ID ) ) {
			return $actions;
		}

		$actions['filetoweb_sync'] = '<a href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Sync with FileToWeb', 'filetoweb-integration' ) . '</a>';

		if ( get_post_meta( $post->ID, Document_State::META_DOCUMENT_ID, true ) ) {
			$actions['filetoweb_poll'] = '<a href="' . esc_url( self::admin_action_url( 'poll_now', $post->ID ) ) . '">' . esc_html__( 'Poll FileToWeb', 'filetoweb-integration' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Render bulk queue status.
	 */
	private static function render_bulk_queue_status() {
		$state     = Bulk_Queue::queue_state();
		$remaining = count( $state['items'] );

		if ( ! $state['total'] ) {
			echo '<p><em>' . esc_html__( 'No bulk sync queue is active.', 'filetoweb-integration' ) . '</em></p>';
			return;
		}

		echo '<p>';
		echo esc_html(
			sprintf(
				__( 'Type: %1$s. Total: %2$d. Processed: %3$d. Remaining: %4$d. Queued: %5$d. Skipped: %6$d. Failed: %7$d.', 'filetoweb-integration' ),
				$state['type'],
				absint( $state['total'] ),
				absint( $state['processed'] ),
				absint( $remaining ),
				absint( $state['queued'] ),
				absint( $state['skipped'] ),
				absint( $state['failed'] )
			)
		);
		echo '</p>';
	}

	/**
	 * Format counts for notices.
	 *
	 * @param string $label Label.
	 * @param array  $counts Counts.
	 * @return string
	 */
	public static function format_counts( $label, $counts ) {
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
	 * Set a current-user transient notice.
	 *
	 * @param string $message Message.
	 */
	public static function set_notice( $message ) {
		set_transient( self::notice_key(), sanitize_text_field( $message ), 60 );
	}

	/**
	 * Get and clear current-user transient notice.
	 *
	 * @return string
	 */
	private static function get_notice() {
		$key    = self::notice_key();
		$notice = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );
		}

		return $notice ? sanitize_text_field( $notice ) : '';
	}

	/**
	 * Current-user notice key.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'filetoweb_integration_notice_' . absint( get_current_user_id() );
	}
}
