# VibeShift EU Shipping — GitHub Releases auto-update

**Date:** 2026-07-20
**Status:** Approved
**Version shipping this feature:** 1.2.0

## Problem

VibeShift EU Shipping installs never learn about new versions. The public
repo `VibeCodeRacing/VibeShift-EU` publishes GitHub Releases with a
hand-built `vibeshift-eu-shipping.zip` asset, but the plugin has no
`Update URI` header and no updater code, so wp-admin shows no update and
every site must be updated by hand.

## Goal

Dashboard update checks discover new GitHub Releases and one-click (or
WordPress auto-update) installs them. Release zips are built and attached
by CI, not by hand.

## Approach

Port the proven IonFlow Chatbot 1.3.0 updater
(`class-ionflow-github-updater.php`) and its tag-triggered release
workflow, simplified for a public repo: no token constant, no
authenticated asset download, no token-specific admin messaging.
Downloads use the release asset's public `browser_download_url`.

Rejected alternatives: verbatim IonFlow port (ships dead credential
plumbing); third-party `plugin-update-checker` library (vendored
dependency, breaks pattern consistency with IonFlow / ElementTest).

## Components

### 1. Updater class — `vibeshift-eu-shipping/includes/class-vibeshift-github-updater.php`

Class `Vibeshift_GitHub_Updater`, static, initialized from the bootstrap.

- Constants: `REPO = 'VibeCodeRacing/VibeShift-EU'`,
  `SLUG = 'vibeshift-eu-shipping'`,
  `CACHE_KEY = 'vibeshift_github_release'`, `CACHE_TTL = 43200` (12 h),
  `STATUS_OPTION = 'vibeshift_update_status'`.
- `update_plugins_github.com` filter (WP 5.8+, driven by the `Update URI`
  header): offer the release version when it is greater than the
  installed version, with the zip asset URL as `package`.
- `plugins_api` filter: "View version details" modal with release notes
  as changelog.
- `upgrader_process_complete`: drop the release transient after this
  plugin updates.
- Asset selection: prefer `vibeshift-eu-shipping-{version}.zip`, then
  `vibeshift-eu-shipping.zip` (the name existing releases use), then any
  `vibeshift-eu-shipping*.zip`, then a lone zip asset. Case-insensitive.
- Status recording: last real check outcome (`ok`, `http_<code>`,
  `network_error`, `bad_payload`, `no_release`, `bad_tag`, `no_asset`)
  in `STATUS_OPTION` (not autoloaded). Cache hits are not recorded.
- Plugins-screen row meta: green `Updates: GitHub ✓ latest x.y.z,
  checked N ago` / amber `no check yet` / red `check failing (<code>)`.
  Admin-notice on plugins.php when the last check failed. No token
  wording anywhere.

### 2. Bootstrap changes — `vibeshift-eu-shipping/vibeshift-eu-shipping.php`

- Add header line: `Update URI: https://github.com/VibeCodeRacing/VibeShift-EU`.
- Define `WC_EORI_VAT_PLUGIN_BASENAME` via `plugin_basename( __FILE__ )`.
- Require the updater class and call `Vibeshift_GitHub_Updater::init()`
  unconditionally (updates must work even when WooCommerce dependency
  checks fail, so init happens outside the Woo gating).
- Bump `Version:` header and `WC_EORI_VAT_VERSION` to `1.2.0`.

### 3. Release workflow — `.github/workflows/release.yml`

Ported from IonFlow, adapted to the repo layout (plugin in the
`vibeshift-eu-shipping/` subdirectory rather than repo root):

- Trigger: tag push `v[0-9]+.[0-9]+.[0-9]+` or bare semver.
- Verify tag version matches: plugin header `Version:`,
  `WC_EORI_VAT_VERSION`, and `readme.txt` `Stable tag`. Fail on mismatch.
- Build `vibeshift-eu-shipping-{version}.zip` containing a single root
  folder `vibeshift-eu-shipping/` — the subdirectory minus `tests/`
  (no `.distignore` file needed; excludes inline).
- Publish the GitHub Release with generated notes and the zip,
  `fail_on_unmatched_files: true`.

### 4. Housekeeping

- Delete the hand-built `vibeshift-eu-shipping.zip` from the repo root;
  add `*.zip` to `.gitignore`.
- Update `readme.txt` stable tag, `README.md` version, and
  `CHANGELOG.md` for 1.2.0.

## Error handling

- Failed or malformed release checks return no update (WordPress shows
  nothing) and record the failure code for the row-meta indicator; they
  are never cached, so the next check retries.
- Unauthenticated GitHub API rate limit (60/hr/IP) is a non-issue: WP
  core caches update checks ~12 h and successes populate the plugin's
  own 12 h transient.
- Drafts/prereleases and non-semver tags are skipped explicitly.

## Verification

- Build the zip locally with the same steps as the workflow; confirm
  layout (`vibeshift-eu-shipping/` root, no `tests/`).
- PHP lint all touched files.
- Simulated update check: load WordPress with the new class present and
  a stubbed older installed version; confirm the filter offers 1.2.0 and
  the status option records `ok`. (The Elimstat test site runs the BnB
  variant of this plugin, so end-to-end verification there is out of
  scope; a scratch/local check is sufficient.)
- After merge: push tag `v1.2.0`, confirm the workflow publishes the
  release with the correctly-named asset.

## Rollout

Existing 1.1.2 sites need one manual update to 1.2.0. From then on new
releases appear in wp-admin automatically (and WordPress's per-plugin
auto-update toggle works).
