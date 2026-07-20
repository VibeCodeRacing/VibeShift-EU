# VibeShift EU Shipping

WordPress / WooCommerce plugin for EU shipping compliance: **EORI** validation and **EU VAT** number collection with B2B exemption.

- **Plugin Name:** VibeShift EU Shipping  
- **Version:** 1.2.0  
- **Author:** [Vibe Code Racing](https://vibecoderacing.ai)  
- **Plugin URI:** https://vibecoderacing.ai  
- **Text Domain:** `vibeshift-eu-shipping`  
- **Installable package:** `vibeshift-eu-shipping/`

## Requirements

- WordPress 6.8+
- WooCommerce 10.4+
- PHP 7.4+
- SOAP extension (for VIES; EORI still works if SOAP is missing)
- Taxes enabled (for EU VAT module; EORI still works if taxes are off)

## Installation

Copy `vibeshift-eu-shipping/` into `wp-content/plugins/` and activate **VibeShift EU Shipping**.

Deactivate any standalone EORI Number or EU VAT Number plugins first (the merged package stays inactive while they are loaded).

```bash
zip -qr vibeshift-eu-shipping.zip vibeshift-eu-shipping \
  -x '*/.DS_Store' '*/tests/*'
```

Upload the ZIP via **Plugins → Add New → Upload Plugin**.

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

## Order meta (unchanged for data compatibility)

**EORI:** `_eori_number`, `_eori_number_is_validated`, `_eori_number_is_valid`, plus provider/date/company fields.

**VAT:** `_billing_vat_number` (primary), `_vat_number` (legacy fallback).

## What it does

- Collects and validates EORI numbers for configured shipping destinations
- Collects EU VAT numbers with VIES/HMRC validation and optional B2B tax exemption
- Supports classic checkout and WooCommerce block checkout
- Labeled **EU VAT Number** and **EORI Number** lines on order emails
- Flexible Checkout Fields–compatible classic EORI field (`billing_eori_number`)

## Developer tests

```bash
php vibeshift-eu-shipping/tests/merged-plugin-test.php
```

No PHPUnit or WordPress bootstrap required.
