# VibeShift EU Shipping

**Author:** [Vibe Code Racing](https://vibecoderacing.com) · **Plugin:** [vibecoderacing.ai](https://vibecoderacing.ai)

Single installable WordPress/WooCommerce plugin that merges:

- **EORI** collection and registry validation (`modules/eori/`)
- **EU VAT** collection, VIES/HMRC validation, and B2B tax exemption (`modules/eu-vat/`)

## Why merge

Stores that need both identifiers should activate one plugin. The EORI side keeps:

- Classic field key `billing_eori_number` (Flexible Checkout Fields billing nomenclature)
- Late `woocommerce_checkout_fields` priority `10000` re-injection so FCF rebuilds do not drop the field
- Order email meta via `woocommerce_email_order_meta_fields` with labels **EU VAT Number** and **EORI Number**

## Requirements

- WordPress 6.8+
- WooCommerce 10.4+
- PHP 7.4+
- SOAP extension (for VIES; EORI still works if SOAP is missing)
- Taxes enabled (for EU VAT module; EORI still works if taxes are off)

## Install

Copy `vibeshift-eu-shipping/` into `wp-content/plugins/` and activate **VibeShift EU Shipping**.

Deactivate standalone EORI Number and EU VAT Number plugins if present.

```bash
zip -qr vibeshift-eu-shipping.zip vibeshift-eu-shipping \
  -x '*/.DS_Store' '*/tests/*'
```

## Settings

| Domain | Path |
|--------|------|
| EORI | WooCommerce → Settings → Shipping → EORI Number Validation |
| EU VAT | WooCommerce → Settings → Tax |

## Checkout fields

| Field | Key |
|-------|-----|
| EORI (classic) | `billing_eori_number` |
| EORI (blocks) | `vibeshift-eu-shipping/eori-number` |
| VAT (classic) | `billing_vat_number` |

## Order meta

**EORI:** `_eori_number`, `_eori_number_is_validated`, `_eori_number_is_valid`, plus provider/date/company fields.

**VAT:** `_billing_vat_number` (primary), `_vat_number` (legacy fallback).

## Developer tests

```bash
php tests/merged-plugin-test.php
```

No PHPUnit or WordPress bootstrap required.
