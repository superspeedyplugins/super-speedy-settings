<?php
/**
 * Super Speedy Settings - CORE.
 *
 * Loaded exactly once per site, from the NEWEST bundled copy across every
 * active Super Speedy plugin, by the frozen loader stub in
 * super-speedy-settings.php (the candidate registry, settings 1.5.0 - design:
 * .docs/2026-08-03-latest-copy-wins-loader-design.md). Do not require this
 * file directly from a plugin; require super-speedy-settings.php as always.
 *
 * The real settings class lives here as SuperSpeedySettingsCore. The public
 * name SuperSpeedySettings_1_0 is the loader's frozen facade - plugin main
 * files keep calling SuperSpeedySettings_1_0::init() exactly as before, and
 * the queue drains into SuperSpeedySettingsCore::init() below.
 */
if (!defined('ABSPATH')) { return; }

// A plugin activated after plugins_loaded can include a second physical core in
// the activation request. Keep the already-running core for that request; the
// normal candidate election selects the newest copy on the next fresh request.
if ( class_exists( 'SuperSpeedySettingsCore', false ) || ! empty( $GLOBALS['ssp_settings_core_bootstrapped'] ) ) {
    return;
}
$GLOBALS['ssp_settings_core_bootstrapped'] = true;
$GLOBALS['ssp_settings_selected_dir'] = __DIR__;
$GLOBALS['ssp_settings_selected_version'] = '1.14.5';

require_once __DIR__ . '/abstract-class-super-speedy-settings.php';
if ( is_admin() ) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
}

if ( ! function_exists( 'superspeedy_mcp_enabled' ) ) {
    /** MCP is an explicit site-admin opt-in. */
    function superspeedy_mcp_enabled() {
        $options = get_option( 'superspeedy_options', array() );
        return is_array( $options )
            && isset( $options['superspeedy_field_mcp_enabled'] )
            && '1' === (string) $options['superspeedy_field_mcp_enabled'];
    }
}

// Use the official WordPress MCP Adapter. An already-loaded standalone plugin
// or Composer copy wins; otherwise the selected settings core loads its bundle.
if ( superspeedy_mcp_enabled() && file_exists( __DIR__ . '/class-ssp-official-mcp-adapter.php' ) ) {
    require_once __DIR__ . '/class-ssp-official-mcp-adapter.php';
    SSP_Official_MCP_Adapter::bootstrap();
}

