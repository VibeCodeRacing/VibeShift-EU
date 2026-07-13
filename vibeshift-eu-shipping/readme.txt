=== VibeShift EU Shipping ===
Contributors: vibecoderacing
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 10.4
Stable tag: 1.1.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Merged plugin: collect and validate EORI numbers and EU VAT numbers at WooCommerce checkout.

== Description ==

One installable plugin that provides:

* EORI number collection and registry validation for configured shipping destinations
* EU VAT number collection, VIES/HMRC validation, and B2B VAT exemption
* Classic checkout EORI field key `billing_eori_number` (Flexible Checkout Fields compatible)
* Late `woocommerce_checkout_fields` re-injection so FCF rebuilds keep the EORI field
* Order email meta lines labeled **EU VAT Number** and **EORI Number**

== Installation ==

1. Copy `vibeshift-eu-shipping/` into `wp-content/plugins/`
2. Activate **VibeShift EU Shipping**
3. Disable the separate EORI Number and EU VAT Number plugins if they were installed previously
4. Configure EORI under WooCommerce > Settings > Shipping
5. Configure VAT under WooCommerce > Settings > Tax

== Developer tests ==

```bash
php tests/merged-plugin-test.php
```

== Changelog ==

= 1.1.1 =
* Fix: resolved remaining WordPress Plugin Check warnings (settings option prefix alignment, EU VAT report query prefetch/caching notes, phpcs ignore annotations for intentional legacy WC_* prefixes, and related global prefix hygiene).
* Metadata: author Vibe Code Racing (https://vibecoderacing.com); Plugin URI https://vibecoderacing.ai.

= 1.1.0 =
* Fix: closed a Blocks/Store API checkout gap where EU VAT B2B-required and invalid-VAT rejection rules were only enforced on classic checkout. Order validation now runs before Store API payment.
* Add: guard against running alongside the standalone EORI Number and EU VAT Number plugins. The merged plugin stays inactive and shows an admin notice until the standalone plugins are deactivated, preventing duplicate checkout hooks.
* Security: sanitized and unslashed all checkout and admin superglobal input; hardened direct file access, output escaping, and removed discouraged functions.
* i18n: unified all text domains to `vibeshift-eu-shipping` and added the `languages/vibeshift-eu-shipping.pot` translation template.
* Metadata: raised the checkout block `apiVersion`, updated "Tested up to" to WordPress 7.0, and resolved WordPress Plugin Check findings.
* Rebrand: VibeShift EU Shipping (author Vibe Code Racing; Plugin URI https://vibecoderacing.ai; Author URI https://vibecoderacing.com).

= 1.0.0 =
* Initial merged release combining EORI Number and EU VAT Number into one installable plugin.
* FCF-compatible `billing_eori_number` field with late `woocommerce_checkout_fields` restore.
* Order email meta labeled "EU VAT Number" and "EORI Number".
* B2B required-VAT message uses `vat_country` (shipping country when applicable) instead of billing country; VAT not required when shipping outside the EU.
