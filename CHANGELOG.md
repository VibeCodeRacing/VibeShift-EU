# Changelog

All notable changes to **VibeShift EU Shipping** (`vibeshift-eu-shipping`).

## 1.2.2 - 2026-07-20

- Developer site URIs now use the CamelCase brand form VibeCodeRacing.ai in the plugin header, update-modal author link, and READMEs (matching VibeSplit's brand style).

## 1.2.1 - 2026-07-20

- Standardized developer site URIs on vibecoderacing.ai: the `Author URI` header, the update-modal author link, and README author links previously pointed at vibecoderacing.com.

## 1.2.0 - 2026-07-20

- Added automatic updates from GitHub Releases: a new `Update URI` header plus an in-plugin updater (`includes/class-vibeshift-github-updater.php`) let WordPress discover release zips published on the public repo. Release checks are cached for 12 hours; a status line on the Plugins screen shows the last check result, with an admin notice when checks fail.
- Added a tag-triggered GitHub Actions release workflow that verifies version consistency (plugin header, `WC_EORI_VAT_VERSION`, readme stable tag) and builds/attaches the installable zip, replacing the hand-built release asset.

## 1.1.2 - 2026-07-13

- Fixed a classic-checkout dead-end (found by E2E test T5 on woo.dougstate.com): with non-EU billing and EU shipping, checkout demanded an EU VAT number while the shipping VAT field had been stripped from the form by Flexible Checkout Fields, leaving the customer no visible field to fill. Both VAT fields are now re-added on a late `woocommerce_checkout_fields` pass (priority 10000), mirroring the EORI field's FCF restore.
- Server-side VAT selection now falls back to the posted billing VAT number when the shipping VAT field is absent or empty (still validated against the shipping country), instead of silently discarding the customer's input; also removes a PHP 8 "undefined array key shipping_vat_number" warning.
- Checkout JS falls back to toggling the billing VAT row by shipping destination when the shipping VAT row is missing from the DOM.

## 1.1.1 - 2026-07-13

- Resolved remaining WordPress Plugin Check warnings: settings option prefix alignment, EU VAT report query prefetch/caching guidance, and phpcs annotations for intentional legacy `WC_*` / `wc_*` prefixes kept for data compatibility.
- Confirmed packaging metadata: author **Vibe Code Racing** (https://vibecoderacing.com), Plugin URI https://vibecoderacing.ai.

## 1.1.0 - 2026-07-13

- Closed a Blocks/Store API checkout gap where EU VAT B2B-required and invalid-VAT rejection rules were only enforced on classic checkout. Order validation now runs on `woocommerce_checkout_validate_order_before_payment` before Store API payment.
- Added a guard against running alongside standalone EORI Number and EU VAT Number plugins: the merged plugin stays inactive and shows an admin notice until those plugins are deactivated, preventing duplicate checkout hooks and same-named function loads.
- Sanitized and unslashed all checkout/admin superglobal input; hardened direct file access, output escaping, and removed discouraged functions.
- Unified all text domains to `vibeshift-eu-shipping` and added the `languages/vibeshift-eu-shipping.pot` translation template.
- Raised the checkout block `apiVersion`, updated "Tested up to" to WordPress 7.0, and resolved WordPress Plugin Check findings.
- Rebranded as **VibeShift EU Shipping** (author Vibe Code Racing; Plugin URI https://vibecoderacing.ai; Author URI https://vibecoderacing.com) for copyright-facing packaging.

## 1.0.0 - 2026-07-13

- Initial merged release combining EORI Number and EU VAT Number into one installable plugin.
- FCF-compatible `billing_eori_number` field with late `woocommerce_checkout_fields` restore.
- Order email meta labeled "EU VAT Number" and "EORI Number".
- Fixed the B2B required-VAT message to use `vat_country` (shipping country when applicable) instead of billing country; VAT not required when shipping outside the EU.
