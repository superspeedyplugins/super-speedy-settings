<?php
/**
 * Admin guide and one-time connection command generator for Super Speedy MCP.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'SSP_MCP_Setup' ) ) {
    class SSP_MCP_Setup {
        const PAGE_SLUG        = 'superspeedy-mcp-setup';
        const APP_ID           = '9bf74e6d-44ea-4f89-946d-af480c56733e';
        const AGENT_ROLE       = 'super_speedy_agent';
        const AGENT_USER_OPTION = 'ssp_mcp_agent_user_id';

        public static function init() {
            add_action( 'init', array( __CLASS__, 'register_agent_role' ), 1 );
            add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 6 );
            add_action( 'wp_ajax_superspeedy_mcp_create_connection', array( __CLASS__, 'create_connection' ) );
            add_action( 'wp_ajax_superspeedy_mcp_revoke_connection', array( __CLASS__, 'revoke_connection' ) );
        }

        public static function register_agent_role() {
            $role = get_role( self::AGENT_ROLE );
            if ( ! $role ) {
                $role = add_role(
                    self::AGENT_ROLE,
                    __( 'Super Speedy Agent', 'super-speedy-settings' ),
                    array( 'read' => true )
                );
            }
            if ( $role && ! $role->has_cap( 'read' ) ) {
                $role->add_cap( 'read' );
            }
        }

        public static function register_page() {
            add_submenu_page(
                'superspeedy',
                __( 'MCP setup', 'super-speedy-settings' ),
                __( 'MCP setup', 'super-speedy-settings' ),
                'manage_options',
                self::PAGE_SLUG,
                array( __CLASS__, 'render_page' )
            );
        }

        private static function has_licence() {
            $options = get_option( 'superspeedy_options', array() );
            return is_array( $options ) && ! empty( $options['superspeedy_field_licensekey'] );
        }

        private static function application_passwords_available( $user = null ) {
            if ( $user && function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
                return wp_is_application_passwords_available_for_user( $user );
            }
            if ( function_exists( 'wp_is_application_passwords_available' ) ) {
                return wp_is_application_passwords_available();
            }
            return false;
        }

        private static function connection_name() {
            $parts = wp_parse_url( home_url( '/' ) );
            $site  = isset( $parts['host'] ) ? $parts['host'] : 'wordpress';
            $path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
            $name  = sanitize_key( 'super-speedy-' . $site . ( $path ? '-' . $path : '' ) );
            return substr( $name ?: 'super-speedy-wordpress', 0, 64 );
        }

        /** Capabilities needed to inspect and configure active Super Speedy products. */
        public static function manage_capabilities() {
            return (array) apply_filters( 'ssp_mcp_agent_manage_capabilities', array(
                'ssp_manage_plugins',
                'ais_manage',
                'dii_manage',
                'ei_manage_images',
                'fst_manage',
                'spro_manage',
                'ssapcbp_manage',
                'ssap_manage',
                'ssa_manage',
                'ssc_manage',
                'sscs_manage',
                'sspc_manage_comments',
                'sse_manage_emails',
                'ssf_manage_filters',
                'ssi_manage_imports',
                'sspa_manage',
                'sss_manage_search',
                'sssm_manage',
            ) );
        }

        /** Capabilities which permit installation, sends or high-impact execution. */
        public static function execute_capabilities() {
            return (array) apply_filters( 'ssp_mcp_agent_execute_capabilities', array(
                'spro_apply',
                'ssapcbp_apply',
                'ssa_execute',
                'sspc_bulk_manage',
                'sse_send_emails',
                'ssf_apply_filters',
                'ssi_run_imports',
                'sspa_execute',
            ) );
        }

        private static function agent_user() {
            $user_id = (int) get_option( self::AGENT_USER_OPTION, 0 );
            if ( $user_id ) {
                $user = get_user_by( 'id', $user_id );
                if ( $user instanceof WP_User ) {
                    return $user;
                }
            }
            return null;
        }

        /** @return WP_User|WP_Error */
        private static function ensure_agent_user() {
            $user = self::agent_user();
            if ( $user ) {
                return $user;
            }

            self::register_agent_role();
            $base     = 'super-speedy-agent';
            $username = $base;
            $suffix   = 2;
            while ( username_exists( $username ) ) {
                $username = $base . '-' . $suffix;
                $suffix++;
            }

            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_pass'    => wp_generate_password( 64, true, true ),
                'display_name' => __( 'Super Speedy Agent', 'super-speedy-settings' ),
                'role'         => self::AGENT_ROLE,
            ) );
            if ( is_wp_error( $user_id ) ) {
                return $user_id;
            }
            update_option( self::AGENT_USER_OPTION, (int) $user_id, false );
            return get_user_by( 'id', $user_id );
        }

        private static function sync_agent_capabilities( WP_User $user, $allow_execute, $allow_install ) {
            $all = array_merge( self::manage_capabilities(), self::execute_capabilities(), array( 'ssp_install_plugins', 'install_plugins', 'activate_plugins' ) );
            foreach ( array_unique( $all ) as $capability ) {
                $user->remove_cap( $capability );
            }
            foreach ( self::manage_capabilities() as $capability ) {
                $user->add_cap( $capability );
            }
            if ( $allow_execute ) {
                foreach ( self::execute_capabilities() as $capability ) {
                    $user->add_cap( $capability );
                }
            }
            if ( $allow_install ) {
                $user->add_cap( 'ssp_install_plugins' );
                $user->add_cap( 'install_plugins' );
                $user->add_cap( 'activate_plugins' );
            }
            update_user_meta( $user->ID, 'ssp_mcp_allow_execute', $allow_execute ? '1' : '0' );
            update_user_meta( $user->ID, 'ssp_mcp_allow_install', $allow_install ? '1' : '0' );
        }

        private static function app_passwords( $user_id ) {
            if ( ! class_exists( 'WP_Application_Passwords' ) ) {
                return array();
            }
            return WP_Application_Passwords::get_user_application_passwords( $user_id );
        }

        private static function existing_connection() {
            $user = self::agent_user();
            if ( ! $user ) {
                return null;
            }
            foreach ( self::app_passwords( $user->ID ) as $password ) {
                if ( isset( $password['app_id'] ) && self::APP_ID === $password['app_id'] ) {
                    return $password;
                }
            }
            return null;
        }

        private static function delete_existing_connection() {
            $user     = self::agent_user();
            $existing = self::existing_connection();
            if ( $user && $existing && ! empty( $existing['uuid'] ) ) {
                return WP_Application_Passwords::delete_application_password( $user->ID, $existing['uuid'] );
            }
            return true;
        }

        /** Generate client configuration without putting secrets in shell arguments. */
        public static function connection_config_strings( $name, $endpoint, $username, $password ) {
            $package = '@automattic/mcp-wordpress-remote@0.4.0';
            $environment = array(
                'WP_API_URL'      => (string) $endpoint,
                'WP_API_USERNAME' => (string) $username,
                'WP_API_PASSWORD' => (string) $password,
                'OAUTH_ENABLED'   => 'false',
            );

            $toml_environment = array();
            foreach ( $environment as $key => $value ) {
                $toml_environment[] = $key . ' = ' . wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }
            $toml = '[mcp_servers.' . sanitize_key( $name ) . "]\n"
                . 'command = "npx"' . "\n"
                . 'args = ["-y", "' . $package . '"]' . "\n"
                . 'env = { ' . implode( ', ', $toml_environment ) . ' }';
            $json = wp_json_encode(
                array(
                    'mcpServers' => array(
                        sanitize_key( $name ) => array(
                            'command' => 'npx',
                            'args'    => array( '-y', $package ),
                            'env'     => $environment,
                        ),
                    ),
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            return array( 'codex' => $toml, 'claude' => $json );
        }

        public static function create_connection() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'You do not have permission to create this connection.', 'super-speedy-settings' ) ), 403 );
            }
            check_ajax_referer( 'superspeedy_mcp_setup', 'nonce' );

            $adapter = SSP_Official_MCP_Adapter::status();
            if ( empty( $adapter['available'] ) ) {
                wp_send_json_error( array( 'message' => $adapter['message'] ?: __( 'The MCP Adapter is unavailable.', 'super-speedy-settings' ) ), 400 );
            }

            // Creating the agent creates a user account. On multisite a sub-site administrator
            // has manage_options but may not create users; the network's own policy decides.
            if ( ! self::agent_user() && ! current_user_can( 'create_users' ) ) {
                wp_send_json_error( array( 'message' => __( 'Creating the connection adds a user account, which your account is not allowed to do on this network. Ask a network administrator to generate it.', 'super-speedy-settings' ) ), 403 );
            }

            $user = self::ensure_agent_user();
            if ( is_wp_error( $user ) ) {
                wp_send_json_error( array( 'message' => $user->get_error_message() ), 500 );
            }
            if ( ! self::application_passwords_available( $user ) ) {
                wp_send_json_error( array( 'message' => __( 'Application Passwords are not available. Use HTTPS, or mark a local development site as a local environment.', 'super-speedy-settings' ) ), 400 );
            }

            $allow_execute = ! empty( $_POST['allow_execute'] );
            $allow_install = ! empty( $_POST['allow_install'] );
            self::sync_agent_capabilities( $user, $allow_execute, $allow_install );

            $deleted = self::delete_existing_connection();
            if ( is_wp_error( $deleted ) ) {
                wp_send_json_error( array( 'message' => $deleted->get_error_message() ), 500 );
            }

            $created = WP_Application_Passwords::create_new_application_password(
                $user->ID,
                array(
                    'name'   => __( 'Super Speedy MCP connection', 'super-speedy-settings' ),
                    'app_id' => self::APP_ID,
                )
            );
            if ( is_wp_error( $created ) ) {
                wp_send_json_error( array( 'message' => $created->get_error_message() ), 500 );
            }

            $password = $created[0];
            $endpoint = rest_url( 'mcp/mcp-adapter-default-server' );
            $name     = self::connection_name();
            $configs  = self::connection_config_strings( $name, $endpoint, $user->user_login, $password );

            wp_send_json_success( array(
                'codex'   => $configs['codex'],
                'claude'  => $configs['claude'],
                'name'    => $name,
                'user'    => $user->user_login,
                'message' => __( 'Connection configuration created for the dedicated Super Speedy Agent user. Copy it into your client configuration now: WordPress will not show this password again.', 'super-speedy-settings' ),
            ) );
        }

        public static function revoke_connection() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'You do not have permission to revoke this connection.', 'super-speedy-settings' ) ), 403 );
            }
            check_ajax_referer( 'superspeedy_mcp_setup', 'nonce' );
            $deleted = self::delete_existing_connection();
            if ( is_wp_error( $deleted ) ) {
                wp_send_json_error( array( 'message' => $deleted->get_error_message() ), 500 );
            }
            wp_send_json_success( array( 'message' => __( 'The Super Speedy MCP Application Password has been revoked.', 'super-speedy-settings' ) ) );
        }

        /** Human-readable description of the code which supplied the Adapter. */
        public static function provider_label( $adapter ) {
            $provider = isset( $adapter['provider'] ) ? $adapter['provider'] : 'unavailable';
            $labels   = array(
                'official-plugin'   => __( 'Official MCP Adapter plugin', 'super-speedy-settings' ),
                'external-composer' => __( 'Official MCP Adapter supplied by a Composer package', 'super-speedy-settings' ),
                'bundled'           => __( 'Official MCP Adapter bundled by Super Speedy Plugins', 'super-speedy-settings' ),
                'unavailable'       => __( 'Unavailable', 'super-speedy-settings' ),
            );
            if ( 'plugin-bundle' === $provider ) {
                $provider_name = ! empty( $adapter['provider_name'] ) ? $adapter['provider_name'] : __( 'another plugin', 'super-speedy-settings' );
                return sprintf(
                    /* translators: %s: plugin name */
                    __( 'Official MCP Adapter bundled by %s', 'super-speedy-settings' ),
                    $provider_name
                );
            }
            return isset( $labels[ $provider ] ) ? $labels[ $provider ] : $provider;
        }

        public static function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            $has_licence  = self::has_licence();
            $adapter      = SSP_Official_MCP_Adapter::status();
            $app_password = self::application_passwords_available();
            $existing     = self::existing_connection();
            $agent        = self::agent_user();
            $execute      = $agent && '1' === get_user_meta( $agent->ID, 'ssp_mcp_allow_execute', true );
            $install      = $agent && '1' === get_user_meta( $agent->ID, 'ssp_mcp_allow_install', true );
            $ready        = ! empty( $adapter['available'] ) && $app_password;
            $provider_label = self::provider_label( $adapter );
            ?>
            <div class="wrap superspeedy-mcp-setup">
                <div class="super-speedy-plugins-admin-header"><a href="https://www.superspeedyplugins.com/" target="_blank" rel="noopener"><img src="<?php echo esc_url( plugins_url( 'super-speedy-plugins-black-text.png', __FILE__ ) ); ?>" width="151" height="32" alt="Super Speedy Plugins"></a></div>
                <h1><?php esc_html_e( 'Connect an AI assistant', 'super-speedy-settings' ); ?></h1>
                <p class="description"><?php esc_html_e( 'Create one connection for every active Super Speedy plugin through the official WordPress MCP Adapter.', 'super-speedy-settings' ); ?></p>

                <div class="ssp-mcp-status-grid">
                    <div class="ssp-mcp-card">
                        <h2><?php esc_html_e( 'Connection status', 'super-speedy-settings' ); ?></h2>
                        <p><span class="ssp-mcp-dot <?php echo $ready ? 'is-ready' : 'is-blocked'; ?>"></span><strong><?php echo esc_html( $ready ? __( 'MCP Adapter ready', 'super-speedy-settings' ) : __( 'Connection requirements incomplete', 'super-speedy-settings' ) ); ?></strong></p>
                        <dl>
                            <dt><?php esc_html_e( 'Provider', 'super-speedy-settings' ); ?></dt><dd><?php echo esc_html( $provider_label ); ?></dd>
                            <dt><?php esc_html_e( 'Version', 'super-speedy-settings' ); ?></dt><dd><?php echo esc_html( ! empty( $adapter['version'] ) ? $adapter['version'] : '—' ); ?></dd>
                            <dt><?php esc_html_e( 'Endpoint', 'super-speedy-settings' ); ?></dt><dd><code><?php echo esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code></dd>
                            <dt><?php esc_html_e( 'Agent user', 'super-speedy-settings' ); ?></dt><dd><?php echo esc_html( $agent ? $agent->user_login : __( 'Created when you generate the connection', 'super-speedy-settings' ) ); ?></dd>
                        </dl>
                        <?php if ( empty( $adapter['available'] ) && ! empty( $adapter['message'] ) ) : ?><p class="notice notice-error inline"><?php echo esc_html( $adapter['message'] ); ?></p><?php endif; ?>
                    </div>

                    <div class="ssp-mcp-card">
                        <h2><?php esc_html_e( 'Before you connect', 'super-speedy-settings' ); ?></h2>
                        <ol>
                            <li class="<?php echo $has_licence ? 'is-complete' : ''; ?>"><?php echo wp_kses_post( sprintf( __( 'Enter your licence on the <a href="%s">Super Speedy Plugins settings page</a>.', 'super-speedy-settings' ), esc_url( admin_url( 'admin.php?page=superspeedy' ) ) ) ); ?></li>
                            <li class="<?php echo $app_password ? 'is-complete' : ''; ?>"><?php esc_html_e( 'Make sure WordPress Application Passwords are available. Production sites require HTTPS.', 'super-speedy-settings' ); ?></li>
                            <li><?php esc_html_e( 'Generate the configuration, paste it into your MCP client configuration file, then restart your AI assistant.', 'super-speedy-settings' ); ?></li>
                        </ol>
                        <?php if ( ! $has_licence ) : ?><p class="notice notice-warning inline"><?php esc_html_e( 'You can connect without a licence, but the assistant cannot download other Super Speedy plugins until a licence is entered.', 'super-speedy-settings' ); ?></p><?php endif; ?>
                        <?php if ( ! $app_password ) : ?><p class="notice notice-error inline"><?php esc_html_e( 'Application Passwords are unavailable. Production sites need HTTPS. Local HTTP sites must use the local WordPress environment type.', 'super-speedy-settings' ); ?></p><?php endif; ?>
                    </div>
                </div>

                <div class="ssp-mcp-card ssp-mcp-command-card">
                    <h2><?php esc_html_e( 'Generate connection configuration', 'super-speedy-settings' ); ?></h2>
                    <p><?php esc_html_e( 'This creates or updates a dedicated Super Speedy Agent user and gives it only the selected Super Speedy capabilities. Generating again invalidates the previous connection.', 'super-speedy-settings' ); ?></p>
                    <p><label><input type="checkbox" id="ssp-mcp-allow-execute" <?php checked( $execute ); ?>> <?php esc_html_e( 'Allow high-impact plugin actions such as sends, imports and applied optimisation plans', 'super-speedy-settings' ); ?></label></p>
                    <p><label><input type="checkbox" id="ssp-mcp-allow-install" <?php checked( $install ); ?>> <?php esc_html_e( 'Allow installation and activation of other licensed Super Speedy plugins', 'super-speedy-settings' ); ?></label></p>
                    <?php if ( $existing ) : ?><p class="ssp-mcp-existing"><?php esc_html_e( 'A Super Speedy MCP connection already exists. Its secret cannot be shown again, but you can replace or revoke it.', 'super-speedy-settings' ); ?></p><?php endif; ?>
                    <p><button type="button" class="button button-primary" id="ssp-mcp-generate" <?php disabled( ! $ready ); ?>><?php echo esc_html( $existing ? __( 'Replace connection configuration', 'super-speedy-settings' ) : __( 'Generate connection configuration', 'super-speedy-settings' ) ); ?></button> <button type="button" class="button" id="ssp-mcp-revoke" <?php disabled( ! $existing ); ?>><?php esc_html_e( 'Revoke connection', 'super-speedy-settings' ); ?></button></p>
                    <div id="ssp-mcp-result" hidden>
                        <div class="notice notice-warning inline"><p id="ssp-mcp-message"></p></div>
                        <label for="ssp-mcp-codex"><strong><?php esc_html_e( 'Codex config.toml', 'super-speedy-settings' ); ?></strong></label>
                        <div class="ssp-mcp-command"><textarea id="ssp-mcp-codex" rows="4" readonly spellcheck="false"></textarea><button type="button" class="button ssp-mcp-copy" data-target="ssp-mcp-codex"><?php esc_html_e( 'Copy', 'super-speedy-settings' ); ?></button></div>
                        <label for="ssp-mcp-claude"><strong><?php esc_html_e( 'Claude Code configuration', 'super-speedy-settings' ); ?></strong></label>
                        <div class="ssp-mcp-command"><textarea id="ssp-mcp-claude" rows="4" readonly spellcheck="false"></textarea><button type="button" class="button ssp-mcp-copy" data-target="ssp-mcp-claude"><?php esc_html_e( 'Copy', 'super-speedy-settings' ); ?></button></div>
                    </div>
                    <div id="ssp-mcp-error" class="notice notice-error inline" hidden><p></p></div>
                </div>

                <p><a href="https://www.superspeedyplugins.com/kb/super-speedy-filters/getting-started/setting-up-a-store-with-an-ai-assistant/" target="_blank" rel="noopener"><?php esc_html_e( 'Read the complete AI assistant setup guide', 'super-speedy-settings' ); ?></a> · <a href="https://github.com/WordPress/mcp-adapter" target="_blank" rel="noopener"><?php esc_html_e( 'Official WordPress MCP Adapter', 'super-speedy-settings' ); ?></a></p>
            </div>
            <?php
        }
    }
}
