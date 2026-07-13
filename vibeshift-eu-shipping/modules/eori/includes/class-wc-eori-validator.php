<?php
/**
 * EORI validation client and parser.
 *
 * @package vibeshift-eu-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates EORI numbers against official registries.
 */
class WC_EORI_Validator {
	const PROVIDER_HMRC = 'hmrc';
	const PROVIDER_EU   = 'eu';

	const HMRC_ENDPOINT = 'https://api.service.hmrc.gov.uk/customs/eori/lookup/check-multiple-eori';
	const EU_ENDPOINT   = 'https://ec.europa.eu/taxation_customs/dds2/eos/eori_detail.jsp';

	/**
	 * Normalize an EORI number for validation and storage.
	 *
	 * @param string $eori Raw EORI.
	 * @return string
	 */
	public static function normalize( $eori ) {
		return strtoupper( preg_replace( '/[\s-]+/', '', trim( (string) $eori ) ) );
	}

	/**
	 * Validate local EORI format.
	 *
	 * @param string $eori EORI number.
	 * @return true|WP_Error
	 */
	public static function validate_format( $eori ) {
		$eori = self::normalize( $eori );

		if ( ! preg_match( '/^[A-Z]{2}[A-Z0-9]{1,15}$/', $eori ) ) {
			return new WP_Error(
				'wc-eori-format-error',
				__( 'Enter a valid EORI number. It must start with two uppercase country letters followed by up to 15 letters or numbers.', 'vibeshift-eu-shipping' )
			);
		}

		return true;
	}

	/**
	 * Get the validation provider for an EORI number.
	 *
	 * @param string $eori EORI number.
	 * @return string|WP_Error
	 */
	public static function get_provider_for_eori( $eori ) {
		$eori   = self::normalize( $eori );
		$prefix = substr( $eori, 0, 2 );

		if ( 'GB' === $prefix ) {
			return self::PROVIDER_HMRC;
		}

		if ( 'XI' === $prefix || in_array( $prefix, self::get_eu_country_codes(), true ) ) {
			return self::PROVIDER_EU;
		}

		return new WP_Error(
			'wc-eori-unsupported-prefix',
			sprintf(
				/* translators: %s: EORI country prefix */
				__( 'EORI numbers beginning with %s are not supported by this checkout validation.', 'vibeshift-eu-shipping' ),
				$prefix
			)
		);
	}

