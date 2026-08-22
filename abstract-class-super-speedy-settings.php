<?php
if (!class_exists('SuperSpeedySettings')) {
    abstract class SuperSpeedySettings {
        protected static $instance = null;
        protected static $version = 1.0;
        protected static $menu_slug = 'superspeedy';
        private static $superspeedy_licenses = array();
        /**
         * The range, for the settings page. Performance Analysis is deliberately
         * NOT here: it is free, it is the diagnostic that points at everything
         * else, and it gets its own panel at the top of the page rather than a
         * row in a licence table. See display_performance_analysis_panel().
         */
        protected static $base_plugin_info = array(
            'scalability-pro' => array('name' => 'Scalability Pro', 'function' => 'wpi_getIndexes', 'discord' => 'https://discord.gg/f8BZxBPkMg'),
            'super-speedy-imports' => array('name' => 'Super Speedy Imports', 'function' => 'super_speedy_imports_activate', 'discord' => 'https://discord.gg/nYhTqmJjBQ'),
            'delayed-image-import' => array('name' => 'Delayed Image Import', 'function' => 'delayed_image_import_active', 'discord' => 'https://discord.com/channels/837258064704438282/1288689363391217677'),
            'super-speedy-search' => array('name' => 'Super Speedy Search', 'function' => 'sss_update_db_check', 'discord' => 'https://discord.gg/acmgV8SaAd'),
            'auto-infinite-scroll' => array('name' => 'Auto Infinite Scroll', 'function' => 'ais_active', 'discord' => 'https://discord.gg/eJFa36gfXG'),
            'external-images' => array('name' => 'External Images', 'function' => 'external_images_scripts', 'discord' => 'https://discord.gg/m3jN2pkeFT'),
            'super-speedy-filters' => array('name' => 'Super Speedy Filters', 'function' => 'fww_update_db_check', 'discord' => 'https://discord.gg/wFmJaNCeyY'),
            'super-speedy-sitemaps' => array('name' => 'Super Speedy Sitemaps', 'function' => 'sssm_active', 'discord' => 'https://discord.com/channels/837258064704438282/1209821217624952895'),
            'super-speedy-ajax-prices' => array('name' => 'Super Speedy Ajax Prices', 'function' => 'wc_ajax_pricing_init', 'discord' => 'https://discord.com/channels/837258064704438282/1476527369329049661'),
            'super-speedy-chat' => array('name' => 'Super Speedy Chat', 'function' => 'ssc_init_plugin', 'discord' => 'https://discord.gg/zDqg2UX9wp'),
            'super-speedy-coming-soon' => array('name' => 'Super Speedy Coming Soon', 'function' => 'sscs_maybe_replace_add_to_cart', 'discord' => 'https://discord.gg/zDqg2UX9wp'),
            'super-speedy-emails' => array('name' => 'Super Speedy Emails', 'function' => 'sse_init', 'discord' => 'https://discord.gg/zDqg2UX9wp'),
        );

        protected static function getVersion() {
            return static::$version;
        }
        protected static function delete_license_cache() {
            $superspeedy_licenses = self::getLicenses();
            
            // Iterate through all plugins and delete their license transients
            foreach($superspeedy_licenses as $slug => $ssp_license) {
                $transient_key = 'superspeedy_l_' . $slug;
                delete_transient($transient_key);
            }
        }
        protected static function getLicenses() {
            global $superspeedy_licenses;
            global $superspeedy_plugin_versions;

            // loop through the global $superspeedy_licenses if they are an array and add plugin_slug => array(version, file) to the a $old_licenses array - file needs to be pulled from the global $superspeedy_plugin_versions
            if (is_array($superspeedy_licenses)) {
                $old_licenses = array();
                foreach ($superspeedy_licenses as $plugin_slug => $license) {
                    if (!is_array($license) || !isset($license['file'])) {
                        continue;
                    }
                    if (!$superspeedy_plugin_versions) continue;
                    if (!isset($superspeedy_plugin_versions[$plugin_slug])) {
                        continue;
                    }
                    $old_licenses[$plugin_slug] = array(
                        'version' => $superspeedy_plugin_versions[$plugin_slug],
                        'file' => $license['file']
                    );
                }
            } else {
                $old_licenses = array();
            }
            $licenses = array_merge(self::$superspeedy_licenses, $old_licenses);
            foreach (self::$base_plugin_info as $slug => $info) {
                if (isset($licenses[$slug])) {
                    $licenses[$slug] = array_merge($info, $licenses[$slug]);
                } else {
                    $licenses[$slug] = $info;
                }
            }    



            return $licenses;
        }
        
        public static function init($plugin_info) {
            $callingClass = get_called_class();
            if (self::$instance === null || $callingClass::getVersion() > self::$instance::getVersion()) {
                // Clean up any existing instance
                if (self::$instance !== null) {
                    remove_action('init', array(self::$instance, 'setup'));
                    remove_action('admin_menu', array(self::$instance, 'register_menu'));
                }
                
                // Set new instance
                self::$instance = new $callingClass();
                add_action('init', array(self::$instance, 'setup'), 5);
            }
            self::$superspeedy_licenses[$plugin_info['plugin_slug']] = array(
                'version' => $plugin_info['version'],
                'file' => $plugin_info['file']
            );

        }

        public function setup() {
            remove_action('admin_menu', 'superspeedy_options_page');
            add_action('admin_menu', array($this, 'register_menu'), 5);
            remove_action('init', 'ssp_initialize_updaters');
            add_action('admin_enqueue_scripts', array($this, 'context_help'));
        }
        abstract public function register_menu();
        
        abstract public function display_settings_page();
        
        function context_help($hook) {
            wp_enqueue_script( 
                'super_speedy_context_help_js', 
                plugins_url( 'context-help.js',__FILE__ ), 
                array('jquery'), 
                filemtime(__DIR__ . '/context-help.js')
            );
            wp_enqueue_style('super_speedy_context_help', plugins_url('context-help.css', __FILE__), null, filemtime(__DIR__ . '/context-help.css'));
        }
    }
}
