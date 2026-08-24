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
            'provider_name' => '',
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
                $provider = array(
                    'provider'      => defined( 'WP_MCP_DIR' ) ? 'official-plugin' : 'external-composer',
                    'provider_name' => '',
                );
                try {
                    $reflection = new ReflectionClass( $adapter_class );
                    $provider = self::identify_provider(
                        (string) $reflection->getFileName(),
                        __DIR__ . '/vendor',
                        WP_PLUGIN_DIR,
                        null,
                        defined( 'WP_MCP_DIR' )
                    );
                } catch ( ReflectionException $exception ) {
                    // Provider labelling is informational; the class is usable.
                }
                self::start( $adapter_class, $provider['provider'], $provider['provider_name'] );
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

        /** Identify the plugin or package which loaded an existing Adapter class. */
        public static function identify_provider( $class_file, $bundle_dir, $plugin_dir, $plugins = null, $official_plugin_loaded = false ) {
            if ( $official_plugin_loaded ) {
                return array( 'provider' => 'official-plugin', 'provider_name' => '' );
            }

            $class_file = wp_normalize_path( (string) $class_file );
            $bundle_dir = trailingslashit( wp_normalize_path( (string) $bundle_dir ) );
            $plugin_dir = trailingslashit( wp_normalize_path( (string) $plugin_dir ) );

            if ( strpos( $class_file, $bundle_dir ) === 0 ) {
                return array( 'provider' => 'bundled', 'provider_name' => '' );
            }

            if ( strpos( $class_file, $plugin_dir ) === 0 ) {
                $relative = ltrim( substr( $class_file, strlen( $plugin_dir ) ), '/' );
                $slug     = strtok( $relative, '/' );
                if ( null === $plugins ) {
                    if ( ! function_exists( 'get_plugins' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';
                    }
                    $plugins = get_plugins();
                }
                foreach ( (array) $plugins as $plugin_file => $headers ) {
                    if ( strpos( wp_normalize_path( $plugin_file ), $slug . '/' ) === 0 ) {
                        $name = ! empty( $headers['Name'] ) ? $headers['Name'] : $slug;
                        return array( 'provider' => 'plugin-bundle', 'provider_name' => $name );
                    }
                }
                return array( 'provider' => 'plugin-bundle', 'provider_name' => $slug );
            }

            return array( 'provider' => 'external-composer', 'provider_name' => '' );
        }

        private static function start( $adapter_class, $provider, $provider_name = '' ) {
            $adapter_class::instance();

            self::$status = array(
                'available' => true,
                'provider'  => $provider,
                'provider_name' => $provider_name,
                'version'   => defined( $adapter_class . '::VERSION' ) ? constant( $adapter_class . '::VERSION' ) : self::VERSION,
                'message'   => '',
            );
        }

        public static function status() {
            return self::$status;
        }
    }
}
