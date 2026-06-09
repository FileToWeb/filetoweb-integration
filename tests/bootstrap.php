<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! class_exists( 'WP_Widget' ) ) {
	class WP_Widget {
		public function __construct() {}
		public function get_field_id( $field_name ) {
			return $field_name;
		}
		public function get_field_name( $field_name ) {
			return $field_name;
		}
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../includes/class-security.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-capabilities.php';
require_once __DIR__ . '/../includes/class-document-state.php';
require_once __DIR__ . '/../includes/class-api-client.php';
require_once __DIR__ . '/../includes/class-source-resolver.php';
require_once __DIR__ . '/../includes/class-sync.php';
require_once __DIR__ . '/../includes/class-cron.php';
require_once __DIR__ . '/../includes/class-admin.php';
require_once __DIR__ . '/../includes/class-meeting-materials.php';
require_once __DIR__ . '/../includes/class-local-html.php';
require_once __DIR__ . '/../includes/class-native-page.php';
require_once __DIR__ . '/../includes/class-pdf-to-page.php';
require_once __DIR__ . '/../includes/class-bulk-queue.php';
require_once __DIR__ . '/../includes/class-link-rewriter.php';
require_once __DIR__ . '/../includes/class-widget.php';
require_once __DIR__ . '/../includes/class-plugin.php';
