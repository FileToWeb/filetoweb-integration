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
	const QUERY_VAR_POST_ID    = 'filetoweb_local_html';
	const QUERY_VAR_TOKEN      = 'ftw_token';
	const HOOK_RETRY_PREVIEW   = 'filetoweb_integration_retry_preview';
	const PREVIEW_RETRY_DELAYS = array( 60, 300, 900, 3600 );

	/**
	 * Per-request publication results produced by status polling.
	 *
	 * @var array
	 */
	private static $poll_refresh_results = array();

	/**
	 * Whether the latest failed publication is safe to retry automatically.
	 *
	 * @var bool
	 */
	private static $last_refresh_retryable = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_local_html' ), -100 );
		add_action( 'filetoweb_integration_after_sync_post', array( __CLASS__, 'refresh_after_sync' ), 20, 3 );
		add_action( 'filetoweb_integration_after_poll_post', array( __CLASS__, 'refresh_after_poll' ), 20, 3 );
		add_action( self::HOOK_RETRY_PREVIEW, array( __CLASS__, 'retry_preview' ) );
		add_action( 'trashed_post', array( __CLASS__, 'stop_preview_retry' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'stop_preview_retry' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'stop_preview_retry' ) );
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
		$result = self::refresh_for_post( $post_id, $document );
		self::handle_refresh_result( $post_id, $result );
	}

	/**
	 * Refresh local HTML after a poll response.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $document API document.
	 * @param bool  $force_latest Whether an administrator explicitly requested the latest published HTML.
	 */
	public static function refresh_after_poll( $post_id, $document, $force_latest = false ) {
		$post_id = absint( $post_id );
		if ( $force_latest ) {
			self::clear_preview_retry( $post_id );
		}

		self::$poll_refresh_results[ $post_id ] = self::refresh_for_post( $post_id, $document, $force_latest );
		self::handle_refresh_result( $post_id, self::$poll_refresh_results[ $post_id ] );
	}

	/**
	 * Retry publication for a ready conversion without resubmitting its PDF.
	 *
	 * @param int $post_id Source post ID.
	 */
	public static function retry_preview( $post_id ) {
		$post_id = absint( $post_id );
		delete_post_meta( $post_id, Document_State::META_NEXT_PREVIEW_RETRY_AT );

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || in_array( isset( $post->post_status ) ? $post->post_status : '', array( 'trash', 'auto-draft' ), true ) || 'ready' !== get_post_meta( $post_id, Document_State::META_STATUS, true ) ) {
			self::clear_preview_retry( $post_id );
			return;
		}

		$result = self::refresh_for_post( $post_id, null, true );
		self::handle_refresh_result( $post_id, $result );
	}

	/**
	 * Remove a deleted or trashed post from the preview retry queue.
	 *
	 * @param int $post_id Source post ID.
	 */
	public static function stop_preview_retry( $post_id ) {
		self::clear_preview_retry( absint( $post_id ) );
	}

	/**
	 * Clear every queued preview retry during plugin deactivation.
	 */
	public static function clear_all_preview_retries() {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::HOOK_RETRY_PREVIEW );
		}
	}

	/**
	 * Return the publication result produced by the latest poll in this request.
	 *
	 * @param int $post_id Post ID.
	 * @return string Result, or an empty string when polling did not reach publication.
	 */
	public static function poll_refresh_result( $post_id ) {
		$post_id = absint( $post_id );

		return isset( self::$poll_refresh_results[ $post_id ] ) ? self::$poll_refresh_results[ $post_id ] : '';
	}

	/**
	 * Clear a prior per-request poll publication result.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function clear_poll_refresh_result( $post_id ) {
		unset( self::$poll_refresh_results[ absint( $post_id ) ] );
	}

	/**
	 * Refresh cached local HTML for a ready source.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $document Optional API document.
	 * @param bool       $force_latest Bypass the PDF-based cache check and fetch the latest published HTML.
	 * @return string Status.
	 */
	public static function refresh_for_post( $post_id, $document = null, $force_latest = false ) {
		$post_id                     = absint( $post_id );
		self::$last_refresh_retryable = false;

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

		$viewer_url = self::local_viewer_url( $viewer_url );

		if ( ! $viewer_url || ! $fingerprint ) {
			return 'skipped';
		}

		if ( ! $force_latest && self::has_current_local_html( $post_id, $viewer_url, $fingerprint ) ) {
			$preview_record  = Proud_HTML_Preview::record_for_post( $post_id );
			$needs_migration = Proud_HTML_Preview::needs_storage_migration( $preview_record );

			if ( get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true ) && $needs_migration ) {
				if ( ! Proud_HTML_Preview::migrate_existing_post( $post_id ) ) {
					$error = Proud_HTML_Preview::last_publish_error();
					update_post_meta( $post_id, Document_State::META_LAST_ERROR, $error ? $error : __( 'FileToWeb local HTML cache could not be published.', 'filetoweb-integration' ) );
					return 'failed';
				}
			}
			Native_Page::maybe_auto_create_draft( $post_id );
			update_post_meta( $post_id, Document_State::META_LAST_ERROR, '' );
			return 'current';
		}

		$response = self::fetch_html( $viewer_url, $force_latest );

		if ( ! $response['ok'] ) {
			self::$last_refresh_retryable = ! empty( $response['retryable'] );
			update_post_meta( $post_id, Document_State::META_LAST_ERROR, $response['error'] );
			return 'failed';
		}

		$html = self::prepare_local_html( $response['html'] );

		if ( ! $html ) {
			update_post_meta( $post_id, Document_State::META_LAST_ERROR, __( 'FileToWeb local HTML cache was empty.', 'filetoweb-integration' ) );
			return 'failed';
		}

		$source_url = get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true );

		if ( $source_url ) {
			$record = Proud_HTML_Preview::publish( $post_id, $html, $viewer_url, $source_url, $fingerprint, $force_latest );

			if ( ! $record ) {
				self::$last_refresh_retryable = Proud_HTML_Preview::last_publish_retryable();
				$error = Proud_HTML_Preview::last_publish_error();
				update_post_meta( $post_id, Document_State::META_LAST_ERROR, $error ? $error : __( 'FileToWeb local HTML cache could not be written.', 'filetoweb-integration' ) );
				return 'failed';
			}
		} else {
			// PDF-to-Page uploads intentionally retain no public source PDF and
			// use the local cache only as an intermediate draft-page artifact.
			$html = self::mirror_filetoweb_assets( $post_id, $fingerprint, $viewer_url, $html );
			$path = self::write_local_file( $post_id, $fingerprint, $html );

			if ( ! $path ) {
				update_post_meta( $post_id, Document_State::META_LAST_ERROR, __( 'FileToWeb local HTML cache could not be written.', 'filetoweb-integration' ) );
				return 'failed';
			}

			update_post_meta( $post_id, Document_State::META_LOCAL_HTML_PATH, $path );
			update_post_meta( $post_id, Document_State::META_LOCAL_HTML_TOKEN, wp_generate_password( 32, false, false ) );
			update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, $viewer_url );
			update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, $fingerprint );
			update_post_meta( $post_id, Document_State::META_LOCAL_HTML_UPDATED_AT, current_time( 'mysql', true ) );
		}

		Native_Page::maybe_auto_create_draft( $post_id );
		update_post_meta( $post_id, Document_State::META_LAST_ERROR, '' );

		return 'updated';
	}

	/**
	 * Keep preview publication retries independent from conversion polling.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $result Refresh result.
	 */
	private static function handle_refresh_result( $post_id, $result ) {
		if ( in_array( $result, array( 'updated', 'current' ), true ) ) {
			self::clear_preview_retry( $post_id );
			return;
		}

		if ( 'failed' === $result && self::$last_refresh_retryable ) {
			self::schedule_preview_retry( $post_id );
		} elseif ( 'failed' === $result ) {
			self::clear_preview_retry( $post_id );
		}
	}

	/**
	 * Schedule a bounded publication retry for one ready document.
	 *
	 * @param int $post_id Source post ID.
	 */
	private static function schedule_preview_retry( $post_id ) {
		$post_id = absint( $post_id );
		$args    = array( $post_id );
		if ( ! $post_id || wp_next_scheduled( self::HOOK_RETRY_PREVIEW, $args ) ) {
			return;
		}

		$attempts = absint( get_post_meta( $post_id, Document_State::META_PREVIEW_RETRY_ATTEMPTS, true ) );
		if ( $attempts >= count( self::PREVIEW_RETRY_DELAYS ) ) {
			return;
		}

		$delay     = self::PREVIEW_RETRY_DELAYS[ $attempts ];
		$jitter    = $post_id % max( 1, min( 60, (int) floor( $delay / 10 ) ) );
		$when      = time() + $delay + $jitter;
		$scheduled = wp_schedule_single_event( $when, self::HOOK_RETRY_PREVIEW, $args );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			return;
		}

		update_post_meta( $post_id, Document_State::META_PREVIEW_RETRY_ATTEMPTS, $attempts + 1 );
		update_post_meta( $post_id, Document_State::META_NEXT_PREVIEW_RETRY_AT, $when );
	}

	/**
	 * Clear one post's publication retry state.
	 *
	 * @param int $post_id Source post ID.
	 */
	private static function clear_preview_retry( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK_RETRY_PREVIEW, array( $post_id ) );
		delete_post_meta( $post_id, Document_State::META_PREVIEW_RETRY_ATTEMPTS );
		delete_post_meta( $post_id, Document_State::META_NEXT_PREVIEW_RETRY_AT );
	}

	/**
	 * Return the local public HTML URL for a source post.
	 *
	 * @param int $post_id Post ID.
	 * @param bool $prepare_record Whether a specifically targeted record may be normalized.
	 * @return string
	 */
	public static function local_url( $post_id, $prepare_record = true ) {
		$post_id         = absint( $post_id );
		$preview_record  = $prepare_record
			? Proud_HTML_Preview::prepare_public_record( $post_id )
			: Proud_HTML_Preview::record_for_post( $post_id );
		$has_preview_api = function_exists( '\\Proud\\Core\\proud_html_preview_url' );
		$is_durable      = Proud_HTML_Preview::is_durable_record( $preview_record );

		// A verified shared-storage preview lives outside the pod. Resolve it before
		// looking at this pod's ephemeral uploads directory.
		if ( $has_preview_api && $is_durable ) {
			$source_url = get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true );
			$durable_url = call_user_func( '\\Proud\\Core\\proud_html_preview_url', $post_id, $source_url );

			if ( $durable_url ) {
				return $durable_url;
			}
		}

		if ( ! self::has_local_html( $post_id ) ) {
			return '';
		}

		// Preserve the existing local fallback for pre-marker records while their
		// bounded background migration is still pending.
		if ( $has_preview_api && $preview_record && ! $is_durable ) {
			$source_url = get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true );
			$durable_url = call_user_func( '\\Proud\\Core\\proud_html_preview_url', $post_id, $source_url );

			if ( $durable_url ) {
				return $durable_url;
			}
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
	 * @param bool $prepare_record Whether a specifically targeted record may be normalized.
	 * @return string
	 */
	public static function public_url_for_post( $post_id, $prepare_record = true ) {
		$owner_post_id = Source_Resolver::preview_owner_post_id( $post_id );

		if ( Proud_HTML_Preview::is_public_paused( $owner_post_id ) ) {
			return '';
		}

		$page_url = Native_Page::approved_page_url( $post_id );

		if ( $page_url ) {
			return $page_url;
		}

		return self::local_url( $owner_post_id, $prepare_record );
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

		$hide_shell = '<style data-filetoweb-local-viewer="v1">.ftw-tabbar,[data-ftw-tabbar],.ftw-page-filename,.ftw-static-bundle-heading{display:none!important}.ftw-page-body{margin:0}.ftw-shell{padding-top:0!important}.ftw-generated-root{box-sizing:border-box!important;max-width:100%!important;min-width:0!important}</style>';

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
	 * Return the FileToWeb URL variant intended for local WordPress embedding.
	 *
	 * @param string $url FileToWeb viewer URL.
	 * @return string
	 */
	private static function local_viewer_url( $url ) {
		$url = Security::sanitize_filetoweb_url( $url );

		if ( ! $url ) {
			return '';
		}

		$path = (string) parse_url( $url, PHP_URL_PATH );

		if ( false === stripos( $path, '/continuous' ) ) {
			return $url;
		}

		return Security::sanitize_filetoweb_url(
			add_query_arg(
				array(
					'chrome' => '0',
				),
				$url
			)
		);
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
	 * @param bool   $bypass_cache Request revalidation from FileToWeb and intermediary caches.
	 * @return array
	 */
	private static function fetch_html( $url, $bypass_cache = false ) {
		$url = Security::sanitize_filetoweb_url( $url );

		if ( ! $url ) {
			return array(
				'ok'        => false,
				'error'     => __( 'FileToWeb local HTML URL is not allowed.', 'filetoweb-integration' ),
				'html'      => '',
				'retryable' => false,
			);
		}

		$timeout = apply_filters( 'filetoweb_integration_preview_timeout', 45, $url );
		$args    = array(
			'timeout'            => max( 10, min( 60, absint( $timeout ) ) ),
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
		);

		if ( $bypass_cache ) {
			$args['headers'] = array(
				'Cache-Control' => 'no-cache',
				'Pragma'        => 'no-cache',
			);
		}

		$response = wp_remote_get(
			$url,
			$args
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			return array(
				'ok'        => false,
				'error'     => $message,
				'html'      => '',
				'retryable' => Api_Client::is_retryable_wp_error( $response ),
			);
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'        => false,
				'error'     => sprintf( __( 'FileToWeb local HTML fetch returned HTTP %d.', 'filetoweb-integration' ), $code ),
				'html'      => '',
				'retryable' => in_array( $code, array( 408, 425, 429 ), true ) || $code >= 500,
			);
		}

		return array(
			'ok'        => true,
			'error'     => '',
			'html'      => (string) wp_remote_retrieve_body( $response ),
			'retryable' => false,
		);
	}

	/**
	 * Mirror FileToWeb assets referenced by local HTML into WordPress uploads.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $fingerprint Source fingerprint.
	 * @param string $viewer_url FileToWeb viewer URL used for the local HTML.
	 * @param string $html Local HTML.
	 * @return string
	 */
	private static function mirror_filetoweb_assets( $post_id, $fingerprint, $viewer_url, $html ) {
		$post_id    = absint( $post_id );
		$viewer_url = Security::sanitize_filetoweb_url( $viewer_url );

		if ( ! $post_id || ! $viewer_url || ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
		$baseurl = isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';

		if ( ! $basedir || ! $baseurl ) {
			return $html;
		}

		$asset_root = self::asset_root( $post_id, $fingerprint );
		$asset_dir  = trailingslashit( $basedir ) . 'filetoweb-integration/assets/' . $asset_root;
		$asset_url  = trailingslashit( $baseurl ) . 'filetoweb-integration/assets/' . $asset_root;
		$mirrored   = array();

		return preg_replace_callback(
			'~\b(?P<attr>src|href|poster)=(?P<quote>["\'])(?P<value>[^"\']+)(?P=quote)~i',
			function ( $match ) use ( $viewer_url, $asset_dir, $asset_url, &$mirrored ) {
				$value      = html_entity_decode( $match['value'], ENT_QUOTES, 'UTF-8' );
				$remote_url = self::resolve_filetoweb_asset_url( $value, $viewer_url );

				if ( ! $remote_url ) {
					return $match[0];
				}

				if ( isset( $mirrored[ $remote_url ] ) ) {
					$local_url = $mirrored[ $remote_url ];
				} else {
					$asset_path = self::asset_relative_path( $remote_url );

					if ( ! $asset_path ) {
						return $match[0];
					}

					$local_path = trailingslashit( $asset_dir ) . $asset_path;
					$local_url  = trailingslashit( $asset_url ) . str_replace( '%2F', '/', rawurlencode( $asset_path ) );
					$local_url  = str_replace( '%2F', '/', $local_url );

					if ( ! self::download_asset( $remote_url, $local_path ) ) {
						return $match[0];
					}

					$mirrored[ $remote_url ] = $local_url;
				}

				return $match['attr'] . '=' . $match['quote'] . esc_url_raw( $local_url ) . $match['quote'];
			},
			$html
		);
	}

	/**
	 * Return deterministic local asset root.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $fingerprint Source fingerprint.
	 * @return string
	 */
	private static function asset_root( $post_id, $fingerprint ) {
		$hash = substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $fingerprint ), 0, 32 );

		return absint( $post_id ) . '-' . ( $hash ? $hash : 'asset' );
	}

	/**
	 * Resolve a FileToWeb asset URL from an HTML attribute value.
	 *
	 * @param string $value Raw attribute value.
	 * @param string $viewer_url FileToWeb viewer URL.
	 * @return string
	 */
	private static function resolve_filetoweb_asset_url( $value, $viewer_url ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value || preg_match( '~^(data|blob|mailto|tel|javascript):~i', $value ) || '#' === substr( $value, 0, 1 ) ) {
			return '';
		}

		$viewer_parts = parse_url( $viewer_url );

		if ( empty( $viewer_parts['scheme'] ) || empty( $viewer_parts['host'] ) ) {
			return '';
		}

		$asset_url = '';
		$parts     = parse_url( $value );

		if ( ! empty( $parts['scheme'] ) ) {
			$asset_url = $value;
		} elseif ( 0 === strpos( $value, '//' ) ) {
			$asset_url = $viewer_parts['scheme'] . ':' . $value;
		} elseif ( 0 === strpos( $value, '/' ) ) {
			$asset_url = $viewer_parts['scheme'] . '://' . $viewer_parts['host'] . $value;
		} else {
			$base_path = isset( $viewer_parts['path'] ) ? preg_replace( '~/[^/]*$~', '/', $viewer_parts['path'] ) : '/';
			$asset_url = $viewer_parts['scheme'] . '://' . $viewer_parts['host'] . $base_path . $value;
		}

		$asset_url = Security::sanitize_filetoweb_url( $asset_url );

		if ( ! $asset_url ) {
			return '';
		}

		return self::asset_relative_path( $asset_url ) ? $asset_url : '';
	}

	/**
	 * Return a safe local relative path for a FileToWeb asset URL.
	 *
	 * @param string $url FileToWeb asset URL.
	 * @return string
	 */
	private static function asset_relative_path( $url ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );

		if ( ! preg_match( '~^/d/[^/]+/assets/(?P<asset>.+)$~', $path, $match ) ) {
			return '';
		}

		$asset = rawurldecode( $match['asset'] );
		$parts = array_filter( explode( '/', str_replace( '\\', '/', $asset ) ), 'strlen' );

		if ( empty( $parts ) || count( $parts ) > 8 ) {
			return '';
		}

		$safe_parts = array();

		foreach ( $parts as $part ) {
			if ( '.' === $part || '..' === $part ) {
				return '';
			}

			$safe = sanitize_file_name( $part );

			if ( '' === $safe ) {
				return '';
			}

			$safe_parts[] = $safe;
		}

		$filename = end( $safe_parts );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ) {
			return '';
		}

		return implode( '/', $safe_parts );
	}

	/**
	 * Download a FileToWeb asset into the local uploads directory.
	 *
	 * @param string $url Remote FileToWeb asset URL.
	 * @param string $path Local file path.
	 * @return bool
	 */
	private static function download_asset( $url, $path ) {
		if ( file_exists( $path ) && is_readable( $path ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return false;
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
			return false;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) > 5 * 1024 * 1024 ) {
			return false;
		}

		return false !== file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
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
