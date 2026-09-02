<?php
/**
 * Resolve WordPress attachment and Proud Document sources.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Source_Resolver {
	/**
	 * Guard used when reading the original WordPress URL internally.
	 *
	 * @var bool
	 */
	private static $reading_original_source = false;

	/**
	 * Is the plugin reading original source URLs internally?
	 *
	 * @return bool
	 */
	public static function is_reading_original_source() {
		return self::$reading_original_source;
	}

	/**
	 * Return stable external ID for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function attachment_external_id( $attachment_id ) {
		return 'wordpress:' . self::site_hash() . ':attachment:' . absint( $attachment_id );
	}

	/**
	 * Return stable external ID for a Proud Document without an attachment.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function document_external_id( $post_id ) {
		return 'wordpress:' . self::site_hash() . ':document:' . absint( $post_id );
	}

	/**
	 * Read an attachment URL without triggering public replacement.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function original_attachment_url( $attachment_id ) {
		self::$reading_original_source = true;
		$url = wp_get_attachment_url( $attachment_id );
		self::$reading_original_source = false;

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Read the original source URL for admin/status displays.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function admin_original_source_url( $post ) {
		if ( ! is_object( $post ) ) {
			return '';
		}

		if ( 'attachment' === $post->post_type ) {
			return self::original_attachment_url( $post->ID );
		}

		if ( 'document' !== $post->post_type ) {
			return '';
		}

		self::$reading_original_source = true;
		$url = get_post_meta( $post->ID, 'document', true );
		self::$reading_original_source = false;

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Resolve a PDF attachment source.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public static function for_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$mime          = get_post_mime_type( $attachment_id );
		$url           = self::original_attachment_url( $attachment_id );
		$file          = get_attached_file( $attachment_id );
		$filename      = $file ? basename( $file ) : basename( (string) parse_url( $url, PHP_URL_PATH ) );

		if ( ! $url || ! self::is_pdf_source( $url, $filename, $mime ) || ! Security::is_safe_source_url( $url ) ) {
			return null;
		}

		$fingerprint = self::source_fingerprint( $url, $filename, $file, null, $attachment_id );

		return array(
			'external_id'                 => self::attachment_external_id( $attachment_id ),
			'source_url'                  => $url,
			'filename'                    => $filename ? $filename : 'document.pdf',
			'fingerprint'                 => $fingerprint['value'],
			'fingerprint_algorithm'       => $fingerprint['algorithm'],
			'related_attachment_id'       => $attachment_id,
			'sync_post_id'                => $attachment_id,
		);
	}

	/**
	 * Resolve a Proud Document source.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function for_document( $post_id ) {
		$post_id = absint( $post_id );

		self::$reading_original_source = true;
		$url = get_post_meta( $post_id, 'document', true );
		self::$reading_original_source = false;

		$filename      = get_post_meta( $post_id, 'document_filename', true );
		$meta_raw      = get_post_meta( $post_id, 'document_meta', true );
		$meta          = self::parse_document_meta( $meta_raw );
		$attachment_id = self::document_attachment_id( $meta, $url );

		if ( $attachment_id ) {
			$source = self::for_attachment( $attachment_id );

			if ( $source ) {
				$source['sync_post_id'] = $attachment_id;
				return $source;
			}
		}

		$mime = is_array( $meta ) && isset( $meta['mime'] ) ? $meta['mime'] : '';

		if ( ! $url || ! self::is_pdf_source( $url, $filename, $mime ) || ! Security::is_safe_source_url( $url ) ) {
			return null;
		}

		$fingerprint = self::source_fingerprint( $url, $filename, null, $meta, 0 );

		return array(
			'external_id'                 => self::document_external_id( $post_id ),
			'source_url'                  => esc_url_raw( $url ),
			'filename'                    => $filename ? $filename : basename( (string) parse_url( $url, PHP_URL_PATH ) ),
			'fingerprint'                 => $fingerprint['value'],
			'fingerprint_algorithm'       => $fingerprint['algorithm'],
			'related_attachment_id'       => 0,
			'sync_post_id'                => $post_id,
		);
	}

	/**
	 * Does a source look like a PDF?
	 *
	 * @param string $url URL.
	 * @param string $filename Filename.
	 * @param string $mime Mime type.
	 * @return bool
	 */
	public static function is_pdf_source( $url, $filename, $mime ) {
		$mime     = strtolower( trim( (string) $mime ) );
		$filename = strtolower( trim( (string) $filename ) );
		$path     = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );

		return 'application/pdf' === $mime || '.pdf' === substr( $filename, -4 ) || '.pdf' === substr( $path, -4 );
	}

	/**
	 * Resolve attachment id from Proud Document metadata or URL.
	 *
	 * @param mixed  $meta Metadata.
	 * @param string $url URL.
	 * @return int
	 */
	public static function document_attachment_id( $meta, $url = '' ) {
		if ( is_array( $meta ) ) {
			foreach ( array( 'fid', 'id', 'attachment_id', 'attachmentId' ) as $key ) {
				if ( ! empty( $meta[ $key ] ) && absint( $meta[ $key ] ) > 0 ) {
					return absint( $meta[ $key ] );
				}
			}
		}

		if ( $url && function_exists( 'attachment_url_to_postid' ) ) {
			$attachment_id = attachment_url_to_postid( $url );

			if ( $attachment_id ) {
				return absint( $attachment_id );
			}
		}

		return 0;
	}

	/**
	 * Return the post that owns the public preview for a source post.
	 *
	 * ProudCity Document templates prefer the linked attachment ID from the
	 * document metadata. Refresh actions must publish the preview against that
	 * same attachment so the public template can resolve it.
	 *
	 * @param int $post_id Source post ID.
	 * @return int
	 */
	public static function preview_owner_post_id( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'document' !== get_post_type( $post_id ) ) {
			return $post_id;
		}

		$meta          = self::parse_document_meta( get_post_meta( $post_id, 'document_meta', true ) );
		$attachment_id = self::document_attachment_id( $meta );

		return $attachment_id && 'attachment' === get_post_type( $attachment_id ) ? $attachment_id : $post_id;
	}

	/**
	 * Parse Proud Document metadata defensively.
	 *
	 * @param mixed $meta_raw Raw metadata.
	 * @return array|null
	 */
	public static function parse_document_meta( $meta_raw ) {
		if ( is_array( $meta_raw ) ) {
			return $meta_raw;
		}

		if ( ! is_string( $meta_raw ) || '' === trim( $meta_raw ) ) {
			return null;
		}

		$decoded = json_decode( $meta_raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Build a stable source fingerprint.
	 *
	 * @param string $url URL.
	 * @param string $filename Filename.
	 * @param string $file Local file path.
	 * @param mixed  $meta Document metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	private static function source_fingerprint( $url, $filename, $file, $meta, $attachment_id ) {
		if ( $file && is_readable( $file ) ) {
			return array(
				'value'     => hash_file( 'sha256', $file ),
				'algorithm' => 'sha256',
			);
		}

		$head = self::http_head_fingerprint( $url, $filename );

		if ( $head ) {
			return $head;
		}

		$attachment_meta = self::attachment_fingerprint_metadata( $attachment_id );
		$size            = '';

		if ( is_array( $meta ) && isset( $meta['size'] ) ) {
			$size = $meta['size'];
		}

		return array(
			'value'     => hash(
				'sha256',
				wp_json_encode(
					array(
						'url'        => $url,
						'filename'   => $filename,
						'size'       => $size,
						'attachment' => $attachment_meta,
					)
				)
			),
			'algorithm' => $attachment_meta ? 'wordpress-attachment-meta-sha256' : 'wordpress-meta-sha256',
		);
	}

	/**
	 * Build a fingerprint from HTTP HEAD metadata.
	 *
	 * @param string $url URL.
	 * @param string $filename Filename.
	 * @return array|null
	 */
	private static function http_head_fingerprint( $url, $filename ) {
		if ( ! $url || ! function_exists( 'wp_remote_head' ) || ! Security::is_safe_source_url( $url ) ) {
			return null;
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'            => 10,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$headers = array();

		foreach ( array( 'etag', 'last-modified', 'content-length', 'content-md5', 'content-type' ) as $header ) {
			$value = wp_remote_retrieve_header( $response, $header );

			if ( null !== $value && '' !== $value ) {
				$headers[ $header ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			}
		}

		if ( empty( $headers['etag'] ) && empty( $headers['last-modified'] ) && empty( $headers['content-length'] ) && empty( $headers['content-md5'] ) ) {
			return null;
		}

		return array(
			'value'     => hash(
				'sha256',
				wp_json_encode(
					array(
						'url'      => $url,
						'filename' => $filename,
						'headers'  => $headers,
					)
				)
			),
			'algorithm' => 'http-head-sha256',
		);
	}

	/**
	 * Return WordPress attachment metadata relevant to fingerprinting.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	private static function attachment_fingerprint_metadata( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return null;
		}

		return array(
			'attached_file' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'metadata'      => get_post_meta( $attachment_id, '_wp_attachment_metadata', true ),
			'mime'          => get_post_mime_type( $attachment_id ),
			'modified'      => get_post_modified_time( 'U', true, $attachment_id ),
		);
	}

	/**
	 * Stable site hash.
	 *
	 * @return string
	 */
	public static function site_hash() {
		return substr( hash( 'sha256', home_url() ), 0, 12 );
	}
}
