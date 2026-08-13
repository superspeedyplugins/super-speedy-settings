<?php
/**
 * Load the official WordPress MCP Adapter, preferring any copy already loaded.
 *
 * The bundled dependency is installed with Composer and loaded through Jetpack
 * Autoloader, which is the integration pattern recommended by the MCP Adapter
 * project for plugins that share this dependency.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'SSP_Official_MCP_Adapter' ) ) {
    class SSP_Official_MCP_Adapter {
        const VERSION = '0.5.0';

        private static $status = array(
            'available' => false,
            'provider'  => 'unavailable',
            'version'   => '',
            'message'   => '',
        );

        /**
         * Initialise the official adapter once WordPress has loaded every plugin.
         */
        public static function bootstrap() {
            if ( ! function_exists( 'wp_register_ability' ) ) {
                self::$status['message'] = __( 'WordPress 6.9 or newer is required for MCP.', 'super-speedy-settings' );
                return;
            }

            if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
                self::$status['message'] = __( 'PHP 7.4 or newer is required for MCP.', 'super-speedy-settings' );
                return;
            }

            $adapter_class = '\\WP\\MCP\\Core\\McpAdapter';

            // Every normal plugin file has loaded before plugins_loaded runs. If
            // the standalone official plugin, or another Composer integration,
            // supplied the class, use it and never load our bundled copy.
            if ( class_exists( $adapter_class ) ) {
                $provider = defined( 'WP_MCP_DIR' ) ? 'official-plugin' : 'external-composer';
                try {
                    $reflection = new ReflectionClass( $adapter_class );
                    $class_file = wp_normalize_path( (string) $reflection->getFileName() );
                    $bundle_dir = trailingslashit( wp_normalize_path( __DIR__ . '/vendor' ) );
                    if ( strpos( $class_file, $bundle_dir ) === 0 ) {
                        $provider = 'bundled';
                    }
                } catch ( ReflectionException $exception ) {
                    // The class is usable; provider labelling is informational only.
                }
                self::start( $adapter_class, $provider );
                return;
            }

            $autoloader = __DIR__ . '/vendor/autoload_packages.php';
            if ( ! is_readable( $autoloader ) ) {
                self::$status['message'] = __( 'The bundled MCP Adapter dependencies are missing.', 'super-speedy-settings' );
                return;
            }

            require_once $autoloader;

            if ( ! class_exists( $adapter_class ) ) {
                self::$status['message'] = __( 'The bundled MCP Adapter could not be loaded.', 'super-speedy-settings' );
                return;
            }

            self::start( $adapter_class, 'bundled' );
        }

        private static function start( $adapter_class, $provider ) {
            $adapter_class::instance();

            self::$status = array(
                'available' => true,
                'provider'  => $provider,
                'version'   => defined( $adapter_class . '::VERSION' ) ? constant( $adapter_class . '::VERSION' ) : self::VERSION,
                'message'   => '',
            );
        }

        public static function status() {
            return self::$status;
        }
    }
}
