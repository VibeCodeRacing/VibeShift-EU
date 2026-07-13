# Changelog

All notable changes to **VibeShift EU Shipping** (`vibeshift-eu-shipping`).

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
