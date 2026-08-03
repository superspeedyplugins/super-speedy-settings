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
 * per-release edit in this file is the version literal at the bottom, which
 * must match SuperSpeedySettingsCore::SETTINGS_VERSION.
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
        /** Load the newest registered copy's core; fall back down the list if a copy is mid-update. */
        function ssp_settings_load_newest() {
            $candidates = isset($GLOBALS['ssp_settings_candidates']) && is_array($GLOBALS['ssp_settings_candidates'])
                ? $GLOBALS['ssp_settings_candidates']
                : array();
            uksort($candidates, 'version_compare');
            foreach (array_reverse($candidates, true) as $dir) {
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
    $GLOBALS['ssp_settings_candidates']['1.5.3'] = __DIR__;
}
