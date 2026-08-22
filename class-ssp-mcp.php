<?php
/**
 * Shared Super Speedy ability surface — the `ssp` namespace.
 *
 * This file lives in the super-speedy-settings submodule, so ONE implementation
 * lands in every Super Speedy plugin on its next settings sync. It registers
 * once per site no matter how many of the plugins are installed (see the
 * did-register guard), because nine copies of the same ability would be nine
 * collisions.
 *
 * The point of it is the bootstrap chain: Dave installs ONE Super Speedy plugin
 * by hand and enters the licence, and an agent installs and configures the rest.
 * That is also a real agency feature - provisioning a client site is currently a
 * stack of hand-uploaded zips.
 *
 * SECURITY IS THE WHOLE DESIGN of install-plugin:
 *   - the caller NEVER supplies a URL. It passes a slug, and the zip is resolved
 *     from the update server using this site's own licence.
 *   - the resolved URL is checked against a hard host allowlist before download,
 *     so a compromised or spoofed feed cannot make us fetch a zip from anywhere
 *     else.
 *   - a dedicated capability, confirm:true, and an audit log row per install.
 *
 * @since settings 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'SSP_MCP' ) ) {

class SSP_MCP {

    const CATEGORY   = 'ssp';
    const CAP_MANAGE = 'ssp_manage_plugins';
    const CAP_INSTALL = 'ssp_install_plugins';
    const AUDIT_OPTION = 'ssp_mcp_install_log';

    /**
     * Hosts a plugin zip may be downloaded from. Anything else is refused even
     * if the update feed asks for it.
     */
    /**
     * Public packages this site may download WITHOUT a licence key, by slug.
     *
     * EXACT URLs only, never a host or a pattern. Listing one here is a deliberate
     * decision to trust that single unchanging URL: nothing else on the same host
     * becomes downloadable, and adding another plugin means adding another literal.
     *
     * The URL must be the stable "latest" form, so this list does not have to be
     * edited at every release. The version a site is OFFERED still comes from the
     * update feed's `version` field; this is only where the package is fetched from.
     */
    public static function public_packages() {
        return (array) apply_filters( 'superspeedy_public_packages', array(
            'super-speedy-performance-analysis' => 'https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip',
        ) );
    }

    /** The exact package URLs approved above, for validate_package_url(). */
    public static function allowed_package_urls() {
        return array_values( self::public_packages() );
    }

    public static function allowed_hosts() {
        return (array) apply_filters( 'superspeedy_install_allowed_hosts', array(
            'www.superspeedyplugins.com',
            'superspeedyplugins.com',
            'auth.superspeedyplugins.com',
        ) );
    }

    public static function init() {
        // Only one copy registers, however many Super Speedy plugins are active.
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        add_filter( 'user_has_cap', array( __CLASS__, 'grant_implicit_caps' ), 10, 1 );
        add_filter( 'mcp_adapter_default_transport_permission_user_capability', array( __CLASS__, 'adapter_access_capability' ), 10, 1 );
        add_filter( 'mcp_adapter_discover_abilities_capability', array( __CLASS__, 'adapter_access_capability' ), 10, 1 );
        add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
    }

    public static function adapter_access_capability( $capability ) {
        return self::CAP_MANAGE;
    }

    public static function grant_implicit_caps( $allcaps ) {
        if ( ! empty( $allcaps['manage_options'] ) ) {
            $allcaps[ self::CAP_MANAGE ]  = true;
            $allcaps[ self::CAP_INSTALL ] = true;
        }
        return $allcaps;
    }

    public static function can_read()    { return current_user_can( self::CAP_MANAGE ); }
    public static function can_install() { return current_user_can( self::CAP_INSTALL ); }

    public static function register_category() {
        wp_register_ability_category( self::CATEGORY, array(
            'label'       => __( 'Super Speedy Plugins', 'super-speedy-settings' ),
            'description' => __( 'Licence status and installation of the Super Speedy plugin range.', 'super-speedy-settings' ),
        ) );
    }

    /**
     * The plugins this site's licence could install, from the shared registry.
     *
     * @return array slug => array( name, file )
     */
    public static function known_plugins() {
        $known = array(
            'super-speedy-filters'      => array( 'name' => 'Super Speedy Filters',      'file' => 'super-speedy-filters/super-speedy-filters.php' ),
            'super-speedy-search'       => array( 'name' => 'Super Speedy Search',       'file' => 'super-speedy-search/super-speedy-search.php' ),
            'super-speedy-imports'      => array( 'name' => 'Super Speedy Imports',      'file' => 'super-speedy-imports/super-speedy-imports.php' ),
            'scalability-pro'           => array( 'name' => 'Scalability Pro',           'file' => 'scalability-pro/scalability-pro.php' ),
            'super-speedy-performance-analysis' => array( 'name' => 'Super Speedy Performance Analysis', 'file' => 'super-speedy-performance-analysis/super-speedy-performance-analysis.php' ),
            'external-images'           => array( 'name' => 'External Images',           'file' => 'external-images/external-images.php' ),
            'auto-infinite-scroll'      => array( 'name' => 'Auto Infinite Scroll',      'file' => 'auto-infinite-scroll/auto-infinite-scroll.php' ),
            'super-speedy-ajax-prices'  => array( 'name' => 'Super Speedy Ajax Prices',  'file' => 'super-speedy-ajax-prices/super-speedy-ajax-prices.php' ),
            'super-speedy-sitemaps'     => array( 'name' => 'Super Speedy Sitemaps',     'file' => 'super-speedy-sitemaps/super-speedy-sitemaps.php' ),
            'super-speedy-emails'       => array( 'name' => 'Super Speedy Emails',       'file' => 'super-speedy-emails/super-speedy-emails.php' ),
            'delayed-image-import'      => array( 'name' => 'Delayed Image Import',      'file' => 'delayed-image-import/delayed-image-import.php' ),
            'fullscreen-typeform'       => array( 'name' => 'Fullscreen Typeform',       'file' => 'fullscreen-typeform/fullscreen-typeform.php' ),
            'super-speedy-chat'         => array( 'name' => 'Super Speedy Chat',         'file' => 'super-speedy-chat/super-speedy-chat.php' ),
            'super-speedy-coming-soon'  => array( 'name' => 'Super Speedy Coming Soon Products', 'file' => 'super-speedy-coming-soon/super-speedy-coming-soon.php' ),
            'super-speedy-comments'     => array( 'name' => 'Super Speedy Comments',     'file' => 'super-speedy-comments/super-speedy-comments.php' ),
            'ssap-addon-country-based-prices' => array(
                'name'     => 'Super Speedy AJAX Prices: Country Based Prices',
                'file'     => 'ssap-addon-country-based-prices/ssap-addon-country-based-prices.php',
                'requires' => array( 'super-speedy-ajax-prices' ),
            ),
            'super-speedy-archives'     => array(
                'name'         => 'Super Speedy Archives',
                'file'         => 'super-speedy-archives/super-speedy-archives.php',
                'requires_any' => array( 'scalability-pro', 'super-speedy-filters' ),
            ),
        );
        return (array) apply_filters( 'superspeedy_known_plugins', $known );
    }

    public static function register_abilities() {

        $read = array(
            'category' => self::CATEGORY,
            'meta'     => array(
                'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                'show_in_rest' => true,
                'mcp'          => array( 'public' => true, 'type' => 'tool' ),
            ),
        );

        wp_register_ability( self::CATEGORY . '/list-plugins', array_merge( $read, array(
            'label'               => __( 'List Super Speedy plugins', 'super-speedy-settings' ),
            'description'         => __( 'Every plugin in the Super Speedy range, showing which are installed, which are active, and which this site\'s licence covers. Call this FIRST when setting up a site, to see what still needs installing.', 'super-speedy-settings' ),
            'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false, 'default' => array() ),
            'output_schema'       => array( 'type' => 'object' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'execute_callback'    => array( __CLASS__, 'exec_list_plugins' ),
        ) ) );

        wp_register_ability( self::CATEGORY . '/get-licence-status', array_merge( $read, array(
            'label'               => __( 'Check the licence', 'super-speedy-settings' ),
            'description'         => __( 'Whether this site has a Super Speedy licence key entered and what it covers. If no licence is present, nothing can be installed and a human has to enter the key once on the Super Speedy Plugins settings screen.', 'super-speedy-settings' ),
            'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false, 'default' => array() ),
            'output_schema'       => array( 'type' => 'object' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'execute_callback'    => array( __CLASS__, 'exec_licence_status' ),
        ) ) );

        wp_register_ability( self::CATEGORY . '/install-plugin', array(
            'category'            => self::CATEGORY,
            'meta'                => array(
                'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ),
                'show_in_rest' => true,
                'mcp'          => array( 'public' => true, 'type' => 'tool' ),
            ),
            'label'               => __( 'Install a Super Speedy plugin', 'super-speedy-settings' ),
            'description'         => __( 'Install, and optionally activate, one of the Super Speedy plugins using this site\'s own licence. You pass only the plugin slug — the download is resolved from the licensed update server, never from a URL you supply. Requires confirm=true. Use list-plugins to see the available slugs. If the plugin is already installed this reports that and changes nothing.', 'super-speedy-settings' ),
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'slug'     => array( 'type' => 'string', 'description' => __( 'e.g. super-speedy-filters', 'super-speedy-settings' ) ),
                    'activate' => array( 'type' => 'boolean', 'description' => __( 'Activate after installing. Defaults to true.', 'super-speedy-settings' ) ),
                    'confirm'  => array( 'type' => 'boolean', 'description' => __( 'Safety interlock. Must be true — this installs code on the site.', 'super-speedy-settings' ) ),
                ),
                'required'             => array( 'slug' ),
                'default'              => array(),
            ),
            'output_schema'       => array( 'type' => 'object' ),
            'permission_callback' => array( __CLASS__, 'can_install' ),
            'execute_callback'    => array( __CLASS__, 'exec_install_plugin' ),
        ) );

        wp_register_ability( self::CATEGORY . '/list-install-log', array_merge( $read, array(
            'label'               => __( 'List agent-installed plugins', 'super-speedy-settings' ),
            'description'         => __( 'The audit log of plugins installed through install-plugin: what, when, by which user, and whether it was activated. Use it to show a human exactly what an agent changed.', 'super-speedy-settings' ),
            'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false, 'default' => array() ),
            'output_schema'       => array( 'type' => 'object' ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'execute_callback'    => array( __CLASS__, 'exec_list_log' ),
        ) ) );

        wp_register_ability( self::CATEGORY . '/list-permitted-abilities', array_merge( $read, array(
            'label'               => __( 'List permitted Super Speedy abilities', 'super-speedy-settings' ),
            'description'         => __( 'Machine-readable manifest used by the shared MCP bridge. Returns only public abilities which the authenticated user is permitted to execute. Human workflows should normally use the MCP tools list instead.', 'super-speedy-settings' ),
            'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false, 'default' => array() ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'total'     => array( 'type' => 'integer' ),
                    'abilities' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                ),
                'required'   => array( 'total', 'abilities' ),
            ),
            'permission_callback' => array( __CLASS__, 'can_read' ),
            'execute_callback'    => array( __CLASS__, 'exec_list_permitted_abilities' ),
        ) ) );
    }

    /* --------------------------------------------------------------------- */

    public static function exec_list_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = get_plugins();
        $active    = (array) get_option( 'active_plugins', array() );

        $rows = array();
        foreach ( self::known_plugins() as $slug => $info ) {
            $is_installed = isset( $installed[ $info['file'] ] );
            $rows[] = array(
                'slug'      => $slug,
                'name'      => $info['name'],
                'installed' => $is_installed,
                'active'    => in_array( $info['file'], $active, true ),
                'version'   => $is_installed ? $installed[ $info['file'] ]['Version'] : null,
                'requires'  => isset( $info['requires'] ) ? array_values( $info['requires'] ) : array(),
                'requires_any' => isset( $info['requires_any'] ) ? array_values( $info['requires_any'] ) : array(),
            );
        }

        return array(
            'total'         => count( $rows ),
            'plugins'       => $rows,
            'has_licence'   => self::licence_key() !== '',
            'notes'         => __( 'Install missing ones with install-plugin, passing the slug and confirm=true. A licence must already be entered on this site.', 'super-speedy-settings' ),
        );
    }

    protected static function licence_key() {
        $options = get_option( 'superspeedy_options' );
        if ( is_array( $options ) && ! empty( $options['superspeedy_field_licensekey'] ) ) {
            return (string) $options['superspeedy_field_licensekey'];
        }
        return '';
    }

    public static function exec_licence_status( $input = array() ) {
        $key = self::licence_key();
        return array(
            'has_licence' => $key !== '',
            'masked_key'  => $key === '' ? '' : substr( $key, 0, 4 ) . str_repeat( '*', max( 0, strlen( $key ) - 8 ) ) . substr( $key, -4 ),
            'site'        => wp_parse_url( get_home_url(), PHP_URL_HOST ),
            'notes'       => $key === ''
                ? __( 'No licence key is entered. A human must add one on the Super Speedy Plugins settings screen before anything can be installed.', 'super-speedy-settings' )
                : __( 'A licence is present. install-plugin resolves downloads against it.', 'super-speedy-settings' ),
        );
    }

    /**
     * Resolve the download URL for a slug from the update feed, and refuse
     * anything that is not on the allowlist.
     *
     * @return string|WP_Error
     */
    public static function resolve_download_url( $slug ) {
        // A plugin with an approved public package is free and needs no licence:
        // fetch it straight from its one allow-listed URL and never call the feed.
        $public = self::public_packages();
        if ( isset( $public[ $slug ] ) ) {
            return (string) $public[ $slug ];
        }

        $feed = 'https://www.superspeedyplugins.com/assets/plugins/' . rawurlencode( $slug ) . '.json';
        $response = wp_safe_remote_get( $feed, array(
            'timeout'     => 20,
            'redirection' => 0,
            'sslverify'   => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'ssp_feed_unreachable', sprintf( __( 'Could not reach the update server: %s', 'super-speedy-settings' ), $response->get_error_message() ) );
        }
        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'ssp_unknown_plugin', sprintf( __( 'No update feed for "%s". Check the slug with list-plugins.', 'super-speedy-settings' ), $slug ) );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['download_url'] ) ) {
            return new WP_Error( 'ssp_no_download', __( 'The update feed did not offer a download for this plugin.', 'super-speedy-settings' ) );
        }

        $url = (string) $data['download_url'];
        $validated = self::validate_package_url( $url );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        // Carry the licence with the SAME parameter set the update checker sends
        // (add_license_to_remote_update_request in super-speedy-settings-core.php).
        // The auth server refuses anything without license_version=2 as a legacy
        // client, so a bare license_key is not enough.
        $key = self::licence_key();
        if ( $key !== '' ) {
            $domain = (string) wp_parse_url( get_home_url(), PHP_URL_HOST );
            if ( $domain === 'localhost' ) {
                $domain = 'lh';
            }
            $url = add_query_arg( array(
                'license_key'     => $key,
                'domain'          => $domain,
                'license_version' => 2,
                'sv'              => class_exists( 'SuperSpeedySettingsCore' ) ? SuperSpeedySettingsCore::SETTINGS_VERSION : '',
            ), $url );
        }

        return $url;
    }

    /** Validate the complete package origin, not merely its hostname. */
    public static function validate_package_url( $url ) {
        $parts = wp_parse_url( $url );
        $host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
        $scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        $port = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : 443;

        if ( ! is_array( $parts ) || 'https' !== $scheme || 443 !== $port || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return new WP_Error(
                'ssp_package_origin_invalid',
                __( 'Refusing a plugin package URL that is not a credential-free HTTPS origin on port 443.', 'super-speedy-settings' )
            );
        }
        // An exactly allow-listed public package is approved as a whole URL, so the
        // host allowlist below never has to be widened to cover its origin.
        if ( in_array( $url, self::allowed_package_urls(), true ) ) {
            return true;
        }

        $allowed = array_map( 'strtolower', self::allowed_hosts() );
        if ( ! in_array( $host, $allowed, true ) ) {
            return new WP_Error(
                'ssp_host_not_allowed',
                sprintf( __( 'Refusing to download from "%s" because it is not an approved Super Speedy host.', 'super-speedy-settings' ), $host )
            );
        }
        return true;
    }

    /** Request arguments for a final-origin-bound package download. */
    public static function package_request_args( $filename ) {
        return array(
            'timeout'     => 300,
            'redirection' => 0,
            'sslverify'   => true,
            'stream'      => true,
            'filename'    => $filename,
        );
    }

    /**
     * Validate a redirect that a public package download is allowed to follow.
     *
     * `releases/latest/download/<file>` answers with TWO hops: first to the same
     * repository's tagged asset url, then to a short-lived SIGNED url that differs
     * on every request and therefore cannot be allow-listed literally. Both are
     * derived from the approved url rather than trusted by host alone:
     *
     *   hop 1 must stay under the SAME repository's `/releases/` path
     *   hop 2 must be https on port 443 at a githubusercontent.com host
     *
     * Anything else, and any redirect at all for a url that was not exactly
     * allow-listed, is refused.
     *
     * @param string $url    Redirect target offered by the server.
     * @param string $origin The exactly allow-listed url the download started at.
     */
    public static function validate_public_package_redirect( $url, $origin ) {
        $url    = (string) $url;
        $parts  = wp_parse_url( $url );
        $host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
        $scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        $port   = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : 443;

        if ( ! is_array( $parts ) || 'https' !== $scheme || 443 !== $port || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return new WP_Error(
                'ssp_package_redirect_invalid',
                __( 'Refusing a package redirect that is not a credential-free HTTPS origin on port 443.', 'super-speedy-settings' )
            );
        }

        $marker = '/releases/';
        $cut    = strpos( (string) $origin, $marker );
        if ( false !== $cut ) {
            $repository = substr( (string) $origin, 0, $cut + strlen( $marker ) );
            if ( 0 === strpos( $url, $repository ) ) {
                return true;
            }
        }

        $suffix = '.githubusercontent.com';
        if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
            return true;
        }

        return new WP_Error(
            'ssp_package_redirect_host',
            sprintf( __( 'Refusing to follow a package redirect to "%s".', 'super-speedy-settings' ), $url )
        );
    }

    /** Download one validated package, following at most the one approved redirect. */
    public static function download_package( $url ) {
        $validated = self::validate_package_url( $url );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $temporary = wp_tempnam( wp_parse_url( $url, PHP_URL_PATH ) );
        if ( ! $temporary ) {
            return new WP_Error( 'ssp_package_temp_failed', __( 'Could not create a temporary file for the plugin package.', 'super-speedy-settings' ) );
        }

        $response = wp_safe_remote_get( $url, self::package_request_args( $temporary ) );
        if ( is_wp_error( $response ) ) {
            unlink( $temporary );
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        // Only an exactly allow-listed public package may redirect at all, and then
        // at most twice: the tagged asset url, then the signed asset url.
        $public    = in_array( $url, self::allowed_package_urls(), true );
        $redirects = 0;
        while ( $public && $redirects < 2 && in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
            $target    = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
            $permitted = self::validate_public_package_redirect( $target, $url );
            if ( is_wp_error( $permitted ) ) {
                unlink( $temporary );
                return $permitted;
            }
            $response = wp_safe_remote_get( $target, self::package_request_args( $temporary ) );
            if ( is_wp_error( $response ) ) {
                unlink( $temporary );
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $redirects++;
        }

        if ( 200 !== $code ) {
            $body = is_readable( $temporary ) ? file_get_contents( $temporary ) : '';
            unlink( $temporary );
            return new WP_Error(
                'http_' . $code,
                sprintf( __( 'The plugin package server returned HTTP %d.', 'super-speedy-settings' ), $code ),
                array( 'code' => $code, 'body' => is_string( $body ) ? $body : '' )
            );
        }

        return $temporary;
    }

    public static function exec_install_plugin( $input = array() ) {
        $slug     = isset( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';
        $activate = array_key_exists( 'activate', $input ) ? (bool) $input['activate'] : true;

        if ( empty( $input['confirm'] ) ) {
            return new WP_Error( 'ssp_confirm_required', __( 'This installs code on the site. Call again with confirm=true.', 'super-speedy-settings' ), array( 'status' => 400 ) );
        }

        if ( ! current_user_can( 'install_plugins' ) || ( $activate && ! current_user_can( 'activate_plugins' ) ) ) {
            return new WP_Error( 'ssp_install_forbidden', __( 'This account does not have WordPress permission to install and activate plugins.', 'super-speedy-settings' ), array( 'status' => 403 ) );
        }

        $known = self::known_plugins();
        if ( $slug === '' || ! isset( $known[ $slug ] ) ) {
            return new WP_Error( 'ssp_unknown_plugin', __( 'Unknown plugin slug. Call list-plugins for the valid ones.', 'super-speedy-settings' ), array( 'status' => 400 ) );
        }

        if ( self::licence_key() === '' ) {
            return new WP_Error( 'ssp_no_licence', __( 'No licence key is entered on this site, so nothing can be downloaded. A human must add one once on the Super Speedy Plugins settings screen.', 'super-speedy-settings' ), array( 'status' => 400 ) );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $file = $known[ $slug ]['file'];

        if ( isset( get_plugins()[ $file ] ) ) {
            $result = array( 'slug' => $slug, 'installed' => true, 'already_present' => true );
            if ( $activate && ! is_plugin_active( $file ) ) {
                $activated = activate_plugin( $file );
                $result['activated'] = ! is_wp_error( $activated );
                if ( is_wp_error( $activated ) ) {
                    $result['activation_error'] = $activated->get_error_message();
                }
            } else {
                $result['activated'] = is_plugin_active( $file );
            }
            $result['notes'] = __( 'Already installed, so nothing was downloaded.', 'super-speedy-settings' );
            return $result;
        }

        $url = self::resolve_download_url( $slug );
        if ( is_wp_error( $url ) ) {
            return $url;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $package = self::download_package( $url );
        if ( is_wp_error( $package ) ) {
            return $package;
        }

        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        try {
            $outcome = $upgrader->install( $package );
        } finally {
            if ( file_exists( $package ) ) {
                unlink( $package );
            }
        }

        if ( is_wp_error( $outcome ) ) {
            return new WP_Error( 'ssp_install_failed', $outcome->get_error_message() );
        }
        if ( $outcome === false || $skin->get_errors()->has_errors() ) {
            return new WP_Error( 'ssp_install_failed', $skin->get_error_messages() ? implode( '; ', $skin->get_error_messages() ) : __( 'The install did not complete.', 'super-speedy-settings' ) );
        }

        $activated = false;
        $activation_error = '';
        if ( $activate ) {
            $result = activate_plugin( $file );
            if ( is_wp_error( $result ) ) {
                $activation_error = $result->get_error_message();
            } else {
                $activated = true;
            }
        }

        self::log_install( $slug, $activated );

        return array(
            'slug'             => $slug,
            'name'             => $known[ $slug ]['name'],
            'installed'        => true,
            'already_present'  => false,
            'activated'        => $activated,
            'activation_error' => $activation_error,
            'notes'            => __( 'Installed from the licensed update server. Check list-plugins to confirm, and see list-install-log for the audit trail.', 'super-speedy-settings' ),
        );
    }

    /** Do not let an approved package URL redirect to an unapproved host. */
    public static function prevent_install_redirects( $args, $url ) {
        if ( true === self::validate_package_url( $url ) ) {
            $args['redirection'] = 0;
            $args['sslverify'] = true;
        }
        return $args;
    }

    protected static function log_install( $slug, $activated ) {
        $log = get_option( self::AUDIT_OPTION );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = array(
            'slug'      => $slug,
            'activated' => (bool) $activated,
            'user_id'   => get_current_user_id(),
            'user'      => wp_get_current_user() ? wp_get_current_user()->user_login : '',
            'time'      => gmdate( 'c' ),
        );
        // Keep the log bounded; this is an audit trail, not history for ever.
        if ( count( $log ) > 200 ) {
            $log = array_slice( $log, -200 );
        }
        update_option( self::AUDIT_OPTION, $log, false );
    }

    public static function exec_list_log() {
        $log = get_option( self::AUDIT_OPTION );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        return array( 'total' => count( $log ), 'installs' => array_reverse( $log ) );
    }

    public static function exec_list_permitted_abilities() {
        $names = array();
        foreach ( wp_get_abilities() as $ability ) {
            $mcp = $ability->get_meta_item( 'mcp', array() );
            if ( ! is_array( $mcp ) || empty( $mcp['public'] ) ) {
                continue;
            }
            $permitted = $ability->check_permissions( array() );
            if ( true === $permitted ) {
                $names[] = $ability->get_name();
            }
        }
        sort( $names, SORT_STRING );
        return array( 'total' => count( $names ), 'abilities' => $names );
    }
}

}
