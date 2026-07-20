<?php

namespace Proud\Core;

function proud_html_preview_queue_cleanup( $provider, $artifact_key, $artifact_url ) {
	$GLOBALS['filetoweb_test_cleanup_queue'][] = array( $provider, $artifact_key, $artifact_url );

	return true;
}
