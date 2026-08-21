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
		add_action( 'admin_post_filetoweb_integration_refresh_preview', array( __CLASS__, 'handle_refresh_preview' ) );
		add_action( 'admin_post_filetoweb_integration_retry_processing', array( __CLASS__, 'handle_retry_processing' ) );
		add_action( 'admin_post_filetoweb_integration_pause_public', array( __CLASS__, 'handle_pause_public' ) );
		add_action( 'admin_post_filetoweb_integration_restore_public', array( __CLASS__, 'handle_restore_public' ) );
		add_action( 'admin_post_filetoweb_integration_backfill', array( __CLASS__, 'handle_backfill' ) );
		add_action( 'admin_post_filetoweb_integration_poll_pending', array( __CLASS__, 'handle_poll_pending' ) );
		add_action( 'admin_post_filetoweb_integration_test_connection', array( __CLASS__, 'handle_test_connection' ) );
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
				<div class="notice notice-warning"><p><?php esc_html_e( 'FileToWeb is disabled. PDF sync, automatic status checks, and public link replacement are inactive.', 'filetoweb-integration' ); ?></p></div>
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
								<?php esc_html_e( 'Replace front-end PDF links with WordPress-local HTML when ready', 'filetoweb-integration' ); ?>
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
							<p class="description"><?php esc_html_e( 'Off by default. If enabled, the original PDF download remains available and FileToWeb prepares the EPUB file only when clicked.', 'filetoweb-integration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Settings::field_id( Settings::KEY_BATCH_SIZE ) ); ?>"><?php esc_html_e( 'Batch size', 'filetoweb-integration' ); ?></label></th>
						<td>
							<input type="number" min="1" max="100" id="<?php echo esc_attr( Settings::field_id( Settings::KEY_BATCH_SIZE ) ); ?>" name="<?php echo esc_attr( Settings::field_name( Settings::KEY_BATCH_SIZE ) ); ?>" value="<?php echo esc_attr( Settings::batch_size() ); ?>" />
							<p class="description"><?php esc_html_e( 'Manual backfill and status-check batches are bounded to protect site performance and customer credit usage.', 'filetoweb-integration' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save FileToWeb settings', 'filetoweb-integration' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'filetoweb_integration_test_connection' ); ?>
				<input type="hidden" name="action" value="filetoweb_integration_test_connection" />
				<?php submit_button( __( 'Test connection', 'filetoweb-integration' ), 'secondary', 'submit', false, Settings::api_key() ? array() : array( 'disabled' => 'disabled' ) ); ?>
				<?php if ( ! Settings::api_key() ) : ?>
					<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Save an API key before testing the connection.', 'filetoweb-integration' ); ?></span>
				<?php endif; ?>
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
				<?php submit_button( __( 'Check pending conversions', 'filetoweb-integration' ), 'secondary', 'submit', false ); ?>
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
		$error_ref   = get_post_meta( $post->ID, Document_State::META_ERROR_REFERENCE, true );
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

		if ( $error_ref ) {
			echo '<p><strong>' . esc_html__( 'Support reference:', 'filetoweb-integration' ) . '</strong><br /><code>' . esc_html( $error_ref ) . '</code></p>';
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
			$state  = self::status_state( $status );
			$action = '';

			if ( 'failed' === $state ) {
				if ( $document_id ) {
					$action = '<a class="button button-primary" href="' . esc_url( self::admin_action_url( 'retry_processing', $post->ID ) ) . '">' . esc_html__( 'Retry processing', 'filetoweb-integration' ) . '</a>';
				} else {
					$action = '<a class="button button-primary" href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Retry sync', 'filetoweb-integration' ) . '</a>';
				}
			} elseif ( 'processing' === $state ) {
				if ( $document_id ) {
					$action = '<a class="button" href="' . esc_url( self::admin_action_url( 'poll_now', $post->ID ) ) . '">' . esc_html__( 'Check conversion progress', 'filetoweb-integration' ) . '</a>';
				} else {
					$action = '<a class="button" href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Submit PDF now', 'filetoweb-integration' ) . '</a>';
				}
			} elseif ( 'not_synced' === $state ) {
				$action = '<a class="button" href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Sync PDF now', 'filetoweb-integration' ) . '</a>';
			} elseif ( 'ready' === $state && $document_id ) {
				$action = '<a class="button" href="' . esc_url( self::admin_action_url( 'refresh_preview', $post->ID ) ) . '">' . esc_html__( 'Refresh embedded preview', 'filetoweb-integration' ) . '</a>';
			}

			if ( $action ) {
				echo '<p>' . $action . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		$owner_post_id = Source_Resolver::preview_owner_post_id( $post->ID );

		if ( self::can_sync_post( $post->ID ) && self::can_sync_post( $owner_post_id ) ) {
			self::render_public_replacement_controls(
				$owner_post_id,
				self::admin_action_url( 'pause_public', $post->ID ),
				self::admin_action_url( 'restore_public', $post->ID )
			);
		}

		Native_Page::render_admin_panel( $post );
	}

	/**
	 * Render the per-source public PDF/HTML switch.
	 *
	 * @param int    $source_id Source post that owns the public preview.
	 * @param string $pause_url Nonce-protected pause URL.
	 * @param string $restore_url Nonce-protected restore URL.
	 * @param bool   $compact Whether to use compact meeting-row styling.
	 */
	public static function render_public_replacement_controls( $source_id, $pause_url, $restore_url, $compact = false ) {
		$source_id = absint( $source_id );

		if ( ! $source_id ) {
			return;
		}

		$paused = Proud_HTML_Preview::is_public_paused( $source_id );
		$style  = $compact
			? 'margin:6px 0 0;padding:7px 8px;border-left:3px solid #dba617;background:#fcf9e8;font-size:12px;line-height:1.4;'
			: 'margin:12px 0;padding:10px 12px;border-left:4px solid #dba617;background:#fcf9e8;';

		if ( $paused ) {
			echo '<div class="filetoweb-public-replacement-status is-paused" style="' . esc_attr( $style ) . '">';
			echo '<strong>' . esc_html__( 'Public view: Original PDF', 'filetoweb-integration' ) . '</strong>';
			echo '<p style="margin:4px 0 8px;">' . esc_html__( 'FileToWeb can keep syncing in the background, but this PDF will stay public until you restore its HTML preview.', 'filetoweb-integration' ) . '</p>';
			echo '<a class="button' . ( $compact ? ' button-small' : '' ) . '" href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Restore HTML preview', 'filetoweb-integration' ) . '</a>';

			if ( ! Settings::replace_links_enabled() ) {
				echo '<p style="margin:8px 0 0;"><em>' . esc_html__( 'Public replacement is also turned off for the whole site.', 'filetoweb-integration' ) . '</em></p>';
			}

			echo '</div>';
			return;
		}

		if ( ! Settings::replace_links_enabled() ) {
			if ( ! $compact ) {
				echo '<p><em>' . esc_html__( 'Public replacement is off for the whole site, so visitors receive the original PDF.', 'filetoweb-integration' ) . '</em></p>';
			}
			return;
		}

		if ( ! Proud_HTML_Preview::has_current_local_preview( $source_id ) ) {
			return;
		}

		echo '<div class="filetoweb-public-replacement-status" style="margin:' . ( $compact ? '6px 0 0' : '12px 0' ) . ';">';
		echo '<a class="button' . ( $compact ? ' button-small' : '' ) . '" href="' . esc_url( $pause_url ) . '">' . esc_html__( 'Show original PDF publicly', 'filetoweb-integration' ) . '</a>';
		if ( ! $compact ) {
			echo '<p class="description">' . esc_html__( 'Use this while the HTML version needs more work. Syncing and editing continue without republishing it.', 'filetoweb-integration' ) . '</p>';
		}
		echo '</div>';
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
			'processing' => array( '#2271b1', '#f0f6fc', __( 'Processing', 'filetoweb-integration' ), __( 'FileToWeb is processing this PDF. WordPress checks for updates automatically.', 'filetoweb-integration' ) ),
			'ready'      => array( '#008a20', '#edfaef', __( 'Ready', 'filetoweb-integration' ), __( 'Generated HTML is ready for public replacement.', 'filetoweb-integration' ) ),
			'failed'     => array( '#b32d2e', '#fcf0f1', __( 'Failed', 'filetoweb-integration' ), __( 'Processing needs attention. Retry sync after reviewing the error.', 'filetoweb-integration' ) ),
		);

		$config = isset( $styles[ $state ] ) ? $styles[ $state ] : $styles['not_synced'];

			echo '<div class="filetoweb-status-alert" style="border-left:4px solid ' . esc_attr( $config[0] ) . ';background:' . esc_attr( $config[1] ) . ';padding:10px 12px;margin:0 0 12px;">';
			echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">';
			echo '<span style="display:inline-block;border:1px solid ' . esc_attr( $config[0] ) . ';border-radius:3px;color:' . esc_attr( $config[0] ) . ';font-weight:600;padding:2px 8px;">' . esc_html( $config[2] ) . '</span>';
			if ( 'processing' === $state ) {
				echo self::processing_help_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
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

			$badge = '<span style="display:inline-block;border:1px solid ' . esc_attr( $config[0] ) . ';border-radius:3px;color:' . esc_attr( $config[0] ) . ';font-weight:600;padding:1px 6px;">' . esc_html( $config[1] ) . '</span>';

			if ( 'processing' === $state ) {
				$badge .= self::processing_help_icon();
			}

			return $badge;
		}

		/**
		 * Render compact help for processing-time expectations.
		 *
		 * @return string
		 */
		public static function processing_help_icon() {
			$text = __( 'Most PDFs finish within a few minutes. Larger or more complex PDFs can take up to 10 minutes. You can leave this screen; WordPress checks automatically and public links keep using the original PDF until the HTML version is ready.', 'filetoweb-integration' );

			return '<details class="filetoweb-processing-help" style="display:inline-block;position:relative;margin-left:4px;vertical-align:middle;">'
				. '<summary aria-label="' . esc_attr( __( 'About FileToWeb processing time', 'filetoweb-integration' ) ) . '" style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border:1px solid #8c8f94;border-radius:50%;color:#2271b1;background:#fff;font-size:12px;font-weight:700;line-height:1;cursor:pointer;list-style:none;">'
				. '<span aria-hidden="true">i</span>'
				. '</summary>'
				. '<span class="filetoweb-processing-help-text" role="tooltip" style="display:block;position:absolute;z-index:10000;top:22px;right:0;width:260px;padding:8px 10px;border:1px solid #c3c4c7;background:#fff;color:#1d2327;box-shadow:0 2px 8px rgba(0,0,0,.12);font-size:12px;font-weight:400;line-height:1.4;text-align:left;">'
				. esc_html( $text )
				. '</span>'
				. '</details>';
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
	 * Handle an explicit ready-preview refresh.
	 */
	public static function handle_refresh_preview() {
		self::handle_post_action( 'refresh_preview' );
	}

	/**
	 * Handle an explicit failed-document retry.
	 */
	public static function handle_retry_processing() {
		self::handle_post_action( 'retry_processing' );
	}

	/**
	 * Handle an explicit switch back to the original public PDF.
	 */
	public static function handle_pause_public() {
		self::handle_post_action( 'pause_public' );
	}

	/**
	 * Handle an explicit restoration of the current HTML preview.
	 */
	public static function handle_restore_public() {
		self::handle_post_action( 'restore_public' );
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

		self::set_notice( self::format_counts( __( 'Status check', 'filetoweb-integration' ), $counts ) );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Verify the stored API key against FileToWeb.
	 */
	public static function handle_test_connection() {
		if ( ! self::can_manage_settings() ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_test_connection' );

		$result = Api_Client::get_auth_context();
		$notice = self::format_connection_notice( $result );

		self::set_connection_notice( $notice['type'], $notice['message'] );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Convert an API context response into a safe admin notice.
	 *
	 * @param array $result API client result.
	 * @return array
	 */
	public static function format_connection_notice( $result ) {
		if ( empty( $result['ok'] ) ) {
			$error = isset( $result['error'] ) ? sanitize_text_field( $result['error'] ) : __( 'Unknown connection error.', 'filetoweb-integration' );

			return array(
				'type'    => 'error',
				'message' => sprintf( __( 'FileToWeb connection failed: %s', 'filetoweb-integration' ), $error ),
			);
		}

		$body         = isset( $result['body'] ) && is_array( $result['body'] ) ? $result['body'] : array();
		$account      = isset( $body['account'] ) && is_array( $body['account'] ) ? $body['account'] : array();
		$project      = isset( $body['project'] ) && is_array( $body['project'] ) ? $body['project'] : array();
		$scopes       = isset( $body['scopes'] ) && is_array( $body['scopes'] ) ? array_map( 'sanitize_text_field', $body['scopes'] ) : array();
		$account_name = isset( $account['name'] ) ? sanitize_text_field( $account['name'] ) : '';
		$project_name = isset( $project['name'] ) ? sanitize_text_field( $project['name'] ) : '';

		if ( '' === $account_name || '' === $project_name ) {
			return array(
				'type'    => 'error',
				'message' => __( 'FileToWeb connected, but the workspace or folder details were unavailable.', 'filetoweb-integration' ),
			);
		}

		if ( ! in_array( 'documents:read', $scopes, true ) || ! in_array( 'documents:write', $scopes, true ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'FileToWeb connected, but this API key does not have the document read and write permissions required by the plugin.', 'filetoweb-integration' ),
			);
		}

		return array(
			'type'    => 'success',
			'message' => sprintf(
				__( 'Connected to FileToWeb workspace “%1$s”, folder “%2$s”.', 'filetoweb-integration' ),
				$account_name,
				$project_name
			),
		);
	}

	/**
	 * Render transient-backed admin notice.
	 */
	public static function render_admin_notice() {
		$notice = self::get_notice();

		if ( $notice ) {
			$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		$connection_notice = self::get_connection_notice();

		if ( $connection_notice ) {
			$class = 'error' === $connection_notice['type'] ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $connection_notice['message'] ) . '</p></div>';
		}
	}

	/**
	 * Handle metabox admin action.
	 *
	 * @param string $action Action suffix.
	 */
	private static function handle_post_action( $action ) {
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		$notice_type = 'success';

		if ( ! self::can_sync_post( $post_id ) ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( 'filetoweb_integration_' . $action . '_' . $post_id );

		if ( 'pause_public' === $action ) {
			$owner_post_id = Source_Resolver::preview_owner_post_id( $post_id );
			if ( ! self::can_sync_post( $owner_post_id ) ) {
				wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
			}
			$paused        = Proud_HTML_Preview::pause_public( $owner_post_id );
			$message       = $paused
				? __( 'The original PDF is now public. FileToWeb syncing can continue in the background.', 'filetoweb-integration' )
				: __( 'FileToWeb could not switch this item to its original PDF.', 'filetoweb-integration' );
			$notice_type   = $paused ? 'success' : 'error';
		} elseif ( 'restore_public' === $action ) {
			$owner_post_id = Source_Resolver::preview_owner_post_id( $post_id );
			if ( ! self::can_sync_post( $owner_post_id ) ) {
				wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
			}
			$restored      = Proud_HTML_Preview::restore_public( $owner_post_id );
			$message       = $restored
				? __( 'The current FileToWeb HTML preview is public again.', 'filetoweb-integration' )
				: __( 'The HTML preview is not current, so the original PDF remains public. Sync or refresh the preview, then restore it again.', 'filetoweb-integration' );
			$notice_type   = $restored ? 'success' : 'error';
		} elseif ( 'sync_now' === $action ) {
			$result  = 'attachment' === get_post_type( $post_id ) ? Sync::sync_attachment_now( $post_id, 'manual_sync' ) : Sync::sync_document_now( $post_id, 'manual_sync' );
			$message = sprintf( __( 'FileToWeb sync %s.', 'filetoweb-integration' ), isset( $result['status'] ) ? $result['status'] : 'complete' );
		} elseif ( 'retry_processing' === $action ) {
			$document_id = Security::sanitize_identifier( get_post_meta( $post_id, Document_State::META_DOCUMENT_ID, true ) );
			$result      = $document_id ? Api_Client::reprocess_document( $document_id ) : array( 'ok' => false, 'error' => __( 'FileToWeb document ID is missing.', 'filetoweb-integration' ) );

			if ( ! empty( $result['ok'] ) && ! empty( $result['body']['document'] ) && is_array( $result['body']['document'] ) ) {
				Document_State::write_polled_state( $post_id, $result['body']['document'] );
				$message = __( 'FileToWeb processing has been queued again.', 'filetoweb-integration' );
			} else {
				Document_State::mark_failed(
					$post_id,
					isset( $result['error'] ) ? $result['error'] : __( 'FileToWeb retry failed.', 'filetoweb-integration' ),
					isset( $result['error_code'] ) ? $result['error_code'] : '',
					isset( $result['reference'] ) ? $result['reference'] : '',
					! empty( $result['retryable'] )
				);
				$message = __( 'FileToWeb could not queue the retry. Review the error and try again.', 'filetoweb-integration' );
			}
		} elseif ( 'refresh_preview' === $action ) {
			$refresh_post_id = Source_Resolver::preview_owner_post_id( $post_id );

			if ( $refresh_post_id !== $post_id && ! get_post_meta( $refresh_post_id, Document_State::META_DOCUMENT_ID, true ) ) {
				Document_State::copy_state( $post_id, $refresh_post_id );
			}

			Local_HTML::clear_poll_refresh_result( $refresh_post_id );
			$poll_result    = Sync::poll_post( $refresh_post_id, true );
			$refresh_result = Local_HTML::poll_refresh_result( $refresh_post_id );

			if ( $refresh_post_id !== $post_id ) {
				Document_State::copy_state( $refresh_post_id, $post_id );
			}

			$source_url  = get_post_meta( $refresh_post_id, Document_State::META_ORIGINAL_URL, true );
			$has_preview = ! $source_url || (bool) Proud_HTML_Preview::record_for_post( $refresh_post_id );

			if ( in_array( $refresh_result, array( 'updated', 'current' ), true ) && $has_preview ) {
				$message = __( 'FileToWeb embedded preview refreshed.', 'filetoweb-integration' );
			} else {
				$error       = get_post_meta( $refresh_post_id, Document_State::META_LAST_ERROR, true );
				$notice_type = 'error';
				$message     = $error
					? sprintf( __( 'FileToWeb could not refresh the embedded preview: %s', 'filetoweb-integration' ), $error )
					: sprintf( __( 'FileToWeb could not refresh the embedded preview. Status check: %s.', 'filetoweb-integration' ), $poll_result );
			}
		} else {
			$result  = Sync::poll_post( $post_id );
			$message = sprintf( __( 'FileToWeb status check %s.', 'filetoweb-integration' ), $result );
		}

		self::set_notice( $message, $notice_type );

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
	 * Build the ready-preview refresh URL for another admin surface.
	 *
	 * Meeting materials reuse the attachment refresh handler so publication,
	 * authorization, error reporting, and redirect behavior stay consistent.
	 *
	 * @param int $post_id PDF-backed post or attachment ID.
	 * @return string
	 */
	public static function refresh_preview_url( $post_id ) {
		return self::admin_action_url( 'refresh_preview', $post_id );
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
		$owner_id   = Source_Resolver::preview_owner_post_id( $post_id );

		echo self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $page_count ) {
			echo '<br />' . esc_html( sprintf( _n( '%d page', '%d pages', $page_count, 'filetoweb-integration' ), $page_count ) );
		}

		if ( Proud_HTML_Preview::is_public_paused( $owner_id ) ) {
			echo '<br /><strong style="color:#996800;">' . esc_html__( 'Public: Original PDF', 'filetoweb-integration' ) . '</strong>';
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

		$status      = get_post_meta( $post->ID, Document_State::META_STATUS, true );
		$state       = self::status_state( $status );
		$document_id = get_post_meta( $post->ID, Document_State::META_DOCUMENT_ID, true );

		if ( 'not_synced' === $state ) {
			$actions['filetoweb_sync'] = '<a href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Sync with FileToWeb', 'filetoweb-integration' ) . '</a>';
		} elseif ( 'processing' === $state ) {
			$actions[ $document_id ? 'filetoweb_poll' : 'filetoweb_sync' ] = $document_id
				? '<a href="' . esc_url( self::admin_action_url( 'poll_now', $post->ID ) ) . '">' . esc_html__( 'Check conversion progress', 'filetoweb-integration' ) . '</a>'
				: '<a href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Submit PDF now', 'filetoweb-integration' ) . '</a>';
		} elseif ( 'failed' === $state ) {
			$actions[ $document_id ? 'filetoweb_retry' : 'filetoweb_sync' ] = $document_id
				? '<a href="' . esc_url( self::admin_action_url( 'retry_processing', $post->ID ) ) . '">' . esc_html__( 'Retry FileToWeb processing', 'filetoweb-integration' ) . '</a>'
				: '<a href="' . esc_url( self::admin_action_url( 'sync_now', $post->ID ) ) . '">' . esc_html__( 'Retry FileToWeb sync', 'filetoweb-integration' ) . '</a>';
		} elseif ( 'ready' === $state && $document_id ) {
			$actions['filetoweb_refresh_preview'] = '<a href="' . esc_url( self::admin_action_url( 'refresh_preview', $post->ID ) ) . '">' . esc_html__( 'Refresh embedded preview', 'filetoweb-integration' ) . '</a>';
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
	 * @param string $type Notice type.
	 */
	public static function set_notice( $message, $type = 'success' ) {
		set_transient(
			self::notice_key(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => sanitize_text_field( $message ),
			),
			60
		);
	}

	/**
	 * Get and clear current-user transient notice.
	 *
	 * @return array|null
	 */
	private static function get_notice() {
		$key    = self::notice_key();
		$notice = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );
		}

		if ( is_array( $notice ) ) {
			return ! empty( $notice['message'] )
				? array(
					'type'    => isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success',
					'message' => sanitize_text_field( $notice['message'] ),
				)
				: null;
		}

		// Preserve notices stored by plugin versions before typed notices.
		return $notice
			? array(
				'type'    => 'success',
				'message' => sanitize_text_field( $notice ),
			)
			: null;
	}

	/**
	 * Current-user notice key.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'filetoweb_integration_notice_' . absint( get_current_user_id() );
	}

	/**
	 * Store a one-time connection notice for the current user.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 */
	private static function set_connection_notice( $type, $message ) {
		set_transient(
			self::connection_notice_key(),
			array(
				'type'    => 'error' === $type ? 'error' : 'success',
				'message' => sanitize_text_field( $message ),
			),
			60
		);
	}

	/**
	 * Read and clear the current user's connection notice.
	 *
	 * @return array|null
	 */
	private static function get_connection_notice() {
		$key    = self::connection_notice_key();
		$notice = get_transient( $key );

		if ( $notice ) {
			delete_transient( $key );
		}

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return null;
		}

		return array(
			'type'    => isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'success',
			'message' => sanitize_text_field( $notice['message'] ),
		);
	}

	/**
	 * Current-user connection notice key.
	 *
	 * @return string
	 */
	private static function connection_notice_key() {
		return 'filetoweb_integration_connection_notice_' . absint( get_current_user_id() );
	}
}
