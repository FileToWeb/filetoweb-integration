<?php
/**
 * Minimal stand-in for the WP-CLI formatting helpers used by the commands.
 *
 * @package FileToWeb\Integration
 */

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
	/**
	 * Record the rows a command asked WP-CLI to render.
	 *
	 * @param string $format Output format.
	 * @param array  $items Rows.
	 * @param array  $fields Columns.
	 */
	function format_items( $format, $items, $fields ) {
		\FtwTestWpCli::$format = (string) $format;
		\FtwTestWpCli::$items  = array_values( is_array( $items ) ? $items : array() );
		\FtwTestWpCli::$fields = is_array( $fields ) ? $fields : array();
	}
}
