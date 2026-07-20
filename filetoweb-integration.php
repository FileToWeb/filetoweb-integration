<?php
/**
 * Plugin Name:       FileToWeb Integration
 * Plugin URI:        https://filetoweb.com
 * Description:       Converts PDF attachments and Proud Document files with FileToWeb, serves WordPress-local HTML when ready, and offers an intentional PDF-to-Page workflow.
 * Version:           0.1.32
 * Requires at least: 5.7
 * Requires PHP:      7.0
 * Author:            FileToWeb
 * Author URI:        https://filetoweb.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       filetoweb-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FILETOWEB_INTEGRATION_VERSION', '0.1.32' );
define( 'FILETOWEB_INTEGRATION_FILE', __FILE__ );
define( 'FILETOWEB_INTEGRATION_PATH', plugin_dir_path( __FILE__ ) );
define( 'FILETOWEB_INTEGRATION_URL', plugin_dir_url( __FILE__ ) );

require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-security.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-settings.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-capabilities.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-document-state.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-proud-html-preview.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-api-client.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-source-resolver.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-sync.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-cron.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-admin.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-meeting-materials.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-local-html.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-native-page.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-pdf-to-page.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-bulk-queue.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-link-rewriter.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-accessibility-attribution.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-widget.php';
require_once FILETOWEB_INTEGRATION_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'FileToWeb\\Integration\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FileToWeb\\Integration\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'FileToWeb\\Integration\\Plugin', 'init' ) );
