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

require_once __DIR__ . '/abstract-class-super-speedy-settings.php';
require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

// Shared `ssp` ability surface (licence status, and installing the rest of the range
// from this site's own licence). Lives here so one implementation reaches every Super
// Speedy plugin on its next settings sync; SSP_MCP::init() guards against the nine
// copies registering nine times. Version-gated on the core Abilities API.
if ( file_exists( __DIR__ . '/class-ssp-mcp.php' ) ) {
    require_once __DIR__ . '/class-ssp-mcp.php';
    if ( function_exists( 'wp_register_ability' ) && class_exists( 'SSP_MCP' ) ) {
        SSP_MCP::init();
    }
}
// AI Engine aliases (SSP_AI_Engine): mirrors the ssp abilities - and any plugin
// namespace registered with it - onto AI Engine's MCP endpoint. Pure filters, so
// inert unless AI Engine is active. Must load BEFORE plugins instantiate
// SuperSpeedy_MCP_Config, which registers its namespace with the adapter.
if ( file_exists( __DIR__ . '/class-ssp-ai-engine.php' ) ) {
    require_once __DIR__ . '/class-ssp-ai-engine.php';
    if ( function_exists( 'wp_register_ability' ) && class_exists( 'SSP_AI_Engine' ) ) {
        SSP_AI_Engine::init();
    }
}
// Shared config base (SuperSpeedy_MCP_Config): opt-in <slug>/get-config +
// <slug>/update-config for any plugin that instantiates it with its settings
// registry. Defining the class activates nothing by itself.
if ( file_exists( __DIR__ . '/class-superspeedy-mcp-config.php' )
    && function_exists( 'wp_register_ability' ) ) {
    require_once __DIR__ . '/class-superspeedy-mcp-config.php';
}
use SuperSpeedy\PluginUpdateChecker\v5\PucFactory;
if (!class_exists('SuperSpeedySettingsCore')) {
    class SuperSpeedySettingsCore extends SuperSpeedySettings {
        protected static $version = 1.5;
        // Version of the shared super-speedy-settings code itself, sent to auth as
        // &sv= (distinct from a plugin's own build, sent as v=). Lets auth see which
        // sites run stale settings code (e.g. still checking www, or missing a param).
        // Bump this whenever the settings code changes in a way worth tracking.
        const SETTINGS_VERSION = '1.5.6';
        private $update_checkers = array();
        
        
        public function register_menu() {
            $superspeedy_licenses = $this->getLicenses();
            $total_changes = 0;
            // Iterate over licenses array and count total changes
            foreach ($superspeedy_licenses as $slug => $ssp_license) {
                if (isset($ssp_license['version'])) { // checks if the plugin is active
                    $changes = $this->get_changes_since_version($slug, $ssp_license['version']);
                    if ($changes) {
                        $total_changes += $changes['count'];
                    }
                }
            }
            // Generate menu title, add bubble if there are updates
            $menu_title = 'Super Speedy ' . ($total_changes ? ' <span class="update-plugins count-' . $total_changes . '"><span class="plugin-count">' . $total_changes . '</span></span>' : '');
    
            add_menu_page(
                'Super Speedy Settings',
                $menu_title,
                'manage_options',
                self::$menu_slug,
                array($this, 'display_settings_page'),
                plugins_url('super-speedy.png', __FILE__)
            );
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
            $this->ssp_initialize_updaters();
            add_action( 'admin_init', array($this, 'settings_init') );
            add_action('wp_error_added', array($this, 'handle_wp_error_added'), 10, 4);
            add_action( 'admin_enqueue_scripts', array($this, 'enqueue_admin_script'));
            add_action('wp_ajax_superspeedy_recheck_license', array($this, 'handle_recheck_license'));
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
        public function settings_intro() {
            ?>
            <p>Enter your license key below to enable plugin updates. </p>
            <p>Please remember to renew your license to continue to support our small team so we can keep solving these WordPress performance issues.
            <?php 
        }
        public function display_license_table( ) {
            $superspeedy_licenses = $this->getLicenses();
            $site_url = get_site_url();
            $site_url = str_replace('localhost', 'lh', $site_url); // localhost gets banned by GridPane
            $utm_source = parse_url($site_url, PHP_URL_HOST);

            $action = 'puc_check_for_updates';

            // Get the nonce
            $nonce = wp_create_nonce($action);

            // Construct the URL
            $update_plugin_url = admin_url('plugins.php') . "?puc_check_for_updates=1&_wpnonce=" . $nonce . "&puc_slug=";

            // Top-of-table "Recheck Licenses" button — gives users a clear
            // affordance after a renewal/upgrade without scrolling down to the
            // licence-key field. Disabled until a key is entered/saved further
            // down; JS keeps the disabled state in sync as the user types.
            $options    = get_option('superspeedy_options');
            $saved_key  = is_array($options) && !empty($options['superspeedy_field_licensekey']) ? $options['superspeedy_field_licensekey'] : '';
            $btn_disabled = $saved_key === '' ? ' disabled' : '';
            echo '<p class="superspeedy-recheck-row">';
            echo '<button type="button" id="recheck-licenses-top" class="button button-primary"' . $btn_disabled . '>Recheck Licenses</button>';
            echo ' <span class="description recheck-licenses-hint"' . ($saved_key === '' ? '' : ' style="display:none"') . '>Enter your licence key below and save settings to enable.</span>';
            echo '</p>';

            echo '<table class="superspeedy-plugins">';
            echo '<thead><tr><th></th><th>Plugin Name</th><th>Installation Status</th><th>License Status</th><th>Changes</th><th>Docs</th><th>Live Support</th></tr></thead>';
            echo '<tbody>';
            
            foreach($superspeedy_licenses as $slug => $ssp_license) {
                $license = $this->check_license($slug);
                if ($license['error'] == 'not installed') {
                    echo '<tr class="not-installed">';
                } else {
                    if (empty($ssp_license['function']) || !function_exists($ssp_license['function'])) {
                        echo '<tr class="deactivated">';
                    } else {
                        echo '<tr class="activated">';
                    }
                }
                echo '<td><a href="' . esc_url('https://www.superspeedyplugins.com/product/' . $slug . '/') . '" target="_blank"><img src="' . esc_url(plugins_url('images/' . $slug . '.png', __FILE__)) . '" alt="' . esc_attr($ssp_license['name'] ?? '') . '" style="height: 50px; width: auto;"></a></td>';
                echo '<td><span class="plugin-name-bold">' . esc_html($ssp_license['name'] ?? '') . '</span><br><a href="' . esc_url('https://www.superspeedyplugins.com/product/' . $slug . '/') . '" target="_blank" class="plugin-page-link">View ' . esc_html($ssp_license['name'] ?? '') . ' plugin page</a></td>';
                
    
                if ($license['error'] == 'not installed') {
                    echo '<td>Not installed</td>';
                    echo '<td>Not checked</td>';
                } else {
                    $active = (!empty($ssp_license['function']) && function_exists($ssp_license['function'])) ? "Activated" : "Deactivated";
                    echo "<td>Installed / $active</td>";
                    echo '<td>' . esc_html($license['error']) . '</td>';
                }
            
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
            
            echo '</tbody>';
            echo '</table>';
            echo '<p><strong>Please note:</strong> Our plugins must be activated in order to update them. If you wish to update them without activating them, you must download the latest zip from <a href="https://www.superspeedyplugins.com/my-account/downloads/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin">your account</a> and upload the new zip file manually.</p>';
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
                $encoded_licensekey = urlencode($options['superspeedy_field_licensekey']);

                // Licence checks go DIRECT to the auth server. www has no route for
                // this path and returns rest_no_route (404); new clients hit auth.
                $keycheckurl = 'https://auth.superspeedyplugins.com/wp-json/wpiapi/check_product_key/' . $encoded_licensekey . '/' . $slug . '/' . $parsedurl['host'] . '/';
                // Report the installed version (v=) so the server can track which build
                // each site runs, the site admin email (email=, stored server-side in a
                // SEPARATE field - NOT a known customer), and the shared settings-code
                // version (sv=). Harmless query params; older servers ignore them.
                // force=1 bypasses the server cache.
                $ssp_qs = array();
                if ($force) {
                    $ssp_qs['force'] = 1;
                }
                $ssp_licenses = self::getLicenses();
                if (isset($ssp_licenses[$slug]['version']) && $ssp_licenses[$slug]['version'] !== '') {
                    $ssp_qs['v'] = (string) $ssp_licenses[$slug]['version'];
                }
                $ssp_admin_email = get_option('admin_email');
                if ($ssp_admin_email) {
                    $ssp_qs['email'] = $ssp_admin_email;
                }
                $ssp_qs['sv'] = self::SETTINGS_VERSION;
                if (!empty($ssp_qs)) {
                    $keycheckurl .= '?' . http_build_query($ssp_qs);
                }
//                if ($homeurl == "https://localhost") {
//                    $keycheckurl = 'https://localhost/wp-json/wpiapi/check_product_key/' . $encoded_licensekey . '/' . $slug . '/' . $parsedurl['host'] . '/';
//                }
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $keycheckurl);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($curl, CURLOPT_HEADER, FALSE);
                // Issue #165: a challenged/unreachable server must not hang the
                // settings page. 60s here meant up to a minute PER PLUGIN when
                // the auth server could not be reached; fail fast instead - the
                // result is cached either way and the next visit retries.
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_TIMEOUT, 15);
    
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                "Accept: application/json", 
                "Cache-Control: no-cache"
                ));
                $keycheckdata = curl_exec($curl);
                // Issue #267: remember when we last actually phoned home, so the
                // settings page can show it next to the frequency choice.
                update_option('superspeedy_last_check', time(), false);

                //echo "<pre>" . print_r($keycheckdata, true) . "</pre>";
    
                $api_resp = json_decode($keycheckdata);
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
            // ALWAYS append the licence credentials. This used to be gated on the
            // call stack containing wp_update_plugins / wp_ajax_update_plugin,
            // which left every other update flow (auto-updates, bulk updates,
            // WP-CLI) with a BARE download URL - and the auth server's legacy v1
            // path served bare URLs with no licence check at all, so those flows
            // updated free forever. The v1 path is now closed server-side; the
            // params are harmless in non-download contexts (just query args).

            $options = get_option( 'superspeedy_options' );
    
            if (!$options) {
                $options = get_option('wpintense_options');
                if ($options) {
                    update_option('superspeedy_options', $options);
                }
            }
    
    
            $license_key = 'no_license_entered';
            if (isset($options['superspeedy_field_licensekey'])) {
                $license_key = $options['superspeedy_field_licensekey'];
            } else {
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
            // Installed build (v=), shared settings-code version (sv=) and site admin
            // email (email=, stored server-side in a SEPARATE field, never treated as a
            // known customer), so the download path logs the same detail as the check.
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
            // Append these parameters to the download_url
            $update->download_url = add_query_arg(array(
                'license_key' => $license_key,
                'domain'      => $domain,
                'license_version' => 2,
                'v'           => $installed_version,
                'sv'          => self::SETTINGS_VERSION,
                'email'       => get_option('admin_email'),
            ), $update->download_url);
    
    //            $update->download_url = str_replace('https://www.superspeedyplugins.com', 'https://www.superspeedypluginsXASDASD.com', $update->download_url);
            return $update;
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
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
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
                    preg_match("/\d+\.\d+/", $line, $matches);
                    $line_version = $matches[0] ?? null;
    
                    try {
                        // Try to cast versions to floats and perform comparison
                        $line_version_float = (float)$line_version;
                        $version_float = (float)$version;
                    
                        // If any of the versions couldn't be converted to a float, exception will be thrown and this line won't be executed
                        if ($line_version_float <= $version_float) {
                            break;
                        }
                    } catch (Exception $e) {
                        // An error occurred while converting the versions to floats, ignore and continue
                        continue;
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
                // Check if data is already in the cache
            $transient_key = 'superspeedy_changes_days_' . $plugin . '_' . $days;
            if (($changes = get_transient($transient_key)) !== false) {
                return $changes;
            }

            $response = $this->remote_get('https://www.superspeedyplugins.com/assets/plugins/' . $plugin . '/readme.txt');

            // Check if there was an error in retrieving the content (issue #165:
            // non-200 challenge pages count as failure, same as above)
            if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
                return false; // failed to check for updates
            }

            $file_content = wp_remote_retrieve_body($response);
            $lines = array_map('trim', explode("\n", $file_content));
            
            // Initialize variables
            $changes = [];
            $count = 0;
            $record = false;

            // Calculate the date X days ago
            $date_x_days_ago = new DateTime();
            $date_x_days_ago->sub(new DateInterval("P{$days}D"));

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
                    $date_start = strpos($line, '(') + 1;
                    $date_length = strpos($line, ')') - $date_start;
                    $line_date_str = substr($line, $date_start, $date_length);
                    $line_date = DateTime::createFromFormat('dS F Y', $line_date_str);

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

            $clean_message = $json->message;
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
            wp_enqueue_script('super_speedy_updates', plugin_dir_url(__FILE__) . 'super-speedy-updates.js', array('jquery'), time(), true);
            // add super-speedy-settings/admin.css to the admin page but ONLY on our admin page
            if ($hook == 'toplevel_page_superspeedy') {
                // Atlas icon packs for the service panels - bundled locally (no external requests).
                foreach (array('basic-ui', 'school', 'arrow') as $ssp_pack) {
                    wp_enqueue_style('ssp-atlas-' . $ssp_pack, plugin_dir_url(__FILE__) . 'packs/' . $ssp_pack . '/style.css', array(), '1.0.0');
                }
                wp_enqueue_style('super_speedy_admin', plugin_dir_url(__FILE__) . 'admin.css', array(), time());
                wp_enqueue_script('super_speedy_settings', plugin_dir_url(__FILE__) . 'super-speedy-settings.js', array('jquery'), time(), true);
                wp_localize_script('super_speedy_settings', 'superspeedy_ajax', array(
                    'nonce' => wp_create_nonce('superspeedy_recheck_license')
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
