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
		public $client;

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

if ( ! class_exists( 'FtwTestHttpHandlerStack' ) ) {
	class FtwTestHttpHandlerStack {
		public $middleware = array();

		public function push( $middleware, $name = '' ) {
			$this->middleware[ $name ] = $middleware;
		}

		public function remove( $name ) {
			unset( $this->middleware[ $name ] );
		}

		public function dispatch( $options, $next = null ) {
			$next = $next ?: function ( $request_options ) {
				return $request_options;
			};
			$handler = function ( $request, $request_options ) use ( $next ) {
				unset( $request );
				return $next( $request_options );
			};

			foreach ( array_reverse( $this->middleware ) as $middleware ) {
				$handler = $middleware( $handler );
			}

			return $handler( (object) array(), $options );
		}
	}
}

if ( ! class_exists( 'FtwTestGoogleHttpClient' ) ) {
	class FtwTestGoogleHttpClient {
		private $handler;

		public function __construct( $handler ) {
			$this->handler = $handler;
		}

		public function getConfig( $name = null ) {
			return 'handler' === $name ? $this->handler : array( 'handler' => $this->handler );
		}
	}
}

if ( ! class_exists( 'FtwTestGoogleClient' ) ) {
	class FtwTestGoogleClient {
		private $http;

		public function __construct( $http ) {
			$this->http = $http;
		}

		public function getHttpClient() {
			return $this->http;
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

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'FtwTestWpCli' ) ) {
	/**
	 * Records what a command wrote, so tests can assert on CLI output.
	 */
	class FtwTestWpCli {
		public static $commands = array();
		public static $logs     = array();
		public static $warnings = array();
		public static $success  = null;
		public static $error    = null;
		public static $items    = array();
		public static $fields   = array();
		public static $format   = '';

		public static function reset() {
			self::$commands = array();
			self::$logs     = array();
			self::$warnings = array();
			self::$success  = null;
			self::$error    = null;
			self::$items    = array();
			self::$fields   = array();
			self::$format   = '';
		}
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal stand-in for the WP-CLI runtime.
	 */
	class WP_CLI {
		public static function add_command( $name, $class ) {
			FtwTestWpCli::$commands[] = array(
				'name'  => $name,
				'class' => $class,
			);
		}

		public static function log( $message ) {
			FtwTestWpCli::$logs[] = (string) $message;
		}

		public static function line( $message = '' ) {
			FtwTestWpCli::$logs[] = (string) $message;
		}

		public static function warning( $message ) {
			FtwTestWpCli::$warnings[] = (string) $message;
		}

		public static function success( $message ) {
			FtwTestWpCli::$success = (string) $message;
			FtwTestWpCli::$logs[]  = (string) $message;
		}

		public static function error( $message ) {
			FtwTestWpCli::$error = (string) $message;
		}
	}
}

if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
	require_once __DIR__ . '/wp-cli-utils-stub.php';
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
require_once __DIR__ . '/../includes/class-cli-preview-command.php';
require_once __DIR__ . '/../includes/class-cli.php';
require_once __DIR__ . '/../includes/class-plugin.php';
