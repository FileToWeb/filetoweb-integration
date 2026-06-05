<?php
/**
 * Local HTML cache for ready FileToWeb documents.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Local_HTML {
	const QUERY_VAR_POST_ID = 'filetoweb_local_html';
	const QUERY_VAR_TOKEN   = 'ftw_token';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_local_html' ), -100 );
		add_action( 'filetoweb_integration_after_sync_post', array( __CLASS__, 'refresh_after_sync' ), 20, 3 );
		add_action( 'filetoweb_integration_after_poll_post', array( __CLASS__, 'refresh_after_poll' ), 20, 2 );
	}

	/**
	 * Refresh local HTML after a sync response.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $source Source.
	 * @param array $document API document.
	 */
	public static function refresh_after_sync( $post_id, $source, $document ) {
		unset( $source );
		self::refresh_for_post( $post_id, $document );
	}

	/**
	 * Refresh local HTML after a poll response.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $document API document.
	 */
	public static function refresh_after_poll( $post_id, $document ) {
		self::refresh_for_post( $post_id, $document );
	}

	/**
	 * Refresh cached local HTML for a ready source.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $document Optional API document.
	 * @return string Status.
	 */
	public static function refresh_for_post( $post_id, $document = null ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'ready' !== get_post_meta( $post_id, Document_State::META_STATUS, true ) ) {
			return 'skipped';
		}

		$viewer_url  = self::source_viewer_url( $post_id );
		$fingerprint = get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT, true );

		if ( is_array( $document ) ) {
			$viewer_url = Security::sanitize_filetoweb_url(
				! empty( $document['continuous_url'] ) ? $document['continuous_url'] : ( ! empty( $document['html_url'] ) ? $document['html_url'] : $viewer_url )
			);
		}

		if ( ! $viewer_url || ! $fingerprint ) {
			return 'skipped';
		}

		if ( self::has_current_local_html( $post_id, $viewer_url, $fingerprint ) ) {
			Native_Page::maybe_auto_create_draft( $post_id );
			return 'current';
		}

		$response = self::fetch_html( $viewer_url );

		if ( ! $response['ok'] ) {
			update_post_meta( $post_id, Document_State::META_LAST_ERROR, $response['error'] );
			return 'failed';
		}

		$html = self::prepare_local_html( $response['html'] );

		if ( ! $html ) {
			update_post_meta( $post_id, Document_State::META_LAST_ERROR, __( 'FileToWeb local HTML cache was empty.', 'filetoweb-integration' ) );
			return 'failed';
		}

		$path = self::write_local_file( $post_id, $fingerprint, $html );

		if ( ! $path ) {
			update_post_meta( $post_id, Document_State::META_LAST_ERROR, __( 'FileToWeb local HTML cache could not be written.', 'filetoweb-integration' ) );
			return 'failed';
		}

		$token = wp_generate_password( 32, false, false );

		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_PATH, $path );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_TOKEN, $token );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, $viewer_url );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, $fingerprint );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_UPDATED_AT, current_time( 'mysql', true ) );

		Native_Page::maybe_auto_create_draft( $post_id );

		return 'updated';
	}

	/**
	 * Return the local public HTML URL for a source post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function local_url( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! self::has_local_html( $post_id ) ) {
			return '';
		}

		$token = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_TOKEN, true );

		if ( ! $token ) {
			return '';
		}

		return add_query_arg(
			array(
				self::QUERY_VAR_POST_ID => $post_id,
				self::QUERY_VAR_TOKEN   => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Return the preferred citizen-facing URL for a source.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function public_url_for_post( $post_id ) {
		$page_url = Native_Page::approved_page_url( $post_id );

		if ( $page_url ) {
			return $page_url;
		}

		return self::local_url( $post_id );
	}

	/**
	 * Does the source have a local HTML file?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_local_html( $post_id ) {
		$path = self::safe_local_path( $post_id );

		return $path && file_exists( $path ) && is_readable( $path );
	}

	/**
	 * Return local HTML contents.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function read_local_html( $post_id ) {
		$path = self::safe_local_path( $post_id );

		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}

		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Serve cached HTML without calling FileToWeb.
	 */
	public static function maybe_serve_local_html() {
		$post_id = isset( $_GET[ self::QUERY_VAR_POST_ID ] ) ? absint( wp_unslash( $_GET[ self::QUERY_VAR_POST_ID ] ) ) : 0;

		if ( ! $post_id ) {
			return;
		}

		$provided = isset( $_GET[ self::QUERY_VAR_TOKEN ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR_TOKEN ] ) ) : '';
		$expected = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_TOKEN, true );

		if ( ! $provided || ! $expected || ! hash_equals( (string) $expected, (string) $provided ) ) {
			status_header( 404 );
			exit;
		}

		$html = self::read_local_html( $post_id );

		if ( ! $html ) {
			$fallback = Source_Resolver::admin_original_source_url( get_post( $post_id ) );

			if ( $fallback ) {
				wp_safe_redirect( $fallback );
				exit;
			}

			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Prepare FileToWeb HTML for local citizen rendering.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function prepare_local_html( $html ) {
		$html = is_string( $html ) ? trim( $html ) : '';

		if ( '' === $html ) {
			return '';
		}

		$html = preg_replace( '~<script\b[^>]*>.*?</script>~is', '', $html );
		$html = preg_replace( '~<meta\b[^>]*name=(["\'])ftw-tabbar\1[^>]*>~is', '', $html );

		$hide_shell = '<style data-filetoweb-local-viewer="v1">.ftw-tabbar,[data-ftw-tabbar],.ftw-page-filename,.ftw-static-bundle-heading{display:none!important}.ftw-page-body{margin:0}.ftw-shell{padding-top:0!important}</style>';

		if ( false !== stripos( $html, '</head>' ) ) {
			$html = preg_replace( '~</head>~i', $hide_shell . '</head>', $html, 1 );
		} else {
			$html = '<!doctype html><html><head>' . $hide_shell . '</head><body>' . $html . '</body></html>';
		}

		return $html;
	}

	/**
	 * Return body content suitable for a WordPress page.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function local_body_for_post( $post_id ) {
		$html = self::read_local_html( $post_id );

		if ( ! $html ) {
			return '';
		}

		$styles = '';

		if ( preg_match_all( '~<style\b[^>]*>.*?</style>~is', $html, $matches ) ) {
			$styles = implode( "\n", $matches[0] );
		}

		$body = $html;

		if ( preg_match( '~<body\b[^>]*>([\s\S]*?)</body>~i', $html, $match ) ) {
			$body = $match[1];
		}

		$body = preg_replace( '~<script\b[^>]*>.*?</script>~is', '', $body );

		return trim( $styles . "\n" . $body );
	}

	/**
	 * Return the FileToWeb source URL to fetch.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function source_viewer_url( $post_id ) {
		$continuous_url = Document_State::ready_continuous_url( $post_id );

		return $continuous_url ? $continuous_url : Document_State::ready_html_url( $post_id );
	}

	/**
	 * Is the current local cache up to date?
	 *
	 * @param int    $post_id Post ID.
	 * @param string $viewer_url Viewer URL.
	 * @param string $fingerprint Source fingerprint.
	 * @return bool
	 */
	private static function has_current_local_html( $post_id, $viewer_url, $fingerprint ) {
		return self::has_local_html( $post_id )
			&& $viewer_url === get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, true )
			&& $fingerprint === get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, true );
	}

	/**
	 * Fetch ready FileToWeb HTML for local caching.
	 *
	 * @param string $url FileToWeb URL.
	 * @return array
	 */
	private static function fetch_html( $url ) {
		$url = Security::sanitize_filetoweb_url( $url );

		if ( ! $url ) {
			return array(
				'ok'    => false,
				'error' => __( 'FileToWeb local HTML URL is not allowed.', 'filetoweb-integration' ),
				'html'  => '',
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 20,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
				'html'  => '',
			);
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'    => false,
				'error' => sprintf( __( 'FileToWeb local HTML fetch returned HTTP %d.', 'filetoweb-integration' ), $code ),
				'html'  => '',
			);
		}

		return array(
			'ok'    => true,
			'error' => '',
			'html'  => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Write local HTML to uploads.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $fingerprint Source fingerprint.
	 * @param string $html HTML.
	 * @return string
	 */
	private static function write_local_file( $post_id, $fingerprint, $html ) {
		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( ! $basedir ) {
			return '';
		}

		$dir = trailingslashit( $basedir ) . 'filetoweb-integration';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$filename = absint( $post_id ) . '-' . substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $fingerprint ), 0, 32 ) . '.html';
		$path     = trailingslashit( $dir ) . $filename;

		return false === file_put_contents( $path, $html ) ? '' : $path; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
	}

	/**
	 * Return a validated local HTML cache path.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function safe_local_path( $post_id ) {
		$path    = get_post_meta( absint( $post_id ), Document_State::META_LOCAL_HTML_PATH, true );
		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		if ( ! is_string( $path ) || ! $path || ! $basedir ) {
			return '';
		}

		$real_path = realpath( $path );
		$real_base = realpath( trailingslashit( $basedir ) . 'filetoweb-integration' );

		$real_base = $real_base ? trailingslashit( $real_base ) : '';

		if ( ! $real_path || ! $real_base || 0 !== strpos( $real_path, $real_base ) ) {
			return '';
		}

		return $real_path;
	}
}
