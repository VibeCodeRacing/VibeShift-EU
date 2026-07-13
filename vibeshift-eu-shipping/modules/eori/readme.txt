=== EORI Number ===
Contributors: vibeshift
Requires at least: 6.8
Requires PHP: 7.4
WC requires at least: 10.4
Stable tag: 1.0.8
License: GPLv3

Collects and validates EORI numbers at WooCommerce checkout for configured shipping destinations.

== Validation providers ==

* GB EORI numbers are checked with the HMRC Check an EORI Number API.
* XI and EU member-state EORI numbers are checked with the European Commission EORI checker.

== Manual checkout validation ==

1. Activate WooCommerce and EORI Number.
2. Open WooCommerce > Settings > Shipping and confirm the EORI Number Validation settings.
3. Classic checkout: ship to a configured EORI-required country and confirm checkout blocks when the EORI field is empty.
4. Classic checkout: enter an invalid EORI and confirm checkout blocks.
5. Block checkout: confirm the EORI number additional checkout field appears only for configured EORI-required destinations, shows as required, and the same missing/invalid behavior blocks checkout.
6. Place a test order with a valid EORI and confirm `_eori_number`, `_eori_number_is_validated`, `_eori_number_is_valid`, `_eori_validation_provider`, `_eori_validation_date`, `_eori_company_name`, `_eori_company_address`, and `_eori_validation_error` are stored.
7. Confirm the order list column and EORI Validation metabox show the validation result for shipping staff.
8. With Flexible Checkout Fields active and billing fields customized, confirm the EORI field appears on classic checkout only after selecting a configured EORI-required country.

== Developer test ==

The standalone validator test can be run where PHP is available:

`php woocommerce-eori-number/tests/eori-validator-test.php`

== Changelog ==

= 1.0.8 - 2026-07-03 =
* EORI is now required by the shipping destination only: EU billing with non-EU shipping no longer requires an EORI number, and carts with nothing to ship never require one (reverts the 1.0.7 behavior introduced by PR #24).
* Added regression tests locking in the shipping-destination rule for classic checkout, block checkout, and order-level validation.

= 1.0.7 - 2026-07-01 =
* Fixed VAT meta key priority on order emails: _billing_vat_number is now checked before the legacy _vat_number fallback.

= 1.0.6 - 2026-07-01 =
* Added labeled EU VAT Number and EORI Number lines to order emails via woocommerce_email_order_meta_fields.
* Fixed a classic checkout EORI validation bypass for virtual carts.

= 1.0.5 - 2026-07-01 =
* Fixed block checkout EORI validation bypasses.
* Skipped EORI validation entirely for non-required destinations so stale or hidden EORI values can no longer block checkout.
* Fixed the classic checkout field fallback so the posted EORI value is found even when checkout-field managers rename the field key.
* Verified end-to-end on staging at classic checkout: empty EORI blocked, invalid EORI blocked, valid EORI order placed with validation meta stored.

= 1.0.4 - 2026-06-03 =
* Removed the visible optional label from the EORI checkout field.
* Marked the EORI field required only when the selected destination country requires EORI validation.
* Added block checkout metadata and frontend label-state handling for conditional required/hidden behavior.
* Added regression coverage for required labels and block checkout field registration.

= 1.0.3 - 2026-06-03 =
* Added classic checkout country-based visibility so the EORI field appears only for configured EORI-required destinations.
* Kept Flexible Checkout Fields compatibility when checkout-field managers rebuild billing fields.
* Added regression coverage for the EORI field visibility hook and checkout-country script.

= 1.0.2 - 2026-06-02 =
* Fixed a classic checkout display conflict with Flexible Checkout Fields.
* Restored `billing_eori_number` after checkout-field managers rebuild billing fields.
* Added regression coverage for restored checkout-field behavior.

= 1.0.1 - 2026-06-02 =
* Removed the UK from the default EORI-required shipping country list.
* Kept GB EORI validation support available when stores manually require it.
* Added an upgrade migration that removes the UK only from untouched old defaults.
* Updated checkout/help text so UK shipping is not described as EORI-required by default.

= 1.0.0 - 2026-06-02 =
* Added the standalone EORI Number plugin.
* Added classic and block checkout support.
* Added configurable required shipping countries, registry validation, validation caching, order/customer meta storage, admin visibility, REST fields, privacy export/erase support, and validator test coverage.
