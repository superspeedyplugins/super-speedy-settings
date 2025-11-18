<?php

require_once 'abstract-class-super-speedy-settings.php';
require 'plugin-update-checker/plugin-update-checker.php';
use SuperSpeedy\PluginUpdateChecker\v5\PucFactory;
if (!class_exists('SuperSpeedySettings_1_0')) {
    class SuperSpeedySettings_1_0 extends SuperSpeedySettings {
        protected static $version = 1.0;
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
                <div style="padding:10px;"><img src="<?php echo plugins_url('super-speedy-plugins.svg', __FILE__); ?>"></div>
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'superspeedy' );
                    do_settings_sections( 'superspeedy' );
                    $this->display_license_table();

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
                    $slug
                );
                add_filter('puc_pre_inject_update-' . $slug, array($this, 'add_license_to_remote_update_request'));
            }
    
        }
        public function handle_recheck_license() {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'superspeedy_recheck_license')) {
                wp_send_json_error('Invalid nonce');
            }
        
            // Get the current license key from the AJAX request
            $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
            
            $options = get_option('superspeedy_options', array());
            $options['superspeedy_field_licensekey'] = $license_key;
            update_option('superspeedy_options', $options);
        
            // Delete all license transients
            $this->delete_license_cache();
        
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
                'superspeedy_field_licensekey', // As of WP 4.6 this value is used only internally.
                                        // Use $args' label_for to populate the id inside the callback.
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
        }
        public function settings_intro() {
            ?>
            <p>Enter your license key below to enable plugin updates. </p>
            <ul style="list-style:disc; margin-left:20px;"><li>
                Your license key enables updates for your plugins. You can find your license key in your <a href="https://www.superspeedyplugins.com/my-account/?utm_source=license_checker&utm_content=plugin"> account page</a>.
                </li>
            <li>You can renew your license and lock in 25%-off whatever you paid for your first year. <a href="https://www.superspeedyplugins.com/my-account/renewals/?utm_source=license_checker&utm_content=plugin">Renew your license here</a>.</li>
            <li>You can also upgrade your license to allow more websites and only pay the difference. <a href="https://www.superspeedyplugins.com/my-account/upgrade-account/?utm_source=license_checker&utm_content=plugin">Upgrade your license here</a>.</li>
            </ul>
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
    
    
            echo '<table class="superspeedy-plugins">';
            echo '<thead><tr><th>Plugin Name</th><th>Installation Status</th><th>License Status</th><th>Changes</th><th>Docs</th><th>Live Support</th></tr></thead>';
            echo '<tbody>';
            
            foreach($superspeedy_licenses as $slug => $ssp_license) {
                $license = $this->check_license($slug);
                if ($license['error'] == 'not installed') {
                    echo '<tr class="not-installed">';
                } else {
                    if (!function_exists($ssp_license['function'])) {
                        echo '<tr class="deactivated">';
                    } else {
                        echo '<tr class="activated">';
                    }
                }
                echo '<td>' . $ssp_license['name'] . '</td>';
                
    
                if ($license['error'] == 'not installed') {
                    echo '<td>Not installed</td>';
                    echo '<td>Not checked</td>';
                } else {
                    $active = function_exists($ssp_license['function']) ? "Activated" : "Deactivated";
                    echo "<td>Installed / $active</td>";
                    echo '<td>' . $license['error'] . '</td>';
                }
            
                if (isset($ssp_license['version'])) {
                    $changes = $this->get_changes_since_version($slug, $ssp_license['version']);
                    if (!$changes) {
                        echo '<td>Unable to check for updates - please check your server allows remote URL requests</td>';
                    } else {
                        echo '<td>' . $changes["count"] . ' changes since your last update<br /><a href="https://www.superspeedyplugins.com/support/changelogs/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin" target="_blank">View Change log</a>';
                        if ($changes["count"] > 0) {
                            echo ' | <a href="' . $update_plugin_url . $slug . '">Update Plugin</a>';
                        }
                        echo '</td>';
                    }
                } else {
                    $changes = $this->get_changes_since_days($slug, 90);
                    echo '<td>' . $changes["count"] . ' changes in the last 90 days<br /><a href="https://www.superspeedyplugins.com/support/changelogs/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin" target="_blank">View Change log</a></td>';
                }
                echo '<td><a href="https://www.superspeedyplugins.com/kb/' . $slug . '/?utm_source=' . urlencode($utm_source) . '&utm_content=plugin" target="_blank">Knowledge Base</a></td>';
                echo '<td><a href="' . $ssp_license['discord'] . '" target="_blank">Discord Channel</a></td>';
            
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
                    value="<?php esc_html_e($license_key);?>">
            <button type="button" id="recheck-license" class="button">Recheck now</button>
    
            <?php
        }
        function check_license($slug) {
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
            //todo: add back in once checks properly tested
            if (get_transient('superspeedy_l_' . $slug)) {
                return get_transient('superspeedy_l_' . $slug);
            }
            $homeurl = get_home_url();
            $parsedurl = parse_url($homeurl);
            if (isset($parsedurl['host']) && !empty($parsedurl['host']) && isset($options['superspeedy_field_licensekey']) && !empty($options['superspeedy_field_licensekey']) && !empty($slug)) {
                $encoded_licensekey = urlencode($options['superspeedy_field_licensekey']);

                $keycheckurl = 'https://www.superspeedyplugins.com/wp-json/wpiapi/check_product_key/' . $encoded_licensekey . '/' . $slug . '/' . $parsedurl['host'] . '/';
//                if ($homeurl == "https://localhost") {
//                    $keycheckurl = 'https://localhost/wp-json/wpiapi/check_product_key/' . $encoded_licensekey . '/' . $slug . '/' . $parsedurl['host'] . '/';
//                }
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
                curl_setopt($curl, CURLOPT_URL, $keycheckurl);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($curl, CURLOPT_HEADER, FALSE);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
                curl_setopt($curl, CURLOPT_TIMEOUT,60);
    
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                "Accept: application/json", 
                "Cache-Control: no-cache"
                ));
                $keycheckdata = curl_exec($curl);
                curl_close($curl);
    
                //echo "<pre>" . print_r($keycheckdata, true) . "</pre>";
    
                $api_resp = json_decode($keycheckdata);
        //        echo "<pre>" . print_r($api_resp, true) . "</pre>";
    
        //        $api_resp = array('activated' => true, 'error' => 'No message');
                if (isset($api_resp->activated) && (bool) $api_resp->activated == true) {
                    $data = array('error' => 'valid', 'message' => 'Plugin installed with valid key');
                    set_transient('superspeedy_l_' . $slug, $data, WEEK_IN_SECONDS);
                    return $data;
                }
                if (isset($api_resp) && $api_resp->error == 'invalid') {
                    $data = array('error' => 'invalid', 'message' => 'Enter a valid license key to enable updates for Super Speedy plugins.');
                    set_transient('superspeedy_l_' . $slug, $data, WEEK_IN_SECONDS);
                    return $data;
                }
                if (isset($api_resp) && $api_resp->error == 'expired') {
                    $data = array('error' => 'expired', 'message' => 'License expired - please purchase a license extension to enable updates.');
                    set_transient('superspeedy_l_' . $slug, $data, WEEK_IN_SECONDS);
                    return $data;
                }
                if (isset($api_resp) && $api_resp->error == 'exceeded') {
                    $data = array('error' => 'exceeded', 'message' => 'Website limit exceeded - please purchase additional licenses or deactivate a previous website license to enable updates.');
                    set_transient('superspeedy_l_' . $slug, $data, WEEK_IN_SECONDS);
                    return $data;
                }
            } else {
                return array('error' => 'invalid', 'message' => 'Host URL cannot be identified.');
            }
    
    //        return array('error' => $api_resp->error, 'message' => 'Enter a valid license key to enable updates for WP Intense plugins.');
            $data = array('error' => $api_resp->error, 'message' => $api_resp->error);
            set_transient('superspeedy_l_' . $slug, $data, HOUR_IN_SECONDS);
    
            return array('error' => $api_resp->error, 'message' => $api_resp->error);
        }
        public function add_license_to_remote_update_request($update) {
            //this function is DEFINITELY called to check for updates but MAYBE not to get the download link - so this should always work
            if (!isset($update->download_url)) return $update;
            $e = new \Exception;
            if (strpos($e->getTraceAsString(), 'wp_update_plugins') === false) return $update;
            if (strpos($e->getTraceAsString(), 'wp_ajax_update_plugin') === false) return $update;
    //        if (strpos($e->getTraceAsString(), 'Plugin_Upgrader->bulk_upgrade') === false) return $update;
            
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
            // Append these parameters to the download_url
            $update->download_url = add_query_arg(array(
                'license_key' => $license_key,
                'domain'      => $domain,
                'license_version' => 2
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
    
            // Check if there was an error in retrieving the content
            if (is_wp_error($response)) {
                $result = ["count" => 0, "changes" => 0];
                // Store failed retrieval for 1 hour
                set_transient($transient_key, $result, 1 * HOUR_IN_SECONDS);
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
            set_transient($transient_key, $result, 8 * HOUR_IN_SECONDS);
            return $result;
        }
        function get_changes_since_days($plugin, $days) {
                // Check if data is already in the cache
            $transient_key = 'superspeedy_changes_days_' . $plugin . '_' . $days;
            if (($changes = get_transient($transient_key)) !== false) {
                return $changes;
            }

            $response = $this->remote_get('https://www.superspeedyplugins.com/assets/plugins/' . $plugin . '/readme.txt');

            // Check if there was an error in retrieving the content
            if (is_wp_error($response)) {
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
            set_transient($transient_key, $result, 24 * HOUR_IN_SECONDS);

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
            set_transient($transient_key, $response, 2 * HOUR_IN_SECONDS);
        
            return $response;
        }
        public function handle_wp_error_added($code, $message, $data, $object) {

            // check if body key exists and check if it's json format
    
            if (is_array($data) && isset($data['body']) && is_string($data['body'])) {
                $json = json_decode($data['body']);
                if ($json !== null) {
                    // if $json -> code exists && $json -> message exists, $json -> data exists
                    $code = isset($json->code) ? $json->code : $code;
                    $message = isset($json->message) ? $json->message : $message;
                    if ($code && $message) {
                        add_settings_error('superspeedy_messages', 'superspeedy_message', $message, 'error');
                        echo '';
                        echo "Error Code: " . esc_html($code) . "\n";
                        echo "Error Message: " . esc_html($message) . "\n";
                        echo "\n\n";
                        echo '';
    
                    }
                }
            }
        }
        public function enqueue_admin_script( $hook ) {
            wp_enqueue_script('super_speedy_updates', plugin_dir_url(__FILE__) . 'super-speedy-updates.js', array('jquery'), time(), true);
            // add super-speedy-settings/admin.css to the admin page but ONLY on our admin page
            if ($hook == 'toplevel_page_superspeedy') {
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




add_action('admin_head', function() {
    ?>
    <style>
    #adminmenu li#toplevel_page_superspeedy .update-plugins {
        background-color:#8035be;
        border:1px solid black;
        line-height:1.3em;
    }

    /* Add the lightning flash animation on hover */
    #toplevel_page_superspeedy:hover .wp-menu-image img {
       animation: lightning-flash 1.5s infinite;
    }
    
    /* Define the lightning flash keyframes */
    @keyframes lightning-flash {
        0% {
            filter: brightness(1) drop-shadow(0 0 0 rgba(255, 255, 0, 0));
        }
        /* First flash */
        15% {
            filter: brightness(2) drop-shadow(0 0 5px rgba(255, 255, 0, 0.8));
            fill: rgba(255, 255, 0, 0.8);

        }
        30% {
            filter: brightness(1) drop-shadow(0 0 0 rgba(255, 255, 0, 0));
        }
        /* Second flash */
        45% {
            filter: brightness(1.7) drop-shadow(0 0 3px rgba(255, 255, 0, 0.6));
        }
        60% {
            filter: brightness(1) drop-shadow(0 0 0 rgba(255, 255, 0, 0));
        }
        /* Small afterglow */
        75% {
            filter: brightness(1.3) drop-shadow(0 0 2px rgba(255, 255, 0, 0.4));
        }
        100% {
            filter: brightness(1) drop-shadow(0 0 0 rgba(255, 255, 0, 0));
        }
    }
    </style>
    <?php
});