# Latest-copy-wins: making every plugin use the newest bundled settings code

Date: 2026-08-03. Status: ✅ Option B IMPLEMENTED same day in settings 1.5.0 -
`super-speedy-settings.php` is now the frozen loader stub, the real code moved to
`super-speedy-settings-core.php` (class `SuperSpeedySettingsCore`), and AIS's load-time
`class_exists` gate moved to `plugins_loaded`. Verified: old-copy-first mixed sites fall
back to old behaviour untouched; newest wins in either load order; torn-update falls
back to the next-best candidate; late (activation-time) registrations drain via the
`ssp_settings_plugin_registered` action. Options A and C below kept for the record;
C (a real dependency plugin) remains the long-term destination.
Dave's framing: contracts stay backwards compatible forever, so a
newer copy of this submodule must always be safe to run against older plugins.

## The problem

Every Super Speedy plugin bundles this submodule. The class definitions are
`class_exists`-guarded, so the FIRST copy to load defines the code for the whole
family, and WordPress loads active plugins alphabetically by path -
auto-infinite-scroll before everything else. AIS is also the least-released plugin, so
in practice a site with five Super Speedy plugins runs the settings code of whichever
plugin the customer updated least recently, not most recently.

The existing `SuperSpeedySettings::init()` version preference does not help: it picks
between INSTANCES after the classes are defined, but every copy declares
`protected static $version = 1.0` and the class body itself is first-wins, so newer
code bundled by a later-loading plugin never executes. (Verified live on the dev box
on 2026-08-03: with external-images on settings 1.3.0 active, super-speedy-filters'
1.4.0 copy was ignored - `SETTINGS_VERSION` reported 1.3.0 and the 1.4.0 methods did
not exist.)

`sv=` reporting to auth tells us which sites run stale settings code, but observability
is not a fix.

## Option A - Dave's proposal: scan sibling plugin folders, load the newest copy

Each copy's loader, before defining anything, scans
`WP_PLUGIN_DIR/*/super-speedy-settings/`, reads a version marker WITHOUT executing
anything (`get_file_data()` on the `SETTINGS_VERSION` line, or a dedicated VERSION
file), and `require`s the newest copy's file instead of its own.

It works, and after one full settings-sync release (every plugin shipping the scanning
loader once) any single future plugin update lifts the whole family. Requirements to
do it safely:

1. **Never execute code from inactive or unknown plugins.** This is the one genuinely
   new risk class the scan creates. Today a deactivated plugin's files never run; a
   naive scan would execute `evil-plugin/super-speedy-settings/super-speedy-settings.php`
   claiming `SETTINGS_VERSION 99.0.0` on every request - arbitrary code execution from
   a plugin the admin believes is off, or from a third-party/nulled plugin that ships a
   spoofed folder. Mitigation: candidates are the KNOWN Super Speedy slugs only (the
   `base_plugin_info` list) INTERSECTED with `active_plugins`. With that, planting a
   winning copy requires write access inside an active Super Speedy plugin's folder,
   which is full file-write compromise anyway.
2. **Path hygiene.** `realpath()` every candidate and require it to sit inside
   `WP_PLUGIN_DIR`; never take a path from the database. If the scan result is cached
   (option/transient), an attacker with DB write could poison the cached path - so
   either do not cache paths at all, or re-validate whitelist + realpath on every load.
   Prefer no cache: the whitelist bounds the scan to ~16 `file_exists()` calls plus one
   small read, sub-millisecond warm, acceptable even though the loader runs on every
   front-end request.
3. **Torn updates.** WP replaces a plugin folder during update; a request in that
   window sees a missing or partial candidate. `file_exists` checks with fallback to
   the plugin's own copy cover the missing case; a half-written winner would fatal
   (unrecoverable at require time), but that window is tiny and exists today for a
   plugin's own copy anyway.