// Shared `ssp` ability surface (licence status, and installing the rest of the range
// from this site's own licence). Lives here so one implementation reaches every Super
// Speedy plugin on its next settings sync; SSP_MCP::init() guards against the nine
// copies registering nine times. Version-gated on the core Abilities API.
// The class itself is loaded ALWAYS, because the settings page's own install
// button calls SSP_MCP::exec_install_plugin() and must work on a site that has
// never opted into MCP. Only the ABILITY REGISTRATION is gated on the opt-in.
// Defining the class has no side effects: the file declares one class and hooks
// nothing at include time.
if ( file_exists( __DIR__ . '/class-ssp-mcp.php' ) ) {
    require_once __DIR__ . '/class-ssp-mcp.php';
    if ( superspeedy_mcp_enabled() && function_exists( 'wp_register_ability' ) && class_exists( 'SSP_MCP' ) ) {
        SSP_MCP::init();
    }
}
// Shared config base (SuperSpeedy_MCP_Config): opt-in <slug>/get-config +
// <slug>/update-config for any plugin that instantiates it with its settings
// registry. Defining the class activates nothing by itself.
if ( superspeedy_mcp_enabled()
    && file_exists( __DIR__ . '/class-superspeedy-mcp-config.php' )
    && function_exists( 'wp_register_ability' ) ) {
    require_once __DIR__ . '/class-superspeedy-mcp-config.php';
}
if ( superspeedy_mcp_enabled() && file_exists( __DIR__ . '/class-ssp-mcp-setup.php' ) ) {
    require_once __DIR__ . '/class-ssp-mcp-setup.php';
    SSP_MCP_Setup::init();
}
use SuperSpeedy\PluginUpdateChecker\v5\PucFactory;
if (!class_exists('SuperSpeedySettingsCore')) {
    class SuperSpeedySettingsCore extends SuperSpeedySettings {
        protected static $version = 1.6;
        // Version of the shared super-speedy-settings code itself, sent to auth as
        // &sv= (distinct from a plugin's own build, sent as v=). Lets auth see which
        // sites run stale settings code (e.g. still checking www, or missing a param).
        // Bump this whenever the settings code changes in a way worth tracking.
        const SETTINGS_VERSION = '1.14.5';
        // Package downloads carry the licence key in this header, never in the URL
        // (settings 1.14.3). A URL is written to access logs, proxy logs, the stored
        // update_plugins transient and the upgrader's "Downloading update from ..."
        // line; a request header is not. The auth server still accepts the old
        // ?license_key= parameter so sites on older builds keep updating.
        const LICENSE_HEADER = 'X-SSP-License-Key';
        const LICENSED_PACKAGE_HOST = 'auth.superspeedyplugins.com';
        private $update_checkers = array();
        
        
        public function register_menu() {
            // The plain title. The update-count bubble is added by decorate_update_counts()
            // at admin_menu priority 999, once every plugin has registered its submenu, so
            // that the count can sit per-plugin when the menu is open.
            $menu_title = 'Super Speedy';

            add_menu_page(
                'Super Speedy Settings',
                $menu_title,
                'manage_options',
                self::$menu_slug,
                array($this, 'display_settings_page'),
                plugins_url('super-speedy.png', __FILE__)
            );
        }
        /** The bubble markup wp-admin already styles for plugin update counts. */
        protected function update_count_bubble( $count ) {
            $count = (int) $count;
            return ' <span class="update-plugins count-' . $count . '"><span class="plugin-count">' . $count . '</span></span>';
        }

        /**
         * Titles are matched loosely because a submenu item is labelled "Imports" while the
         * plugin is "Super Speedy Imports", and the page slugs are inconsistent
         * (`scalabilitypro`, `super-speedy-imports`, `sspa`). Strip the shared prefix and
         * everything that is not a letter or a digit, then compare.
         */
        protected function normalise_menu_title( $title ) {
            $title = strtolower( trim( strip_tags( (string) $title ) ) );
            $title = preg_replace( '/^super\s*speedy\s*/', '', $title );
            return preg_replace( '/[^a-z0-9]+/', '', (string) $title );
        }

        /**
         * Counts already cached for this plugin+version, WITHOUT any HTTP.
         *
         * This is what lets the bubble appear on every admin screen. Fetching is limited
         * to the two screens that justify it (below), but once a count is cached every
         * other screen can show it for the price of one transient read.
         */
        protected function cached_changes_count( $slug, $version ) {
            $cached = get_transient( 'superspeedy_changes_' . $slug . '_' . $version );
            return ( is_array( $cached ) && isset( $cached['count'] ) ) ? (int) $cached['count'] : null;
        }

        /** Is the visitor on a screen where FETCHING changelog counts is worth the HTTP? */
        protected function should_count_updates() {
            global $pagenow, $submenu;
            if ( 'plugins.php' === $pagenow ) {
                return true;
            }
            if ( 'admin.php' !== $pagenow || ! isset( $_GET['page'] ) ) {
                return false;
            }
            $page = sanitize_key( wp_unslash( $_GET['page'] ) );
            if ( self::$menu_slug === $page ) {
                return true;
            }
            foreach ( (array) ( isset( $submenu[ self::$menu_slug ] ) ? $submenu[ self::$menu_slug ] : array() ) as $item ) {
                if ( isset( $item[2] ) && $item[2] === $page ) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Place the update counts, once every plugin has registered its submenu.
         *
         * The aggregate ALWAYS stays on "Super Speedy": collapsed, hovered or open, that
         * is the thing Dave looks at to know whether anything moved. The per-plugin counts
         * are added as well, on each submenu item, so that expanding the menu - by hover
         * fly-out or by clicking through - shows WHICH plugin the aggregate is made of.
         * They cost nothing when the submenu is not on screen, because WordPress renders
         * it either way and CSS decides whether it is visible.
         *
         * Counts come from the changelog feed and are only fetched on screens where they
         * are shown, so no other wp-admin page pays for the HTTP. Only INSTALLED AND ACTIVE
         * plugins are counted - an inactive one has no registered version.
         */
        public function decorate_update_counts() {
            global $menu, $submenu;

            // Fetch on the two screens that justify the HTTP; everywhere else, show what is
            // already cached. Dave wants the bubble visible wherever he is in wp-admin, and
            // a cached read costs nothing.
            $may_fetch = $this->should_count_updates();

            $counts = array();
            $total  = 0;
            foreach ( $this->getLicenses() as $slug => $ssp_license ) {
                if ( ! isset( $ssp_license['version'] ) ) {
                    continue; // not active: nothing registered a version
                }
                if ( $may_fetch ) {
                    $changes = $this->get_changes_since_version( $slug, $ssp_license['version'] );
                    $count   = ( is_array( $changes ) && isset( $changes['count'] ) ) ? (int) $changes['count'] : null;
                } else {
                    $count = $this->cached_changes_count( $slug, $ssp_license['version'] );
                }
                if ( ! $count ) {
                    continue;
                }
                $counts[ $slug ] = $count;
                $total          += $count;
            }
            if ( ! $total ) {
                return;
            }

            foreach ( (array) $menu as $index => $item ) {
                if ( isset( $item[2] ) && self::$menu_slug === $item[2] ) {
                    $menu[ $index ][0] .= $this->update_count_bubble( $total );
                    break;
                }
            }

            // And against each plugin, so an expanded menu shows which one moved.
            $names = array();
            foreach ( $this->getLicenses() as $slug => $ssp_license ) {
                if ( isset( $counts[ $slug ] ) && ! empty( $ssp_license['name'] ) ) {
                    $names[ $this->normalise_menu_title( $ssp_license['name'] ) ] = $slug;
                }
            }
            foreach ( (array) ( isset( $submenu[ self::$menu_slug ] ) ? $submenu[ self::$menu_slug ] : array() ) as $index => $item ) {
                if ( ! isset( $item[0] ) ) {
                    continue;
                }
                $key = $this->normalise_menu_title( $item[0] );
                if ( isset( $names[ $key ] ) ) {
                    $submenu[ self::$menu_slug ][ $index ][0] .= $this->update_count_bubble( $counts[ $names[ $key ] ] );
                }
            }
        }

        public function display_settings_page() {
            // check user capabilities
        
            // add error/update messages
            // check if the user have submitted the settings
            // WordPress will add the "settings-updated" $_GET parameter to the url
            if ( isset( $_GET['settings-updated'] ) ) {
                // add settings saved message with the class of "updated"
                add_settings_error( 'superspeedy_messages', 'superspeedy_message', __( 'Settings Saved', 'superspeedy' ), 'updated' );
            }
        
            // show error/update messages
            settings_errors( 'superspeedy_messages' );
            ?>
            <div class="wrap">
                <?php
                // The header panel is white, so the BLACK text logo belongs
                // here. The white variant ships alongside it for any plugin
                // that needs a logo on a dark background.
                ?>
                <div class="super-speedy-plugins-admin-header"><a href="https://www.superspeedyplugins.com/" target="_blank"><img src="<?php echo esc_url( plugins_url( 'super-speedy-plugins-black-text.png', __FILE__ ) ); ?>" width="151" height="32" alt="Super Speedy Plugins"></a></div>
                
                <?php $this->display_performance_analysis_panel(); ?>

                <!-- Service panels flexbox -->
                <div class="superspeedy-services">
                    <div class="superspeedy-service-panel">
                        <a href="https://www.superspeedyplugins.com/kb/" target="_blank">
                            <i class="at-open-book-bold" aria-hidden="true"></i>
                            <h2>Knowledge Base</h2>
                            <p>Learn how to use our plugins</p>
                        </a>
                    </div>
                    <div class="superspeedy-service-panel">
                        <a href="https://www.superspeedyplugins.com/super-speedy-discord/" target="_blank">
                            <i class="at-chat-dots-bold" aria-hidden="true"></i>
                            <h2>Chat Live with Developers</h2>
                            <p>Instant answers via Discord</p>
                        </a>
                    </div>
                    <div class="superspeedy-service-panel">
                        <a href="https://www.superspeedyplugins.com/latest/" target="_blank">
                            <i class="at-clipboard-lines-document-bold" aria-hidden="true"></i>
                            <h2>Performance Articles</h2>
                            <p>The latest performance articles</p>
                        </a>
                    </div>
                    <div class="superspeedy-service-panel">
                        <a href="https://www.superspeedyplugins.com/kb/super-speedy-basics/upgrade-and-renewal-discounts/" target="_blank">
                            <i class="at-arrow-up-from-square-bold" aria-hidden="true"></i>
                            <h2>Upgrade Options</h2>
                            <p>Upgrade and pay the difference</p>
                        </a>
                    </div>
                    <div class="superspeedy-service-panel">
                        <a href="https://www.superspeedyplugins.com/kb/super-speedy-basics/upgrade-and-renewal-discounts/" target="_blank">
                            <i class="at-arrows-rotating-right-bold" aria-hidden="true"></i>
                            <h2>Renewal Options</h2>
                            <p>Get 30% off all renewals</p>
                        </a>
                    </div>
                </div>
                
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'superspeedy' );
                    $this->display_license_table();
                    do_settings_sections( 'superspeedy' );

                    submit_button( 'Save Settings' );
                    ?>
                </form>
            </div>
            <?php
        }
        public function setup() {
            // call parent setup
            parent::setup();
            // Every package download path goes through WP_Http: wp-admin updates,
            // cron auto-updates, WP-CLI and the ssp/install-plugin installer. So the
            // licence header is added here, for every context, and NOT behind
            // is_admin() - WP-CLI and cron are not admin requests.
            add_filter( 'http_request_args', array( $this, 'add_license_header_to_package_request' ), 10, 2 );
            if ( is_admin() ) {
                $this->ssp_initialize_updaters();
            }
            // Late, so every Super Speedy plugin has registered its submenu by the time
            // the counts are placed. 999 rather than the default 10 for that reason.
            add_action( 'admin_menu', array($this, 'decorate_update_counts'), 999 );
            add_action( 'admin_init', array($this, 'settings_init') );
            add_action('wp_error_added', array($this, 'handle_wp_error_added'), 10, 4);
            add_action( 'admin_enqueue_scripts', array($this, 'enqueue_admin_script'));
            add_action('wp_ajax_superspeedy_recheck_license', array($this, 'handle_recheck_license'));
            add_action('wp_ajax_superspeedy_install_performance_analysis', array($this, 'handle_install_performance_analysis'));
            add_action('updated_option', array($this, 'keep_shared_options_out_of_autoload'), 10, 1);
            $this->ensure_options_not_autoloaded();
        }
        public function keep_shared_options_out_of_autoload( $option ) {
            if ( 'superspeedy_options' === $option ) {
                $this->ensure_options_not_autoloaded();
            }
        }
        public function ensure_options_not_autoloaded() {
            if ( function_exists( 'wp_set_option_autoload_values' ) ) {
                wp_set_option_autoload_values( array( 'superspeedy_options' => false ) );
                return;
            }
            global $wpdb;
            $wpdb->update(
                $wpdb->options,
                array( 'autoload' => 'no' ),
                array( 'option_name' => 'superspeedy_options' ),
                array( '%s' ),
                array( '%s' )
            );
        }
        public function should_fetch_changelogs() {
            global $pagenow;
            if ( 'plugins.php' === $pagenow ) {
                return true;
            }
            return 'admin.php' === $pagenow
                && isset( $_GET['page'] )
                && 'superspeedy' === sanitize_key( wp_unslash( $_GET['page'] ) );
        }
        function ssp_initialize_updaters() {    
            $superspeedy_licenses = $this->getLicenses(); // Get the global licenses
            foreach($superspeedy_licenses as $slug => $ssp_license) {
                if (!isset($ssp_license['file'])) continue; // only add update checker for newer versions and when plugin is active
    
                $this->update_checkers[$slug] = PucFactory::buildUpdateChecker(
                    'https://www.superspeedyplugins.com/assets/plugins/' . $slug . '.json',
                    $ssp_license['file'], //Full path to the main plugin file or functions.php.
                    $slug,
                    $this->check_period_hours() // issue #267: user-cappable check cadence
                );
                add_filter('puc_pre_inject_update-' . $slug, array($this, 'add_license_to_remote_update_request'));
            }
    
        }
        public function handle_recheck_license() {
            // Only administrators may overwrite the licence key or trigger the
            // outbound licence calls this handler performs.
            if (!current_user_can('manage_options')) {
                wp_send_json_error('forbidden');
                return;
            }
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'superspeedy_recheck_license')) {
                wp_send_json_error('Invalid nonce');
                return;
            }

            // Get the current license key from the AJAX request
            $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

            $options = get_option('superspeedy_options', array());
            $options['superspeedy_field_licensekey'] = $license_key;
            update_option('superspeedy_options', $options);

            // Delete all license transients
            $this->delete_license_cache();

            // Re-fetch each installed plugin's licence status with force=1 so
            // the auth-server also bypasses its 1-hour MySQL cache. Repopulates
            // the transients we just deleted so the page reload reads fresh
            // results without a second round of HTTP calls.
            if (!empty($license_key)) {
                $superspeedy_licenses = $this->getLicenses();
                foreach ($superspeedy_licenses as $slug => $info) {
                    $this->check_license($slug, true);
                }
            }

            wp_send_json_success();
        }
        public function settings_validate_and_save( $input ) {
            $input = is_array($input) ? $input : array();
            // If the license key was changed, delete the license cache
            $options = get_option('superspeedy_options');
            if (!$options || 
                !isset($options['superspeedy_field_licensekey']) || 
                !isset($input['superspeedy_field_licensekey']) ||
                $options['superspeedy_field_licensekey'] !== $input['superspeedy_field_licensekey']) {
                $this->delete_license_cache();
            }
        
            // Sanitize and validate the input as needed before saving
            $new_input = array_map('sanitize_text_field', $input);
            $new_input['superspeedy_field_mcp_enabled'] = !empty($input['superspeedy_field_mcp_enabled']) ? '1' : '0';
        
            return $new_input;
        }
        public function settings_init() {
            // Register a new setting for "wpintense" page.
            register_setting( 'superspeedy', 'superspeedy_options', array($this, 'settings_validate_and_save' ));
        
            // Register a new section in the "wpintense" page.
            add_settings_section(
                'superspeedy_section_license',
                __( 'License', 'superspeedy' ), array($this, 'settings_intro'),
                'superspeedy'
            );
        
            // Register a new field in the "superspeedy_section_license" section, inside the "wpintense" page.
            add_settings_field(
                'superspeedy_field_licensekey',
                __( 'License Key', 'superspeedy' ),
                array($this, 'field_licensekey_cb'),
                'superspeedy',
                'superspeedy_section_license',
                array(
                    'label_for'         => 'superspeedy_field_licensekey',
                    'class'             => 'superspeedy_row',
                    'superspeedy_custom_data' => 'custom',
                )
            );

            // Issue #267: user control over how often the plugins phone home.
            add_settings_field(
                'superspeedy_field_checkfrequency',
                __( 'Check frequency', 'superspeedy' ),
                array($this, 'field_checkfrequency_cb'),
                'superspeedy',
                'superspeedy_section_license',
                array(
                    'label_for' => 'superspeedy_field_checkfrequency',
                    'class'     => 'superspeedy_row',
                )
            );

            add_settings_field(
                'superspeedy_field_mcp_enabled',
                __( 'MCP access', 'superspeedy' ),
                array($this, 'field_mcp_enabled_cb'),
                'superspeedy',
                'superspeedy_section_license',
                array(
                    'label_for' => 'superspeedy_field_mcp_enabled',
                    'class' => 'superspeedy_row',
                )
            );
        }
        public function field_mcp_enabled_cb( $args ) {
            $options = get_option( 'superspeedy_options', array() );
            $enabled = is_array($options) && isset($options[ $args['label_for'] ]) && '1' === (string) $options[ $args['label_for'] ];
            echo '<label><input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="superspeedy_options[' . esc_attr($args['label_for']) . ']" value="1"' . checked($enabled, true, false) . '> ' . esc_html__( 'Enable the Super Speedy MCP endpoint and AI-agent abilities', 'superspeedy' ) . '</label>';
            echo '<p class="description">' . esc_html__( 'Off by default. Enabling loads the bundled MCP Adapter and adds the MCP setup page after saving. Disabling removes the endpoint; any existing Application Password remains dormant until MCP is enabled again or revoked.', 'superspeedy' ) . '</p>';
        }
        public function field_checkfrequency_cb( $args ) {
            $options = get_option( 'superspeedy_options' );
            $current = is_array($options) && isset($options[ $args['label_for'] ]) ? $options[ $args['label_for'] ] : '';
            $choices = array(
                ''       => __( 'Default - licence every 2 hours, updates twice a day', 'superspeedy' ),
                'daily'  => __( 'At most once a day', 'superspeedy' ),
                'weekly' => __( 'At most once a week', 'superspeedy' ),
            );
            echo '<select id="' . esc_attr( $args['label_for'] ) . '" name="superspeedy_options[' . esc_attr( $args['label_for'] ) . ']">';
            foreach ( $choices as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__( 'How often the Super Speedy plugins contact superspeedyplugins.com for licence, update and changelog checks. Checks only ever run while someone is logged in to wp-admin, so this is a cap, not a schedule. The Recheck now button always checks immediately.', 'superspeedy' ) . '</p>';
            $last = (int) get_option( 'superspeedy_last_check' );
            if ( $last > 0 ) {
                /* translators: %s: human-readable time difference, e.g. "3 hours" */
                echo '<p class="description">' . esc_html( sprintf( __( 'Licence last checked: %s ago.', 'superspeedy' ), human_time_diff( $last ) ) ) . '</p>';
            }
        }
        /** Slug and main file of the free diagnostic that leads this page. */
        const SSPA_SLUG = 'super-speedy-performance-analysis';
        const SSPA_FILE = 'super-speedy-performance-analysis/super-speedy-performance-analysis.php';

        /** installed-and-active | installed | missing, for the panel below. */
        protected function performance_analysis_state() {
            if ( function_exists( 'sspa_default_options' ) ) {
                return 'active';
            }
            return file_exists( WP_PLUGIN_DIR . '/' . self::SSPA_FILE ) ? 'inactive' : 'missing';
        }

        /**
         * Performance Analysis leads this page, always, in every plugin that bundles
         * these settings. It is free, it is the diagnostic that says which plugin is
         * actually costing the site time, and it is how a customer finds out which of
         * the rest of the range would help. So it gets a panel at the top rather than
         * a row in a licence table, and the panel is never dismissible.
         *
         * Nothing here is an admin notice: this is our own settings screen.
         */
        /**
         * The measurements Performance Analysis can take, as launcher buttons.
         *
         * Shown even when the plugin is NOT installed, greyed out, because the list itself
         * is the argument: nobody else can measure a real checkout or a real save, and a
         * shop owner reading "Analyse checkout performance" learns something a paragraph
         * would not tell them. A disabled button always says WHY it is disabled - a control
         * that just looks broken is worse than no control.
         *
         * Every link OPENS the tool primed. None of them measures anything: checkout in
         * particular makes a real purchase and sends real email, so the press that starts
         * it must happen in front of the pre-flight, never from this page.
         */
        protected function performance_analysis_actions($state) {
            $active = ('active' === $state);
            $woo = class_exists('WooCommerce');

            $cart = $woo && function_exists('wc_get_cart_url') ? wc_get_cart_url() : '';
            $recent = get_posts(array('numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids'));
            $editor = $recent ? get_edit_post_link($recent[0], 'raw') : '';

            $headline = ($active && class_exists('SSPA_Report') && method_exists('SSPA_Report', 'headline'))
                ? SSPA_Report::headline()
                : null;

            $actions = array();

            $actions[] = array(
                'label' => __('Analyse my slowest page', 'super-speedy-settings'),
                'href' => (is_array($headline) && !empty($headline['url']))
                    ? add_query_arg('sspa_open', 'page', $headline['url'])
                    : admin_url('admin.php?page=sspa'),
                'enabled' => $active,
                'why' => __('Profiles one page and shows where its time went, per plugin, per query.', 'super-speedy-settings'),
            );

            $actions[] = array(
                'label' => __('Analyse checkout performance', 'super-speedy-settings'),
                'href' => $cart ? add_query_arg('sspa_open', 'checkout', $cart) : '',
                'enabled' => $active && '' !== $cart,
                'why' => $woo
                    ? __('Measures one real purchase step by step, split at the moment the money is taken, then the order screen afterwards.', 'super-speedy-settings')
                    : __('Measures a real purchase step by step. Needs WooCommerce.', 'super-speedy-settings'),
                'blocked' => $active && !$woo ? __('WooCommerce is not active on this site', 'super-speedy-settings') : '',
            );

            $actions[] = array(
                'label' => __('Analyse a post or product save', 'super-speedy-settings'),
                'href' => $editor ? add_query_arg('sspa_open', 'save', $editor) : '',
                'enabled' => $active && '' !== $editor,
                'why' => __('WordPress saves over ajax and then reloads, so a slow save hides in a request nothing watches. This profiles the write itself.', 'super-speedy-settings'),
                'blocked' => $active && '' === $editor ? __('No published post to edit yet', 'super-speedy-settings') : '',
            );

            $actions[] = array(
                'label' => __('Plugin Impact Analysis', 'super-speedy-settings'),
                'href' => admin_url('admin.php?page=sspa&tab=plugins'),
                'enabled' => $active,
                'why' => __('Measures what each plugin actually costs by disabling it for test requests only, and reports the ones that make the site faster too.', 'super-speedy-settings'),
            );

            return $actions;
        }

        protected function render_performance_analysis_actions($state) {
            $actions = $this->performance_analysis_actions($state);
            $reason = ('missing' === $state)
                ? __('Install Performance Analysis to use this', 'super-speedy-settings')
                : (('inactive' === $state) ? __('Activate Performance Analysis to use this', 'super-speedy-settings') : '');

            // Labels only, in a row. The explanation each one used to carry lives in the
            // hover title: four buttons read as four things you can do, four paragraphs read
            // as a wall. The reason a disabled one is disabled stays visible on hover too,
            // because a dead-looking button with no explanation is the thing to avoid.
            echo '<div class="superspeedy-sspa-actions">';
            foreach ($actions as $action) {
                $blocked = !empty($action['blocked']) ? $action['blocked'] : $reason;
                $title = $action['why'];
                if (empty($action['enabled']) && '' !== $blocked) {
                    $title .= ' (' . $blocked . ')';
                }
                if (!empty($action['enabled'])) {
                    echo '<a class="button" href="' . esc_url($action['href']) . '" title="' . esc_attr($title) . '">' . esc_html($action['label']) . '</a>';
                } else {
                    echo '<span class="button disabled" aria-disabled="true" title="' . esc_attr($title) . '">' . esc_html($action['label']) . '</span>';
                }
            }
            echo '</div>';
        }

        /** The single primary action, which sits top right whatever state we are in. */
        protected function performance_analysis_primary($state) {
            if ('active' === $state) {
                return '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=sspa')) . '">'
                    . esc_html__('Open Performance Analysis', 'super-speedy-settings') . '</a>';
            }
            if ('inactive' === $state) {
                $activate = wp_nonce_url(
                    self_admin_url('plugins.php?action=activate&plugin=' . rawurlencode(self::SSPA_FILE)),
                    'activate-plugin_' . self::SSPA_FILE
                );
                return '<a class="button button-primary" href="' . esc_url($activate) . '">'
                    . esc_html__('Activate Performance Analysis', 'super-speedy-settings') . '</a>';
            }
            if (current_user_can('install_plugins')) {
                return '<button type="button" class="button button-primary" id="superspeedy-install-sspa" data-nonce="'
                    . esc_attr(wp_create_nonce('superspeedy_install_sspa')) . '">'
                    . esc_html__('Install Performance Analysis FREE', 'super-speedy-settings') . '</button>'
                    . ' <span class="superspeedy-sspa-status" role="status"></span>';
            }
            return '<a class="button button-primary" href="https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest" target="_blank" rel="noopener">'
                . esc_html__('Download Performance Analysis FREE', 'super-speedy-settings') . '</a>';
        }

        public function display_performance_analysis_panel() {
            $state = $this->performance_analysis_state();
            $active = ('active' === $state);

            $summary = ($active && class_exists('SSPA_Report') && method_exists('SSPA_Report', 'summary'))
                ? SSPA_Report::summary()
                : null;
            $score = (is_array($summary) && null !== $summary['score']) ? (int) $summary['score'] : null;

            echo '<div class="superspeedy-sspa-panel superspeedy-sspa-' . esc_attr($state) . '">';

            // Header: what it is, and the one primary action, top right.
            echo '<div class="superspeedy-sspa-head">';
            echo '<div class="superspeedy-sspa-intro">';
            echo '<h2>' . esc_html__('Performance Analysis', 'super-speedy-settings') . '</h2>';
            echo '<p>' . esc_html__('Super Speedy Performance Analysis is the latest free tool that every WordPress site needs to instantly identify your performance bottlenecks.', 'super-speedy-settings') . '</p>';
            echo '</div>';
            echo '<div class="superspeedy-sspa-primary">' . $this->performance_analysis_primary($state) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above.
            echo '</div>';

            echo '<div class="superspeedy-sspa-body">';

            // The score, to the left of everything else.
            if (null !== $score) {
                echo '<div class="superspeedy-sspa-scorebox">';
                echo '<span class="superspeedy-sspa-scorelabel">' . esc_html__('Your latest site score', 'super-speedy-settings') . '</span>';
                echo '<span class="superspeedy-sspa-scorevalue">' . esc_html($score) . '<span class="superspeedy-sspa-scoremax">/100</span></span>';
                echo '<a class="superspeedy-sspa-scorehelp" href="' . esc_url('https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/') . '" target="_blank" rel="noopener">'
                    . esc_html__('Learn how to improve this score', 'super-speedy-settings') . '</a>';
                echo '</div>';
            }

            echo '<div class="superspeedy-sspa-main">';

            if ($active && null !== $score) {
                $headline = method_exists('SSPA_Report', 'headline') ? SSPA_Report::headline() : null;
                if (is_array($headline) && $headline['gen_ms'] > 0) {
                    echo '<p class="superspeedy-sspa-headline">';
                    if (!empty($headline['component']) && $headline['component_ms'] > 0) {
                        printf(
                            /* translators: 1: page identifier, 2: milliseconds, 3: component name, 4: milliseconds. */
                            esc_html__('Slowest page measured: %1$s at %2$sms, and %3$s accounts for %4$sms of it.', 'super-speedy-settings'),
                            '<code>' . esc_html($headline['page_key']) . '</code>',
                            esc_html(number_format_i18n($headline['gen_ms'])),
                            '<strong>' . esc_html($headline['component']) . '</strong>',
                            esc_html(number_format_i18n($headline['component_ms']))
                        );
                    } else {
                        printf(
                            /* translators: 1: page identifier, 2: milliseconds. */
                            esc_html__('Slowest page measured: %1$s at %2$sms.', 'super-speedy-settings'),
                            '<code>' . esc_html($headline['page_key']) . '</code>',
                            esc_html(number_format_i18n($headline['gen_ms']))
                        );
                    }
                    echo '</p>';
                }
            } elseif ($active) {
                $pages = method_exists('SSPA_Report', 'page_analysis_count') ? SSPA_Report::page_analysis_count() : 0;
                echo '<p>' . esc_html($pages
                    ? sprintf(
                        /* translators: %d: number of single pages already analysed. */
                        _n(
                            '%d page analysed so far. A site-wide analysis gives you a score and finds the plugins costing you the most across every key page.',
                            '%d pages analysed so far. A site-wide analysis gives you a score and finds the plugins costing you the most across every key page.',
                            $pages,
                            'super-speedy-settings'
                        ),
                        $pages
                    )
                    : __('Nothing analysed yet. Run one analysis to find out which plugin is costing this site the most time.', 'super-speedy-settings')
                ) . '</p>';
            }

            $this->render_performance_analysis_actions($state);

            echo '</div></div></div>';
        }

        /**
         * Install Performance Analysis from its one allow-listed public package.
         *
         * Delegates to SSP_MCP::exec_install_plugin(), which is the single shared
         * installer used by the agent surface and by Scalability Pro, so there is
         * one implementation of download, validation and activation rather than three.
         */
        public function handle_install_performance_analysis() {
            if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
                wp_send_json_error( array( 'message' => __( 'You do not have permission to install plugins on this site.', 'super-speedy-settings' ) ), 403 );
            }
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'superspeedy_install_sspa' ) ) {
                wp_send_json_error( array( 'message' => __( 'That request expired. Reload the page and try again.', 'super-speedy-settings' ) ), 400 );
            }
            if ( ! class_exists( 'SSP_MCP' ) ) {
                wp_send_json_error( array( 'message' => __( 'The shared installer is not available in this copy of Super Speedy Settings.', 'super-speedy-settings' ) ), 500 );
            }

            // confirm: the human clicked the button, which is what the interlock is for.
            $result = SSP_MCP::exec_install_plugin( array(
                'slug'     => self::SSPA_SLUG,
                'activate' => true,
                'confirm'  => true,
            ) );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
            }
            wp_send_json_success( array(
                'message' => __( 'Installed. Opening Performance Analysis.', 'super-speedy-settings' ),
                'redirect' => admin_url( 'admin.php?page=sspa' ),
            ) );
        }

        public function settings_intro() {
            // A licence key is for the PAID plugins. Free ones update without one, so
            // this page must not tell every visitor they need a key.
            ?>
            <p>Enter your licence key below to enable updates for the paid Super Speedy plugins. The free ones, including Performance Analysis, update without a key.</p>
            <p>If you hold a licence, please remember to renew it to continue to support our small team so we can keep solving these WordPress performance issues.</p>
            <?php
        }
        /** Human wording for a licence result. Never print a raw error code at a user. */
        protected function licence_status_label( $license ) {
            $error = isset( $license['error'] ) ? strtolower( trim( (string) $license['error'] ) ) : '';
            switch ( $error ) {
                case '':
                case 'ok':
                case 'valid':
                    return __( 'Licensed', 'super-speedy-settings' );
                case 'invalid':
                    return __( 'No licence key entered', 'super-speedy-settings' );
                case 'expired':
                    return __( 'Licence expired', 'super-speedy-settings' );
                case 'not purchased':
                    return __( 'Not purchased', 'super-speedy-settings' );
            }
            // Anything the licence server says that we do not recognise is shown as
            // its own message rather than its code, and never as a bare token.
            return isset( $license['message'] ) && '' !== trim( (string) $license['message'] )
                ? (string) $license['message']
                : __( 'Licence status unavailable', 'super-speedy-settings' );
        }

        public function display_license_table( ) {
            $superspeedy_licenses = $this->getLicenses();
            $site_url = get_site_url();
            $site_url = str_replace('localhost', 'lh', $site_url); // localhost gets banned by GridPane
            $utm_source = parse_url($site_url, PHP_URL_HOST);

            $action = 'puc_check_for_updates';
            $nonce = wp_create_nonce($action);
            $update_plugin_url = admin_url('plugins.php') . "?puc_check_for_updates=1&_wpnonce=" . $nonce . "&puc_slug=";

            // Two sections: what is on this site, and the range. A licence audit of plugins
            // you have not bought is noise, so unowned products get a browsable row rather
            // than a status column. Performance Analysis appears here like everything else,
            // as well as in its own panel above.
            $installed = array();
            $rest      = array();
            foreach ( $superspeedy_licenses as $slug => $ssp_license ) {
                $license = $this->check_license( $slug );
                if ( isset( $license['error'] ) && 'not installed' === $license['error'] ) {
                    $rest[ $slug ] = $ssp_license;
                } else {
                    $installed[ $slug ] = array( 'info' => $ssp_license, 'license' => $license );
                }
            }

            // The recheck control and the licence-key advice only mean anything when a
            // licensed plugin is actually installed.
            if ( ! empty( $installed ) ) {
                $options    = get_option('superspeedy_options');
                $saved_key  = is_array($options) && !empty($options['superspeedy_field_licensekey']) ? $options['superspeedy_field_licensekey'] : '';
                $btn_disabled = $saved_key === '' ? ' disabled' : '';
                echo '<p class="superspeedy-recheck-row">';
                echo '<button type="button" id="recheck-licenses-top" class="button button-primary"' . $btn_disabled . '>Recheck Licenses</button>';
                echo ' <span class="description recheck-licenses-hint"' . ($saved_key === '' ? '' : ' style="display:none"') . '>Enter your licence key below and save settings to enable.</span>';
                echo '</p>';
            }

            if ( ! empty( $installed ) ) {
                echo '<h2>' . esc_html__( 'On this site', 'super-speedy-settings' ) . '</h2>';
                echo '<table class="superspeedy-plugins">';
                echo '<thead><tr><th></th><th>Plugin Name</th><th>Installation Status</th><th>License Status</th><th>Changes</th><th>Docs</th><th>Live Support</th></tr></thead>';
                echo '<tbody>';

                foreach ( $installed as $slug => $row ) {
                    $ssp_license = $row['info'];
                    $license     = $row['license'];
                    $is_active   = ( ! empty($ssp_license['function']) && function_exists($ssp_license['function']) );
                    echo '<tr class="' . ( $is_active ? 'activated' : 'deactivated' ) . '">';
                    echo '<td>';
                    if ( file_exists( __DIR__ . '/images/' . $slug . '.png' ) ) {
                        echo '<a href="' . esc_url('https://www.superspeedyplugins.com/product/' . $slug . '/') . '" target="_blank"><img src="' . esc_url(plugins_url('images/' . $slug . '.png', __FILE__)) . '" alt="' . esc_attr($ssp_license['name'] ?? '') . '" style="height: 50px; width: auto;"></a>';
                    }
                    echo '</td>';
                    echo '<td><span class="plugin-name-bold">' . esc_html($ssp_license['name'] ?? '') . '</span><br><a href="' . esc_url('https://www.superspeedyplugins.com/product/' . $slug . '/') . '" target="_blank" class="plugin-page-link">View ' . esc_html($ssp_license['name'] ?? '') . ' plugin page</a></td>';
                    echo '<td>' . esc_html( $is_active ? __( 'Installed / Activated', 'super-speedy-settings' ) : __( 'Installed / Deactivated', 'super-speedy-settings' ) ) . '</td>';
                    echo '<td>' . esc_html(
                        ! empty( $ssp_license['free'] )
                            ? __( 'Free - no key needed', 'super-speedy-settings' )
                            : $this->licence_status_label( $license )
                    ) . '</td>';

                    if (isset($ssp_license['version'])) {
                        $changes = $this->get_changes_since_version($slug, $ssp_license['version']);
                        if (!$changes) {
                            echo '<td>Unable to check for updates - please check your server allows remote URL requests</td>';
                        } else {
                            echo '<td>' . esc_html($changes["count"]) . ' changes since your last update<br /><a href="' . esc_url('https://www.superspeedyplugins.com/support/changelogs/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin') . '" target="_blank">View Change log</a>';
                            if ($changes["count"] > 0) {
                                echo ' | <a href="' . esc_url($update_plugin_url . $slug) . '">Update Plugin</a>';
                            }
                            echo '</td>';
                        }
                    } else {
                        $changes = $this->get_changes_since_days($slug, 90);
                        echo '<td>' . esc_html($changes["count"]) . ' changes in the last 90 days<br /><a href="' . esc_url('https://www.superspeedyplugins.com/support/changelogs/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin') . '" target="_blank">View Change log</a></td>';
                    }
                    echo '<td><a href="' . esc_url('https://www.superspeedyplugins.com/kb/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin') . '" target="_blank">Knowledge Base</a></td>';
                    echo '<td>' . (!empty($ssp_license['discord']) ? '<a href="' . esc_url($ssp_license['discord']) . '" target="_blank">Discord Channel</a>' : 'N/A') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
                echo '<p><strong>Please note:</strong> Our plugins must be activated in order to update them. If you wish to update them without activating them, you must download the latest zip from <a href="https://www.superspeedyplugins.com/my-account/downloads/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin">your account</a> and upload the new zip file manually.</p>';
            }

            if ( ! empty( $rest ) ) {
                echo '<h2>' . esc_html__( 'The Super Speedy Plugins Range', 'super-speedy-settings' ) . '</h2>';
                echo '<table class="superspeedy-plugins superspeedy-plugins-range">';
                echo '<tbody>';
                foreach ( $rest as $slug => $ssp_license ) {
                    $product = 'https://www.superspeedyplugins.com/product/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin';
                    echo '<tr class="not-installed">';
                    echo '<td>';
                    if ( file_exists( __DIR__ . '/images/' . $slug . '.png' ) ) {
                        echo '<a href="' . esc_url($product) . '" target="_blank"><img src="' . esc_url(plugins_url('images/' . $slug . '.png', __FILE__)) . '" alt="' . esc_attr($ssp_license['name'] ?? '') . '" style="height: 50px; width: auto;"></a>';
                    }
                    echo '</td>';
                    echo '<td><span class="plugin-name-bold">' . esc_html($ssp_license['name'] ?? '') . '</span></td>';
                    echo '<td><a href="' . esc_url($product) . '" target="_blank">' . esc_html__( 'View plugin page', 'super-speedy-settings' ) . '</a></td>';
                    echo '<td><a href="' . esc_url('https://www.superspeedyplugins.com/kb/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin') . '" target="_blank">' . esc_html__( 'Knowledge Base', 'super-speedy-settings' ) . '</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
        public function field_licensekey_cb( $args ) {
            // Get the value of the setting we've registered with register_setting()
            $options = get_option( 'superspeedy_options' );
            $site_url = get_site_url();
            $site_url = str_replace('localhost', 'lh', $site_url); // localhost gets banned by GridPane
    
            $utm_source = parse_url($site_url, PHP_URL_HOST);
            $license_key = '';
            if ($options && is_array($options) && array_key_exists($args['label_for'], $options)) {
                $license_key = $options[ $args['label_for'] ];
            }

            ?>
            <input type="password" id="<?php echo esc_attr( $args['label_for'] ); ?>"
                    data-custom="<?php echo esc_attr( $args['superspeedy_custom_data'] ); ?>"
                    name="superspeedy_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
                    value="<?php echo esc_attr($license_key);?>">
            <button type="button" id="recheck-license" class="button">Recheck now</button>
    
            <?php
        }
        /**
         * Issue #267: how often the plugins phone home for licence, update and
         * changelog data. '' = the classic cadence (licence 2h, changelog 2h/8h,
         * updates 12h). Every check is lazy - it only ever fires while someone
         * is in wp-admin and the relevant cache has expired - so this is a cap
         * on frequency, not a schedule. The "Recheck now" button always checks
         * immediately regardless.
         */
        protected function check_frequency() {
            $options = get_option('superspeedy_options');
            $freq = is_array($options) && isset($options['superspeedy_field_checkfrequency']) ? $options['superspeedy_field_checkfrequency'] : '';
            return in_array($freq, array('daily', 'weekly'), true) ? $freq : '';
        }
        /** Cache TTL for licence/changelog data: the classic TTL, or the user's chosen interval if longer. */
        protected function check_ttl($default_ttl) {
            switch ($this->check_frequency()) {
                case 'daily':  return max($default_ttl, DAY_IN_SECONDS);
                case 'weekly': return max($default_ttl, WEEK_IN_SECONDS);
            }
            return $default_ttl;
        }
        /** Update-checker period in hours for PucFactory::buildUpdateChecker(). */
        protected function check_period_hours() {
            switch ($this->check_frequency()) {
                case 'daily':  return 24;
                case 'weekly': return 168;
            }
            return 12;
        }
        function check_license($slug, $force = false) {
            $options = get_option('superspeedy_options');

            if (!$options) {
                $options = get_option('wpintense_options');
                if ($options) {
                    $options = array('superspeedy_field_licensekey' => $options['wpintense_field_licensekey']);
                    update_option('superspeedy_options', $options);
                }
            }

            $installed = false;
            if (is_dir(WP_PLUGIN_DIR . '/' . $slug)) {
                $installed = true;
            }
            if (!$installed) {
                return array('error' => 'not installed', 'message' => 'This plugin is not installed');
            }
            if (!isset($options['superspeedy_field_licensekey'])) {
                return array('error' => 'invalid', 'message' =>'Enter a valid license key to enable updates for Super Speedy plugins.');
            }
            if (!$force) {
                $cached = get_transient('superspeedy_l_' . $slug);
                if ($cached) {
                    return $cached;
                }
            }
            $homeurl = get_home_url();
            $parsedurl = parse_url($homeurl);
            if (isset($parsedurl['host']) && !empty($parsedurl['host']) && isset($options['superspeedy_field_licensekey']) && !empty($options['superspeedy_field_licensekey']) && !empty($slug)) {
                // Licence checks go DIRECT to the auth server. www has no route for
                // this path and returns rest_no_route (404); new clients hit auth.
                $keycheckurl = 'https://auth.superspeedyplugins.com/wp-json/wpiapi/check_product_key/';
                // Report the installed version (v=) and shared settings-code version
                // (sv=) in the POST body. force=1 bypasses the server cache.
                // force=1 bypasses the server cache.
                $ssp_qs = array();
                if ($force) {
                    $ssp_qs['force'] = 1;
                }
                $ssp_licenses = self::getLicenses();
                if (isset($ssp_licenses[$slug]['version']) && $ssp_licenses[$slug]['version'] !== '') {
                    $ssp_qs['v'] = (string) $ssp_licenses[$slug]['version'];
                }
                $ssp_qs['sv'] = self::SETTINGS_VERSION;
//                if ($homeurl == "https://localhost") {
//                    $keycheckurl = 'https://localhost/wp-json/wpiapi/check_product_key/' . $encoded_licensekey . '/' . $slug . '/' . $parsedurl['host'] . '/';
//                }
                // Issue #165: a challenged/unreachable server must not hang the
                // settings page. 60s here meant up to a minute PER PLUGIN when
                // the auth server could not be reached; fail fast instead - the
                // result is cached either way and the next visit retries.
                $keycheckresponse = wp_safe_remote_post($keycheckurl, array(
                    'timeout' => 15,
                    'redirection' => 0,
                    'headers' => array(
                        'Accept' => 'application/json',
                        'Cache-Control' => 'no-cache',
                    ),
                    'body' => array(
                        'key'           => (string) $options['superspeedy_field_licensekey'],
                        'wpiplugin'     => (string) $slug,
                        'licensedomain' => (string) $parsedurl['host'],
                        'force'         => !empty($ssp_qs['force']) ? 1 : 0,
                        'v'             => isset($ssp_qs['v']) ? $ssp_qs['v'] : '',
                        'sv'            => $ssp_qs['sv'],
                    ),
                ));
                // Issue #267: remember when we last actually phoned home, so the
                // settings page can show it next to the frequency choice.
                update_option('superspeedy_last_check', time(), false);

                if (is_wp_error($keycheckresponse)) {
                    $api_resp = (object) array('error' => $keycheckresponse->get_error_message());
                } else {
                    $api_resp = json_decode(wp_remote_retrieve_body($keycheckresponse));
                    if (!is_object($api_resp)) {
                        $api_resp = (object) array('error' => 'License server returned an invalid response.');
                    }
                }
        //        echo "<pre>" . print_r($api_resp, true) . "</pre>";
    
        //        $api_resp = array('activated' => true, 'error' => 'No message');
                if (isset($api_resp->activated) && (bool) $api_resp->activated == true) {
                    $data = array('error' => 'valid', 'message' => 'Plugin installed with valid key');
                    set_transient('superspeedy_l_' . $slug, $data, $this->check_ttl(2 * HOUR_IN_SECONDS));
                    return $data;
                }
                if (isset($api_resp) && $api_resp->error == 'invalid') {
                    $data = array('error' => 'invalid', 'message' => 'Enter a valid license key to enable updates for Super Speedy plugins.');
                    set_transient('superspeedy_l_' . $slug, $data, $this->check_ttl(2 * HOUR_IN_SECONDS));
                    return $data;
                }
                if (isset($api_resp) && $api_resp->error == 'expired') {
                    $data = array('error' => 'expired', 'message' => 'License expired - please purchase a license extension to enable updates.');
                    set_transient('superspeedy_l_' . $slug, $data, $this->check_ttl(2 * HOUR_IN_SECONDS));
                    return $data;
                }
                if (isset($api_resp) && $api_resp->error == 'exceeded') {
                    $data = array('error' => 'exceeded', 'message' => 'Website limit exceeded - please purchase additional licenses or deactivate a previous website license to enable updates.');
                    set_transient('superspeedy_l_' . $slug, $data, $this->check_ttl(2 * HOUR_IN_SECONDS));
                    return $data;
                }
            } else {
                return array('error' => 'invalid', 'message' => 'Host URL cannot be identified.');
            }
    
    //        return array('error' => $api_resp->error, 'message' => 'Enter a valid license key to enable updates for WP Intense plugins.');
            $data = array('error' => $api_resp->error, 'message' => $api_resp->error);
            set_transient('superspeedy_l_' . $slug, $data, $this->check_ttl(HOUR_IN_SECONDS));
    
            return array('error' => $api_resp->error, 'message' => $api_resp->error);
        }
        public function add_license_to_remote_update_request($update) {
            if (!isset($update->download_url)) return $update;

            // A free plugin with an approved public package is downloaded from that exact
            // url and carries NO licence credentials. The feed still supplies the version,
            // so the site is told what to install by superspeedyplugins.com and fetches it
            // from the public release. Without this the free plugins inherit the licensed
            // download url and a keyless site gets a 403 where it used to get an update.
            $ssp_free_slug = isset($update->slug) ? (string) $update->slug : '';
            if ($ssp_free_slug && class_exists('SSP_MCP') && method_exists('SSP_MCP', 'public_packages')) {
                $ssp_public = SSP_MCP::public_packages();
                if (isset($ssp_public[$ssp_free_slug])) {
                    $update->download_url = (string) $ssp_public[$ssp_free_slug];
                    return $update;
                }
            }

            // ALWAYS append the (non-secret) licence parameters. This used to be gated
            // on the call stack containing wp_update_plugins / wp_ajax_update_plugin,
            // which left every other update flow (auto-updates, bulk updates,
            // WP-CLI) with a BARE download URL - and the auth server's legacy v1
            // path served bare URLs with no licence check at all, so those flows
            // updated free forever. The v1 path is now closed server-side.
            //
            // The licence KEY itself is not in the URL: add_license_header_to_package_request()
            // sends it as the X-SSP-License-Key header when the package is fetched.

            $options = get_option( 'superspeedy_options' );
    
            if (!$options) {
                $options = get_option('wpintense_options');
                if ($options) {
                    update_option('superspeedy_options', $options);
                }
            }
    
    
            if (!isset($options['superspeedy_field_licensekey'])) {
                $update->upgrade_notice = 'Enter a valid license key to enable updates for Super Speedy plugins.';
            }
            // Your domain
            $domain = 'domain_not_found';
            $homeurl = get_home_url();
            $parsedurl = parse_url($homeurl);
            if (isset($parsedurl['host']) && !empty($parsedurl['host'])) {
                $domain = $parsedurl['host'];
            }
            if ($domain == 'localhost') {
                $domain = 'lh';
            }
            // Installed build (v=) and shared settings-code version (sv=).
            $upd_slug = isset($update->slug) ? $update->slug : '';
            $installed_version = '';
            if ($upd_slug) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                foreach (get_plugins() as $upd_file => $upd_data) {
                    if (strpos($upd_file, $upd_slug . '/') === 0) {
                        $installed_version = $upd_data['Version'];
                        break;
                    }
                }
            }
            // Append these parameters to the download_url. No license_key: see above.
            $update->download_url = add_query_arg(array(
                'domain'      => $domain,
                'license_version' => 2,
                'v'           => $installed_version,
                'sv'          => self::SETTINGS_VERSION,
            ), $update->download_url);
    
    //            $update->download_url = str_replace('https://www.superspeedyplugins.com', 'https://www.superspeedypluginsXASDASD.com', $update->download_url);
            return $update;
        }
        /**
         * Is this the licence-checked package endpoint on the auth server?
         *
         * Exactly https://auth.superspeedyplugins.com[:443]/[wp-json/]secure-wpi/v1/download/<slug>,
         * the only url every <slug>.json download_url may hold. Nothing else ever
         * receives the licence header.
         */
        public static function is_licensed_package_url( $url ) {
            $parts = wp_parse_url( (string) $url );
            return is_array( $parts )
                && isset( $parts['scheme'], $parts['host'], $parts['path'] )
                && 'https' === strtolower( $parts['scheme'] )
                && self::LICENSED_PACKAGE_HOST === strtolower( $parts['host'] )
                && ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] )
                && ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
                && 1 === preg_match( '#^/(?:wp-json/)?secure-wpi/v1/download/[A-Za-z0-9-]+/?$#', $parts['path'] );
        }

        /** The licence key entered on this site, or '' when none is. */
        public static function stored_license_key() {
            $options = get_option( 'superspeedy_options' );
            if ( is_array( $options ) && isset( $options['superspeedy_field_licensekey'] ) ) {
                return trim( (string) $options['superspeedy_field_licensekey'] );
            }
            return '';
        }

        /**
         * http_request_args: authorise a package download with the X-SSP-License-Key
         * header instead of a license_key query parameter.
         *
         * Redirects are switched off for this request: WpOrg\Requests re-sends the
         * original headers to a redirect target, so following one would hand the key
         * to wherever it pointed. The auth endpoint streams the zip itself and never
         * redirects, so a redirect here is a fault and surfaces as a failed download.
         */
        public function add_license_header_to_package_request( $args, $url ) {
            if ( ! self::is_licensed_package_url( $url ) ) {
                return $args;
            }
            $args['redirection'] = 0;
            $key = self::stored_license_key();
            if ( '' === $key ) {
                return $args; // The auth server refuses it as "Licence key not recognised".
            }
            $headers = isset( $args['headers'] ) ? $args['headers'] : array();
            if ( ! is_array( $headers ) ) {
                $headers = '' === (string) $headers ? array() : WP_Http::processHeaders( (string) $headers )['headers'];
            }
            $headers[ self::LICENSE_HEADER ] = $key;
            $args['headers'] = $headers;
            return $args;
        }
        function get_changes_since_version($plugin, $version) {
            // Check if data is already in the cache
            $transient_key = 'superspeedy_changes_' . $plugin . '_' . $version;
            if (($changes = get_transient($transient_key)) !== false) {
                return $changes;
            }
            // Open the changelog file
            $response = $this->remote_get('https://www.superspeedyplugins.com/assets/plugins/' . $plugin . '/readme.txt?v=' . $version);

            // Check if there was an error in retrieving the content. Issue #165:
            // a non-200 (e.g. a Cloudflare challenge page) is NOT a WP_Error but
            // its HTML body must not be parsed as a readme - treat it as a
            // failed check too.
            if (!$this->is_valid_changelog_response($response)) {
                $result = ["count" => 0, "changes" => 0];
                // Store failed retrieval for 1 hour
                set_transient($transient_key, $result, $this->check_ttl(1 * HOUR_IN_SECONDS));
                return false; // failed to check for updates
            }

            $file_content = wp_remote_retrieve_body($response);
            $lines = array_map('trim', explode("\n", $file_content));
    
            //todo: need to configure cloudflare & nginx so .txt files are not cached if they have a version number        
            // Initialize variables
            $changes = [];
            $count = 0;
            $record = false;
    
            // Skip the early lines to only look at the changelog
            $changelog_position = array_search("== Changelog ==", $lines);
            if ($changelog_position !== false) {
                $lines = array_slice($lines, $changelog_position + 1);
            }
            // Read the file line by line
            foreach ($lines as $line) {
                // Trim the line to remove leading/trailing whitespace
                $line = trim($line);
    
                // Check if the line is a version line
                if (strpos($line, '=') === 0 && strpos($line, '==') !== 0) {
                    // Extract the version number from the line
                    preg_match('/\d+(?:\.\d+)+(?:[-+][0-9A-Za-z.-]+)?/', $line, $matches);
                    $line_version = $matches[0] ?? null;
                    if ( null === $line_version ) {
                        continue;
                    }
                    if ( version_compare( $line_version, (string) $version, '<=' ) ) {
                        break;
                    }
                    
                    // Start recording changes if we've passed the version in the changelog
                    $record = true;
                }
                
                // Record the change
                if ($record && strpos($line, '*') === 0) {
                    $changes[] = $line;
                    $count++;
                }
            }
    
            // Prepare result
            $result = ["count" => $count, "changes" => $changes];
            // Store data in cache, keep it for 8 hours
            set_transient($transient_key, $result, $this->check_ttl(8 * HOUR_IN_SECONDS));
            return $result;
        }
        function get_changes_since_days($plugin, $days) {
            $days = absint( $days );
            if ( $days < 1 ) {
                return false;
            }
                // Check if data is already in the cache
            $transient_key = 'superspeedy_changes_days_' . $plugin . '_' . $days;
            if (($changes = get_transient($transient_key)) !== false) {
                return $changes;
            }

            $response = $this->remote_get('https://www.superspeedyplugins.com/assets/plugins/' . $plugin . '/readme.txt');

            // Check if there was an error in retrieving the content (issue #165:
            // non-200 challenge pages count as failure, same as above)
            if (!$this->is_valid_changelog_response($response)) {
                return false; // failed to check for updates
            }

            $file_content = wp_remote_retrieve_body($response);
            $lines = array_map('trim', explode("\n", $file_content));
            
            // Initialize variables
            $changes = [];
            $count = 0;
            $record = false;

            // Calculate the date X days ago
            $date_x_days_ago = new DateTimeImmutable( 'today', wp_timezone() );
            $date_x_days_ago = $date_x_days_ago->sub(new DateInterval("P{$days}D"));

            // Skip the early lines to only look at the changelog
            $changelog_position = array_search("== Changelog ==", $lines);
            if ($changelog_position !== false) {
                $lines = array_slice($lines, $changelog_position + 1);
            }
            // Read the file line by line
            foreach ($lines as $line) {
                // Trim the line to remove leading/trailing whitespace
                $line = trim($line);
                

                // Check if the line is a version line
                if (strpos($line, '=') === 0 && strpos($line, '==') !== 0) {
                    // Extract the date from the line
                    if ( ! preg_match( '/\((\d{1,2}(?:st|nd|rd|th) [A-Za-z]+ \d{4})\)/', $line, $date_match ) ) {
                        continue;
                    }
                    $line_date = DateTimeImmutable::createFromFormat( '!jS F Y', $date_match[1], wp_timezone() );
                    $date_errors = DateTimeImmutable::getLastErrors();
                    if ( false === $line_date || ( is_array( $date_errors ) && ( $date_errors['warning_count'] || $date_errors['error_count'] ) ) ) {
                        continue;
                    }

                    // If we've reached a date that is X days ago or more, stop recording changes
                    if ($line_date < $date_x_days_ago) {
                        break;
                    }

                    // Start recording changes if we've passed the date in the changelog
                    $record = true;
                }
                
                // Record the change
                if ($record && strpos($line, '*') === 0) {
                    $changes[] = $line;
                    $count++;
                }
            }

            // Prepare result
            $result = ["count" => $count, "changes" => $changes];

            // Store data in cache, keep it for 24 hours
            set_transient($transient_key, $result, $this->check_ttl(24 * HOUR_IN_SECONDS));

            return $result;

        }
        function remote_get($url, $args = array()) {
            // Create a unique transient key based on the URL
            $transient_key = 'ssp_' . md5($url);
        
            // Try to get the content from the transient
            $cached_content = get_transient($transient_key);
        
            if ($cached_content !== false) {
                return $cached_content;
            }
        
            // If not in transient, fetch the content
            $response = wp_remote_get($url, $args);

            // Cache the response, whether it's a WP_Error or not
            set_transient($transient_key, $response, $this->check_ttl(2 * HOUR_IN_SECONDS));
        
            return $response;
        }
        public function is_valid_changelog_response( $response ) {
            if ( ! is_array( $response ) || is_wp_error( $response ) ) {
                return false;
            }
            if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return false;
            }
            $body = wp_remote_retrieve_body( $response );
            if ( ! is_string( $body ) || '' === trim( $body ) || strlen( $body ) > 2 * MB_IN_BYTES ) {
                return false;
            }
            $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
            if ( false !== strpos( $content_type, 'text/html' ) ) {
                return false;
            }
            return true;
        }
        public function handle_wp_error_added($code, $message, $data, $object) {
            // Only act on array data carrying a JSON body.
            if (!is_array($data) || !isset($data['body']) || !is_string($data['body'])) {
                return;
            }

            // Idempotency: if this error already carries our settings-page link,
            // don't re-enhance it (prevents appending the link twice).
            if (is_string($message) && strpos($message, 'page=superspeedy') !== false) {
                return;
            }

            // Scope: when the request URL is available, only enhance errors from
            // our own license/update server. Other plugins' HTTP errors (e.g. GitHub
            // API rate limits) should be left untouched.
            if (isset($data['url']) && is_string($data['url'])
                && strpos($data['url'], 'superspeedyplugins.com') === false) {
                return;
            }

            $json = json_decode($data['body']);
            if ($json === null || !isset($json->message)) {
                return;
            }

            $clean_message = esc_html( wp_strip_all_tags( (string) $json->message ) );
            $settings_url = admin_url('admin.php?page=superspeedy');
            $clean_message .= ' <a href="' . esc_url($settings_url) . '">Go to Super Speedy Settings to enter your license key</a>.';

            // WP_Error::add() re-fires wp_error_added, so unhook before mutating
            // to avoid infinite recursion, then rehook afterwards.
            remove_action('wp_error_added', array($this, 'handle_wp_error_added'), 10);
            $object->remove($code);
            $object->add($code, $clean_message, $data);
            add_action('wp_error_added', array($this, 'handle_wp_error_added'), 10, 4);
        }
        public function enqueue_admin_script( $hook ) {
            wp_enqueue_script('super_speedy_updates', plugin_dir_url(__FILE__) . 'super-speedy-updates.js', array('jquery'), filemtime(__DIR__ . '/super-speedy-updates.js'), true);
            // add super-speedy-settings/admin.css to the admin page but ONLY on our admin page
            if ($hook == 'toplevel_page_superspeedy' || $hook == 'super-speedy_page_superspeedy-mcp-setup') {
                // Atlas icon packs for the service panels - bundled locally (no external requests).
                foreach (array('basic-ui', 'school', 'arrow') as $ssp_pack) {
                    wp_enqueue_style('ssp-atlas-' . $ssp_pack, plugin_dir_url(__FILE__) . 'packs/' . $ssp_pack . '/style.css', array(), '1.0.0');
                }
                wp_enqueue_style('super_speedy_admin', plugin_dir_url(__FILE__) . 'admin.css', array(), filemtime(__DIR__ . '/admin.css'));
                wp_enqueue_script('super_speedy_settings', plugin_dir_url(__FILE__) . 'super-speedy-settings.js', array('jquery'), filemtime(__DIR__ . '/super-speedy-settings.js'), true);
                wp_localize_script('super_speedy_settings', 'superspeedy_ajax', array(
                    'nonce' => wp_create_nonce('superspeedy_recheck_license')
                ));
            }
            if ($hook == 'super-speedy_page_superspeedy-mcp-setup') {
                wp_enqueue_script('super_speedy_mcp_setup', plugin_dir_url(__FILE__) . 'mcp-setup.js', array('jquery'), self::SETTINGS_VERSION, true);
                wp_localize_script('super_speedy_mcp_setup', 'superspeedy_mcp', array(
                    'nonce'      => wp_create_nonce('superspeedy_mcp_setup'),
                    'generate'   => __('Generate connection commands', 'super-speedy-settings'),
                    'generating' => __('Generating…', 'super-speedy-settings'),
                    'replace'    => __('Replace connection commands', 'super-speedy-settings'),
                    'copied'     => __('Copied', 'super-speedy-settings'),
                    'error'      => __('The connection command could not be generated.', 'super-speedy-settings'),
                ));
            }
        }
    
    }

}

/*todo: 
1. Move all items below into the new class
2. Also, re: spro - test removing cast on postmeta to see if that speeds up foundthru price filter - check it's still correct too
*/

// Replay the plugin registrations the frozen facade queued before this core
// loaded, and keep draining for plugins that register later in the request
// (e.g. a plugin loaded at activation time, after plugins_loaded).
if (!function_exists('ssp_settings_core_drain_queue')) {
    function ssp_settings_core_drain_queue() {
        if (empty($GLOBALS['ssp_settings_queue']) || !is_array($GLOBALS['ssp_settings_queue'])) {
            return;
        }
        $ssp_queue = $GLOBALS['ssp_settings_queue'];
        $GLOBALS['ssp_settings_queue'] = array();
        foreach ($ssp_queue as $ssp_plugin_info) {
            SuperSpeedySettingsCore::init($ssp_plugin_info);
        }
    }
}
ssp_settings_core_drain_queue();
add_action('ssp_settings_plugin_registered', 'ssp_settings_core_drain_queue');
