# Super Speedy Settings: shared assets and phone home

Date grouped: 2026-07-28

Shared asset delivery and configurable licence/update check frequency.

These are current work candidates from the public issue tracker. The earlier
audit checked the changelog and current local code, but an open item is not
automatically a reproduced defect. Reproduce it or establish equivalent direct
code/test evidence before changing behaviour.

## Issues

### [#165: Plugin assets loaded from superspeedyplugins.com breaks because of Cloudflare](https://github.com/superspeedyplugins/issue-tracker/issues/165)

Labels: unlabelled

Checked the current local plugin copies. Shared settings/updater assets are still loaded by each plugin bundle, so this needs a concrete failing asset URL before one code path can be confirmed. Suggested fix: paste the blocked URL and response, then serve all required assets locally and remove any runtime dependency on superspeedyplugins.com.

Before fixing:

- reproduce the reported behaviour where practical;
- identify the root cause, not only the visible symptom;
- add or update regression coverage in the plugin’s own test convention;
- update the plugin `readme.txt` changelog with the released fix version.

### [#267: Add option to stop phone home to force this to be enabled at least once per day or per week when admins visit the page to let them see them](https://github.com/superspeedyplugins/issue-tracker/issues/267)

Labels: unlabelled

Checked the shared settings code in the current local plugins. Licence/update checks remain automatic and there is no user option implementing the requested once-per-day/week phone-home policy. Suggested fix: add an interval setting with a last-check timestamp and retain an explicit manual recheck button.

Before fixing:

- reproduce the reported behaviour where practical;
- identify the root cause, not only the visible symptom;
- add or update regression coverage in the plugin’s own test convention;
- update the plugin `readme.txt` changelog with the released fix version.

## Completion and issue-tracker hand-off

Once work from this pack is complete, write a Markdown fix report to:

`/Users/dave/issue-tracker/.fixes/`

Suggested filename: `YYYY-MM-DD-super-speedy-settings-shared-assets-and-phone-home.md`

The report must name this work-pack file and include, for every issue worked:

- issue number and final status: fully fixed, partially fixed, not reproducible,
  or needs more information;
- reproduction or direct code evidence;
- root cause and files/functions changed;
- automated tests and manual checks performed;
- exact `readme.txt` changelog version containing the fix.

Do not close the public issue from the plugin work session. The issue-tracker
session will read the fix report, verify the current local code and changelog,
then post the appropriate GitHub update or close it with
`fixed in version <version>`.

---

## Status update - 2026-08-03 (settings 1.4.0)

This work-pack file was found sitting untracked inside super-speedy-search's submodule
working copy; it now lives in the super-speedy-settings repo itself, which is the
component both issues are about. Work done in settings 1.4.0:

- **#267 - done.** New "Check frequency" setting on the Super Speedy settings page
  (`superspeedy_field_checkfrequency`: default / daily / weekly). It caps the licence
  transient TTLs, the changelog-fetch TTLs and the plugin-update-checker period
  (12h -> 24h/168h). Checks were already lazy (admin-visit-driven), so this delivers
  exactly the requested "at most once per day/week when admins visit" model. The page
  shows "Licence last checked: X ago" (stored in `superspeedy_last_check`) and the
  manual Recheck now / Recheck Licenses buttons still check immediately.
- **#165 - hardened; full fix needs the reporter's failing URL.** Audit: every GUI
  asset (images, admin.css, JS, Atlas icon packs) is already bundled and served via
  plugins_url() - no wp-admin page loads any asset from superspeedyplugins.com. The
  remaining runtime calls are data fetches (readme.txt changelog counts, the licence
  check to auth, the update JSON). Two failure modes fixed without a repro: a
  Cloudflare challenge page (non-200 with an HTML body) was parsed as a readme -
  now any non-200 counts as a failed check; and the licence-check cURL ran with 60s
  timeouts, hanging the settings page up to a minute per plugin when the endpoint was
  unreachable - now 10s connect / 15s total, fail fast, cached, retried next visit.
  If the reporter's broken "asset" turns out to be one of these data URLs, this plus
  the #267 frequency cap should resolve it; a concrete failing URL is still wanted
  before claiming the full fix.

Fix report: `/Users/dave/issue-tracker/.fixes/2026-08-03-super-speedy-settings-shared-assets-and-phone-home.md`
