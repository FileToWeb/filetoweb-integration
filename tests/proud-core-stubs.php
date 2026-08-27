<?php

namespace Proud\Core;

function proud_html_preview_queue_cleanup( $provider, $artifact_key, $artifact_url ) {
	$GLOBALS['filetoweb_test_cleanup_queue'][] = array( $provider, $artifact_key, $artifact_url );

	if ( ! empty( $GLOBALS['filetoweb_test_cleanup_queue_results'] ) && is_array( $GLOBALS['filetoweb_test_cleanup_queue_results'] ) ) {
		return (bool) array_shift( $GLOBALS['filetoweb_test_cleanup_queue_results'] );
	}

	return true;
}

function proud_html_preview_url( $post_id, $source_url = '' ) {
	$GLOBALS['filetoweb_test_preview_url_calls'][] = array( $post_id, $source_url );

	return isset( $GLOBALS['filetoweb_test_preview_urls'][ $post_id ] )
		? $GLOBALS['filetoweb_test_preview_urls'][ $post_id ]
		: '';
}
