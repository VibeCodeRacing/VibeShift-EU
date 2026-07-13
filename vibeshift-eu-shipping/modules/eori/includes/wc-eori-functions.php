<?php
/**
 * Shared EORI helper functions.
 *
 * @package vibeshift-eu-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get EU member country codes used for EORI validation scope.
 *
 * @return array
 */
function wc_eori_get_eu_country_codes() {
	return array(
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'EL',
		'ES',
		'FI',
		'FR',
		'GR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	);
}

/**
 * Get default shipping countries requiring EORI validation.
 *
 * @return array
 */
function wc_eori_get_default_required_countries() {
	return wc_eori_get_eu_country_codes();
}

/**
 * Get configured countries requiring EORI validation.
 *
 * @return array
 */
function wc_eori_get_required_countries() {
	$countries = get_option( 'woocommerce_eori_required_countries', wc_eori_get_default_required_countries() );

	if ( is_string( $countries ) ) {
		$countries = array_filter( array_map( 'trim', explode( ',', $countries ) ) );
	}

	if ( ! is_array( $countries ) || empty( $countries ) ) {
		$countries = wc_eori_get_default_required_countries();
	}

	$countries = array_values(
		array_unique(
			array_map(
				'strtoupper',
				array_filter( $countries )
			)
		)
	);

	/**
	 * Filters the shipping countries that require EORI validation.
	 *
	 * @param array $countries ISO 3166 country codes.
	 */
	return apply_filters( 'woocommerce_eori_required_countries', $countries );
}

/**
 * Check whether an order destination requires EORI validation.
 *
 * @param string $country Country code.
 * @return bool
 */
function wc_eori_is_country_required( $country ) {
	return in_array( strtoupper( (string) $country ), wc_eori_get_required_countries(), true );
}

/**
 * Whether the current cart uses a separate shipping address at checkout.
 *
 * Mirrors WooCommerce's needs_shipping_address() so classic checkout validation
 * agrees with block checkout and order-level destination resolution.
 *
 * @return bool
 */
function wc_eori_cart_needs_shipping_address() {
	if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
		if ( is_callable( array( WC()->cart, 'needs_shipping_address' ) ) ) {
			return (bool) WC()->cart->needs_shipping_address();
		}

		if ( is_callable( array( WC()->cart, 'needs_shipping' ) ) ) {
			$needs_shipping = (bool) WC()->cart->needs_shipping();
			$billing_only   = function_exists( 'wc_ship_to_billing_address_only' ) && wc_ship_to_billing_address_only();

			return $needs_shipping && ! $billing_only;
		}
	}

	return false;
}

/**
 * Whether the current cart contains anything that physically ships.
 *
 * EORI is a customs identifier: it only applies when goods cross a border,
 * so carts with nothing to ship must never produce an EORI destination.
 * Defaults to true when no cart is available so a missing cart can never
 * reopen a validation bypass.
 *
 * @return bool
 */
function wc_eori_cart_needs_shipping() {
	if ( function_exists( 'WC' ) && WC() && WC()->cart && is_callable( array( WC()->cart, 'needs_shipping' ) ) ) {
		return (bool) WC()->cart->needs_shipping();
	}

	return true;
}

/**
 * Determine the checkout destination country from classic checkout POST data.
 *
 * EORI is required by the SHIPPING destination only. Returns an empty string
 * when nothing in the cart ships (no destination, EORI never required). When
 * goods ship without a separate shipping address, they ship to the billing
 * address, so the billing country is the shipping destination in that case.
 *
 * @param array $data Checkout POST data.
 * @return string
 */
function wc_eori_get_destination_country_from_posted_data( $data ) {
	$data = is_array( $data ) ? $data : array();

	if ( ! wc_eori_cart_needs_shipping() ) {
		return '';
	}

	$billing_country   = isset( $data['billing_country'] ) ? strtoupper( wc_clean( $data['billing_country'] ) ) : '';
	$shipping_country  = isset( $data['shipping_country'] ) ? strtoupper( wc_clean( $data['shipping_country'] ) ) : '';
	$ship_to_different = ! empty( $data['ship_to_different_address'] );

	if ( wc_eori_cart_needs_shipping_address() && $ship_to_different && $shipping_country ) {
		return $shipping_country;
	}

	return $billing_country;
}

/**
 * Determine the checkout destination country for an order.
 *
 * EORI is required by the SHIPPING destination only. Returns an empty string
 * when the order ships nothing, the shipping country when set, and falls back
 * to the billing country only for shippable orders without a separate shipping
 * address (goods then ship to the billing address).
 *
 * @param WC_Order $order Order object.
 * @return string
 */
