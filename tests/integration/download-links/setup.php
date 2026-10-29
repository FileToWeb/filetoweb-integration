<?php
/** Install the exact ZIP and create ready local PDF/HTML fixtures without an API. */

use FileToWeb\Integration\Document_State;
use FileToWeb\Integration\Settings;

$finished = false;
register_shutdown_function( function () use ( &$finished ) {
	if ( ! $finished ) {
		fwrite( STDERR, "Download fixture setup did not finish.\n" );
		exit( 1 );
	}
} );
$site_url = 'http://127.0.0.1:' . getenv( 'FTW_DOWNLOAD_TEST_PORT' );
define( 'WP_HOME', $site_url );
define( 'WP_SITEURL', $site_url );
define( 'WP_CONTENT_URL', $site_url . '/wp-content' );
$_SERVER['HTTP_HOST'] = '127.0.0.1:' . getenv( 'FTW_DOWNLOAD_TEST_PORT' );
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_INSTALLING', true );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! is_blog_installed() ) {
	wp_install( 'Download regression', 'fixture-admin', 'fixture@example.test', false, '', 'fixture-only-password' );
}
update_option( 'home', $site_url );
update_option( 'siteurl', $site_url );
WP_Filesystem();
$result = unzip_file( '/ftw-release.zip', WP_PLUGIN_DIR );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
$result = activate_plugin( 'filetoweb-integration/filetoweb-integration.php' );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
update_option( Settings::OPTION_SETTINGS, array(
	Settings::KEY_ENABLED => '1',
	Settings::KEY_REPLACE_LINKS => '1',
	Settings::KEY_API_BASE_URL => 'https://filetoweb.com',
	Settings::KEY_API_KEY => '',
) );

// A valid, single-page PDF with deterministic bytes and cross-reference offsets.
$pdf = "%PDF-1.4\n";
$objects = array(
	'<< /Type /Catalog /Pages 2 0 R >>',
	'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
	'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
	"<< /Length 0 >>\nstream\nendstream",
);
$offsets = array();
foreach ( $objects as $index => $object ) {
	$offsets[] = strlen( $pdf );
	$pdf .= ( $index + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
}
$xref = strlen( $pdf );
$pdf .= "xref\n0 5\n0000000000 65535 f \n";
foreach ( $offsets as $offset ) {
	$pdf .= sprintf( "%010d 00000 n \n", $offset );
}
$pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
$uploads = wp_upload_dir();
$directory = $uploads['basedir'] . '/filetoweb-integration';
wp_mkdir_p( $directory );
$path = $directory . '/original.pdf';
file_put_contents( $path, $pdf );
$source = $uploads['baseurl'] . '/filetoweb-integration/original.pdf';
$id = wp_insert_attachment( array( 'post_title' => 'Original PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $path );
$html_path = $directory . '/download-test.html';
file_put_contents( $html_path, '<!doctype html><html lang="en"><title>Accessible preview</title><h1>Accessible preview fixture</h1></html>' );
foreach ( array(
	Document_State::META_STATUS => 'ready',
	Document_State::META_HTML_URL => 'https://filetoweb.com/d/download-test/1',
	Document_State::META_ORIGINAL_URL => $source,
	Document_State::META_LOCAL_HTML_PATH => $html_path,
	Document_State::META_LOCAL_HTML_TOKEN => 'download-test-token',
) as $key => $value ) {
	update_post_meta( $id, $key, $value );
}
update_option( 'ftw_download_test_source', $source );
echo 'Activated plugin ' . FILETOWEB_INTEGRATION_VERSION . " from ZIP; fixture: " . $site_url . "/?ftw_download_test=1\n";
$finished = true;