	/**
	 * Validate an EORI number using the appropriate official provider.
	 *
	 * @param string $eori Raw EORI number.
	 * @return array Validation result.
	 */
	public static function validate( $eori ) {
		$eori = self::normalize( $eori );

		$format = self::validate_format( $eori );
		if ( is_wp_error( $format ) ) {
			return self::error_result( $eori, '', $format, false );
		}

		$provider = self::get_provider_for_eori( $eori );
		if ( is_wp_error( $provider ) ) {
			return self::error_result( $eori, '', $provider, false );
		}

		$cache_key = self::get_cache_key( $eori );
		$cached    = self::get_cached_result( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( self::PROVIDER_HMRC === $provider ) {
			$result = self::validate_with_hmrc( $eori );
		} else {
			$result = self::validate_with_eu_checker( $eori );
		}

		if ( is_wp_error( $result ) ) {
			return self::error_result( $eori, $provider, $result, false );
		}

		$result = self::apply_result_filter( $result );

		if ( ! empty( $result['validated'] ) ) {
			self::set_cached_result( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Parse an HMRC Check an EORI Number API response.
	 *
	 * @param string $body Response body.
	 * @param string $eori Normalized EORI number.
	 * @return array|WP_Error
	 */
	public static function parse_hmrc_response( $body, $eori ) {
		$data = json_decode( (string) $body, true );

		if ( ! is_array( $data ) || empty( $data[0] ) || ! is_array( $data[0] ) ) {
			return new WP_Error( 'wc-eori-hmrc-parse-error', __( 'The HMRC EORI validation response could not be understood.', 'vibeshift-eu-shipping' ) );
		}

		$item = null;
		foreach ( $data as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate['eori'] ) && self::normalize( $candidate['eori'] ) === self::normalize( $eori ) ) {
				$item = $candidate;
				break;
			}
		}

		if ( ! $item ) {
			$item = $data[0];
		}

		$valid           = ! empty( $item['valid'] );
		$company_details = isset( $item['companyDetails'] ) && is_array( $item['companyDetails'] ) ? $item['companyDetails'] : array();
		$address         = isset( $company_details['address'] ) && is_array( $company_details['address'] ) ? $company_details['address'] : array();

		return self::base_result(
			$eori,
			self::PROVIDER_HMRC,
			$valid,
			$item['processingDate'] ?? gmdate( 'c' ),
			$company_details['traderName'] ?? '',
			self::format_hmrc_address( $address ),
			$valid ? '' : __( 'This EORI number is not valid.', 'vibeshift-eu-shipping' )
		);
	}

	/**
	 * Parse the European Commission EORI checker detail response.
	 *
	 * @param string $body Response body.
	 * @param string $eori Normalized EORI number.
	 * @return array|WP_Error
	 */
	public static function parse_eu_response( $body, $eori ) {
		$body = (string) $body;

		if ( false !== stripos( $body, 'This EORI number is not valid' ) ) {
			return self::base_result(
				$eori,
				self::PROVIDER_EU,
				false,
				self::extract_eu_table_value( $body, array( 'Request date' ) ),
				'',
				'',
				__( 'This EORI number is not valid.', 'vibeshift-eu-shipping' )
			);
		}

		if ( false === stripos( $body, 'This EORI number is valid' ) ) {
			return new WP_Error( 'wc-eori-eu-parse-error', __( 'The EU EORI validation response could not be understood.', 'vibeshift-eu-shipping' ) );
		}

		return self::base_result(
			$eori,
			self::PROVIDER_EU,
			true,
			self::extract_eu_table_value( $body, array( 'Request date' ) ),
			self::extract_eu_table_value( $body, array( 'Name', 'Trader name', 'Economic operator name' ) ),
			self::extract_eu_table_value( $body, array( 'Address', 'Economic operator address' ) ),
			''
		);
	}

	/**
	 * Validate using HMRC.
	 *
	 * @param string $eori Normalized EORI number.
	 * @return array|WP_Error
	 */
	private static function validate_with_hmrc( $eori ) {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return new WP_Error( 'wc-eori-http-unavailable', __( 'WordPress HTTP functions are unavailable for EORI validation.', 'vibeshift-eu-shipping' ) );
		}

		$response = wp_remote_post(
			self::HMRC_ENDPOINT,
			array(
				'timeout' => self::get_http_timeout(),
				'headers' => array(
					'Accept'       => 'application/vnd.hmrc.1.0+json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array( 'eoris' => array( $eori ) ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wc-eori-hmrc-error', __( 'Error communicating with the HMRC EORI validation service. Please try again.', 'vibeshift-eu-shipping' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'wc-eori-hmrc-error', __( 'The HMRC EORI validation service is unavailable. Please try again.', 'vibeshift-eu-shipping' ) );
		}

		return self::parse_hmrc_response( wp_remote_retrieve_body( $response ), $eori );
	}

	/**
	 * Validate using the European Commission checker.
	 *
	 * @param string $eori Normalized EORI number.
	 * @return array|WP_Error
	 */
	private static function validate_with_eu_checker( $eori ) {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'wc-eori-http-unavailable', __( 'WordPress HTTP functions are unavailable for EORI validation.', 'vibeshift-eu-shipping' ) );
		}

		$url = add_query_arg(
			array(
				'Lang'     => 'en',
				'EoriNumb' => $eori,
			),
			self::EU_ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::get_http_timeout(),
				'headers' => array(
					'User-Agent' => 'VibeShift EU Shipping/' . ( defined( 'WC_EORI_VAT_VERSION' ) ? WC_EORI_VAT_VERSION : ( defined( 'WC_EORI_VERSION' ) ? WC_EORI_VERSION : '1.1.0' ) ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wc-eori-eu-error', __( 'Error communicating with the EU EORI validation service. Please try again.', 'vibeshift-eu-shipping' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'wc-eori-eu-error', __( 'The EU EORI validation service is unavailable. Please try again.', 'vibeshift-eu-shipping' ) );
		}

		return self::parse_eu_response( wp_remote_retrieve_body( $response ), $eori );
	}

	/**
	 * Build a standard validation result.
	 *
	 * @param string $eori EORI number.
	 * @param string $provider Provider.
	 * @param bool   $valid Is valid.
	 * @param string $validation_date Validation date.
	 * @param string $company_name Company name.
	 * @param string $company_address Company address.
	 * @param string $error Error message.
	 * @return array
	 */
	private static function base_result( $eori, $provider, $valid, $validation_date = '', $company_name = '', $company_address = '', $error = '' ) {
		return array(
			'eori'             => self::normalize( $eori ),
			'valid'            => (bool) $valid,
			'validated'        => true,
			'provider'         => $provider,
			'validation_date'  => $validation_date ? $validation_date : gmdate( 'c' ),
			'company_name'     => self::normalize_text_value( $company_name ),
			'company_address'  => self::normalize_text_value( $company_address ),
			'error'            => self::normalize_text_value( $error ),
		);
	}

	/**
	 * Build a standard error result.
	 *
	 * @param string   $eori EORI number.
	 * @param string   $provider Provider.
	 * @param WP_Error $error Error.
	 * @param bool     $validated Whether the registry responded.
	 * @return array
	 */
	private static function error_result( $eori, $provider, $error, $validated ) {
		return array(
			'eori'             => self::normalize( $eori ),
			'valid'            => false,
			'validated'        => (bool) $validated,
			'provider'         => $provider,
			'validation_date'  => '',
			'company_name'     => '',
			'company_address'  => '',
			'error'            => $error->get_error_message(),
			'error_code'       => $error->get_error_code(),
		);
	}

	/**
	 * Format HMRC address fields into one line.
	 *
	 * @param array $address Address fields.
	 * @return string
	 */
	private static function format_hmrc_address( $address ) {
		$parts = array();
		foreach ( array( 'streetAndNumber', 'cityName', 'postcode' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$parts[] = self::normalize_text_value( $address[ $key ] );
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Extract a value from the EU checker table by possible labels.
	 *
	 * @param string $body HTML body.
	 * @param array  $labels Possible labels.
	 * @return string
	 */
	private static function extract_eu_table_value( $body, $labels ) {
		if ( ! preg_match_all( '/<tr\b[^>]*>(.*?)<\/tr>/is', $body, $rows ) ) {
			return '';
		}

		foreach ( $rows[1] as $row ) {
			if ( ! preg_match_all( '/<td\b[^>]*>(.*?)<\/td>/is', $row, $cells ) || count( $cells[1] ) < 2 ) {
				continue;
			}

			$label = self::normalize_text_value( $cells[1][0] );
			$value = self::normalize_text_value( $cells[1][1] );

			foreach ( $labels as $candidate ) {
				if ( 0 === strcasecmp( $label, $candidate ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize text extracted from a response.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalize_text_value( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Get EU member country codes.
	 *
	 * @return array
	 */
	private static function get_eu_country_codes() {
		if ( function_exists( 'wc_eori_get_eu_country_codes' ) ) {
			return wc_eori_get_eu_country_codes();
		}

		return array(
			'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES',
			'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV',
			'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
		);
	}

	/**
	 * Apply result extension filter.
	 *
	 * @param array $result Validation result.
	 * @return array
	 */
	private static function apply_result_filter( $result ) {
		if ( function_exists( 'apply_filters' ) ) {
			$result = apply_filters( 'woocommerce_eori_validation_result', $result );
		}
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Get HTTP timeout.
	 *
	 * @return int
	 */
	private static function get_http_timeout() {
		$timeout = function_exists( 'get_option' ) ? (int) get_option( 'woocommerce_eori_http_timeout', 15 ) : 15;
		if ( function_exists( 'apply_filters' ) ) {
			$timeout = (int) apply_filters( 'woocommerce_eori_http_timeout', $timeout );
		}
		return max( 1, $timeout );
	}

	/**
	 * Get cache duration.
	 *
	 * @return int
	 */
	private static function get_cache_duration() {
		$default  = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$duration = function_exists( 'get_option' ) ? (int) get_option( 'woocommerce_eori_cache_duration', $default ) : $default;
		if ( function_exists( 'apply_filters' ) ) {
			$duration = (int) apply_filters( 'woocommerce_eori_cache_duration', $duration );
		}
		return max( 0, $duration );
	}

	/**
	 * Get transient cache key.
	 *
	 * @param string $eori EORI number.
	 * @return string
	 */
	private static function get_cache_key( $eori ) {
		return 'wc_eori_' . md5( self::normalize( $eori ) );
	}

	/**
	 * Read a cached validation result.
	 *
	 * @param string $cache_key Cache key.
	 * @return array|false
	 */
	private static function get_cached_result( $cache_key ) {
		if ( ! function_exists( 'get_transient' ) ) {
			return false;
		}

		$cached = get_transient( $cache_key );
		return is_array( $cached ) ? $cached : false;
	}

	/**
	 * Cache a validation result.
	 *
	 * @param string $cache_key Cache key.
	 * @param array  $result Validation result.
	 * @return void
	 */
	private static function set_cached_result( $cache_key, $result ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$duration = self::get_cache_duration();
		if ( $duration > 0 ) {
			set_transient( $cache_key, $result, $duration );
		}
	}
}
