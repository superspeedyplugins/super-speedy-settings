# MCP gap: no way to install a Super Speedy plugin without a browser or a shell

**Date:** 2026-07-20
**Status:** 🔴 Gap report / proposal. Raised from the demo-site build
(`/opt/homebrew/var/www/demo.superspeedy-plugins.com`). Not to be built by that session.
**Where it belongs:** here, in the shared `super-speedy-settings` submodule, because every
Super Speedy plugin already carries it. Building it once makes it available to all of them.
**Related:**
- `super-speedy-imports/.docs/2026-07-20-mcp-gap-remote-demo-site-control.md`
- skills: `plugin-mcp`, `wp-abilities-api`

---

## 1. The problem

WordPress core's REST plugin controller (`POST /wp/v2/plugins`) installs **from wordpress.org
by slug only**. It will happily fetch WooCommerce. It cannot install anything else.

The Super Speedy paid plugins are distributed as zips from superspeedyplugins.com, and the
update path is the bundled `plugin-update-checker` — which handles **updates to an already
installed plugin**, never a first install.

So provisioning a site with Super Speedy plugins requires either:

1. wp-admin → Plugins → Add New → Upload, once per plugin, or
2. SSH and `wp plugin install <url> --activate`.

An agent holding an application password can do neither.

## 2. Why this is worth building beyond the demo

- **Agency provisioning.** Setting up a new client site means uploading five to nine zips by
  hand. Doing it for a batch of sites is an afternoon. This is the single most repetitive
  task in owning the suite.
- **"Claude, set up my store."** The whole direction of the MCP work across the plugins
  assumes the plugins are already installed. This is the step before step one, and without it
  every other ability starts from a manual prerequisite.
- **Staging refreshes.** Rebuild staging from live, plugins gone, do it all again.
- **It is one implementation for nine plugins.** The submodule is already in every plugin, so
  the ability exists everywhere the moment any one Super Speedy plugin is active.

The bootstrap property is the appealing part: **install one Super Speedy plugin by hand, and
every other one becomes installable over MCP from that point on.**

## 3. Proposal

| Ability | readonly | Purpose |
|---|---|---|
| `ssp/list-available-plugins` | ✔ | What the licence covers, what is installed, what version, what is out of date |
| `ssp/install-plugin` | | `{ slug }` → resolve the zip from the update server using the site's existing licence, install, optionally activate |
| `ssp/activate-plugin` / `ssp/deactivate-plugin` | | |
| `ssp/get-licence-status` | ✔ | Which licence is active, what it covers, expiry |

`ssp/install-plugin` should take a **slug**, not a URL. The licence key and the update-server
base are already on the site, so the plugin can resolve the download itself. That removes the
need for the caller to supply a URL at all, which removes most of the security surface.

## 4. 🔴 Security — read this before writing any code

An ability that installs code from a URL is remote code execution with extra steps. The
non-negotiables:

- **Never accept an arbitrary host.** Resolve zips from the configured update server only. If
  a `zip_url` parameter is ever added, it must be checked against a hardcoded allowlist, not a
  filter a third-party plugin could widen.
- **Require a valid licence** for the requested slug. This is also the natural rate limit.
- **Dedicated capability** (e.g. `ssp_manage_plugins`), never `manage_options`, so an app
  password can be scoped to provisioning and nothing else.
- **`confirm: true`** on install and activate.
- **Verify the download** — checksum from the update server, and refuse on mismatch rather
  than installing anyway. The demo-data downloader in Super Speedy Imports already does
  sha256 verification (`super-speedy-imports.php:3019-3027`); reuse that pattern.
- **Respect `DISALLOW_FILE_MODS`.** If the site has file modifications disabled, return that
  as the real reason. Do not attempt a workaround.
- **Log every install** with the user who triggered it.

If any of the above cannot be satisfied, the honest outcome is an error naming the reason —
never a silent partial install.

## 5. Interim answer

Until this exists: SSH, or upload once by hand. Note that a **wordpress.org Lite edition
installs over REST today** with no manual step, since core accepts wp.org slugs — so once
Super Speedy Imports Lite is published, a bare site can be bootstrapped to a working import
entirely over the API.
