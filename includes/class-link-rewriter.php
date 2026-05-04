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
	 * Original URL to FileToWeb ready URL map.
	 *
	 * @var array|null
	 */
	private static $ready_url_map = null;

	/**
	 * FileToWeb page URL to continuous preview URL map.
	 *
	 * @var array|null
	 */
	private static $preview_url_map = null;

	/**
	 * Per-request lookup cache.
	 *
	 * @var array
	 */
	private static $resolved_public_urls = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_attachment_url' ), 20, 2 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_document_meta' ), 20, 4 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content_pdf_links' ), 20 );
		add_filter( 'widget_text', array( __CLASS__, 'filter_content_pdf_links' ), 20 );
		add_filter( 'widget_text_content', array( __CLASS__, 'filter_content_pdf_links' ), 20 );
	}

	/**
	 * Replace attachment helper URLs on the front end.
	 *
	 * @param string $url Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		if ( Source_Resolver::is_reading_original_source() || ! self::is_public_replacement_context() ) {
			return $url;
		}

		$html_url = self::ready_attachment_html_url( $attachment_id, $url );

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

		$html_url = self::ready_document_html_url( $object_id );

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

		if ( false !== stripos( $content, '<a' ) && false !== stripos( $content, 'href=' ) ) {
			$content = preg_replace_callback(
				'~<a\b([^>]*?)\bhref=(["\'])(.*?)\2([^>]*)>~is',
				array( __CLASS__, 'replace_content_link_href' ),
				$content
			);
		}

		if ( false !== stripos( $content, 'docs.google.com/gview' ) ) {
			$content = preg_replace_callback(
				'~\bsrc=(["\'])(?:https?:)?//docs\.google\.com/gview\?url=([^"\']+?)(?:&amp;|&#0?38;|&)embedded=true\1~i',
				array( __CLASS__, 'replace_google_docs_preview_src' ),
				$content
			);
		}

		return $content;
	}

	/**
	 * Resolve a WordPress URL to ready FileToWeb URL.
	 *
	 * Public for the widget and tests.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function filetoweb_url_for_wordpress_url( $url ) {
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );

		if ( ! $url || preg_match( '/^(mailto|tel|javascript):/i', $url ) ) {
			return '';
		}

		$absolute_url = self::absolute_public_url( $url );

		if ( ! $absolute_url ) {
			return '';
		}

		$host = strtolower( (string) parse_url( $absolute_url, PHP_URL_HOST ) );

		if ( in_array( $host, Settings::allowed_filetoweb_hosts(), true ) ) {
			return '';
		}

		$key = Security::normalize_public_url_key( $absolute_url );

		if ( isset( self::$resolved_public_urls[ $key ] ) ) {
			return self::$resolved_public_urls[ $key ];
		}

		$map = self::ready_url_map();

		if ( isset( $map[ $key ] ) ) {
			self::$resolved_public_urls[ $key ] = $map[ $key ];
			return self::$resolved_public_urls[ $key ];
		}

		$attachment_id = self::attachment_id_for_public_url( $absolute_url );
		$html_url      = $attachment_id ? self::ready_attachment_html_url( $attachment_id, $absolute_url ) : '';

		self::$resolved_public_urls[ $key ] = $html_url ? $html_url : '';

		return self::$resolved_public_urls[ $key ];
	}

	/**
	 * Replace one anchor href.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private static function replace_content_link_href( $matches ) {
		$html_url = self::filetoweb_url_for_wordpress_url( $matches[3] );

		if ( ! $html_url ) {
			return $matches[0];
		}

		return '<a' . $matches[1] . 'href=' . $matches[2] . esc_url( $html_url ) . $matches[2] . $matches[4] . '>';
	}

	/**
	 * Replace Google Docs preview iframe source for already-ready FileToWeb URLs.
	 *
	 * @param array $matches Regex matches.
	 * @return string
	 */
	private static function replace_google_docs_preview_src( $matches ) {
		$quote       = $matches[1];
		$raw_url     = html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );
		$url         = rawurldecode( $raw_url );
		$preview_url = self::filetoweb_preview_url_for_html_url( $url );

		if ( ! $preview_url ) {
			return $matches[0];
		}

		return 'src=' . $quote . esc_url( $preview_url ) . $quote;
	}

	/**
	 * Return ready attachment HTML URL only if the current source is still a PDF.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $url Current URL.
	 * @return string
	 */
	private static function ready_attachment_html_url( $attachment_id, $url ) {
		$html_url = Document_State::ready_html_url( $attachment_id );

		if ( ! $html_url ) {
			return '';
		}

		$mime     = get_post_mime_type( $attachment_id );
		$filename = basename( (string) parse_url( $url, PHP_URL_PATH ) );

		return Source_Resolver::is_pdf_source( $url, $filename, $mime ) ? $html_url : '';
	}

	/**
	 * Return ready document HTML URL only if the current source is still a PDF.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function ready_document_html_url( $post_id ) {
		$html_url = Document_State::ready_html_url( $post_id );

		if ( ! $html_url ) {
			return '';
		}

		$url = Source_Resolver::admin_original_source_url( get_post( $post_id ) );

		if ( ! $url ) {
			return '';
		}

		$filename = get_post_meta( $post_id, 'document_filename', true );
		$meta     = json_decode( (string) get_post_meta( $post_id, 'document_meta', true ), true );
		$mime     = is_array( $meta ) && isset( $meta['mime'] ) ? $meta['mime'] : '';

		return Source_Resolver::is_pdf_source( $url, $filename, $mime ) ? $html_url : '';
	}

	/**
	 * Build original-url => ready-html-url map once per request.
	 *
	 * @return array
	 */
	private static function ready_url_map() {
		if ( null !== self::$ready_url_map ) {
			return self::$ready_url_map;
		}

		self::$ready_url_map = array();

		$limit = absint( apply_filters( 'filetoweb_integration_ready_url_map_limit', 1000 ) );
		$limit = max( 1, min( 5000, $limit ) );

		$posts = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document' ),
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => Document_State::META_STATUS,
				'meta_value'     => 'ready',
			)
		);

		foreach ( $posts as $post_id ) {
			$html_url = Document_State::ready_html_url( $post_id );

			if ( ! $html_url ) {
				continue;
			}

			$urls = array( get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true ) );

			if ( 'attachment' === get_post_type( $post_id ) ) {
				$urls[] = Source_Resolver::original_attachment_url( $post_id );
				$urls[] = get_permalink( $post_id );
			} elseif ( 'document' === get_post_type( $post_id ) ) {
				$urls[] = Source_Resolver::admin_original_source_url( get_post( $post_id ) );
			}

			foreach ( $urls as $url ) {
				$key = Security::normalize_public_url_key( $url );

				if ( $key ) {
					self::$ready_url_map[ $key ] = $html_url;
				}
			}
		}

		return self::$ready_url_map;
	}

	/**
	 * Build FileToWeb HTML URL => continuous URL map once per request.
	 *
	 * @return array
	 */
	private static function preview_url_map() {
		if ( null !== self::$preview_url_map ) {
			return self::$preview_url_map;
		}

		self::$preview_url_map = array();

		$posts = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document' ),
				'post_status'    => 'any',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'meta_key'       => Document_State::META_STATUS,
				'meta_value'     => 'ready',
			)
		);

		foreach ( $posts as $post_id ) {
			$html_url       = Document_State::ready_html_url( $post_id );
			$continuous_url = Document_State::ready_continuous_url( $post_id );

			if ( $html_url && $continuous_url ) {
				self::$preview_url_map[ Security::normalize_public_url_key( $html_url ) ] = $continuous_url;
			}
		}

		return self::$preview_url_map;
	}

	/**
	 * Resolve a FileToWeb page URL to continuous preview URL.
	 *
	 * @param string $url FileToWeb URL.
	 * @return string
	 */
	private static function filetoweb_preview_url_for_html_url( $url ) {
		$url = Security::sanitize_filetoweb_url( $url );

		if ( ! $url ) {
			return '';
		}

		$map = self::preview_url_map();
		$key = Security::normalize_public_url_key( $url );

		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Resolve WordPress attachment ID for direct upload URL or attachment page URL.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function attachment_id_for_public_url( $url ) {
		if ( function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = attachment_url_to_postid( $url );

			if ( $attachment_id ) {
				return absint( $attachment_id );
			}
		}

		$attachment_id = self::attachment_id_for_upload_url( $url );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		if ( function_exists( 'url_to_postid' ) ) {
			$post_id = url_to_postid( $url );

			if ( $post_id && 'attachment' === get_post_type( $post_id ) ) {
				return absint( $post_id );
			}
		}

		return self::attachment_id_for_attachment_page_url( $url );
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

		if ( ! $path || ! preg_match( '/\.pdf$/i', $path ) ) {
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
				'fields'           => 'ids',
				'meta_key'         => '_wp_attached_file',
				'meta_value'       => $relative,
				'numberposts'      => 1,
				'post_status'      => 'inherit',
				'post_type'        => 'attachment',
				'suppress_filters' => true,
			)
		);

		return $attachments ? absint( $attachments[0] ) : 0;
	}

	/**
	 * Resolve attachment ID from attachment page URL.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private static function attachment_id_for_attachment_page_url( $url ) {
		$path = parse_url( $url, PHP_URL_PATH );

		if ( ! $path ) {
			return 0;
		}

		$home_path = parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		$path = trim( rawurldecode( $path ), '/' );

		if ( ! $path || preg_match( '/\.pdf$/i', $path ) || ! function_exists( 'get_page_by_path' ) ) {
			return 0;
		}

		$basename   = basename( $path );
		$candidates = array( $path );

		if ( $basename && $basename !== $path ) {
			$candidates[] = $basename;
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			$attachment = get_page_by_path( $candidate, OBJECT, 'attachment' );

			if ( $attachment && 'attachment' === get_post_type( $attachment ) ) {
				return absint( $attachment->ID );
			}

			$attachment_id = self::attachment_id_for_slug( $candidate );

			if ( $attachment_id ) {
				return $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Resolve attachment by slug.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	private static function attachment_id_for_slug( $slug ) {
		$attachments = get_posts(
			array(
				'fields'           => 'ids',
				'name'             => sanitize_title( $slug ),
				'numberposts'      => 1,
				'post_status'      => 'inherit',
				'post_type'        => 'attachment',
				'suppress_filters' => true,
			)
		);

		return $attachments ? absint( $attachments[0] ) : 0;
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
