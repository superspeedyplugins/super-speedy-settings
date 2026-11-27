<?php
/**
 * Super Speedy Settings - FROZEN LOADER STUB (the candidate registry).
 *
 * Every Super Speedy plugin bundles this submodule and requires THIS file at
 * load time. Before settings 1.5.0 the first copy to load defined all the
 * classes, so the alphabetically-first plugin's (often oldest) copy won for
 * the whole family. This stub fixes that: each copy only REGISTERS itself
 * here, and at plugins_loaded priority -9999 the NEWEST registered copy's
 * super-speedy-settings-core.php is loaded - so updating any one Super Speedy
 * plugin upgrades the shared settings code for all of them. Design:
 * .docs/2026-08-03-latest-copy-wins-loader-design.md.
 *
 * THIS FILE IS A FROZEN CONTRACT. Old plugins keep running their bundled copy
 * of this stub to find newer code, so it must stay boring and eternally
 * backwards compatible. The frozen pieces are:
 *   - $GLOBALS['ssp_settings_candidates']  version => dir of each loaded copy
 *   - $GLOBALS['ssp_settings_queue']       queued ::init() registrations
 *   - the facade class SuperSpeedySettings_1_0 (const SSP_REGISTRY_FACADE,
 *     static init($plugin_info)) - the public API plugin main files call
 *   - action 'ssp_settings_plugin_registered' fired after each registration
 *   - the core filename 'super-speedy-settings-core.php'
 * Put ALL real behaviour in the core file; never add logic here. The ONLY
 * per-release edits in this file are the two version literals at the bottom
 * (the settings candidate and the dashboard widget candidate), which must
 * match SuperSpeedySettingsCore::SETTINGS_VERSION.
 *
 * ONE ACCEPTED EXCEPTION (Dave, 25 November 2026): the dashboard widget
 * registry and its loader at the end of this file. When an old-style copy
 * (before 1.5.0) loads first it owns SuperSpeedySettings_1_0, no newer core
 * ever loads, and nothing in the core can run on that site. The widget is the
 * one feature that must reach those sites, so it registers and loads from
 * here, independently of the election. It stays small, touches none of the
 * frozen pieces above, and must never grow into a second settings core: any
 * other feature belongs in super-speedy-settings-core.php.
 *
 * Compatibility both ways:
 *   - If an OLD-style copy (pre-1.5.0) loaded first, SuperSpeedySettings_1_0
 *     already exists as the full class: this stub stays completely passive and
 *     the old code serves the site, exactly as before this change.
 *   - If this stub loaded first, an old-style copy loading later skips its own
 *     class definition (class_exists guard) and its plugin's ::init() call is
 *     queued by the facade like everyone else's.
 */

if (!defined('ABSPATH')) { return; }

if (!class_exists('SuperSpeedySettings_1_0')) {

    /**
     * Frozen facade. Plugin main files call SuperSpeedySettings_1_0::init()
     * exactly as they always have; registrations queue until the winning core
     * loads and drains them (it also hooks the action below for any plugin
     * that registers later in the request, e.g. at activation time).
     */
    class SuperSpeedySettings_1_0 {
        const SSP_REGISTRY_FACADE = true;
        public static function init($plugin_info) {
            $GLOBALS['ssp_settings_queue'][] = $plugin_info;
            do_action('ssp_settings_plugin_registered');
        }
    }

    if (!function_exists('ssp_settings_load_newest')) {
        /** Only load candidates installed beneath a WordPress plugin root. */
        function ssp_settings_candidate_is_allowed($dir) {
            $candidate = realpath($dir);
            if ($candidate === false) {
                return false;
            }
            $roots = array(WP_PLUGIN_DIR, WPMU_PLUGIN_DIR);
            foreach (array(WP_PLUGIN_DIR, WPMU_PLUGIN_DIR) as $plugin_root) {
                $installed = glob($plugin_root . '/*', GLOB_ONLYDIR);
                if (is_array($installed)) {
                    $roots = array_merge($roots, $installed);
                }
            }
            foreach ($roots as $root) {
                $root = realpath($root);
                if ($root !== false && ($candidate === $root || strpos($candidate, $root . DIRECTORY_SEPARATOR) === 0)) {
                    return true;
                }
            }
            return false;
        }

        /** Load the newest registered copy's core; fall back down the list if a copy is mid-update. */
        function ssp_settings_load_newest() {
            $candidates = isset($GLOBALS['ssp_settings_candidates']) && is_array($GLOBALS['ssp_settings_candidates'])
                ? $GLOBALS['ssp_settings_candidates']
                : array();
            uksort($candidates, 'version_compare');
            foreach (array_reverse($candidates, true) as $dir) {
                if (!ssp_settings_candidate_is_allowed($dir)) {
                    continue;
                }
                $core = $dir . '/super-speedy-settings-core.php';
                if (is_file($core)) {
                    require_once $core;
                    return;
                }
            }
        }
    }
    add_action('plugins_loaded', 'ssp_settings_load_newest', -9999);
}

// Register this copy as a candidate - but only when the registry facade owns
// the class name. If an old-style full class got there first, the old code is
// serving the site and this copy must stay silent.
if (defined('SuperSpeedySettings_1_0::SSP_REGISTRY_FACADE')) {
    // Keep this literal in sync with SuperSpeedySettingsCore::SETTINGS_VERSION.
    $GLOBALS['ssp_settings_candidates']['1.15.3'] = __DIR__;
}

// Dashboard widget registry (settings 1.15.2). Deliberately independent of the election above.
// An old-style copy (before settings 1.5.0, July 2026) that loads first owns the class name, and
// then no newer core ever loads, so a site with one old Super Speedy plugin would never show the
// Super Speedy Plugins dashboard box (found on a customer site, 24 November 2026). Every copy from 1.15.2 on
// registers here whatever owns the class; if the settings core did not load the widget, the
// newest registered copy's widget file is loaded on its own, in wp-admin, cron and WP-CLI only.
// Frozen like the rest: $GLOBALS['ssp_dashboard_widget_candidates'] (version => dir),
// $GLOBALS['ssp_dashboard_widget_hosts'] (the folder of each plugin carrying a copy) and the
// filename class-ssp-dashboard-widget.php. Keep this literal in sync with SETTINGS_VERSION.
$GLOBALS['ssp_dashboard_widget_candidates']['1.15.3'] = __DIR__;
$GLOBALS['ssp_dashboard_widget_hosts'][] = basename(dirname(__DIR__));
if (!function_exists('ssp_dashboard_widget_load')) {
    function ssp_dashboard_widget_load() {
        if (class_exists('SSP_Dashboard_Widget', false)) {
            return; // the settings core loaded it
        }
        if (!(is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
            return;
        }
        $candidates = (array) $GLOBALS['ssp_dashboard_widget_candidates'];
        uksort($candidates, 'version_compare');
        $plugins = realpath(WP_PLUGIN_DIR);
        foreach (array_reverse($candidates, true) as $dir) {
            $file = realpath($dir . '/class-ssp-dashboard-widget.php');
            $host = realpath(dirname($dir));
            $allowed = $file && $host && $plugins && (dirname($host) === $plugins || in_array($host, array_map('realpath', (array) glob($plugins . '/*', GLOB_ONLYDIR)), true));
            if ($allowed) {
                require_once $file;
                SSP_Dashboard_Widget::bootstrap();
                return;
            }
        }
    }
    add_action('plugins_loaded', 'ssp_dashboard_widget_load', -9998);
}