4. **The scanning loader itself must be boring and eternal.** Old plugins keep running
   THEIR loader to find newer code, so the loader logic can never be clever enough to
   need fixing - version compare, whitelist, require winner, done.

Dave's other idea - second-loaded module rewriting the class - is indeed impossible in
PHP: classes cannot be redefined, and runkit-style extensions are not a thing on
customer hosting.

## Option B - candidate registry (recommended): the Jetpack-autoloader pattern

Key insight: every copy that matters is ALREADY being loaded by its own active plugin.
Nobody needs to scan the filesystem to discover candidates - let each copy register
itself, and decide the winner after all plugins have loaded. This is exactly how the
Jetpack Autoloader and PUC's own `Puc\v5pX` factory solve the same embedded-library
problem.

Restructure the submodule into two files:

- **`super-speedy-settings.php` becomes a tiny, forever-stable loader** (~40 lines):
  - `$GLOBALS['ssp_settings_candidates'][SETTINGS_VERSION] = __DIR__;`
  - a `class_exists`-guarded facade `SuperSpeedySettings_1_0` whose static
    `init($plugin_info)` only queues the args - today's plugin-main-file contract
    (`require` + `::init(...)`) keeps working byte-for-byte;
  - one guarded `plugins_loaded` priority `-9999` hook: `max(version)` wins,
    `require $winner/super-speedy-settings-core.php`, replay the queued `init()`s.
- **`super-speedy-settings-core.php`**: everything that lives in the main file today
  (real settings class, PUC wiring, SSP_MCP, SSP_AI_Engine, SuperSpeedy_MCP_Config),
  loaded exactly once, from the newest active copy.

Why it beats the scan:
- **No new attack surface.** Candidates self-declare by being loaded by their own
  ACTIVE plugin; inactive and foreign folders never enter the registry. (An active
  malicious plugin could register a fake candidate, but an active malicious plugin
  already executes arbitrary code - nothing is escalated.)
- **Zero extra I/O** - no globbing, no version-file reads, nothing to cache.
- Deterministic, order-independent, and the winner is provably one of the copies a
  site's active plugins actually shipped.

Same migration threshold as the scan: an OLD-style copy that loads first still defines
the real classes immediately and wins, so nothing improves until every installed Super
Speedy plugin has shipped one release with the new loader. One settings-sync + release
wave crosses that line for both options equally; after it, any single plugin update
upgrades the family.

**Migration gotcha to audit per plugin:** the registry defers real class availability
from plugin-load time to `plugins_loaded -9999`. Any plugin main file that checks the
submodule's classes at load time breaks its gate - auto-infinite-scroll does exactly
this (`class_exists('SuperSpeedy_MCP_Config')` in auto-infinite-scroll.php). Those
gates move onto a `plugins_loaded` hook in the same release. `::init()` calls are safe
(the facade queues them); the audit is only for immediate `class_exists`/method calls
at include time.

## Option C - the honest long-term answer: a real dependency plugin

The submodule is simulating a shared library. WordPress 6.5+ has first-class plugin
dependencies (`Requires Plugins:` header). A standalone "Super Speedy Core" plugin -
distributed and updated from the same update server, auto-installed by the next update
of any product plugin using the same `Plugin_Upgrader` rails as `ssp/install-plugin` -
gives ONE copy on disk with its own update cycle, and the "5 installed, only 1 updated"
problem ceases to exist structurally (core updates itself regardless of which product
plugins the customer updates). Costs: a distribution change, one more visible plugin to
explain in support, and the dependencies UI is still rough. Worth holding as the
destination; the registry gets 95% of the value with none of the distribution change.

## Recommendation

Implement **Option B** in the next settings release: split loader/core, freeze the
loader, move AIS's (and any similar) load-time class gate to `plugins_loaded`, and
propagate via settings-sync. Keep Option C on the roadmap as the eventual
simplification. If Option A is preferred anyway, it MUST carry the whitelist + active
intersection, realpath containment, and no cached paths.
