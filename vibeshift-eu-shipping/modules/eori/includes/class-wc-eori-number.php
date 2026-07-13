<?php
/**
 * EORI checkout integration.
 *
 * @package vibeshift-eu-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main checkout integration class.
 */
class WC_EORI_Number {
	const BLOCK_FIELD_ID         = 'vibeshift-eu-shipping/eori-number';
	const BLOCK_FIELD_META_KEY   = '_wc_other/vibeshift-eu-shipping/eori-number';
	const CLASSIC_FIELD_KEY      = 'billing_eori_number';
	const CUSTOMER_META_KEY      = 'eori_number';

	/**
	 * Last successful checkout validation result for classic checkout.
	 *
	 * @var array|null
	 */
	private static $checkout_result = null;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'add_classic_checkout_field' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_classic_checkout_field_to_checkout_fields' ), 10000 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_classic_checkout_visibility_script' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'process_classic_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'set_order_data' ) );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'set_customer_data' ) );

		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_checkout_field' ) );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( __CLASS__, 'validate_order_before_payment' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'normalize_store_api_order_meta' ), 10, 2 );

		add_filter( 'woocommerce_api_order_response', array( __CLASS__, 'add_eori_to_order_response' ) );
		add_filter( 'woocommerce_rest_prepare_shop_order', array( __CLASS__, 'add_eori_to_order_response' ) );

