<?php
/**
 * Local WordPress page workflow for converted documents.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Native_Page {
	const ACTION_GENERATE = 'filetoweb_integration_generate_native_page';
	const ACTION_APPROVE  = 'filetoweb_integration_approve_native_page';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_GENERATE, array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_' . self::ACTION_APPROVE, array( __CLASS__, 'handle_approval' ) );
	}

	/**
	 * Create or update a draft local page when ready.
	 *
	 * @param int $post_id Source post ID.
	 * @return int Page ID.
	 */
	public static function ensure_draft_for_post( $post_id ) {
		$post_id = absint( $post_id );
		$content = Local_HTML::local_body_for_post( $post_id );

		if ( ! $post_id || ! $content ) {
			return 0;
		}

		$fingerprint = get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT, true );
		$page_id     = absint( get_post_meta( $post_id, Document_State::META_LOCAL_PAGE_ID, true ) );

		if ( $page_id && 'trash' === get_post_status( $page_id ) ) {
			$page_id = 0;
		}

		$title = self::source_title( $post_id );

		$postarr = array(
			'post_title'   => $title ? $title : __( 'FileToWeb document', 'filetoweb-integration' ),
			'post_content' => $content,
			'post_type'    => 'page',
		);

		if ( $page_id ) {
			$postarr['ID'] = $page_id;
			$result        = wp_update_post( $postarr );
		} else {
			$postarr['post_status'] = 'draft';
			$result                 = wp_insert_post( $postarr );
		}

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		$page_id = absint( $result );

		if ( ! $page_id ) {
			return 0;
		}

		update_post_meta( $post_id, Document_State::META_LOCAL_PAGE_ID, $page_id );
		update_post_meta( $post_id, Document_State::META_LOCAL_PAGE_SOURCE_FP, $fingerprint );
		update_post_meta( $post_id, Document_State::META_LOCAL_PAGE_UPDATED_AT, current_time( 'mysql', true ) );
		update_post_meta( $page_id, '_filetoweb_source_post_id', $post_id );

		return $page_id;
	}

	/**
	 * Auto-create a draft page unless disabled.
	 *
	 * @param int $post_id Source post ID.
	 */
	public static function maybe_auto_create_draft( $post_id ) {
		if ( ! apply_filters( 'filetoweb_integration_auto_create_native_page', false, absint( $post_id ) ) ) {
			return;
		}

		self::ensure_draft_for_post( $post_id );
	}

	/**
	 * Return approved local WordPress page URL.
	 *
	 * @param int $post_id Source post ID.
	 * @return string
	 */
	public static function approved_page_url( $post_id ) {
		$post_id = absint( $post_id );

		if ( '1' !== get_post_meta( $post_id, Document_State::META_LOCAL_PAGE_APPROVED, true ) ) {
			return '';
		}

		$page_id = absint( get_post_meta( $post_id, Document_State::META_LOCAL_PAGE_ID, true ) );

		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Render local WordPress page controls in the FileToWeb metabox.
	 *
	 * @param \WP_Post $post Source post.
	 */
	public static function render_admin_panel( $post ) {
		if ( ! is_object( $post ) || ! $post->ID ) {
			return;
		}

		$post_id   = absint( $post->ID );
		$local_url = Local_HTML::local_url( $post_id );

		echo '<hr />';
		echo '<p><strong>' . esc_html__( 'WordPress-local version', 'filetoweb-integration' ) . '</strong></p>';

		if ( $local_url ) {
			echo '<p><a href="' . esc_url( $local_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View local HTML copy', 'filetoweb-integration' ) . '</a></p>';
		} else {
			echo '<p><em>' . esc_html__( 'No local HTML copy yet. Poll after FileToWeb is ready.', 'filetoweb-integration' ) . '</em></p>';
		}
	}

	/**
	 * Handle draft generation.
	 */
	public static function handle_generate() {
		$post_id = self::requested_post_id();

		if ( ! Capabilities::current_user_can_manage_native_page( $post_id ) ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( self::ACTION_GENERATE . '_' . $post_id );

		if ( ! Local_HTML::has_local_html( $post_id ) ) {
			Local_HTML::refresh_for_post( $post_id );
		}

		$page_id = self::ensure_draft_for_post( $post_id );

		Admin::set_notice( $page_id ? __( 'WordPress draft updated.', 'filetoweb-integration' ) : __( 'WordPress draft could not be updated.', 'filetoweb-integration' ) );
		self::redirect_back( $post_id );
	}

	/**
	 * Handle public replacement approval.
	 */
	public static function handle_approval() {
		$post_id = self::requested_post_id();

		if ( ! Capabilities::current_user_can_manage_native_page( $post_id ) ) {
			wp_die( esc_html__( 'Unauthorized', 'filetoweb-integration' ) );
		}

		check_admin_referer( self::ACTION_APPROVE . '_' . $post_id );

		$approved = isset( $_GET['approved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['approved'] ) );
		$page_id  = absint( get_post_meta( $post_id, Document_State::META_LOCAL_PAGE_ID, true ) );

		if ( $approved && ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) ) {
			Admin::set_notice( __( 'Publish the WordPress page before approving replacement.', 'filetoweb-integration' ) );
			self::redirect_back( $post_id );
		}

		update_post_meta( $post_id, Document_State::META_LOCAL_PAGE_APPROVED, $approved ? '1' : '0' );
		Admin::set_notice( $approved ? __( 'WordPress page approved for public replacement.', 'filetoweb-integration' ) : __( 'WordPress page replacement disabled.', 'filetoweb-integration' ) );
		self::redirect_back( $post_id );
	}

	/**
	 * Return a nonce-protected admin action URL.
	 *
	 * @param string $action Action.
	 * @param int    $post_id Post ID.
	 * @param string $approved Optional approval flag.
	 * @return string
	 */
	private static function admin_action_url( $action, $post_id, $approved = '' ) {
		$args = array(
			'action'  => $action,
			'post_id' => absint( $post_id ),
		);

		if ( '' !== $approved ) {
			$args['approved'] = $approved;
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action . '_' . absint( $post_id ) );
	}

	/**
	 * Requested post ID.
	 *
	 * @return int
	 */
	private static function requested_post_id() {
		return isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
	}

	/**
	 * Redirect to source edit screen.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function redirect_back( $post_id ) {
		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = admin_url( 'post.php?post=' . absint( $post_id ) . '&action=edit' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Source title.
	 *
	 * @param int $post_id Source post ID.
	 * @return string
	 */
	private static function source_title( $post_id ) {
		$title = get_the_title( $post_id );

		return $title ? $title : __( 'FileToWeb document', 'filetoweb-integration' );
	}
}
