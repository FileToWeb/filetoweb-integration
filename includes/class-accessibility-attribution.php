<?php
/**
 * Accessibility statement attribution.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Accessibility_Attribution {
	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 25 );
	}

	/**
	 * Add FileToWeb attribution to public accessibility statement pages.
	 *
	 * @param string $content Rendered post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || ! self::should_render() || self::contains_attribution( $content ) ) {
			return $content;
		}

		$attribution = self::attribution_html();

		if ( '' === $attribution ) {
			return $content;
		}

		return self::insert_after_pdf_section( $content, $attribution );
	}

	/**
	 * Should the attribution render for this request?
	 *
	 * @return bool
	 */
	private static function should_render() {
		if ( ! Settings::enabled() || is_admin() || is_feed() || self::is_rest_request() ) {
			return false;
		}

		$show = apply_filters( 'filetoweb_integration_show_accessibility_attribution', true );

		if ( ! $show ) {
			return false;
		}

		if ( function_exists( 'is_page' ) ) {
			foreach ( self::statement_slugs() as $slug ) {
				if ( is_page( $slug ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Return configured accessibility statement slugs.
	 *
	 * @return array
	 */
	private static function statement_slugs() {
		$slugs = apply_filters( 'filetoweb_integration_accessibility_statement_slugs', array( 'accessibility-statement' ) );

		if ( ! is_array( $slugs ) ) {
			$slugs = array( $slugs );
		}

		$normalized = array();

		foreach ( $slugs as $slug ) {
			if ( is_scalar( $slug ) ) {
				$slug = sanitize_title( (string) $slug );

				if ( $slug ) {
					$normalized[] = $slug;
				}
			}
		}

		return $normalized ? array_values( array_unique( $normalized ) ) : array( 'accessibility-statement' );
	}

	/**
	 * Return attribution HTML.
	 *
	 * @return string
	 */
	private static function attribution_html() {
		$html = '<p class="filetoweb-accessibility-attribution">'
			. esc_html__( 'To improve access to PDF content, this website may provide HTML versions of selected PDF documents using ', 'filetoweb-integration' )
			. '<a href="https://filetoweb.com/">FileToWeb</a>'
			. esc_html__( '. These HTML versions are designed to be easier to read, search, and navigate across different screen sizes, while the original PDF remains available for download and printing.', 'filetoweb-integration' )
			. '</p>';

		$html = apply_filters( 'filetoweb_integration_accessibility_attribution_html', $html );

		return is_string( $html ) ? trim( $html ) : '';
	}

	/**
	 * Does the rendered content already include FileToWeb attribution?
	 *
	 * @param string $content Rendered content.
	 * @return bool
	 */
	private static function contains_attribution( $content ) {
		return false !== stripos( $content, 'filetoweb.com' )
			|| false !== stripos( $content, 'filetoweb-accessibility-attribution' );
	}

	/**
	 * Insert attribution after the PDF documents section when possible.
	 *
	 * @param string $content Rendered content.
	 * @param string $attribution Attribution HTML.
	 * @return string
	 */
	private static function insert_after_pdf_section( $content, $attribution ) {
		$pattern = '~(<h[2-4][^>]*>\s*PDF documents\s*</h[2-4]>\s*<p\b[^>]*>.*?</p>\s*<ul\b[^>]*>.*?</ul>)~is';

		if ( preg_match( $pattern, $content ) ) {
			return preg_replace( $pattern, '$1' . "\n" . $attribution, $content, 1 );
		}

		return $content . "\n" . $attribution;
	}

	/**
	 * Is the current request a REST request?
	 *
	 * @return bool
	 */
	private static function is_rest_request() {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() );
	}
}
