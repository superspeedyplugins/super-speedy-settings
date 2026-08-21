<?php
/**
 * Load the official WordPress MCP Adapter, preferring any copy already loaded.
 *
 * The selected settings core owns one small PSR-4 loader for the two official
 * WordPress package namespaces. This avoids loading a physical Composer or
 * Jetpack autoloader from every plugin that bundles these shared settings.
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

        /** Initialise the official adapter once WordPress has loaded every plugin. */
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

            // A standalone Adapter plugin loads before plugins_loaded. Avoid
            // triggering another package autoloader until that case is checked.
            if ( class_exists( $adapter_class, false ) ) {
                $provider = defined( 'WP_MCP_DIR' ) ? 'official-plugin' : 'external-composer';
                try {
                    $reflection = new ReflectionClass( $adapter_class );
                    $class_file = wp_normalize_path( (string) $reflection->getFileName() );
                    $bundle_dir = trailingslashit( wp_normalize_path( __DIR__ . '/vendor' ) );
                    if ( strpos( $class_file, $bundle_dir ) === 0 ) {
                        $provider = 'bundled';
                    }
                } catch ( ReflectionException $exception ) {
                    // Provider labelling is informational; the class is usable.
                }
                self::start( $adapter_class, $provider );
                return;
            }

            $package_loader = __DIR__ . '/class-ssp-mcp-package-loader.php';
            if ( ! is_readable( $package_loader ) ) {
                self::$status['message'] = __( 'The bundled MCP Adapter dependencies are missing.', 'super-speedy-settings' );
                return;
            }

            require_once $package_loader;
            if ( ! function_exists( 'ssp_mcp_register_package_loader' )
                || ! ssp_mcp_register_package_loader( __DIR__ . '/vendor' ) ) {
                self::$status['message'] = __( 'The bundled MCP Adapter dependencies could not be registered.', 'super-speedy-settings' );
                return;
            }

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