		add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'add_email_order_meta_fields' ), 10, 3 );
	}

	/**
	 * Get checkout field label.
	 *
	 * @return string
	 */
	public static function get_field_label() {
		return get_option( 'woocommerce_eori_field_label', __( 'EORI number', 'vibeshift-eu-shipping' ) );
	}

	/**
	 * Get checkout field description.
	 *
	 * @return string
	 */
	public static function get_field_description() {
		return get_option( 'woocommerce_eori_field_description', __( 'Required when the shipping destination is configured for EORI validation.', 'vibeshift-eu-shipping' ) );
	}

	/**
	 * Add EORI number to classic checkout billing fields.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public static function add_classic_checkout_field( $fields ) {
		if ( is_wc_endpoint_url( 'edit-address' ) ) {
			return $fields;
		}

		$default = '';
		if ( WC() && WC()->session ) {
			$default = WC()->session->get( self::CUSTOMER_META_KEY, '' );
		}

		$user_id = get_current_user_id();
		if ( empty( $default ) && $user_id ) {
			$default = get_user_meta( $user_id, self::CUSTOMER_META_KEY, true );
		}

		$fields[ self::CLASSIC_FIELD_KEY ] = array(
			'label'       => self::get_field_label(),
			'default'     => $default,
			'required'    => false,
			'class'       => array( 'form-row-wide', 'wc-eori-number-field' ),
			'description' => self::get_field_description(),
			'id'          => 'woocommerce_eori_number',
			'priority'    => 125,
		);

		return $fields;
	}

	/**
	 * Ensure the EORI field is present in the final classic checkout field list.
	 *
	 * Some checkout-field managers rebuild section fields after
	 * woocommerce_billing_fields has already run.
	 *
	 * @param array $checkout_fields Checkout fields grouped by section.
	 * @return array
	 */
	public static function add_classic_checkout_field_to_checkout_fields( $checkout_fields ) {
		if ( ! is_array( $checkout_fields ) ) {
			return $checkout_fields;
		}

		if ( ! isset( $checkout_fields['billing'] ) || ! is_array( $checkout_fields['billing'] ) ) {
			$checkout_fields['billing'] = array();
		}

		$checkout_fields['billing'] = self::add_classic_checkout_field( $checkout_fields['billing'] );

		return $checkout_fields;
	}

	/**
	 * Enqueue classic checkout visibility behavior.
	 *
	 * @return void
	 */
	public static function enqueue_classic_checkout_visibility_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		wp_register_script( 'wc-eori-number-classic-checkout', false, array( 'jquery' ), WC_EORI_VERSION, true );
		wp_localize_script(
			'wc-eori-number-classic-checkout',
			'wc_eori_number_params',
			array(
				'required_countries'  => array_values( wc_eori_get_required_countries() ),
				'cart_needs_shipping' => wc_eori_cart_needs_shipping(),
				'field_label'         => self::get_field_label(),
				'block_field_id'      => self::BLOCK_FIELD_ID,
				'required_title'      => __( 'required', 'vibeshift-eu-shipping' ),
			)
		);
		wp_add_inline_script( 'wc-eori-number-classic-checkout', self::get_classic_checkout_visibility_script() );
		wp_enqueue_script( 'wc-eori-number-classic-checkout' );
	}

	/**
	 * Get classic checkout EORI visibility script.
	 *
	 * @return string
	 */
	public static function get_classic_checkout_visibility_script() {
		return <<<'JS'
( function( $ ) {
	'use strict';

	function getRequiredCountries() {
		if ( typeof wc_eori_number_params === 'undefined' || ! $.isArray( wc_eori_number_params.required_countries ) ) {
			return [];
		}

		return wc_eori_number_params.required_countries;
	}

	function getParam( key, fallback ) {
		if ( typeof wc_eori_number_params === 'undefined' || typeof wc_eori_number_params[ key ] === 'undefined' ) {
			return fallback;
		}

		return wc_eori_number_params[ key ];
	}

	function isRequiredCountry( country ) {
		country = country ? String( country ).toUpperCase() : '';

		return country && $.inArray( country, getRequiredCountries() ) >= 0;
	}

	function cartNeedsShippingAddress() {
		return $( '#ship-to-different-address-checkbox' ).length > 0;
	}

	function getClassicDestinationCountry() {
		var billingCountry = $( '#billing_country' ).val();
		var shippingCountry = $( '#shipping_country' ).val();
		var shipToDifferent = $( '#ship-to-different-address-checkbox' ).is( ':checked' );

		// EORI follows the shipping destination only: no shippable items, no destination.
		if ( ! getParam( 'cart_needs_shipping', true ) ) {
			return '';
		}

		if ( cartNeedsShippingAddress() && shipToDifferent && shippingCountry ) {
			return shippingCountry;
		}

		return billingCountry;
	}

	function getBlockDestinationCountry() {
		var cartStore;
		var cartData;
		var customerData;
		var address;

		if ( window.wp && window.wp.data && window.wp.data.select && window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.cartStore ) {
			cartStore = window.wc.wcBlocksData.cartStore;
			cartData = window.wp.data.select( cartStore ).getCartData();
			customerData = window.wp.data.select( cartStore ).getCustomerData();
			address = cartData && cartData.needsShipping && customerData && customerData.shippingAddress ? customerData.shippingAddress : customerData && customerData.billingAddress;

			if ( address && address.country ) {
				return address.country;
			}
		}

		return '';
	}

	function getBlockEoriInput() {
		var fieldId = getParam( 'block_field_id', 'vibeshift-eu-shipping/eori-number' );
		var inputName = 'order_' + fieldId;
		var inputId = 'order-' + fieldId.replace( /\//g, '-' );
		var fieldClass = '.wc-block-components-address-form__' + fieldId.replace( /\//g, '-' );
		var $target = $( '[name="' + inputName + '"], [name="order_vibeshift-eu-shipping/eori-number"], #' + inputId + ', #order-vibeshift-eu-shipping-eori-number, ' + fieldClass ).first();

		if ( $target.length && ! $target.is( ':input' ) ) {
			return $target.find( 'input, select, textarea' ).first();
		}

		return $target;
	}

	function getBlockEoriWrapper( $input ) {
		var $wrapper = $input.closest( '.wc-block-components-text-input' );

		if ( ! $wrapper.length ) {
			$wrapper = $input.parent();
		}

		return $wrapper;
	}

	function cleanOptionalLabel( $label ) {
		var fieldLabel = getParam( 'field_label', 'EORI number' );

		$label.find( '.optional' ).remove();
		$label.contents().filter( function() {
			return this.nodeType === 3 && /\s*\(optional\)/i.test( this.nodeValue );
		} ).each( function() {
			this.nodeValue = this.nodeValue.replace( /\s*\(optional\)/i, '' );
		} );

		if ( $.trim( $label.text() ) === fieldLabel + ' (optional)' ) {
			$label.text( fieldLabel );
		}
	}

	function setRequiredLabelState( $label, isRequired ) {
		cleanOptionalLabel( $label );

		if ( isRequired ) {
			if ( ! $label.find( '.required, .wc-eori-required-indicator' ).length ) {
				$label.append( ' ' ).append( $( '<abbr />', {
					'class': 'required wc-eori-required-indicator',
					title: getParam( 'required_title', 'required' ),
					text: '*'
				} ) );
			}
		} else {
			$label.find( '.wc-eori-required-indicator' ).remove();
		}
	}

	function setFieldRequiredState( $field, $input, isRequired ) {
		var inputId = $input.attr( 'id' );
		var $label = inputId ? $field.find( 'label[for="' + inputId + '"]' ).first() : $field.find( 'label' ).first();

		if ( ! $label.length ) {
			$label = $field.find( 'label' ).first();
		}

		$field.find( 'label .optional' ).remove();
		$field.toggleClass( 'validate-required', isRequired );
		$input.prop( 'required', isRequired );

		if ( isRequired ) {
			$input.attr( 'aria-required', 'true' );
		} else {
			$input.removeAttr( 'aria-required' );
		}

		setRequiredLabelState( $label, isRequired );
	}

	function toggleClassicEoriField() {
		var $field = $( '#woocommerce_eori_number_field' );
		var $input = $( '#woocommerce_eori_number' );
		var isRequired = isRequiredCountry( getClassicDestinationCountry() );

		if ( ! $field.length ) {
			return;
		}

		setFieldRequiredState( $field, $input, isRequired );

		if ( isRequired ) {
			$field.fadeIn();
		} else {
			$field.fadeOut();
		}
	}

	function toggleBlockEoriField() {
		var $input = getBlockEoriInput();
		var $field;
		var isRequired;

		if ( ! $input.length ) {
			return;
		}

		$field = getBlockEoriWrapper( $input );
		isRequired = isRequiredCountry( getBlockDestinationCountry() || getClassicDestinationCountry() );
		setFieldRequiredState( $field, $input, isRequired );
		$field.toggle( isRequired );
	}

	function toggleEoriField() {
		toggleClassicEoriField();
		toggleBlockEoriField();
	}

	function scheduleToggleEoriField() {
		window.clearTimeout( window.wcEoriNumberToggleTimer );
		window.wcEoriNumberToggleTimer = window.setTimeout( toggleEoriField, 50 );
	}

	$( document.body ).on( 'change', '#billing_country, #shipping_country, #ship-to-different-address-checkbox', toggleEoriField );
	$( document.body ).on( 'updated_checkout', toggleEoriField );

	if ( window.wp && window.wp.data && window.wp.data.subscribe && ! window.wcEoriNumberSubscribed ) {
		window.wcEoriNumberSubscribed = true;
		window.wp.data.subscribe( scheduleToggleEoriField );
	}

	if ( window.MutationObserver && ! window.wcEoriNumberObserver ) {
		$( function() {
			var target = document.querySelector( '.wp-block-woocommerce-checkout, form.checkout' );

			if ( target ) {
				window.wcEoriNumberObserver = new window.MutationObserver( scheduleToggleEoriField );
				window.wcEoriNumberObserver.observe( target, { childList: true, subtree: true } );
			}
		} );
	}

	toggleEoriField();
}( jQuery ) );
JS;
	}

	/**
	 * Get block checkout field registration arguments.
	 *
	 * @return array
	 */
	public static function get_block_checkout_field_args() {
		$required_schema = self::get_destination_country_required_schema();

		return array(
			'id'                => self::BLOCK_FIELD_ID,
			'label'             => self::get_field_label(),
			'optionalLabel'     => self::get_field_label(),
			'location'          => 'order',
			'type'              => 'text',
			'required'          => $required_schema,
			'hidden'            => array(
				'not' => $required_schema,
			),
			'sanitize_callback' => array( __CLASS__, 'sanitize_block_field' ),
			'validate_callback' => array( __CLASS__, 'validate_block_field' ),
			'attributes'        => array(
				'autocomplete' => 'off',
				'pattern'      => '[A-Za-z]{2}[A-Za-z0-9\\s-]{1,20}',
				'title'        => __( 'Enter an EORI number beginning with two country letters.', 'vibeshift-eu-shipping' ),
			),
		);
	}

	/**
	 * Build the block checkout conditional schema for EORI-required destinations.
	 *
	 * @return array
	 */
	private static function get_destination_country_required_schema() {
		$required_countries = array_values( wc_eori_get_required_countries() );

		if ( empty( $required_countries ) ) {
			return array(
				'type'       => 'object',
				'required'   => array( 'customer' ),
				'properties' => array(
					'customer' => array(
						'type'       => 'object',
						'required'   => array( 'billing_address' ),
						'properties' => array(
							'billing_address' => array(
								'type'       => 'object',
								'required'   => array( 'country' ),
								'properties' => array(
									'country' => array(
										'const' => '__wc_eori_no_required_countries__',
									),
								),
							),
						),
					),
				),
			);
		}

		// EORI is required by the SHIPPING destination only. Carts that ship
		// nothing (needsShipping=false) must never require the field — do not
		// add billing-country branches for non-shipping carts here; that was
		// the PR #24 regression. Billing-only stores are covered because the
		// Store API mirrors the billing address into the shipping address.
		return array(
			'anyOf' => array(
				self::get_destination_address_schema( 'shipping_address', 'needs_shipping', true, $required_countries ),
				self::get_destination_address_schema( 'shippingAddress', 'needsShipping', true, $required_countries ),
			),
		);
	}

	/**
	 * Build one checkout destination country schema branch.
	 *
	 * @param string $address_key Address object key.
	 * @param string $needs_shipping_key Cart needs-shipping key.
	 * @param bool   $needs_shipping Whether the cart needs shipping for this branch.
	 * @param array  $required_countries Countries that require EORI.
	 * @return array
	 */
	private static function get_destination_address_schema( $address_key, $needs_shipping_key, $needs_shipping, $required_countries ) {
		return array(
			'type'       => 'object',
			'required'   => array( 'cart', 'customer' ),
			'properties' => array(
				'cart'     => array(
					'type'       => 'object',
					'required'   => array( $needs_shipping_key ),
					'properties' => array(
						$needs_shipping_key => array(
							'const' => $needs_shipping,
						),
					),
				),
				'customer' => array(
					'type'       => 'object',
					'required'   => array( $address_key ),
					'properties' => array(
						$address_key => array(
							'type'       => 'object',
							'required'   => array( 'country' ),
							'properties' => array(
								'country' => array(
									'enum' => $required_countries,
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Register EORI number for block checkout.
	 *
	 * @return void
	 */
	public static function register_block_checkout_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		try {
			woocommerce_register_additional_checkout_field( self::get_block_checkout_field_args() );
		} catch ( Exception $e ) {
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					function () use ( $e ) {
						echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
					}
				);
			}
		}
	}

	/**
	 * Sanitize block checkout field.
	 *
	 * @param mixed $field_value Field value.
	 * @return string
	 */
	public static function sanitize_block_field( $field_value ) {
		return WC_EORI_Validator::normalize( $field_value );
	}

	/**
	 * Validate the block checkout field local format.
	 *
	 * Final required-country and registry validation runs against the order before payment.
	 *
	 * @param mixed $field_value Field value.
	 * @return true|WP_Error
	 */
	public static function validate_block_field( $field_value ) {
		$field_value = WC_EORI_Validator::normalize( $field_value );

		if ( '' === $field_value ) {
			return true;
		}

		return WC_EORI_Validator::validate_format( $field_value );
	}

	/**
	 * Validate classic checkout submission.
	 *
	 * @return void
	 */
	public static function process_classic_checkout() {
		self::$checkout_result = null;

		$data    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$country = wc_eori_get_destination_country_from_posted_data( $data );
		$eori    = self::get_eori_from_posted_checkout_data( $data );
		$result  = self::validate_eori_for_country( $eori, $country );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return;
		}

		if ( is_array( $result ) ) {
			self::$checkout_result = $result;
			if ( WC() && WC()->session ) {
				WC()->session->set( self::CUSTOMER_META_KEY, $result['eori'] );
			}
		}
	}

	/**
	 * Store block checkout field in canonical meta as early as possible.
	 *
	 * @param WC_Order        $order Order.
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function normalize_store_api_order_meta( $order, $request ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$additional_fields = $request instanceof WP_REST_Request ? (array) $request->get_param( 'additional_fields' ) : array();

		if ( array_key_exists( self::BLOCK_FIELD_ID, $additional_fields ) ) {
			$eori = WC_EORI_Validator::normalize( $additional_fields[ self::BLOCK_FIELD_ID ] );

			if ( $eori ) {
				$order->update_meta_data( '_eori_number', $eori );
			} else {
				$order->delete_meta_data( '_eori_number' );
			}

			return;
		}

		$eori = wc_eori_get_checkout_submitted_eori( $order );

		if ( $eori ) {
			$order->update_meta_data( '_eori_number', $eori );
		}
	}

	/**
	 * Validate Store API checkout before payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param WP_Error $errors Validation errors.
	 * @return void
	 */
	public static function validate_order_before_payment( $order, $errors ) {
		if ( ! $order instanceof WC_Order || ! $errors instanceof WP_Error ) {
			return;
		}

		$country = wc_eori_get_destination_country_from_order( $order );
		$eori    = wc_eori_get_checkout_submitted_eori( $order );
		$result  = self::validate_eori_for_country( $eori, $country );

		if ( is_wp_error( $result ) ) {
			if ( $eori ) {
				wc_eori_maybe_add_order_note(
					$order,
					array(
						'eori'     => WC_EORI_Validator::normalize( $eori ),
						'valid'    => false,
						'provider' => '',
						'error'    => $result->get_error_message(),
					)
				);
			}
			$errors->add( 'woocommerce_eori_validation_error', $result->get_error_message() );
			return;
		}

		if ( is_array( $result ) ) {
			self::save_validation_result_to_order( $order, $result );
		}
	}

	/**
	 * Save EORI validation data to an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function set_order_data( $order ) {
		if ( ! $order instanceof WC_Order || ! is_array( self::$checkout_result ) ) {
			return;
		}

		self::save_validation_result_to_order( $order, self::$checkout_result );
	}

	/**
	 * Save EORI data to the customer profile.
	 *
	 * @param WC_Customer $customer Customer object.
	 * @return void
	 */
	public static function set_customer_data( $customer ) {
		if ( ! $customer instanceof WC_Customer || empty( self::$checkout_result['eori'] ) || empty( self::$checkout_result['valid'] ) ) {
			return;
		}

		$customer->update_meta_data( self::CUSTOMER_META_KEY, self::$checkout_result['eori'] );
	}

	/**
	 * Print labeled EU VAT and EORI numbers on order emails.
	 *
	 * The EU VAT plugin's own address-format line does not render with the
	 * store's checkout-field setup, leaving the VAT value unlabeled in the
	 * address block, and the EORI number was not printed at all.
	 *
	 * @param array    $fields        Email meta fields.
	 * @param bool     $sent_to_admin Whether the email is sent to the admin.
	 * @param WC_Order $order         Order object.
	 * @return array
	 */
	public static function add_email_order_meta_fields( $fields, $sent_to_admin, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $fields;
		}

		$vat = (string) $order->get_meta( '_billing_vat_number', true );

		if ( '' === $vat ) {
			$vat = (string) $order->get_meta( '_vat_number', true );
		}

		if ( '' !== $vat ) {
			$fields['eu_vat_number'] = array(
				'label' => __( 'EU VAT Number', 'vibeshift-eu-shipping' ),
				'value' => $vat,
			);
		}

		$eori = wc_eori_get_eori_from_order( $order );

		if ( '' !== $eori ) {
			$fields['eori_number'] = array(
				'label' => __( 'EORI Number', 'vibeshift-eu-shipping' ),
				'value' => $eori,
			);
		}

		return $fields;
	}

	/**
	 * Add EORI fields to REST order responses.
	 *
	 * @param WP_REST_Response|array $response Response.
	 * @return WP_REST_Response|array
	 */
	public static function add_eori_to_order_response( $response ) {
		if ( is_a( $response, 'WP_REST_Response' ) && ! empty( $response->data['id'] ) ) {
			$order                              = wc_get_order( (int) $response->data['id'] );
			$response->data['eori_number']      = wc_eori_get_eori_from_order( $order );
			$response->data['eori_validation']  = wc_eori_get_order_validation_data( $order );
		} elseif ( is_array( $response ) && ! empty( $response['id'] ) ) {
			$order                       = wc_get_order( (int) $response['id'] );
			$response['eori_number']     = wc_eori_get_eori_from_order( $order );
			$response['eori_validation'] = wc_eori_get_order_validation_data( $order );
		}

		return $response;
	}

	/**
	 * Validate an EORI value for a destination country.
	 *
	 * @param string $eori Raw EORI.
	 * @param string $country Destination country.
	 * @return array|WP_Error|null
	 */
	private static function validate_eori_for_country( $eori, $country ) {
		$eori       = WC_EORI_Validator::normalize( $eori );
		$is_required = wc_eori_is_country_required( $country );

		if ( ! $is_required ) {
			return null;
		}

		if ( '' === $eori ) {
			return new WP_Error(
				'wc-eori-required',
				sprintf(
					/* translators: %s: destination country code */
					__( 'EORI number is required for orders shipping to %s.', 'vibeshift-eu-shipping' ),
					$country
				)
			);
		}

		$result = WC_EORI_Validator::validate( $eori );

		if ( empty( $result['valid'] ) ) {
			return new WP_Error(
				$result['error_code'] ?? 'wc-eori-invalid',
				! empty( $result['error'] ) ? $result['error'] : __( 'The EORI number could not be validated.', 'vibeshift-eu-shipping' )
			);
		}

		return $result;
	}

	/**
	 * Persist validation result to order meta.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $result Validation result.
	 * @return void
	 */
	private static function save_validation_result_to_order( $order, $result ) {
		$previous_eori  = $order->get_meta( '_eori_number', true );
		$previous_valid = $order->get_meta( '_eori_number_is_valid', true );

		$order->update_meta_data( '_eori_number', $result['eori'] ?? '' );
		$order->update_meta_data( '_eori_number_is_validated', ! empty( $result['validated'] ) ? 'true' : 'false' );
		$order->update_meta_data( '_eori_number_is_valid', ! empty( $result['valid'] ) ? 'true' : 'false' );
		$order->update_meta_data( '_eori_validation_provider', $result['provider'] ?? '' );
		$order->update_meta_data( '_eori_validation_date', $result['validation_date'] ?? '' );
		$order->update_meta_data( '_eori_company_name', $result['company_name'] ?? '' );
		$order->update_meta_data( '_eori_company_address', $result['company_address'] ?? '' );
		$order->update_meta_data( '_eori_validation_error', $result['error'] ?? '' );

		if ( ( $previous_eori !== ( $result['eori'] ?? '' ) ) || ( $previous_valid !== ( ! empty( $result['valid'] ) ? 'true' : 'false' ) ) ) {
			wc_eori_maybe_add_order_note( $order, $result );
		}

		if ( ! empty( $result['valid'] ) && ! empty( $result['eori'] ) && $order->get_customer_id() ) {
			$customer = new WC_Customer( $order->get_customer_id() );
			$customer->update_meta_data( self::CUSTOMER_META_KEY, $result['eori'] );
			$customer->save_meta_data();
		}
	}

	/**
	 * Get EORI from classic checkout post data.
	 *
	 * @param array $data Checkout data.
	 * @return string
	 */
	private static function get_eori_from_posted_checkout_data( $data ) {
		$field_keys = array(
			self::CLASSIC_FIELD_KEY,
			'woocommerce_eori_number',
		);

		foreach ( $field_keys as $field_key ) {
			if ( isset( $data[ $field_key ] ) ) {
				return wc_clean( $data[ $field_key ] );
			}
		}

		return '';
	}
}
