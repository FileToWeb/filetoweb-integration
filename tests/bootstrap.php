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

if ( ! class_exists( 'FtwTestStatelessClient' ) ) {
	class FtwTestStatelessClient {
		public $exists = true;
		public $checked = array();
		public $objects = array();

		public function media_exists( $path ) {
			$this->checked[] = $path;

			return $this->exists ? (object) array( 'id' => $path ) : false;
		}

		public function get_media( $path, $return_code = false, $target = '' ) {
			unset( $return_code );

			if ( ! array_key_exists( $path, $this->objects ) || ! $target ) {
				return 404;
			}

			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0777, true );
			}

			return false === file_put_contents( $target, $this->objects[ $path ] ) ? 500 : 200;
		}
	}
}

if ( ! class_exists( 'FtwTestStatelessBootstrap' ) ) {
	class FtwTestStatelessBootstrap {
		private $client;
		private $host;

		public function __construct( $client, $host = 'https://storage.googleapis.com/proudcity' ) {
			$this->client = $client;
			$this->host   = $host;
		}

		public function get_client() {
			return $this->client;
		}

		public function get_gs_host() {
			return $this->host;
		}
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/proud-core-stubs.php';

require_once __DIR__ . '/../includes/class-security.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-capabilities.php';
require_once __DIR__ . '/../includes/class-document-state.php';
require_once __DIR__ . '/../includes/class-proud-html-preview.php';
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
require_once __DIR__ . '/../includes/class-accessibility-attribution.php';
require_once __DIR__ . '/../includes/class-widget.php';
require_once __DIR__ . '/../includes/class-plugin.php';
