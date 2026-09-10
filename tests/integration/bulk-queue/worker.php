<?php
/** Test runner commands, using actual WordPress options, cron storage and locks. */
use FileToWeb\Integration\Bulk_Queue;
use FileToWeb\Integration\Cron;
use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;
use FileToWeb\Integration\Source_Resolver;

$command = isset( $argv[1] ) ? $argv[1] : 'status';
$ftw_test_finished = false;
// wp-load can exit for an uninstalled site with status 0. That must never
// masquerade as a passing assertion or a reached worker pause.
register_shutdown_function( function () use ( &$ftw_test_finished ) {
	if ( ! $ftw_test_finished ) {
		fwrite( STDERR, "Integration command did not finish.\n" );
		exit( 1 );
	}
} );
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['REQUEST_METHOD'] = 'GET';
if ( 'install' === $command ) { define( 'WP_INSTALLING', true ); }
require '/var/www/html/wp-load.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function source_files() {
	$uploads = wp_upload_dir();
	wp_mkdir_p( $uploads['basedir'] );
	file_put_contents( $uploads['basedir'] . '/queue-fixture.pdf', "%PDF-1.4\nqueue-test-stable-bytes\n%%EOF\n" );
}

if ( 'install' === $command ) {
	if ( ! is_blog_installed() ) {
		require ABSPATH . 'wp-admin/includes/upgrade.php';
		wp_install( 'Isolated bulk queue tests', 'test-admin', 'nobody@example.org', false, '', 'isolated-password-not-production' );
	}
	update_option( 'home', 'https://example.com' );
	update_option( 'siteurl', 'https://example.com' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	check( null === activate_plugin( 'filetoweb-integration/filetoweb-integration.php' ), 'Plugin activation failed.' );
	source_files();
} elseif ( 'files' === $command ) {
	source_files();
} elseif ( 'reset' === $command ) {
	$count = isset( $argv[2] ) ? (int) $argv[2] : 4;
	wp_clear_scheduled_hook( Bulk_Queue::HOOK_PROCESS );
	delete_transient( 'doing_cron' );
	delete_option( 'ftw_test_signal' );
	delete_option( 'ftw_test_lookups' );
	delete_option( 'ftw_test_unexpected_api' );
	update_option( Settings::OPTION_SETTINGS, array(), false );
	$items = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$id = wp_insert_attachment( array( 'post_title' => 'Queue fixture ' . $i, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), wp_upload_dir()['basedir'] . '/queue-fixture.pdf' );
		check( is_int( $id ) && $id > 0, 'Fixture insert failed.' );
		$items[] = array( 'id' => $id, 'kind' => 'attachment' );
	}
	update_option( Settings::OPTION_SETTINGS, array( Settings::KEY_ENABLED => '1', Settings::KEY_API_KEY => 'ftw_api_isolated_fixture_only', Settings::KEY_API_BASE_URL => 'https://filetoweb.com', Settings::KEY_BATCH_SIZE => 5 ), false );
	update_option( Bulk_Queue::OPTION_QUEUE, array( 'type' => 'meeting_pdfs', 'total' => $count, 'processed' => 0, 'queued' => 0, 'failed' => 0, 'skipped' => 0, 'items' => $items, 'updated_at' => '2000-01-01 00:00:00' ), false );
	update_option( 'ftw_test_ids', array_column( $items, 'id' ), false );
	wp_schedule_single_event( time(), Bulk_Queue::HOOK_PROCESS );
} elseif ( 'run' === $command ) {
	// Model a concurrent cron dispatch or admin button using real WP scheduling.
	$next = wp_next_scheduled( Bulk_Queue::HOOK_PROCESS );
	if ( $next ) { wp_unschedule_event( $next, Bulk_Queue::HOOK_PROCESS ); }
	Bulk_Queue::process_next_batch();
} elseif ( 'recover' === $command ) {
	wp_clear_scheduled_hook( Bulk_Queue::HOOK_PROCESS );
	do_action( Cron::HOOK_POLL_PENDING );
	check( false !== wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ), 'Periodic worker failed to recover abandoned queue.' );
} elseif ( 'id' === $command ) {
	echo get_option( 'ftw_test_ids' )[ (int) $argv[2] ];
	$ftw_test_finished = true;
	exit;
} elseif ( 'signal' === $command ) {
	$signal = get_option( 'ftw_test_signal', array() );
	$ftw_test_finished = true;
	exit( isset( $signal['phase'] ) && $signal['phase'] === $argv[2] ? 0 : 1 );
} elseif ( 'hold-item' === $command ) {
	$id = get_option( 'ftw_test_ids' )[0];
	Cron::with_item_lock( $id, function () use ( $id ) {
		putenv( 'FTW_TEST_PAUSE=item' );
		putenv( 'FTW_TEST_PAUSE_ID=' . $id );
		ftw_test_pause( 'item', $id );
	} );
} elseif ( 'replace-busy' === $command ) {
	$before = Bulk_Queue::queue_state();
	$result = Bulk_Queue::queue_documents();
	check( ! empty( $result['busy'] ), 'Queue replacement was not rejected while locked.' );
	check( $before === Bulk_Queue::queue_state(), 'Queue replacement changed active state.' );
} elseif ( 'assert' === $command ) {
	$state = Bulk_Queue::queue_state();
	check( (int) $argv[2] === (int) $state['processed'], 'Unexpected processed count: ' . wp_json_encode( $state ) );
	check( (int) $state['total'] === (int) $state['processed'] + count( $state['items'] ), 'Queue accounting mismatch.' );
	check( 0 === (int) $state['failed'] && 0 === (int) $state['skipped'], 'Unexpected failures/skips.' );
	check( (bool) count( $state['items'] ) === (bool) wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ), 'Continuation does not match remaining work.' );
} elseif ( 'assert-complete' === $command ) {
	$state = Bulk_Queue::queue_state();
	check( ! get_option( 'ftw_test_unexpected_api' ), 'Unexpected API call: ' . get_option( 'ftw_test_unexpected_api' ) );
	check( empty( $state['items'] ) && (int) $state['queued'] === (int) $state['total'], 'Queue did not complete.' );
	check( ! wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ), 'Drained queue kept its successor.' );
	foreach ( get_option( 'ftw_test_ids' ) as $id ) {
		$document = get_option( 'ftw_test_remote_' . md5( Source_Resolver::attachment_external_id( $id ) ) );
		check( ! empty( $document['id'] ), 'Missing accepted fixture document.' );
		check( $document['id'] === get_post_meta( $id, Document_State::META_DOCUMENT_ID, true ), 'Replay adopted a different document.' );
		check( 'ready' === get_post_meta( $id, Document_State::META_STATUS, true ), 'Fixture document not ready.' );
	}
	if ( isset( $argv[2] ) && 'lookup' === $argv[2] ) {
		check( (int) get_option( 'ftw_test_lookups', 0 ) > 0, 'Accepted request was not recovered by external ID.' );
	}
}

if ( class_exists( Bulk_Queue::class ) ) {
	$state = Bulk_Queue::queue_state();
	echo wp_json_encode( array( 'command' => $command, 'processed' => $state['processed'], 'remaining' => count( $state['items'] ), 'next' => wp_next_scheduled( Bulk_Queue::HOOK_PROCESS ) ) ) . "\n";
}
$ftw_test_finished = true;
