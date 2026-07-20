<?php
/**
 * Durable provider-neutral ProudCity HTML preview publication.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proud_HTML_Preview {
	const META_KEY                 = '_proud_html_preview';
	const OPTION_PROVIDERS         = 'proud_html_preview_providers';
	const OPTION_MIGRATION_VERSION = 'filetoweb_integration_preview_migration_version';
	const PROVIDER                 = 'filetoweb';
	const SCHEMA_VERSION           = 1;
	const MIGRATION_HOOK           = 'filetoweb_integration_migrate_html_previews';
	const MIGRATION_VERSION        = '1';
	const BUNDLE_ROOT              = 'filetoweb-integration/previews';

	/**
	 * Register preview publication lifecycle hooks.
	 */
	public static function init() {
		add_action( 'update_option_' . Settings::OPTION_SETTINGS, array( __CLASS__, 'settings_updated' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'schedule_migration' ), 20 );
		add_action( self::MIGRATION_HOOK, array( __CLASS__, 'migrate_legacy_batch' ) );
	}

	/**
	 * Initialize the provider without changing it during deactivation.
	 */
	public static function activate() {
		self::ensure_provider_state();
		self::schedule_migration();
	}

	/**
	 * Keep the persistent provider map aligned with the explicit public toggle.
	 *
	 * @param mixed  $old_value Previous settings.
	 * @param mixed  $new_value New settings.
	 * @param string $option Option name.
	 */
	public static function settings_updated( $old_value, $new_value, $option = '' ) {
		unset( $old_value, $option );

		$new_value = is_array( $new_value ) ? $new_value : array();
		$enabled   = ! empty( $new_value[ Settings::KEY_REPLACE_LINKS ] ) && '0' !== (string) $new_value[ Settings::KEY_REPLACE_LINKS ];

		self::set_provider_enabled( $enabled );
	}

	/**
	 * Persist the FileToWeb provider state while preserving other providers.
	 *
	 * @param bool $enabled Enabled state.
	 */
	public static function set_provider_enabled( $enabled ) {
		$providers = get_option( self::OPTION_PROVIDERS, array() );
		$providers = is_array( $providers ) ? $providers : array();

		$providers[ self::PROVIDER ] = (bool) $enabled;
		update_option( self::OPTION_PROVIDERS, $providers, false );
	}

	/**
	 * Return whether this provider is enabled for public rendering.
	 *
	 * @return bool
	 */
	public static function provider_enabled() {
		$providers = get_option( self::OPTION_PROVIDERS, array() );

		return is_array( $providers ) && ! empty( $providers[ self::PROVIDER ] );
	}

	/**
	 * Publish a complete immutable HTML preview bundle.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $html Prepared FileToWeb HTML.
	 * @param string $viewer_url FileToWeb viewer URL used to resolve assets.
	 * @param string $source_url Original PDF URL.
	 * @param string $fingerprint Source fingerprint.
	 * @return array|false Preview record or false.
	 */
	public static function publish( $post_id, $html, $viewer_url, $source_url, $fingerprint ) {
		$post_id     = absint( $post_id );
		$html        = is_string( $html ) ? trim( $html ) : '';
		$viewer_url  = Security::sanitize_filetoweb_url( $viewer_url );
		$source_url  = esc_url_raw( $source_url );
		$fingerprint = self::sanitize_fingerprint( $fingerprint );

		if ( ! $post_id || ! $html || ! $viewer_url || ! $source_url || ! $fingerprint ) {
			return false;
		}

		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';

		if ( ! $basedir || ! $baseurl ) {
			return false;
		}

		$hash         = self::fingerprint_slug( $fingerprint );
		$artifact_key = self::BUNDLE_ROOT . '/' . $post_id . '/' . $hash . '/index.html';
		$artifact_url = $baseurl . '/' . $artifact_key;
		$bundle_dir   = trailingslashit( $basedir ) . dirname( $artifact_key );
		$index_path   = trailingslashit( $basedir ) . $artifact_key;
		$current      = self::record_for_post( $post_id );

		if ( self::record_matches( $current, $source_url, $fingerprint, $artifact_key ) && is_readable( $index_path ) ) {
			$manifest  = self::artifact_manifest( $bundle_dir, $basedir, $baseurl );
			$algorithm = sanitize_key( get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT_ALGORITHM, true ) );
			if ( empty( $manifest ) ) {
				return false;
			}

			if ( empty( $current['artifacts'] ) || $manifest !== $current['artifacts'] || $algorithm !== ( isset( $current['source_fingerprint_algorithm'] ) ? $current['source_fingerprint_algorithm'] : '' ) ) {
				if ( ! self::sync_bundle_with_stateless( $bundle_dir, $basedir, $baseurl ) ) {
					return false;
				}

				$current['artifacts']                    = $manifest;
				$current['source_fingerprint_algorithm'] = $algorithm;
				update_post_meta( $post_id, self::META_KEY, $current );
			}

			self::write_legacy_pointer( $post_id, $index_path, $current['token'], $viewer_url, $fingerprint, $current['published_at'] );
			return $current;
		}

		$parent_dir = dirname( $bundle_dir );
		if ( ! wp_mkdir_p( $parent_dir ) ) {
			return false;
		}

		$temp_dir = $bundle_dir . '.tmp-' . strtolower( wp_generate_password( 10, false, false ) );
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return false;
		}

		$bundle_url = dirname( $artifact_url );
		$mirrored   = array();
		$complete   = true;
		$html       = self::mirror_assets( $html, $viewer_url, $temp_dir, $bundle_url, $mirrored, $complete );

		if ( ! $complete ) {
			self::remove_directory( $temp_dir );
			return false;
		}

		$html       = self::sanitize_html( $html );

		if ( ! $html || self::contains_filetoweb_asset_reference( $html, $viewer_url ) || false === file_put_contents( trailingslashit( $temp_dir ) . 'index.html', $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			self::remove_directory( $temp_dir );
			return false;
		}

		if ( is_dir( $bundle_dir ) && is_readable( $index_path ) ) {
			self::remove_directory( $temp_dir );
		} elseif ( is_dir( $bundle_dir ) ) {
			$stale_dir = $bundle_dir . '.stale-' . strtolower( wp_generate_password( 10, false, false ) );

			if ( ! rename( $bundle_dir, $stale_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				self::remove_directory( $temp_dir );
				return false;
			}

			if ( ! rename( $temp_dir, $bundle_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $stale_dir, $bundle_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				self::remove_directory( $temp_dir );
				return false;
			}

			self::remove_directory( $stale_dir );
		} elseif ( ! rename( $temp_dir, $bundle_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			self::remove_directory( $temp_dir );
			return false;
		}

		$token        = wp_generate_password( 40, false, false );
		$published_at = current_time( 'mysql', true );
		$algorithm    = sanitize_key( get_post_meta( $post_id, Document_State::META_SOURCE_FINGERPRINT_ALGORITHM, true ) );
		$manifest     = self::artifact_manifest( $bundle_dir, $basedir, $baseurl );

		if ( empty( $manifest ) ) {
			return false;
		}

		$record       = array(
			'version'            => self::SCHEMA_VERSION,
			'provider'           => self::PROVIDER,
			'source_url'         => $source_url,
			'source_fingerprint' => $fingerprint,
			'source_fingerprint_algorithm' => $algorithm,
			'artifact_key'       => $artifact_key,
			'artifact_url'       => esc_url_raw( $artifact_url ),
			'artifacts'          => $manifest,
			'token'              => $token,
			'published_at'       => $published_at,
		);

		if ( ! self::sync_bundle_with_stateless( $bundle_dir, $basedir, $baseurl ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, $record );
		self::write_legacy_pointer( $post_id, $index_path, $token, $viewer_url, $fingerprint, $published_at );
		self::ensure_provider_state();

		return $record;
	}

	/**
	 * Return a normalized FileToWeb preview record.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function record_for_post( $post_id ) {
		$record = get_post_meta( absint( $post_id ), self::META_KEY, true );

		if ( ! is_array( $record ) || self::PROVIDER !== ( isset( $record['provider'] ) ? $record['provider'] : '' ) ) {
			return array();
		}

		return $record;
	}

	/**
	 * Publish an existing legacy cache without reconverting its PDF.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function migrate_existing_post( $post_id ) {
		$post_id = absint( $post_id );
		$record  = self::record_for_post( $post_id );

		if ( ! $post_id || ( $record && ! empty( $record['artifacts'] ) ) ) {
			return false;
		}

		$path        = self::safe_legacy_path( get_post_meta( $post_id, Document_State::META_LOCAL_HTML_PATH, true ) );
		$viewer_url  = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, true );
		$fingerprint = get_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, true );
		$source_url  = get_post_meta( $post_id, Document_State::META_ORIGINAL_URL, true );

		if ( ! $source_url && function_exists( 'get_post' ) ) {
			$source_url = Source_Resolver::admin_original_source_url( get_post( $post_id ) );
		}

		if ( ! $path || ! $viewer_url || ! $fingerprint || ! $source_url ) {
			return false;
		}

		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_string( $html ) && (bool) self::publish( $post_id, $html, $viewer_url, $source_url, $fingerprint );
	}

	/**
	 * Schedule bounded migration for pre-0.1.32 cache records.
	 */
	public static function schedule_migration() {
		if ( self::MIGRATION_VERSION === (string) get_option( self::OPTION_MIGRATION_VERSION, '' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::MIGRATION_HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::MIGRATION_HOOK );
		}
	}

	/**
	 * Migrate a bounded set of existing local caches without API work.
	 */
	public static function migrate_legacy_batch() {
		$post_ids = get_posts(
			array(
				'post_type'      => array( 'attachment', 'document' ),
				'post_status'    => 'any',
				'posts_per_page' => 25,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => Document_State::META_LOCAL_HTML_PATH,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			self::migrate_existing_post( $post_id );
		}

		if ( 25 === count( $post_ids ) ) {
			wp_schedule_single_event( time() + 30, self::MIGRATION_HOOK );
			return;
		}

		update_option( self::OPTION_MIGRATION_VERSION, self::MIGRATION_VERSION, false );
	}

	/**
	 * Sanitize an HTML document for script-free same-site iframe rendering.
	 *
	 * @param string $html HTML document.
	 * @return string
	 */
	public static function sanitize_html( $html ) {
		$html = is_string( $html ) ? trim( $html ) : '';

		if ( '' === $html ) {
			return '';
		}

		if ( ! class_exists( '\\DOMDocument' ) ) {
			return '';
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );

		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			return '';
		}

		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//script|//iframe|//frame|//frameset|//object|//embed|//applet|//form|//input|//button|//textarea|//select|//base|//meta[@http-equiv]|//*[local-name()="svg" or local-name()="math"]' );

		if ( $nodes ) {
			for ( $index = $nodes->length - 1; $index >= 0; --$index ) {
				$node = $nodes->item( $index );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		$elements = $xpath->query( '//*' );
		if ( $elements ) {
			foreach ( $elements as $element ) {
				$remove = array();

				foreach ( $element->attributes as $attribute ) {
					$name  = strtolower( $attribute->name );
					$value = trim( $attribute->value );

					if ( 0 === strpos( $name, 'on' ) || in_array( $name, array( 'srcdoc', 'formaction' ), true ) ) {
						$remove[] = $attribute->name;
						continue;
					}

					if ( 'style' === $name ) {
						$element->setAttribute( $attribute->name, self::sanitize_css( $value ) );
						continue;
					}

					if ( in_array( $name, array( 'href', 'src', 'poster', 'xlink:href' ), true ) && ! self::safe_embedded_url( $value, 'href' === $name ) ) {
						$remove[] = $attribute->name;
					}
				}

				foreach ( $remove as $name ) {
					$element->removeAttribute( $name );
				}

				if ( 'a' === strtolower( $element->nodeName ) && '_blank' === strtolower( $element->getAttribute( 'target' ) ) ) {
					$element->setAttribute( 'rel', 'noopener noreferrer' );
				}
			}
		}

		$styles = $xpath->query( '//style' );
		if ( $styles ) {
			foreach ( $styles as $style ) {
				$style->nodeValue = self::sanitize_css( $style->nodeValue );
			}
		}

		$links = $xpath->query( '//link' );
		if ( $links ) {
			for ( $index = $links->length - 1; $index >= 0; --$index ) {
				$link = $links->item( $index );
				if ( ! $link || 'stylesheet' !== strtolower( trim( $link->getAttribute( 'rel' ) ) ) || ! self::safe_embedded_url( $link->getAttribute( 'href' ), false ) ) {
					if ( $link && $link->parentNode ) {
						$link->parentNode->removeChild( $link );
					}
				}
			}
		}

		$output = $dom->saveHTML();
		$output = preg_replace( '/^<\?xml[^>]+>\s*/i', '', (string) $output );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return trim( $output );
	}

	/**
	 * Mirror HTML attributes, srcsets, and CSS URLs into the bundle.
	 *
	 * @param string $html HTML.
	 * @param string $viewer_url Viewer URL.
	 * @param string $bundle_dir Temporary bundle directory.
	 * @param string $bundle_url Final public bundle URL.
	 * @param array  $mirrored URL map.
	 * @param bool   $complete Whether every required FileToWeb asset was mirrored.
	 * @return string
	 */
	private static function mirror_assets( $html, $viewer_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
		$html = preg_replace_callback(
			'~\b(?P<attr>src|href|poster)\s*=\s*(?:(?P<quote>["\'])(?P<quoted>[^"\']+)(?P=quote)|(?P<unquoted>[^\s>]+))~i',
			function ( $match ) use ( $viewer_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
				$value  = isset( $match['quoted'] ) && '' !== $match['quoted'] ? $match['quoted'] : $match['unquoted'];
				$quote  = isset( $match['quote'] ) && $match['quote'] ? $match['quote'] : '"';
				$remote = self::resolve_asset_url( $value, $viewer_url );
				$local  = $remote ? self::mirror_asset( $remote, $bundle_dir, $bundle_url, $mirrored, $complete ) : '';

				if ( $remote && ! $local ) {
					$complete = false;
				}

				return $local ? $match['attr'] . '=' . $quote . esc_url_raw( $local ) . $quote : $match[0];
			},
			$html
		);

		$html = preg_replace_callback(
			'~\bsrcset\s*=\s*(?:(?P<quote>["\'])(?P<quoted>[^"\']+)(?P=quote)|(?P<unquoted>[^\s>]+))~i',
			function ( $match ) use ( $viewer_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
				$value = isset( $match['quoted'] ) && '' !== $match['quoted'] ? $match['quoted'] : $match['unquoted'];
				$quote = isset( $match['quote'] ) && $match['quote'] ? $match['quote'] : '"';
				$items = explode( ',', $value );
				foreach ( $items as &$item ) {
					$parts  = preg_split( '/\s+/', trim( $item ), 2 );
					$remote = self::resolve_asset_url( $parts[0], $viewer_url );
					$local  = $remote ? self::mirror_asset( $remote, $bundle_dir, $bundle_url, $mirrored, $complete ) : '';
					if ( $local ) {
						$item = $local . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
					} elseif ( $remote ) {
						$complete = false;
					}
				}

				return 'srcset=' . $quote . implode( ', ', $items ) . $quote;
			},
			$html
		);

		return self::rewrite_css_urls( $html, $viewer_url, $bundle_dir, $bundle_url, $mirrored, $complete );
	}

	/**
	 * Mirror one FileToWeb bundle asset.
	 *
	 * @param string $remote_url Remote asset URL.
	 * @param string $bundle_dir Bundle directory.
	 * @param string $bundle_url Bundle URL.
	 * @param array  $mirrored URL map.
	 * @param bool   $complete Whether every required FileToWeb asset was mirrored.
	 * @return string
	 */
	private static function mirror_asset( $remote_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
		if ( isset( $mirrored[ $remote_url ] ) ) {
			return $mirrored[ $remote_url ];
		}

		$path     = (string) parse_url( $remote_url, PHP_URL_PATH );
		$basename = sanitize_file_name( basename( rawurldecode( $path ) ) );
		$ext      = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

		if ( ! $basename || ! in_array( $ext, array( 'css', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf' ), true ) ) {
			return '';
		}

		$filename = substr( hash( 'sha256', $remote_url ), 0, 16 ) . '-' . $basename;
		$dir      = trailingslashit( $bundle_dir ) . 'assets';
		$path     = trailingslashit( $dir ) . $filename;
		$url      = trailingslashit( $bundle_url ) . 'assets/' . rawurlencode( $filename );

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$mirrored[ $remote_url ] = $url;
		$response = wp_remote_get(
			$remote_url,
			array(
				'timeout'            => 20,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			unset( $mirrored[ $remote_url ] );
			return '';
		}

		$code = absint( wp_remote_retrieve_response_code( $response ) );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || '' === $body || strlen( $body ) > 5 * 1024 * 1024 ) {
			unset( $mirrored[ $remote_url ] );
			return '';
		}

		if ( 'css' === $ext ) {
			$body = self::rewrite_css_urls( $body, $remote_url, $bundle_dir, $bundle_url, $mirrored, $complete );
			$body = self::sanitize_css( $body );
		}

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			unset( $mirrored[ $remote_url ] );
			return '';
		}

		return $url;
	}

	/**
	 * Rewrite CSS url() values that point to FileToWeb bundle assets.
	 *
	 * @param string $css CSS or HTML containing CSS.
	 * @param string $base_url URL used for relative resolution.
	 * @param string $bundle_dir Bundle directory.
	 * @param string $bundle_url Bundle URL.
	 * @param array  $mirrored URL map.
	 * @param bool   $complete Whether every required FileToWeb asset was mirrored.
	 * @return string
	 */
	private static function rewrite_css_urls( $css, $base_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
		return preg_replace_callback(
			'~url\(\s*(?P<quote>["\']?)(?P<value>[^)"\']+)(?P=quote)\s*\)~i',
			function ( $match ) use ( $base_url, $bundle_dir, $bundle_url, &$mirrored, &$complete ) {
				$remote = self::resolve_asset_url( trim( $match['value'] ), $base_url );
				$local  = $remote ? self::mirror_asset( $remote, $bundle_dir, $bundle_url, $mirrored, $complete ) : '';

				if ( $remote && ! $local ) {
					$complete = false;
				}

				return $local ? 'url("' . esc_url_raw( $local ) . '")' : $match[0];
			},
			$css
		);
	}

	/**
	 * Reject any FileToWeb asset dependency that survived mirroring/sanitization.
	 *
	 * @param string $html Sanitized HTML.
	 * @param string $viewer_url FileToWeb viewer URL.
	 * @return bool
	 */
	private static function contains_filetoweb_asset_reference( $html, $viewer_url ) {
		if ( preg_match_all( '~\b(?:src|href|poster)\s*=\s*(["\'])(.*?)\1~is', $html, $matches ) ) {
			foreach ( $matches[2] as $value ) {
				if ( self::resolve_asset_url( $value, $viewer_url ) ) {
					return true;
				}
			}
		}

		if ( preg_match_all( '~\bsrcset\s*=\s*(["\'])(.*?)\1~is', $html, $srcsets ) ) {
			foreach ( $srcsets[2] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $item ) {
					$parts = preg_split( '/\s+/', trim( $item ), 2 );
					if ( ! empty( $parts[0] ) && self::resolve_asset_url( $parts[0], $viewer_url ) ) {
						return true;
					}
				}
			}
		}

		if ( preg_match_all( '~url\(\s*["\']?([^\)"\']+)["\']?\s*\)~i', $html, $css_urls ) ) {
			foreach ( $css_urls[1] as $value ) {
				if ( self::resolve_asset_url( trim( $value ), $viewer_url ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Build a complete, deterministic local/remote artifact manifest.
	 *
	 * @param string $bundle_dir Bundle directory.
	 * @param string $uploads_basedir Upload base directory.
	 * @param string $uploads_baseurl Upload base URL.
	 * @return array
	 */
	private static function artifact_manifest( $bundle_dir, $uploads_basedir, $uploads_baseurl ) {
		$manifest = array();

		foreach ( self::files_in_directory( $bundle_dir ) as $path ) {
			$key = ltrim( str_replace( wp_normalize_path( $uploads_basedir ), '', wp_normalize_path( $path ) ), '/' );

			if ( 0 !== strpos( $key, self::BUNDLE_ROOT . '/' ) ) {
				continue;
			}

			$manifest[] = array(
				'artifact_key' => $key,
				'artifact_url' => esc_url_raw( trailingslashit( $uploads_baseurl ) . str_replace( '%2F', '/', rawurlencode( $key ) ) ),
			);
		}

		usort(
			$manifest,
			function ( $left, $right ) {
				return strcmp( $left['artifact_key'], $right['artifact_key'] );
			}
		);

		return $manifest;
	}

	/**
	 * Resolve and constrain a FileToWeb asset URL.
	 *
	 * @param string $value URL value.
	 * @param string $base_url Base URL.
	 * @return string
	 */
	private static function resolve_asset_url( $value, $base_url ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value || '#' === substr( $value, 0, 1 ) || preg_match( '~^(data|blob|mailto|tel|javascript):~i', $value ) ) {
			return '';
		}

		$base = parse_url( $base_url );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}

		$parts = parse_url( $value );
		if ( ! empty( $parts['scheme'] ) ) {
			$url = $value;
		} elseif ( 0 === strpos( $value, '//' ) ) {
			$url = $base['scheme'] . ':' . $value;
		} elseif ( 0 === strpos( $value, '/' ) ) {
			$url = $base['scheme'] . '://' . $base['host'] . $value;
		} else {
			$base_path = isset( $base['path'] ) ? preg_replace( '~/[^/]*$~', '/', $base['path'] ) : '/';
			$url       = $base['scheme'] . '://' . $base['host'] . $base_path . $value;
		}

		$url  = Security::sanitize_filetoweb_url( $url );
		$path = $url ? (string) parse_url( $url, PHP_URL_PATH ) : '';

		return $url && preg_match( '~^/d/[^/]+/assets/.+~', $path ) ? $url : '';
	}

	/**
	 * Strip active CSS constructs while preserving presentation CSS.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function sanitize_css( $css ) {
		$css = is_string( $css ) ? $css : '';
		$css = preg_replace( '~@import\s+[^;]+;?~i', '', $css );
		$css = preg_replace( '~expression\s*\([^)]*\)~i', '', $css );
		$css = preg_replace( '~url\(\s*["\']?\s*(?:javascript|vbscript):[^)]*\)~i', 'none', $css );
		$css = preg_replace( '~(?:behavior|-moz-binding)\s*:[^;}]*(?:;|(?=}))~i', '', $css );

		return trim( $css );
	}

	/**
	 * Validate a URL that can remain in the static document.
	 *
	 * @param string $value URL.
	 * @param bool   $is_link Whether mailto/tel are allowed.
	 * @return bool
	 */
	private static function safe_embedded_url( $value, $is_link ) {
		$value = trim( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $value || '#' === substr( $value, 0, 1 ) || '/' === substr( $value, 0, 1 ) || './' === substr( $value, 0, 2 ) || '../' === substr( $value, 0, 3 ) ) {
			return true;
		}

		if ( 0 === stripos( $value, 'data:image/' ) ) {
			return true;
		}

		$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
		if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return true;
		}

		return $is_link && in_array( $scheme, array( 'mailto', 'tel' ), true );
	}

	/**
	 * Synchronize every bundle file through WP Stateless when installed.
	 *
	 * @param string $bundle_dir Bundle directory.
	 * @param string $uploads_basedir Upload base directory.
	 * @param string $uploads_baseurl Upload base URL.
	 * @return bool
	 */
	private static function sync_bundle_with_stateless( $bundle_dir, $uploads_basedir, $uploads_baseurl ) {
		if ( ! function_exists( 'has_action' ) || ! has_action( 'sm:sync::syncFile' ) ) {
			return true;
		}

		$files = self::files_in_directory( $bundle_dir );

		foreach ( $files as $path ) {
			$name = ltrim( str_replace( wp_normalize_path( $uploads_basedir ), '', wp_normalize_path( $path ) ), '/' );
			do_action(
				'sm:sync::syncFile',
				$name,
				$path,
				2,
				array(
					'ephemeral'     => false,
					'source'        => 'filetoweb-integration',
					'source_version' => defined( 'FILETOWEB_INTEGRATION_VERSION' ) ? FILETOWEB_INTEGRATION_VERSION : '',
				)
			);
		}

		foreach ( $files as $path ) {
			$name     = ltrim( str_replace( wp_normalize_path( $uploads_basedir ), '', wp_normalize_path( $path ) ), '/' );
			$url      = trailingslashit( $uploads_baseurl ) . str_replace( '%2F', '/', rawurlencode( $name ) );
			$response = wp_safe_remote_head(
				$url,
				array(
					'timeout'            => 10,
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
		}

		return true;
	}

	/**
	 * Return files recursively without relying on WordPress filesystem APIs.
	 *
	 * @param string $dir Directory.
	 * @return array
	 */
	private static function files_in_directory( $dir ) {
		$files = array();
		$items = glob( trailingslashit( $dir ) . '*' );

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_dir( $item ) ) {
				$files = array_merge( $files, self::files_in_directory( $item ) );
			} elseif ( is_file( $item ) ) {
				$files[] = $item;
			}
		}

		return $files;
	}

	/**
	 * Remove a temporary directory recursively.
	 *
	 * @param string $dir Directory.
	 */
	private static function remove_directory( $dir ) {
		foreach ( self::files_in_directory( $dir ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$dirs = glob( trailingslashit( $dir ) . '*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? array_reverse( $dirs ) : array() as $child ) {
			self::remove_directory( $child );
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_rmdir
		}
	}

	/**
	 * Preserve the legacy pointer for older ProudCity/plugin rendering paths.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $path Artifact path.
	 * @param string $token Token.
	 * @param string $viewer_url Viewer URL.
	 * @param string $fingerprint Fingerprint.
	 * @param string $published_at Publication time.
	 */
	private static function write_legacy_pointer( $post_id, $path, $token, $viewer_url, $fingerprint, $published_at ) {
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_PATH, $path );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_TOKEN, $token );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_URL, $viewer_url );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_SOURCE_FP, $fingerprint );
		update_post_meta( $post_id, Document_State::META_LOCAL_HTML_UPDATED_AT, $published_at );
	}

	/**
	 * Set a default provider entry only when an administrator has not chosen one.
	 */
	private static function ensure_provider_state() {
		$providers = get_option( self::OPTION_PROVIDERS, array() );
		$providers = is_array( $providers ) ? $providers : array();

		if ( array_key_exists( self::PROVIDER, $providers ) ) {
			return;
		}

		$settings = Settings::settings();
		$enabled  = ! empty( $settings[ Settings::KEY_REPLACE_LINKS ] ) && '0' !== (string) $settings[ Settings::KEY_REPLACE_LINKS ];

		$providers[ self::PROVIDER ] = $enabled;
		update_option( self::OPTION_PROVIDERS, $providers, false );
	}

	/**
	 * Determine whether a record already points to the current artifact.
	 *
	 * @param array  $record Record.
	 * @param string $source_url Source URL.
	 * @param string $fingerprint Fingerprint.
	 * @param string $artifact_key Artifact key.
	 * @return bool
	 */
	private static function record_matches( $record, $source_url, $fingerprint, $artifact_key ) {
		return is_array( $record )
			&& $source_url === ( isset( $record['source_url'] ) ? $record['source_url'] : '' )
			&& $fingerprint === ( isset( $record['source_fingerprint'] ) ? $record['source_fingerprint'] : '' )
			&& $artifact_key === ( isset( $record['artifact_key'] ) ? $record['artifact_key'] : '' )
			&& ! empty( $record['token'] );
	}

	/**
	 * Validate a legacy local cache path for migration.
	 *
	 * @param mixed $path Path.
	 * @return string
	 */
	private static function safe_legacy_path( $path ) {
		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? realpath( trailingslashit( $uploads['basedir'] ) . 'filetoweb-integration' ) : false;
		$path    = is_string( $path ) ? realpath( $path ) : false;

		return $path && $basedir && 0 === strpos( $path, trailingslashit( $basedir ) ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * Normalize a fingerprint for storage/path use.
	 *
	 * @param mixed $fingerprint Fingerprint.
	 * @return string
	 */
	private static function sanitize_fingerprint( $fingerprint ) {
		$fingerprint = is_scalar( $fingerprint ) ? sanitize_text_field( (string) $fingerprint ) : '';

		return substr( $fingerprint, 0, 255 );
	}

	/**
	 * Return a deterministic path-safe fingerprint segment.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return string
	 */
	private static function fingerprint_slug( $fingerprint ) {
		$slug = substr( preg_replace( '/[^a-zA-Z0-9]/', '', $fingerprint ), 0, 48 );

		return $slug ? strtolower( $slug ) : substr( hash( 'sha256', $fingerprint ), 0, 48 );
	}
}