function wc_eori_get_destination_country_from_order( $order ) {
	if ( ! $order || ! is_object( $order ) ) {
		return '';
	}

	$needs_shipping = true;

	if ( function_exists( 'WC' ) && WC() && WC()->cart && is_callable( array( WC()->cart, 'needs_shipping' ) ) ) {
		$needs_shipping = (bool) WC()->cart->needs_shipping();
	} elseif ( is_callable( array( $order, 'needs_shipping' ) ) ) {
		$needs_shipping = (bool) $order->needs_shipping();
	}

	if ( ! $needs_shipping ) {
		return '';
	}

	if ( is_callable( array( $order, 'get_shipping_country' ) ) && $order->get_shipping_country() ) {
		return strtoupper( (string) $order->get_shipping_country() );
	}

	if ( is_callable( array( $order, 'get_billing_country' ) ) ) {
		return strtoupper( (string) $order->get_billing_country() );
	}

	return '';
}

/**
 * Get an order's stored EORI number.
 *
 * @param WC_Order $order Order object.
 * @return string
 */
function wc_eori_get_eori_from_order( $order ) {
	if ( ! $order || ! is_object( $order ) || ! is_callable( array( $order, 'get_meta' ) ) ) {
		return '';
	}

	return strtoupper( (string) $order->get_meta( '_eori_number', true ) );
}

/**
 * Get the EORI value submitted for the current checkout from order meta.
 *
 * Block checkout persists the live field value to the additional checkout field
 * meta key on every update. Prefer that over canonical _eori_number, which can
 * retain a previous value after the customer clears the field.
 *
 * @param WC_Order $order Order object.
 * @return string
 */
function wc_eori_get_checkout_submitted_eori( $order ) {
	if ( ! $order || ! is_object( $order ) || ! is_callable( array( $order, 'get_meta' ) ) ) {
		return '';
	}

	$block_meta_key = WC_EORI_Number::BLOCK_FIELD_META_KEY;

	if ( is_callable( array( $order, 'meta_exists' ) ) && $order->meta_exists( $block_meta_key ) ) {
		return WC_EORI_Validator::normalize( (string) $order->get_meta( $block_meta_key, true ) );
	}

	return wc_eori_get_eori_from_order( $order );
}

/**
 * Get an order's EORI validation data.
 *
 * @param WC_Order $order Order object.
 * @return array
 */
function wc_eori_get_order_validation_data( $order ) {
	if ( ! $order || ! is_object( $order ) || ! is_callable( array( $order, 'get_meta' ) ) ) {
		return array();
	}

	return array(
		'eori'             => wc_eori_get_eori_from_order( $order ),
		'validated'        => wc_string_to_bool( $order->get_meta( '_eori_number_is_validated', true ) ),
		'valid'            => wc_string_to_bool( $order->get_meta( '_eori_number_is_valid', true ) ),
		'provider'         => $order->get_meta( '_eori_validation_provider', true ),
		'validation_date'  => $order->get_meta( '_eori_validation_date', true ),
		'company_name'     => $order->get_meta( '_eori_company_name', true ),
		'company_address'  => $order->get_meta( '_eori_company_address', true ),
		'error'            => $order->get_meta( '_eori_validation_error', true ),
	);
}

/**
 * Add an order note for a validation result.
 *
 * @param WC_Order $order Order object.
 * @param array    $result Validation result.
 * @return void
 */
function wc_eori_maybe_add_order_note( $order, $result ) {
	if ( ! $order || empty( $result['eori'] ) || ! is_callable( array( $order, 'add_order_note' ) ) ) {
		return;
	}

	if ( ! empty( $result['valid'] ) ) {
		$order->add_order_note(
			sprintf(
				/* translators: %1$s: EORI number, %2$s: provider */
				__( 'EORI number %1$s was validated successfully via %2$s.', 'vibeshift-eu-shipping' ),
				$result['eori'],
				strtoupper( $result['provider'] )
			)
		);
		return;
	}

	if ( ! empty( $result['error'] ) ) {
		$order->add_order_note(
			sprintf(
				/* translators: %1$s: EORI number, %2$s: error message */
				__( 'EORI validation failed for %1$s: %2$s', 'vibeshift-eu-shipping' ),
				$result['eori'],
				$result['error']
			)
		);
	}
}
