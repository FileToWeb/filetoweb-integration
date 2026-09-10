<?php
/** Isolated integration fixture only; never package/install this as a plugin. */
use FileToWeb\Integration\Bulk_Queue;
use FileToWeb\Integration\Source_Resolver;

add_filter( 'pre_wp_mail', '__return_true' );

function ftw_test_signal( $phase, $id ) {
	update_option( 'ftw_test_signal', array( 'phase' => $phase, 'id' => (int) $id ), false );
}

function ftw_test_pause( $phase, $id ) {
	if ( getenv( 'FTW_TEST_PAUSE' ) !== $phase || (int) getenv( 'FTW_TEST_PAUSE_ID' ) !== (int) $id ) {
		return;
	}
	ftw_test_signal( $phase, $id );
	// This process is deliberately killed by the harness, without finally or shutdown.
	$deadline = microtime( true ) + 90;
	while ( microtime( true ) < $deadline ) {
		usleep( 100000 );
	}
	throw new RuntimeException( 'Harness failed to kill its paused worker within 90 seconds.' );
}

add_filter( 'filetoweb_integration_bulk_batch_interval', function () { return 0; } );
add_filter( 'filetoweb_integration_bulk_batch_timeout', function ( $budget ) {
	return false === getenv( 'FTW_TEST_BUDGET' ) ? $budget : (float) getenv( 'FTW_TEST_BUDGET' );
} );

// Intercept all WordPress HTTP traffic. No test request can reach a real API.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	$method = isset( $args['method'] ) ? $args['method'] : 'GET';
	$body = array();
	if ( 'POST' === $method && '/v1/documents' === $path ) {
		$payload = json_decode( $args['body'], true );
		$key = 'ftw_test_remote_' . md5( $payload['external_id'] );
		$document = get_option( $key, false );
		if ( ! $document ) {
			$document = array(
				'id' => 'test-' . md5( $payload['external_id'] ),
				'external_id' => $payload['external_id'],
				'status' => 'ready',
				'source' => array( 'fingerprint' => $payload['source']['fingerprint']['value'], 'fingerprint_algorithm' => $payload['source']['fingerprint']['algorithm'] ),
				'html_url' => 'https://filetoweb.com/d/queue-test/1',
				'continuous_url' => 'https://filetoweb.com/d/queue-test/continuous',
				'page_count' => 1,
			);
			add_option( $key, $document, '', false );
		}
		if ( $document['source']['fingerprint'] !== $payload['source']['fingerprint']['value'] ) {
			throw new RuntimeException( 'Replay changed its source fingerprint.' );
		}
		$id = (int) $payload['metadata']['wordpress_post_id'];
		update_post_meta( $id, '_ftw_test_posts', 1 + (int) get_post_meta( $id, '_ftw_test_posts', true ) );
		ftw_test_pause( 'accepted', $id );
		if ( getenv( 'FTW_TEST_DELAY_MS' ) ) {
			usleep( 1000 * (int) getenv( 'FTW_TEST_DELAY_MS' ) );
		}
		$body = array( 'document' => $document );
	} elseif ( 'GET' === $method && 0 === strpos( $path, '/v1/documents/by-external-id/' ) ) {
		$external = rawurldecode( substr( $path, strlen( '/v1/documents/by-external-id/' ) ) );
		$body = array( 'document' => get_option( 'ftw_test_remote_' . md5( $external ), null ) );
		update_option( 'ftw_test_lookups', 1 + (int) get_option( 'ftw_test_lookups', 0 ), false );
	} elseif ( false !== strpos( $path, '/d/queue-test/' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<!doctype html><html><head><title>Queue fixture</title></head><body><main><p>Converted fixture</p></main></body></html>' );
	} elseif ( 'HEAD' === $method && false !== strpos( $url, 'filetoweb-integration/previews/' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' );
	} else {
		if ( 0 === strpos( $path, '/v1/' ) ) {
			update_option( 'ftw_test_unexpected_api', $method . ' ' . $path, false );
		}
		return new WP_Error( 'blocked_test_network', 'Unexpected outbound request blocked: ' . $method . ' ' . $path );
	}
	return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( $body ) );
}, PHP_INT_MAX, 3 );

add_action( 'filetoweb_integration_after_sync_post', function ( $id ) {
	ftw_test_pause( 'synced', $id );
}, PHP_INT_MAX );
