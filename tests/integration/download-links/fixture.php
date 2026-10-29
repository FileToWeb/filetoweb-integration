<?php
/** Local-only fixture: representative widget output, real WordPress filters. */

// This MU plugin belongs only in the isolated test container.
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', function () {
	return new WP_Error( 'ftw_test_no_network', 'External HTTP is disabled in the download fixture.' );
}, 1 );

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['ftw_download_test'] ) ) {
		return;
	}

	$source = get_option( 'ftw_download_test_source' );
	$markup = '<a href="' . esc_url( $source ) . '">Read accessible document</a> '
		. '<a href="' . esc_url( $source ) . '" class="btn btn-primary btn-sm" download="original.pdf">Download PDF</a> '
		. '<a download href="' . esc_url( $source ) . '">Download with bare attribute</a> '
		. '<iframe title="Embedded document" src="https://docs.google.com/gview?url=' . rawurlencode( $source ) . '&amp;embedded=true"></iframe>';
	header( 'Content-Type: text/html; charset=UTF-8' );
	echo '<!doctype html><html lang="en"><title>FileToWeb download regression</title><body><h1>PDF download regression</h1>';
	foreach ( array( 'the_content', 'widget_text', 'widget_text_content' ) as $hook ) {
		echo '<section id="' . esc_attr( $hook ) . '"><h2>' . esc_html( $hook ) . '</h2>';
		// Repeat filtering as happens when widget markup also passes through the_content.
		echo apply_filters( 'the_content', apply_filters( $hook, $markup ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</section>';
	}
	echo '</body></html>';
	exit;
} );
