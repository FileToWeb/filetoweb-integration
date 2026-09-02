<?php
/**
 * Front-end link replacement.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Link_Rewriter {
	/**
	 * Per-request FileToWeb page URL to WordPress-local preview URL cache.
	 *
	 * @var array
	 */
	private static $resolved_preview_urls = array();

	/**
	 * Per-request lookup cache.
	 *
	 * @var array
	 */
	private static $resolved_public_urls = array();

	/**
	 * Current Proud Document being rewritten by the output buffer.
	 *
	 * @var int
	 */
	private static $document_viewer_post_id = 0;

	/**
	 * Current ProudCity Meeting being rewritten by the output buffer.
	 *
	 * @var int
	 */
	private static $meeting_viewer_post_id = 0;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 20, 2 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_document_meta' ), 20, 4 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content_pdf_links' ), 20 );
		add_filter( 'widget_text', array( __CLASS__, 'filter_content_pdf_links' ), 20 );
		add_filter( 'widget_text_content', array( __CLASS__, 'filter_content_pdf_links' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_document_viewer_buffer' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_meeting_viewer_buffer' ), 0 );
	}

	/**
	 * Replace attachment helper URLs on the front end.
	 *
	 * @param string $url Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		$proudcity_is_resolving_source = function_exists( '\\Proud\\Core\\proud_html_preview_is_resolving_source' )
			&& call_user_func( '\\Proud\\Core\\proud_html_preview_is_resolving_source' );

		if ( Source_Resolver::is_reading_original_source() || $proudcity_is_resolving_source || ! self::is_public_replacement_context() ) {
			return $url;
		}

		if ( self::is_current_meeting_material_attachment( $attachment_id ) ) {
			return $url;
		}

		$html_url = self::ready_attachment_public_url( $attachment_id, $url );

		return $html_url ? $html_url : $url;
	}

	/**
	 * Replace Proud Document `document` meta on the front end.
	 *
	 * @param mixed  $value Existing short-circuit value.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key Meta key.
	 * @param bool   $single Single.
	 * @return mixed
	 */
	public static function filter_document_meta( $value, $object_id, $meta_key, $single ) {
		if ( 'document' !== $meta_key || Source_Resolver::is_reading_original_source() || ! self::is_public_replacement_context() ) {
			return $value;
		}

		if ( 'document' !== get_post_type( $object_id ) ) {
			return $value;
		}

		if ( self::is_current_document_single( $object_id ) ) {
			return $value;
		}

		$html_url = self::ready_document_public_url( $object_id );

		if ( $html_url ) {
			return $single ? $html_url : array( $html_url );
		}

		return $value;
	}

	/**
	 * Replace PDF anchors and Google Docs previews in rendered content/widget text.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function filter_content_pdf_links( $content ) {
		if ( ! is_string( $content ) || ! self::is_public_replacement_context() ) {
			return $content;
		}

		$has_google_preview = false !== stripos( $content, 'docs.google.com/gview' );
		$has_link_candidate = self::content_has_public_url_candidate( $content );

		if ( ! $has_link_candidate && ! $has_google_preview ) {
			return $content;
		}

		if ( $has_link_candidate ) {
			$content = preg_replace_callback(
				'~<a\b([^>]*?)\bhref=(["\'])(.*?)\2([^>]*)>~is',
				array( __CLASS__, 'replace_content_link_href' ),
				$content
			);
		}

		if ( $has_google_preview ) {
			$content = preg_replace_callback(
				'~\bsrc=(["\'])(?:https?:)?//docs\.google\.com/gview\?url=([^"\']+?)(?:&amp;|&#0?38;|&)embedded=true\1~i',
				array( __CLASS__, 'replace_google_docs_preview_src' ),
				$content
			);
		}

		return $content;
	}

	/**
	 * Start a scoped output buffer for the ProudCity single Document viewer.
	 */
	public static function maybe_start_document_viewer_buffer() {
		if ( ! self::should_rewrite_document_viewer() ) {
			return;
		}

		$post_id    = absint( get_queried_object_id() );
		$viewer_url = self::ready_document_viewer_url( $post_id );

		if ( ! $post_id || ! $viewer_url ) {
			return;
		}

		self::$document_viewer_post_id = $post_id;
		ob_start( array( __CLASS__, 'filter_document_viewer_output' ) );
	}

	/**
	 * Replace the ProudCity Google Docs preview iframe with FileToWeb HTML.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string
	 */
	public static function filter_document_viewer_output( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}

		$post_id = self::$document_viewer_post_id;

		if ( ! $post_id && function_exists( 'get_queried_object_id' ) ) {
			$post_id = absint( get_queried_object_id() );
		}

		$viewer_url = self::ready_document_viewer_url( $post_id );
		$source_url = Source_Resolver::admin_original_source_url( get_post( $post_id ) );

		if ( ! $viewer_url || ! $source_url ) {
			return $html;
		}

		$epub_url = self::ready_document_epub_download_url( $post_id );

		if ( $epub_url && self::should_show_document_epub_download( $post_id, $epub_url ) ) {
			$html = self::inject_document_epub_download( $html, $source_url, $epub_url );
		}

		if ( false === stripos( $html, 'docs.google.com/gview' ) || false === stripos( $html, 'doc-preview' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'~<iframe\b[^>]*>~is',
			function ( $matches ) use ( $viewer_url, $source_url ) {
				return self::replace_document_viewer_iframe( $matches[0], $viewer_url, $source_url );
			},
			$html
		);
	}

	/**
	 * Start a scoped output buffer for the ProudCity single Meeting viewer.
	 */
	public static function maybe_start_meeting_viewer_buffer() {
		if ( ! self::should_rewrite_meeting_viewer() ) {
			return;
		}

		$post_id = absint( get_queried_object_id() );

		if ( ! $post_id || empty( self::ready_meeting_viewer_map( $post_id ) ) ) {
			return;
		}

		self::$meeting_viewer_post_id = $post_id;
		ob_start( array( __CLASS__, 'filter_meeting_viewer_output' ) );
	}

	/**
	 * Replace ProudCity Meeting Google Docs preview iframes with FileToWeb HTML.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string
	 */
	public static function filter_meeting_viewer_output( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'docs.google.com/gview' ) ) {
			return $html;
		}

		$post_id = self::$meeting_viewer_post_id;

		if ( ! $post_id && function_exists( 'get_queried_object_id' ) ) {
			$post_id = absint( get_queried_object_id() );
		}

		$map = self::ready_meeting_viewer_map( $post_id );

		if ( empty( $map ) ) {
			return $html;
		}

		return preg_replace_callback(
			'~<iframe\b[^>]*>~is',
			function ( $matches ) use ( $map ) {
				return self::replace_meeting_viewer_iframe( $matches[0], $map );
			},
			$html
		);
	}

	/**
	 * Resolve a WordPress URL to a ready WordPress-local URL.
	 *
	 * Public for the widget and tests.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function public_url_for_wordpress_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );

		if ( ! $url || preg_match( '/^(mailto|tel|javascript):/i', $url ) ) {
			return '';
		}

		$absolute_url = self::absolute_public_url( $url );

		if ( ! $absolute_url ) {
			return '';
		}

		$host = strtolower( (string) parse_url( $absolute_url, PHP_URL_HOST ) );
		$key  = Security::normalize_public_url_key( $absolute_url );

		if ( ! $key ) {
			return '';
		}

		if ( array_key_exists( $key, self::$resolved_public_urls ) ) {
			return self::$resolved_public_urls[ $key ];
		}

		$local_html_post_id = self::local_html_post_id_for_public_url( $absolute_url );

		if ( $local_html_post_id ) {
			$local_html_public_url = Local_HTML::public_url_for_post( $local_html_post_id );

			if ( $local_html_public_url ) {
				self::$resolved_public_urls[ $key ] = $local_html_public_url;
				return self::$resolved_public_urls[ $key ];
			}
		}

		if ( in_array( $host, Settings::allowed_filetoweb_hosts(), true ) ) {
			self::$resolved_public_urls[ $key ] = '';
			return self::$resolved_public_urls[ $key ];
		}

		if ( ! self::is_public_url_candidate( $absolute_url ) ) {
			self::$resolved_public_urls[ $key ] = '';
			return self::$resolved_public_urls[ $key ];
		}

		$attachment_id = self::attachment_id_for_public_url( $absolute_url );
		$html_url      = $attachment_id ? self::ready_attachment_public_url( $attachment_id, $absolute_url ) : '';

		if ( $attachment_id ) {
			self::$resolved_public_urls[ $key ] = $html_url;
			return self::$resolved_public_urls[ $key ];
		}

		$post_id  = self::ready_post_id_for_original_url( $absolute_url );
		$html_url = $post_id ? Document_State::ready_html_url( $post_id ) : '';

		if ( $html_url ) {
			self::$resolved_public_urls[ $key ] = self::ready_replacement_url( $html_url, $post_id, 'original_url', $absolute_url );
			return self::$resolved_public_urls[ $key ];
		}

		self::$resolved_public_urls[ $key ] = '';

		return self::$resolved_public_urls[ $key ];
	}

	/**
	 * Replace one anchor href.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private static function replace_content_link_href( $matches ) {
		if ( ! self::is_public_url_candidate( $matches[3] ) ) {
			return $matches[0];
		}

		if ( self::is_current_meeting_material_url( $matches[3] ) ) {
			return $matches[0];
		}

		$html_url = self::public_url_for_wordpress_url( $matches[3] );

		if ( ! $html_url ) {
			return $matches[0];
		}

		return '<a' . $matches[1] . 'href=' . $matches[2] . esc_url( $html_url ) . $matches[2] . $matches[4] . '>';
	}

	/**
	 * Replace Google Docs preview iframe source for already-ready WordPress-local URLs.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private static function replace_google_docs_preview_src( $matches ) {
		$quote       = $matches[1];
		$raw_url     = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );
		$url         = rawurldecode( $raw_url );
		$preview_url = self::ready_current_meeting_material_viewer_url( $url );

		if ( $preview_url ) {
			return 'src=' . $quote . esc_url( $preview_url ) . $quote;
		}

		$html_url    = Security::sanitize_filetoweb_url( $url );

		if ( ! $html_url ) {
			$html_url = self::public_url_for_wordpress_url( $url );
		}

		$preview_url = $html_url ? self::preview_url_for_public_url( $html_url ) : '';

		if ( ! $preview_url ) {
			return $matches[0];
		}

		return 'src=' . $quote . esc_url( $preview_url ) . $quote;
	}

	/**
	 * Return ready attachment public URL only if the current source is still a PDF.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Current URL.
	 * @return string
	 */
	private static function ready_attachment_public_url( $attachment_id, $url ) {
		$html_url = Document_State::ready_html_url( $attachment_id );

		if ( ! $html_url ) {
			return '';
		}

		$mime     = get_post_mime_type( $attachment_id );
		$filename = basename( (string) parse_url( $url, PHP_URL_PATH ) );

		return Source_Resolver::is_pdf_source( $url, $filename, $mime ) ? self::ready_replacement_url( $html_url, $attachment_id, 'attachment', $url ) : '';
	}

	/**
	 * Return ready document public URL only if the current source is still a PDF.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function ready_document_public_url( $post_id ) {
		$owner_id = Source_Resolver::preview_owner_post_id( $post_id );
		$html_url = Document_State::ready_html_url( $owner_id );

		if ( ! $html_url ) {
			return '';
		}

		$url = Source_Resolver::admin_original_source_url( get_post( $post_id ) );

		if ( ! $url ) {
			return '';
		}

		$filename = get_post_meta( $post_id, 'document_filename', true );
		$meta     = Source_Resolver::parse_document_meta( get_post_meta( $post_id, 'document_meta', true ) );
		$mime     = is_array( $meta ) && isset( $meta['mime'] ) ? $meta['mime'] : '';

		return Source_Resolver::is_pdf_source( $url, $filename, $mime ) ? self::ready_replacement_url( $html_url, $post_id, 'document', $url ) : '';
	}

	/**
	 * Resolve the public citizen-facing URL for a ready FileToWeb source.
	 *
	 * @param string $html_url FileToWeb HTML URL.
	 * @param int    $post_id Source post ID.
	 * @param string $context Replacement context.
	 * @param string $source_url Original source URL.
	 * @param bool   $prepare_record Whether a specifically targeted record may be normalized.
	 * @return string
	 */
	private static function ready_replacement_url( $html_url, $post_id, $context, $source_url = '', $prepare_record = true ) {
		$html_url = Security::sanitize_filetoweb_url( $html_url );
		$owner_id = Source_Resolver::preview_owner_post_id( $post_id );

		if ( ! $html_url || Proud_HTML_Preview::is_public_paused( $owner_id ) ) {
			return '';
		}

		/**
		 * Filters the public replacement URL for a ready FileToWeb source.
		 *
		 * Add-on plugins can return another safe public URL, such as a reviewed
		 * native WordPress page, while the integration continues to store the
		 * original PDF and FileToWeb state internally.
		 *
		 * @param string $html_url FileToWeb HTML URL.
		 * @param int    $post_id Source attachment or Proud Document post ID.
		 * @param string $context Replacement context.
		 * @param string $source_url Original public source URL.
		 */
		$local_url   = Local_HTML::public_url_for_post( $post_id, $prepare_record );
		$replacement = apply_filters( 'filetoweb_integration_ready_replacement_url', $local_url, absint( $post_id ), $context, $source_url );

		return is_string( $replacement ) && $replacement ? esc_url_raw( $replacement ) : '';
	}

	/**
	 * Return the public viewer URL for a ready Proud Document.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function ready_document_viewer_url( $post_id ) {
		$post_id  = absint( $post_id );
		$html_url = $post_id ? self::ready_document_public_url( $post_id ) : '';

		if ( ! $html_url ) {
			return '';
		}

		return $html_url;
	}

	/**
	 * Return the FileToWeb EPUB landing page URL for a ready Proud Document.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function ready_document_epub_download_url( $post_id ) {
		$post_id  = absint( $post_id );
		$owner_id = $post_id ? Source_Resolver::preview_owner_post_id( $post_id ) : 0;
		$html_url = $owner_id ? Document_State::ready_html_url( $owner_id ) : '';

		if ( ! $html_url ) {
			return '';
		}

		$url = Source_Resolver::admin_original_source_url( get_post( $post_id ) );

		if ( ! $url ) {
			return '';
		}

		$filename = get_post_meta( $post_id, 'document_filename', true );
		$meta     = Source_Resolver::parse_document_meta( get_post_meta( $post_id, 'document_meta', true ) );
		$mime     = is_array( $meta ) && isset( $meta['mime'] ) ? $meta['mime'] : '';

		if ( ! Source_Resolver::is_pdf_source( $url, $filename, $mime ) ) {
			return '';
		}

		return self::epub_download_url_for_filetoweb_url( $html_url );
	}

	/**
	 * Convert a FileToWeb document/page URL into its public EPUB landing URL.
	 *
	 * @param string $url FileToWeb URL.
	 * @return string
	 */
	private static function epub_download_url_for_filetoweb_url( $url ) {
		$url = Security::sanitize_filetoweb_url( $url );

		if ( ! $url ) {
			return '';
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$path   = (string) parse_url( $url, PHP_URL_PATH );

		if ( 'https' !== $scheme || ! $host || ! $path ) {
			return '';
		}

		if ( ! preg_match( '~^/d/([A-Za-z0-9_-]{24})(?:/|$)~', $path, $matches ) ) {
			return '';
		}

		return esc_url_raw( 'https://' . $host . '/d/' . $matches[1] . '/download/epub' );
	}

	/**
	 * Should the ready Proud Document output include an EPUB download?
	 *
	 * @param int    $post_id Post ID.
	 * @param string $epub_url EPUB URL.
	 * @return bool
	 */
	private static function should_show_document_epub_download( $post_id, $epub_url ) {
		$show = Settings::epub_download_enabled();

		/**
		 * Filters whether ready Proud Document pages show a FileToWeb EPUB
		 * download link alongside the original PDF download.
		 *
		 * @param bool   $show Whether to show the EPUB download link.
		 * @param int    $post_id Proud Document post ID.
		 * @param string $epub_url FileToWeb EPUB landing page URL.
		 */
		return (bool) apply_filters( 'filetoweb_integration_show_epub_download', $show, absint( $post_id ), $epub_url );
	}

	/**
	 * Insert the EPUB download UI after the original PDF download link.
	 *
	 * @param string $html Rendered HTML.
	 * @param string $source_url Original PDF URL.
	 * @param string $epub_url FileToWeb EPUB URL.
	 * @return string
	 */
	private static function inject_document_epub_download( $html, $source_url, $epub_url ) {
		if ( false !== strpos( $html, 'filetoweb-epub-download' ) ) {
			return $html;
		}

		$inserted = false;

		$rewritten = preg_replace_callback(
			'~<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>[\s\S]*?</a>~i',
			function ( $matches ) use ( &$inserted, $source_url, $epub_url ) {
				if ( $inserted ) {
					return $matches[0];
				}

				$href = html_entity_decode( (string) $matches[2], ENT_QUOTES, 'UTF-8' );

				if ( ! self::public_urls_match( $href, $source_url ) ) {
					return $matches[0];
				}

				$inserted = true;

				return $matches[0] . self::render_document_epub_download( $epub_url );
			},
			$html
		);

		return is_string( $rewritten ) && $inserted ? $rewritten : $html;
	}

	/**
	 * Render the EPUB download UI.
	 *
	 * @param string $epub_url FileToWeb EPUB URL.
	 * @return string
	 */
	private static function render_document_epub_download( $epub_url ) {
		return '<span class="filetoweb-epub-inline">'
			. '<br /><small class="filetoweb-epub-inline__meta">EPUB &middot; reflowable version</small>'
			. '<br /><a class="btn btn-default btn-sm filetoweb-epub-download" href="' . esc_url( $epub_url ) . '">Download EPUB</a>'
			. '</span>';
	}

	/**
	 * Build original-url => ready-viewer-url map for a single ProudCity Meeting.
	 *
	 * @param int $post_id Meeting post ID.
	 * @return array
	 */
	private static function ready_meeting_viewer_map( $post_id ) {
		$post_id = absint( $post_id );
		$map     = array();

		if ( ! $post_id || ! Meeting_Materials::enabled() ) {
			return $map;
		}

		foreach ( Meeting_Materials::attachment_ids_for_meeting( $post_id ) as $attachment_id ) {
			$source_url = Source_Resolver::original_attachment_url( $attachment_id );
			$viewer_url = Meeting_Materials::ready_viewer_url_for_attachment( $attachment_id );

			if ( ! $source_url || ! $viewer_url ) {
				continue;
			}

			$key = Security::normalize_public_url_key( $source_url );

			if ( $key ) {
				$map[ $key ] = $viewer_url;
			}
		}

		return $map;
	}

	/**
	 * Does rendered content contain an anchor URL that can belong to FileToWeb?
	 *
	 * This guard deliberately uses only string checks. Ordinary navigation and
	 * page links must not trigger WordPress URL resolution or database queries.
	 *
	 * @param string $content Rendered content.
	 * @return bool
	 */
	private static function content_has_public_url_candidate( $content ) {
		if ( false === stripos( $content, '<a' ) || false === stripos( $content, 'href=' ) ) {
			return false;
		}

		foreach ( array( '.pdf', '%2epdf', '/wp-content/uploads/', Local_HTML::QUERY_VAR_POST_ID, 'attachment_id=' ) as $marker ) {
			if ( false !== stripos( $content, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Can a URL represent a FileToWeb-backed WordPress source?
	 *
	 * @param string $url Public or relative URL.
	 * @return bool
	 */
	private static function is_public_url_candidate( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );

		if ( ! $url ) {
			return false;
		}

		$absolute_url = self::absolute_public_url( $url );

		if ( ! $absolute_url ) {
			return false;
		}

		$path      = rawurldecode( (string) parse_url( $absolute_url, PHP_URL_PATH ) );
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'pdf' === $extension || ( ! $extension && false !== stripos( $path, '/wp-content/uploads/' ) ) ) {
			return true;
		}

		$query = (string) parse_url( $absolute_url, PHP_URL_QUERY );

		if ( ! $query ) {
			return false;
		}

		if ( false !== stripos( rawurldecode( $query ), '.pdf' ) ) {
			return true;
		}

		parse_str( $query, $params );

		return ( ! empty( $params[ Local_HTML::QUERY_VAR_POST_ID ] ) || ! empty( $params['attachment_id'] ) )
			&& self::is_wordpress_attachment_host( $absolute_url );
	}

	/**
	 * Resolve the newest ready source whose stored original URL is exact.
	 *
	 * @param string $url Original source URL.
	 * @return int
	 */
	private static function ready_post_id_for_original_url( $url ) {
		return self::ready_post_id_for_meta_url( Document_State::META_ORIGINAL_URL, $url );
	}

	/**
	 * Resolve one ready source by an exact URL-valued metadata field.
	 *
	 * This replaces the former request-wide scan of every ready document with a
	 * bounded lookup performed only for a URL that already looks relevant.
	 *
	 * @param string $meta_key URL metadata key.
	 * @param string $url Exact stored URL.
	 * @return int
	 */
	private static function ready_post_id_for_meta_url( $meta_key, $url ) {
		$url = esc_url_raw( $url );

		if ( ! $url ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'              => array( 'attachment', 'document' ),
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'orderby'                => 'ID',
				'order'                  => 'DESC',
				'suppress_filters'       => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => Document_State::META_STATUS,
						'value' => 'ready',
					),
					array(
						'key'     => $meta_key,
						'value'   => $url,
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $posts ) ) {
			return 0;
		}

		$post = reset( $posts );

		return absint( is_object( $post ) && isset( $post->ID ) ? $post->ID : $post );
	}

	/**
	 * Resolve a public URL to the local preview URL.
	 *
	 * @param string $url FileToWeb URL.
	 * @return string
	 */
	private static function preview_url_for_public_url( $url ) {
		$url = esc_url_raw( $url );

		if ( ! $url ) {
			return '';
		}

		$key = Security::normalize_public_url_key( $url );

		if ( ! $key ) {
			return '';
		}

		if ( array_key_exists( $key, self::$resolved_preview_urls ) ) {
			return self::$resolved_preview_urls[ $key ];
		}

		$local_html_post_id = self::local_html_post_id_for_public_url( $url );

		if ( $local_html_post_id ) {
			self::$resolved_preview_urls[ $key ] = Local_HTML::public_url_for_post( $local_html_post_id );
			return self::$resolved_preview_urls[ $key ];
		}

		$post_id = self::ready_post_id_for_meta_url( Document_State::META_HTML_URL, $url );
		$owner_id = $post_id ? Source_Resolver::preview_owner_post_id( $post_id ) : 0;

		if ( ! $owner_id || Proud_HTML_Preview::is_public_paused( $owner_id ) ) {
			self::$resolved_preview_urls[ $key ] = '';
			return self::$resolved_preview_urls[ $key ];
		}

		self::$resolved_preview_urls[ $key ] = Local_HTML::local_url( $owner_id, false );

		return self::$resolved_preview_urls[ $key ];
	}

	/**
	 * Replace the src attribute on the ProudCity document preview iframe.
	 *
	 * @param string $iframe Iframe HTML.
	 * @param string $viewer_url Local viewer URL.
	 * @param string $source_url Original PDF URL.
	 * @return string
	 */
	private static function replace_document_viewer_iframe( $iframe, $viewer_url, $source_url ) {
		if ( ! preg_match( '~\bid=(["\'])doc-preview\1~i', $iframe ) ) {
			return $iframe;
		}

		if ( ! preg_match( '~\bsrc=(["\'])(.*?)\1~is', $iframe, $src_match ) ) {
			return $iframe;
		}

		$preview_source = self::google_docs_preview_source_url( $src_match[2] );

		if ( ! $preview_source || ! self::public_urls_match( $preview_source, $source_url ) ) {
			return $iframe;
		}

		return preg_replace_callback(
			'~\bsrc=(["\'])(.*?)\1~is',
			function ( $matches ) use ( $viewer_url ) {
				return 'src=' . $matches[1] . esc_url( $viewer_url ) . $matches[1];
			},
			$iframe,
			1
		);
	}

	/**
	 * Replace a ProudCity Meeting preview iframe src when its source PDF is ready.
	 *
	 * @param string $iframe Iframe HTML.
	 * @param array  $map Original URL to local viewer URL map.
	 * @return string
	 */
	private static function replace_meeting_viewer_iframe( $iframe, $map ) {
		if ( ! preg_match( '~\bsrc=(["\'])(.*?)\1~is', $iframe, $src_match ) ) {
			return $iframe;
		}

		$preview_source = self::google_docs_preview_source_url( $src_match[2] );

		if ( ! $preview_source ) {
			return $iframe;
		}

		$key = Security::normalize_public_url_key( $preview_source );

		if ( ! $key || empty( $map[ $key ] ) ) {
			return $iframe;
		}

		$viewer_url = $map[ $key ];

		return preg_replace_callback(
			'~\bsrc=(["\'])(.*?)\1~is',
			function ( $matches ) use ( $viewer_url ) {
				return 'src=' . $matches[1] . esc_url( $viewer_url ) . $matches[1];
			},
			$iframe,
			1
		);
	}

	/**
	 * Extract the source URL from a Google Docs preview URL.
	 *
	 * @param string $url Preview URL.
	 * @return string
	 */
	private static function google_docs_preview_source_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$path = (string) parse_url( $url, PHP_URL_PATH );

		if ( 'docs.google.com' !== $host || '/gview' !== $path ) {
			return '';
		}

		$query = parse_url( $url, PHP_URL_QUERY );

		if ( ! $query ) {
			return '';
		}

		parse_str( $query, $params );

		return ! empty( $params['url'] ) ? esc_url_raw( (string) $params['url'] ) : '';
	}

	/**
	 * Compare public URLs after normalizing host/path details.
	 *
	 * @param string $left First URL.
	 * @param string $right Second URL.
	 * @return bool
	 */
	private static function public_urls_match( $left, $right ) {
		$left_key  = Security::normalize_public_url_key( $left );
		$right_key = Security::normalize_public_url_key( $right );

		return $left_key && $right_key && $left_key === $right_key;
	}

	/**
	 * Resolve a WordPress attachment ID for a bounded URL candidate.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function attachment_id_for_public_url( $url ) {
		if ( ! self::is_public_url_candidate( $url ) ) {
			return 0;
		}

		$query = (string) parse_url( $url, PHP_URL_QUERY );

		if ( $query ) {
			parse_str( $query, $params );
			$attachment_id = ! empty( $params['attachment_id'] ) ? absint( $params['attachment_id'] ) : 0;

			if ( $attachment_id && self::is_wordpress_attachment_host( $url ) && 'attachment' === get_post_type( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		$attachment_id = self::attachment_id_for_upload_url( $url );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		if ( self::is_wordpress_attachment_host( $url ) && function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = attachment_url_to_postid( $url );

			if ( $attachment_id ) {
				return absint( $attachment_id );
			}
		}

		return 0;
	}

	/**
	 * Is a URL hosted by WordPress or its configured uploads service?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_wordpress_attachment_host( $url ) {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( ! $host ) {
			return false;
		}

		$allowed_hosts = array( strtolower( (string) parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		$uploads       = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$uploads_host  = ! empty( $uploads['baseurl'] ) ? strtolower( (string) parse_url( $uploads['baseurl'], PHP_URL_HOST ) ) : '';

		if ( $uploads_host ) {
			$allowed_hosts[] = $uploads_host;
		}

		return in_array( $host, array_filter( array_unique( $allowed_hosts ) ), true );
	}

	/**
	 * Resolve a plugin-owned local HTML URL back to the source post ID.
	 *
	 * This lets an already-rewritten local HTML link move forward to an
	 * approved WordPress-native page without requiring editors to relink content.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function local_html_post_id_for_public_url( $url ) {
		$query = parse_url( $url, PHP_URL_QUERY );

		if ( ! $query ) {
			return 0;
		}

		parse_str( $query, $params );

		if ( empty( $params[ Local_HTML::QUERY_VAR_POST_ID ] ) ) {
			return 0;
		}

		if ( ! self::is_wordpress_attachment_host( $url ) ) {
			return 0;
		}

		$post_id = absint( $params[ Local_HTML::QUERY_VAR_POST_ID ] );

		return $post_id && Local_HTML::has_local_html( $post_id ) ? $post_id : 0;
	}

	/**
	 * Resolve attachment ID by upload relative path.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function attachment_id_for_upload_url( $url ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return 0;
		}

		$path = parse_url( $url, PHP_URL_PATH );

		if ( ! $path ) {
			return 0;
		}

		$uploads   = wp_upload_dir();
		$base_path = isset( $uploads['baseurl'] ) ? parse_url( $uploads['baseurl'], PHP_URL_PATH ) : '';
		$relative  = '';

		if ( $base_path && 0 === strpos( $path, $base_path ) ) {
			$relative = substr( $path, strlen( $base_path ) );
		} else {
			$marker   = '/wp-content/uploads/';
			$position = strpos( $path, $marker );

			if ( false !== $position ) {
				$relative = substr( $path, $position + strlen( $marker ) );
			}
		}

		$relative = ltrim( rawurldecode( $relative ), '/' );

		if ( ! $relative ) {
			return 0;
		}

		$attachments = get_posts(
			array(
				'meta_key'               => '_wp_attached_file',
				'meta_value'             => $relative,
				'posts_per_page'          => 1,
				'no_found_rows'           => true,
				'orderby'                 => 'ID',
				'order'                   => 'DESC',
				'post_status'             => 'inherit',
				'post_type'               => 'attachment',
				'suppress_filters'        => true,
				'update_post_meta_cache'  => true,
				'update_post_term_cache'  => false,
			)
		);

		if ( empty( $attachments ) ) {
			return 0;
		}

		$attachment = reset( $attachments );

		return absint( is_object( $attachment ) && isset( $attachment->ID ) ? $attachment->ID : $attachment );
	}

	/**
	 * Make relative or protocol-relative URLs absolute.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function absolute_public_url( $url ) {
		if ( preg_match( '~^https?://~i', $url ) ) {
			return esc_url_raw( $url );
		}

		if ( 0 === strpos( $url, '//' ) ) {
			return esc_url_raw( ( is_ssl() ? 'https:' : 'http:' ) . $url );
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return esc_url_raw( home_url( $url ) );
		}

		return '';
	}

	/**
	 * Is this request rendering the queried Proud Document.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_current_document_single( $post_id ) {
		return function_exists( 'is_singular' )
			&& is_singular( 'document' )
			&& function_exists( 'get_queried_object_id' )
			&& absint( get_queried_object_id() ) === absint( $post_id );
	}

	/**
	 * Is the URL being requested for a material on the queried ProudCity Meeting?
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_current_meeting_material_attachment( $attachment_id ) {
		if ( ! $attachment_id || ! Meeting_Materials::enabled() ) {
			return false;
		}

		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'meeting' ) || ! function_exists( 'get_queried_object_id' ) ) {
			return false;
		}

		$meeting_id = self::$meeting_viewer_post_id ? self::$meeting_viewer_post_id : absint( get_queried_object_id() );

		if ( ! $meeting_id ) {
			return false;
		}

		return in_array( absint( $attachment_id ), array_map( 'absint', Meeting_Materials::attachment_ids_for_meeting( $meeting_id ) ), true );
	}

	/**
	 * Is this URL a PDF material attached to the queried ProudCity Meeting?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_current_meeting_material_url( $url ) {
		if ( ! Meeting_Materials::enabled() || ! function_exists( 'is_singular' ) || ! is_singular( 'meeting' ) ) {
			return false;
		}

		$absolute_url = self::absolute_public_url( $url );

		if ( ! $absolute_url ) {
			return false;
		}

		$attachment_id = self::attachment_id_for_public_url( $absolute_url );

		if ( $attachment_id && self::is_current_meeting_material_attachment( $attachment_id ) ) {
			return true;
		}

		$meeting_id = self::$meeting_viewer_post_id ? self::$meeting_viewer_post_id : ( function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0 );

		if ( ! $meeting_id ) {
			return false;
		}

		foreach ( Meeting_Materials::attachment_ids_for_meeting( $meeting_id ) as $meeting_attachment_id ) {
			$meeting_url = Source_Resolver::original_attachment_url( $meeting_attachment_id );

			if ( $meeting_url && self::public_urls_match( $absolute_url, $meeting_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a current Meeting material source URL directly to its ready viewer URL.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	private static function ready_current_meeting_material_viewer_url( $url ) {
		if ( ! Meeting_Materials::enabled() || ! function_exists( 'is_singular' ) || ! is_singular( 'meeting' ) ) {
			return '';
		}

		$absolute_url = self::absolute_public_url( $url );

		if ( ! $absolute_url ) {
			return '';
		}

		$meeting_id = self::$meeting_viewer_post_id ? self::$meeting_viewer_post_id : ( function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0 );

		if ( ! $meeting_id ) {
			return '';
		}

		foreach ( Meeting_Materials::attachment_ids_for_meeting( $meeting_id ) as $meeting_attachment_id ) {
			$meeting_url = Source_Resolver::original_attachment_url( $meeting_attachment_id );

			if ( $meeting_url && self::public_urls_match( $absolute_url, $meeting_url ) ) {
				return Meeting_Materials::ready_viewer_url_for_attachment( $meeting_attachment_id );
			}
		}

		return '';
	}

	/**
	 * Should the ProudCity document viewer buffer run?
	 *
	 * @return bool
	 */
	private static function should_rewrite_document_viewer() {
		if ( ! self::is_public_replacement_context() ) {
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}

		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'document' ) || ! function_exists( 'get_queried_object_id' ) ) {
			return false;
		}

		return (bool) apply_filters( 'filetoweb_integration_rewrite_document_viewer', true );
	}

	/**
	 * Should the ProudCity Meeting viewer buffer run?
	 *
	 * @return bool
	 */
	private static function should_rewrite_meeting_viewer() {
		if ( ! self::is_public_replacement_context() ) {
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}

		if ( ! Meeting_Materials::enabled() || ! function_exists( 'is_singular' ) || ! is_singular( 'meeting' ) || ! function_exists( 'get_queried_object_id' ) ) {
			return false;
		}

		return (bool) apply_filters( 'filetoweb_integration_rewrite_meeting_viewer', true );
	}

	/**
	 * Should public replacement run?
	 *
	 * @return bool
	 */
	private static function is_public_replacement_context() {
		if ( ! Settings::replace_links_enabled() || is_admin() || is_feed() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}

		return true;
	}
}
