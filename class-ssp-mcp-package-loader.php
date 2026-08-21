<?php
/** Collision-safe loader for the two namespaces used by the bundled MCP Adapter. */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'ssp_mcp_register_package_loader' ) ) {
    /**
     * Register the winning settings copy's bundled MCP packages exactly once.
     *
     * @param string $vendor_dir Absolute vendor directory owned by the winner.
     * @return bool
     */
    function ssp_mcp_register_package_loader( $vendor_dir ) {
        $vendor_dir = realpath( $vendor_dir );
        if ( false === $vendor_dir ) {
            return false;
        }

        $maps = array(
            'WP\\MCP\\'       => $vendor_dir . '/wordpress/mcp-adapter/includes/',
            'WP\\McpSchema\\' => $vendor_dir . '/wordpress/php-mcp-schema/src/',
        );
        foreach ( $maps as $prefix => $base_dir ) {
            $base_dir = realpath( $base_dir );
            if ( false === $base_dir ) {
                return false;
            }
            $maps[ $prefix ] = trailingslashit( $base_dir );
        }

        if ( isset( $GLOBALS['ssp_mcp_package_autoloader'] ) ) {
            return is_callable( $GLOBALS['ssp_mcp_package_autoloader'] );
        }

        $loader = static function ( $class_name ) use ( $maps ) {
            foreach ( $maps as $prefix => $base_dir ) {
                if ( 0 !== strpos( $class_name, $prefix ) ) {
                    continue;
                }

                $relative = substr( $class_name, strlen( $prefix ) );
                if ( '' === $relative ) {
                    return;
                }

                $file = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
                $file = realpath( $file );
                if ( false !== $file && 0 === strpos( $file, $base_dir ) ) {
                    require_once $file;
                }
                return;
            }
        };

        // The selected settings version is the package authority for this
        // request. Prepend so an older MCP bundle registered by WooCommerce or
        // another plugin cannot win merely because that plugin loaded first.
        if ( ! spl_autoload_register( $loader, true, true ) ) {
            return false;
        }

        $GLOBALS['ssp_mcp_package_autoloader'] = $loader;
        $GLOBALS['ssp_mcp_package_vendor_dir'] = $vendor_dir;
        return true;
    }
}
