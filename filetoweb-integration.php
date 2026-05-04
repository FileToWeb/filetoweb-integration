<?php
/**
 * Plugin Name:       FileToWeb Integration
 * Plugin URI:        https://filetoweb.com
 * Description:       Converts PDF attachments and Proud Document files with FileToWeb, then replaces public PDF links with generated HTML links when ready.
 * Version:           0.1.0
 * Author:            FileToWeb
 * Author URI:        https://filetoweb.com
 * License:           GPL-2.0-or-later
 * Text Domain:       filetoweb-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FILETOWEB_INTEGRATION_VERSION', '0.1.0' );
define( 'FILETOWEB_INTEGRATION_FILE', __FILE__ );
define( 'FILETOWEB_INTEGRATION_PATH', plugin_dir_path( __FILE__ ) );
define( 'FILETOWEB_INTEGRATION_URL', plugin_dir_url( __FILE__ ) );

require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-security.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-settings.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-document-state.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-api-client.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-source-resolver.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-sync.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-cron.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-admin.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-link-rewriter.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-widget.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'FileToWeb\\Integration\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FileToWeb\\Integration\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'FileToWeb\\Integration\\Plugin', 'init' ) );
