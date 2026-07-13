<?php
/**
 * Standalone tests for the merged VibeShift EU Shipping plugin.
 *
 * Loads shipped module code (not re-implementations) and asserts pure logic:
 * EORI normalize/format, destination rules, FCF field restore, email meta labels,
 * EU VAT normalize/format.
 *
 * Run: php tests/merged-plugin-test.php
 *
 * @package vibeshift-eu-shipping
 */

// Block direct web access while still allowing the CLI test runner to bootstrap.
if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$plugin_root = dirname( __DIR__ );
$eori_root   = $plugin_root . '/modules/eori';
$vat_root    = $plugin_root . '/modules/eu-vat';

if ( ! defined( 'WC_EORI_VERSION' ) ) {
	define( 'WC_EORI_VERSION', '1.0.8' );
}
if ( ! defined( 'WC_EORI_FILE' ) ) {
	define( 'WC_EORI_FILE', $plugin_root . '/vibeshift-eu-shipping.php' );
}
if ( ! defined( 'WC_EORI_ABSPATH' ) ) {
	define( 'WC_EORI_ABSPATH', $eori_root . '/' );
}
if ( ! defined( 'WC_EU_VAT_VERSION' ) ) {
	define( 'WC_EU_VAT_VERSION', '3.1.0' );
}
if ( ! defined( 'WC_EU_VAT_FILE' ) ) {
	define( 'WC_EU_VAT_FILE', $vat_root . '/eu-vat-number.php' );
}
if ( ! defined( 'WC_EU_ABSPATH' ) ) {
	define( 'WC_EU_ABSPATH', $vat_root . '/' );
}
if ( ! defined( 'WC_EU_VAT_PLUGIN_URL' ) ) {
	define( 'WC_EU_VAT_PLUGIN_URL', 'http://example.test/wp-content/plugins/vibeshift-eu-shipping/modules/eu-vat' );
}

// --- Minimal WordPress / WooCommerce stubs ---------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $errors = array();

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;

			if ( '' !== $code ) {
				$this->add( $code, $message );
			}
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function add( $code, $message, $data = '' ) {
			if ( '' === $this->code ) {
				$this->code = $code;
			}
			if ( '' === $this->message ) {
				$this->message = $message;
			}
			$this->errors[ $code ][] = $message;
		}

		public function get_error_messages( $code = '' ) {
			if ( '' !== $code ) {
				return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
			}

			$messages = array();
			foreach ( $this->errors as $code_messages ) {
				$messages = array_merge( $messages, $code_messages );
			}

			return $messages;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) {
		return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
	}
}

if ( ! function_exists( 'wc_clean' ) ) {
	function wc_clean( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : $value;
	}
}

if ( ! function_exists( 'wc_string_to_bool' ) ) {
	function wc_string_to_bool( $value ) {
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes' ), true );
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'success' ) {
		$GLOBALS['wc_eori_vat_test_notices'][] = array(
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		$args = func_get_args();
		array_shift( $args );
		return $args[0];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		if ( isset( $GLOBALS['wc_eori_vat_test_options'][ $name ] ) ) {
			return $GLOBALS['wc_eori_vat_test_options'][ $name ];
		}
		if ( 'woocommerce_ship_to_destination' === $name && ! empty( $GLOBALS['wc_eori_test_ship_to_destination'] ) ) {
			return $GLOBALS['wc_eori_test_ship_to_destination'];
		}
		if ( 'woocommerce_eu_vat_number_require_country_code' === $name ) {
			return 'yes';
		}
		if ( 'woocommerce_eu_vat_number_use_shipping_country' === $name ) {
			return 'yes';
		}
		return $default;
	}
}

if ( ! function_exists( 'wc_ship_to_billing_address_only' ) ) {
	function wc_ship_to_billing_address_only() {
		return 'billing_only' === get_option( 'woocommerce_ship_to_destination', 'shipping' );
	}
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $endpoint = false ) {
		return false;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return isset( $GLOBALS['wc_eori_vat_test_wc'] ) ? $GLOBALS['wc_eori_vat_test_wc'] : null;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return '';
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public function log( $level, $message ) {}
		};
	}
}

if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		return 0 === strpos( (string) $haystack, (string) $needle );
	}
}

if ( ! class_exists( 'WC_Cart' ) ) {
	class WC_Cart {
		private $needs_shipping = true;
		private $billing_only   = false;
		private $cart           = array();

		public function set_needs_shipping( $needs_shipping ) {
			$this->needs_shipping = (bool) $needs_shipping;
		}

		public function set_billing_only( $billing_only ) {
			$this->billing_only = (bool) $billing_only;
		}

		public function needs_shipping() {
			return $this->needs_shipping;
		}

		public function needs_shipping_address() {
			return $this->needs_shipping && ! $this->billing_only;
		}

		public function set_cart( $cart ) {
			$this->cart = $cart;
		}

		public function get_cart() {
			return $this->cart;
		}
	}
}

if ( ! class_exists( 'WC_EORI_VAT_Test_Product' ) ) {
	class WC_EORI_VAT_Test_Product {
		private $needs_shipping = true;

		public function __construct( $needs_shipping ) {
			$this->needs_shipping = (bool) $needs_shipping;
		}

		public function needs_shipping() {
			return $this->needs_shipping;
		}
	}
}

if ( ! class_exists( 'WC_Session' ) ) {
	class WC_Session {
		public function get( $key, $default = null ) {
			return $default;
		}

		public function set( $key, $value ) {
		}
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {
		public $cart;
		public $session;

		public function __construct() {
			$this->cart    = new WC_Cart();
			$this->session = new WC_Session();
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private $billing_country  = '';
		private $shipping_country = '';
		private $billing_postcode  = '';
		private $shipping_postcode = '';
		private $needs_shipping   = true;
		private $meta             = array();

		public function set_billing_country( $country ) {
			$this->billing_country = $country;
		}

		public function set_shipping_country( $country ) {
			$this->shipping_country = $country;
		}

		public function set_billing_postcode( $postcode ) {
			$this->billing_postcode = $postcode;
		}

		public function set_shipping_postcode( $postcode ) {
			$this->shipping_postcode = $postcode;
		}

		public function set_needs_shipping( $needs_shipping ) {
			$this->needs_shipping = (bool) $needs_shipping;
		}

		public function get_billing_country() {
			return $this->billing_country;
		}

		public function get_shipping_country() {
			return $this->shipping_country;
		}

		public function get_billing_postcode() {
			return $this->billing_postcode;
		}

		public function get_shipping_postcode() {
			return $this->shipping_postcode;
		}

		public function has_shipping_address() {
			return (bool) $this->shipping_country;
		}

		public function needs_shipping() {
			return $this->needs_shipping;
		}

		public function needs_shipping_address() {
			return $this->needs_shipping;
		}

		public function get_meta( $key, $single = true ) {
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
		}

		public function meta_exists( $key ) {
			return array_key_exists( $key, $this->meta );
		}

		public function set_meta( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ) {
			unset( $this->meta[ $key ] );
		}
	}
}

if ( ! class_exists( 'WC_Geolocation' ) ) {
	class WC_Geolocation {
		public static function geolocate_ip() {
			return array(
				'country' => isset( $GLOBALS['wc_eori_vat_test_ip_country'] ) ? $GLOBALS['wc_eori_vat_test_ip_country'] : '',
			);
		}

		public static function get_ip_address() {
			return '203.0.113.10';
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();

		public function __construct( $params = array() ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}
	}
}

$GLOBALS['wc_eori_vat_test_wc']      = new WooCommerce();
$GLOBALS['wc_eori_vat_test_notices'] = array();
$GLOBALS['wc_eori_vat_test_options'] = array();

// --- Load shipped modules ---------------------------------------------------

require_once $eori_root . '/includes/class-wc-eori-validator.php';
require_once $eori_root . '/includes/class-wc-eori-number.php';
require_once $eori_root . '/includes/wc-eori-functions.php';
require_once $vat_root . '/includes/wc-eu-vat-functions.php';
// class-wc-eu-vat-number.php requires admin/vies/uk-api and calls ::init() (add_action stubs absorb hooks).
require_once $vat_root . '/includes/class-wc-eu-vat-number.php';

// --- Assertions -------------------------------------------------------------

function eori_vat_test_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new Exception( esc_html( $message ) );
	}
}

function eori_vat_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new Exception(
			esc_html( $message . ' Expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual ) )
		);
	}
}

$passed = 0;

// 1) EORI normalize / format (shipped WC_EORI_Validator).
eori_vat_test_assert_same( 'GB123456789000', WC_EORI_Validator::normalize( ' gb 123-456-789-000 ' ), 'EORI normalize strips spaces/dashes and uppercases.' );
eori_vat_test_assert_true( true === WC_EORI_Validator::validate_format( 'DE123456789012345' ), 'Valid EORI format passes.' );
eori_vat_test_assert_true( is_wp_error( WC_EORI_Validator::validate_format( '123456' ) ), 'Missing prefix fails EORI format.' );
eori_vat_test_assert_true( is_wp_error( WC_EORI_Validator::validate_format( 'DE1234567890123456' ) ), 'Too long EORI fails format.' );
$passed += 4;

// 2) Destination / required-country rules (shipping-only; virtual never requires).
$default_required = wc_eori_get_default_required_countries();
eori_vat_test_assert_true( in_array( 'DE', $default_required, true ), 'EU countries require EORI by default.' );
eori_vat_test_assert_true( ! in_array( 'GB', $default_required, true ), 'GB is not required by default.' );

$virtual_order = new WC_Order();
$virtual_order->set_billing_country( 'DE' );
$virtual_order->set_shipping_country( 'DE' );
$virtual_order->set_needs_shipping( false );
WC()->cart->set_needs_shipping( false );
eori_vat_test_assert_same( '', wc_eori_get_destination_country_from_order( $virtual_order ), 'Virtual cart has no EORI destination.' );

$shippable = new WC_Order();
$shippable->set_billing_country( 'US' );
$shippable->set_shipping_country( 'DE' );
$shippable->set_needs_shipping( true );
WC()->cart->set_needs_shipping( true );
eori_vat_test_assert_same( 'DE', wc_eori_get_destination_country_from_order( $shippable ), 'Shippable order uses shipping country.' );

WC()->cart->set_needs_shipping( true );
eori_vat_test_assert_same(
	'DE',
	wc_eori_get_destination_country_from_posted_data(
		array(
			'billing_country'           => 'US',
			'shipping_country'          => 'DE',
			'ship_to_different_address' => '1',
		)
	),
	'POST data uses shipping country when ship-to-different is set.'
);

WC()->cart->set_needs_shipping( false );
eori_vat_test_assert_same(
	'',
	wc_eori_get_destination_country_from_posted_data(
		array(
			'billing_country'  => 'DE',
			'shipping_country' => 'DE',
		)
	),
	'Non-shipping cart POST yields empty destination.'
);
eori_vat_test_assert_true( wc_eori_is_country_required( 'DE' ), 'DE is in required list.' );
eori_vat_test_assert_true( ! wc_eori_is_country_required( 'US' ), 'US is not required.' );
$passed += 7;

// Order meta keys for normalized EORI / validation outcome helpers.
$meta_order = new WC_Order();
$meta_order->update_meta_data( '_eori_number', 'DE123456789012345' );
$meta_order->update_meta_data( '_eori_number_is_validated', 'true' );
$meta_order->update_meta_data( '_eori_number_is_valid', 'true' );
eori_vat_test_assert_same( 'DE123456789012345', wc_eori_get_eori_from_order( $meta_order ), 'Order EORI meta key _eori_number is read.' );
$passed += 1;

// 3) Email meta: labeled EU VAT Number + EORI Number via shipped filter callback.
$email_order = new WC_Order();
$email_order->update_meta_data( '_billing_vat_number', 'DE123456789' );
$email_order->update_meta_data( '_eori_number', 'DE123456789012345' );
$fields = WC_EORI_Number::add_email_order_meta_fields( array(), false, $email_order );
eori_vat_test_assert_true( isset( $fields['eu_vat_number'] ), 'Email fields include EU VAT entry when VAT present.' );
eori_vat_test_assert_same( 'EU VAT Number', $fields['eu_vat_number']['label'], 'VAT email label is EU VAT Number.' );
eori_vat_test_assert_same( 'DE123456789', $fields['eu_vat_number']['value'], 'VAT email value from _billing_vat_number.' );
eori_vat_test_assert_true( isset( $fields['eori_number'] ), 'Email fields include EORI entry when EORI present.' );
eori_vat_test_assert_same( 'EORI Number', $fields['eori_number']['label'], 'EORI email label is EORI Number.' );
eori_vat_test_assert_same( 'DE123456789012345', $fields['eori_number']['value'], 'EORI email value from order meta.' );

$legacy_vat_order = new WC_Order();
$legacy_vat_order->update_meta_data( '_vat_number', 'FRXX123456789' );
$legacy_fields = WC_EORI_Number::add_email_order_meta_fields( array(), false, $legacy_vat_order );
eori_vat_test_assert_same( 'FRXX123456789', $legacy_fields['eu_vat_number']['value'], 'VAT falls back to _vat_number.' );

$empty_order = new WC_Order();
$empty_fields = WC_EORI_Number::add_email_order_meta_fields( array( 'keep' => true ), false, $empty_order );
eori_vat_test_assert_true( ! isset( $empty_fields['eu_vat_number'] ), 'Empty VAT omitted from email fields.' );
eori_vat_test_assert_true( ! isset( $empty_fields['eori_number'] ), 'Empty EORI omitted from email fields.' );
eori_vat_test_assert_true( isset( $empty_fields['keep'] ), 'Existing email fields preserved when numbers empty.' );
$passed += 10;

// 4) EU VAT normalize / format (shipped WC_EU_VAT_Number) — valid and invalid.
$normalized = WC_EU_VAT_Number::get_normalized_vat_number( ' de 123.456.789 ', 'DE' );
eori_vat_test_assert_same( 'DE123456789', $normalized, 'VAT normalize uppercases, strips separators, keeps/adds DE prefix.' );

$formatted = WC_EU_VAT_Number::get_formatted_vat_number( 'DE123456789' );
eori_vat_test_assert_same( '123456789', $formatted, 'VAT format strips EU country prefix.' );

$valid_format = WC_EU_VAT_Number::validate_vat_format( 'DE123456789', 'DE' );
eori_vat_test_assert_true( true === $valid_format, 'Valid DE VAT format is accepted (exemption path input).' );

$invalid_format = WC_EU_VAT_Number::validate_vat_format( 'DE12', 'DE' );
eori_vat_test_assert_true( is_wp_error( $invalid_format ), 'Invalid DE VAT format is rejected (exemption path input).' );

// Meta helper uses _billing_vat_number then _vat_number.
$vat_order = new WC_Order();
$vat_order->update_meta_data( '_billing_vat_number', 'IT12345678901' );
eori_vat_test_assert_same( 'IT12345678901', wc_eu_vat_get_vat_from_order( $vat_order ), 'VAT order meta prefers _billing_vat_number.' );
$vat_legacy = new WC_Order();
$vat_legacy->update_meta_data( '_vat_number', 'NL123456789B01' );
eori_vat_test_assert_same( 'NL123456789B01', wc_eu_vat_get_vat_from_order( $vat_legacy ), 'VAT order meta falls back to _vat_number.' );
$passed += 6;

// 5) FCF: classic checkout field uses billing_eori_number and survives late checkout_fields restore after simulated FCF wipe.
$wiped = array(
	'billing'  => array(
		'billing_first_name' => array( 'label' => 'First name' ),
		// FCF-style rebuild: only fields from saved FCF config, no EORI.
	),
	'shipping' => array(),
	'order'    => array(),
);
$restored = WC_EORI_Number::add_classic_checkout_field_to_checkout_fields( $wiped );
eori_vat_test_assert_true( isset( $restored['billing']['billing_eori_number'] ), 'Late checkout_fields pass restores billing_eori_number after FCF wipe.' );
eori_vat_test_assert_same( WC_EORI_Number::CLASSIC_FIELD_KEY, 'billing_eori_number', 'Classic field key is FCF billing nomenclature billing_eori_number.' );
eori_vat_test_assert_true(
	in_array( 'wc-eori-number-field', $restored['billing']['billing_eori_number']['class'], true ),
	'Restored EORI field keeps visibility wrapper class.'
);
$passed += 3;

// Bootstrap structure: single plugin header file loads both modules.
$bootstrap = file_get_contents( $plugin_root . '/vibeshift-eu-shipping.php' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'Plugin Name:' ), 'Bootstrap has Plugin Name header.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'Requires Plugins: woocommerce' ), 'Bootstrap requires WooCommerce.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'modules/eori/' ) || false !== strpos( $bootstrap, 'WC_EORI_ABSPATH' ), 'Bootstrap wires EORI module path.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'modules/eu-vat/' ) || false !== strpos( $bootstrap, 'WC_EU_ABSPATH' ), 'Bootstrap wires EU VAT module path.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'init_eori' ) && false !== strpos( $bootstrap, 'init_eu_vat' ), 'Bootstrap init loads both EORI and EU VAT.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'class-wc-eori-number.php' ), 'Bootstrap includes EORI number class.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'class-wc-eu-vat-number.php' ), 'Bootstrap includes EU VAT number class.' );
eori_vat_test_assert_true( false !== strpos( $bootstrap, 'has_standalone_plugin_conflict' ), 'Bootstrap guards against standalone plugin conflicts.' );
$passed += 8;

// Runtime conflict guard: the merged package must stay inactive when the
// superseded standalone plugin bootstrap classes are already loaded.
if ( ! class_exists( 'WC_EORI_Number_Init' ) ) {
	class WC_EORI_Number_Init {}
}
if ( ! class_exists( 'WC_EU_VAT_Number_Init' ) ) {
	class WC_EU_VAT_Number_Init {}
}
require_once $plugin_root . '/vibeshift-eu-shipping.php';
$merged_bootstrap = new WC_EORI_VAT_Number_Init();
eori_vat_test_assert_true( $merged_bootstrap->has_standalone_plugin_conflict(), 'Merged bootstrap detects active standalone plugin bootstraps.' );
$passed += 1;

// 6) B2B required VAT message must report vat_country (shipping), not billing_country.
// Source regression: sprintf for required-field notice must use $vat_country.
$vat_class_src = file_get_contents( $vat_root . '/includes/class-wc-eu-vat-number.php' );
eori_vat_test_assert_true(
	(bool) preg_match(
		'/required field for your %2\$s country \(%3\$s\)\.[\s\S]{0,400}?\$vat_country\s*[\),]/',
		$vat_class_src
	),
	'B2B required-VAT sprintf uses $vat_country (not $billing_country).'
);

// Runtime: ship to FR (EU) with billing US, B2B required, empty VAT → message names FR not US.
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_b2b']                 = 'yes';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_use_shipping_country'] = 'yes';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_validate_ip']         = 'no';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_field_label']         = 'EU VAT Number';
$GLOBALS['wc_eori_vat_test_notices'] = array();

WC_EU_VAT_Number::validate_checkout(
	array(
		'billing_country'           => 'US',
		'shipping_country'          => 'FR',
		'ship_to_different_address' => '1',
		'billing_vat_number'        => '',
		'shipping_vat_number'       => '',
		'billing_postcode'          => '10001',
		'shipping_postcode'         => '75001',
	),
	true
);

$required_notices = array_values(
	array_filter(
		$GLOBALS['wc_eori_vat_test_notices'],
		static function ( $notice ) {
			return 'error' === $notice['type'] && false !== strpos( $notice['message'], 'required field' );
		}
	)
);
eori_vat_test_assert_true( count( $required_notices ) >= 1, 'B2B empty VAT while shipping to FR adds required-field notice.' );
$required_message = $required_notices[0]['message'];
eori_vat_test_assert_true( false !== strpos( $required_message, '(FR)' ), 'Required VAT message names shipping vat_country FR.' );
eori_vat_test_assert_true( false === strpos( $required_message, '(US)' ), 'Required VAT message must not name billing country US.' );
eori_vat_test_assert_true( false !== strpos( $required_message, 'shipping' ), 'Required VAT message says shipping when ship-to-different uses shipping country.' );

// Non-EU vat_country: shipping US with B2B on must not require VAT.
$GLOBALS['wc_eori_vat_test_notices'] = array();
WC_EU_VAT_Number::validate_checkout(
	array(
		'billing_country'           => 'FR',
		'shipping_country'          => 'US',
		'ship_to_different_address' => '1',
		'billing_vat_number'        => '',
		'shipping_vat_number'       => '',
		'billing_postcode'          => '75001',
		'shipping_postcode'         => '10001',
	),
	true
);
$non_eu_required = array_filter(
	$GLOBALS['wc_eori_vat_test_notices'],
	static function ( $notice ) {
		return 'error' === $notice['type'] && false !== strpos( $notice['message'], 'required field' );
	}
);
eori_vat_test_assert_true( 0 === count( $non_eu_required ), 'B2B does not require VAT when vat_country (shipping) is non-EU US.' );
$passed += 6;

// 7) Store API checkout must block EU VAT required-field and location-confirmation bypasses.
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_b2b']                 = 'yes';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_use_shipping_country'] = 'yes';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_validate_ip']         = 'no';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_field_label']         = 'EU VAT Number';
WC()->cart->set_needs_shipping( true );
WC()->cart->set_cart( array() );

$store_api_required_order = new WC_Order();
$store_api_required_order->set_billing_country( 'US' );
$store_api_required_order->set_shipping_country( 'FR' );
$store_api_required_order->set_billing_postcode( '10001' );
$store_api_required_order->set_shipping_postcode( '75001' );
$store_api_required_order->set_needs_shipping( true );
$store_api_required_errors = new WP_Error();
WC_EU_VAT_Number::validate_order_before_payment( $store_api_required_order, $store_api_required_errors );
$store_api_required_messages = $store_api_required_errors->get_error_messages();
eori_vat_test_assert_true( count( $store_api_required_messages ) >= 1, 'Store API empty VAT while shipping to FR adds a blocking error.' );
eori_vat_test_assert_true( false !== strpos( $store_api_required_messages[0], 'required field' ), 'Store API required VAT error uses required-field message.' );
eori_vat_test_assert_true( false !== strpos( $store_api_required_messages[0], '(FR)' ), 'Store API required VAT error names shipping vat_country FR.' );
eori_vat_test_assert_true( false !== strpos( $store_api_required_messages[0], 'shipping' ), 'Store API required VAT error says shipping when shipping country is used.' );

$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_b2b']         = 'no';
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_validate_ip'] = 'yes';
$GLOBALS['wc_eori_vat_test_ip_country']                                       = 'DE';
WC()->cart->set_needs_shipping( false );
WC()->cart->set_cart(
	array(
		array(
			'data' => new WC_EORI_VAT_Test_Product( false ),
		),
	)
);

$store_api_location_order = new WC_Order();
$store_api_location_order->set_billing_country( 'FR' );
$store_api_location_order->set_billing_postcode( '75001' );
$store_api_location_order->set_needs_shipping( false );
$store_api_location_order->update_meta_data( '_customer_self_declared_country', 'false' );
$store_api_location_errors = new WP_Error();
WC_EU_VAT_Number::validate_order_before_payment( $store_api_location_order, $store_api_location_errors );
$store_api_location_messages = $store_api_location_errors->get_error_messages();
eori_vat_test_assert_true( count( $store_api_location_messages ) >= 1, 'Store API missing location confirmation adds a blocking error.' );
eori_vat_test_assert_true( false !== strpos( $store_api_location_messages[0], 'does not match your billing country (FR)' ), 'Store API location error names billing country FR.' );

$store_api_confirmed_order = new WC_Order();
$store_api_confirmed_order->set_billing_country( 'FR' );
$store_api_confirmed_order->set_billing_postcode( '75001' );
$store_api_confirmed_order->set_needs_shipping( false );
$store_api_confirmed_order->update_meta_data( '_customer_self_declared_country', 'true' );
$store_api_confirmed_errors = new WP_Error();
WC_EU_VAT_Number::validate_order_before_payment( $store_api_confirmed_order, $store_api_confirmed_errors );
eori_vat_test_assert_true( 0 === count( $store_api_confirmed_errors->get_error_messages() ), 'Store API confirmed location does not add a blocking error.' );

$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_validate_ip'] = 'no';
WC()->cart->set_needs_shipping( true );
WC()->cart->set_cart( array() );
$passed += 7;

// 8) T5 regression: VAT fields must survive checkout-field-manager wipes (FCF),
// and a posted billing VAT must not be silently discarded when the shipping
// VAT field was stripped from the rendered form.
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_use_shipping_country'] = 'yes';
$vat_wiped = array(
	'billing'  => array( 'billing_first_name' => array( 'label' => 'First name' ) ),
	'shipping' => array( 'shipping_first_name' => array( 'label' => 'First name' ) ),
	'order'    => array(),
);
$vat_restored = WC_EU_VAT_Number::add_vat_fields_to_checkout_fields( $vat_wiped );
eori_vat_test_assert_true( isset( $vat_restored['billing']['billing_vat_number'] ), 'Late checkout_fields pass restores billing_vat_number after FCF wipe.' );
eori_vat_test_assert_true( isset( $vat_restored['shipping']['shipping_vat_number'] ), 'Late checkout_fields pass restores shipping_vat_number after FCF wipe.' );
eori_vat_test_assert_same( 'woocommerce_eu_vat_number_shipping', $vat_restored['shipping']['shipping_vat_number']['id'], 'Restored shipping VAT field keeps the id the checkout JS toggles.' );

$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_use_shipping_country'] = 'no';
$vat_restored_billing_only = WC_EU_VAT_Number::add_vat_fields_to_checkout_fields( $vat_wiped );
eori_vat_test_assert_true( isset( $vat_restored_billing_only['billing']['billing_vat_number'] ), 'Billing VAT field restored regardless of use_shipping_country.' );
eori_vat_test_assert_true( ! isset( $vat_restored_billing_only['shipping']['shipping_vat_number'] ), 'Shipping VAT field not injected when use_shipping_country is off.' );
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_use_shipping_country'] = 'yes';

// Posted-VAT selection: shipping VAT wins when present; billing VAT is the
// fallback when the shipping field is absent (stripped) or empty.
eori_vat_test_assert_same(
	'FR99999999999',
	WC_EU_VAT_Number::get_posted_vat_number(
		array(
			'shipping_country'    => 'FR',
			'billing_vat_number'  => 'FR19831241179',
			'shipping_vat_number' => 'FR99999999999',
		),
		true,
		true
	),
	'Shipping VAT number wins when the shipping field posted a value.'
);
eori_vat_test_assert_same(
	'FR19831241179',
	WC_EU_VAT_Number::get_posted_vat_number(
		array(
			'shipping_country'   => 'FR',
			'billing_vat_number' => 'FR19831241179',
		),
		true,
		true
	),
	'Billing VAT number is used when the shipping VAT key was never posted.'
);
eori_vat_test_assert_same(
	'FR19831241179',
	WC_EU_VAT_Number::get_posted_vat_number(
		array(
			'shipping_country'    => 'FR',
			'billing_vat_number'  => 'FR19831241179',
			'shipping_vat_number' => '',
		),
		true,
		true
	),
	'Billing VAT number is used when the shipping VAT field posted empty.'
);
eori_vat_test_assert_same(
	'FR19831241179',
	WC_EU_VAT_Number::get_posted_vat_number(
		array(
			'shipping_country'    => 'FR',
			'billing_vat_number'  => 'FR19831241179',
			'shipping_vat_number' => 'FR99999999999',
		),
		true,
		false
	),
	'Billing VAT number is used when not shipping to a different address.'
);

// Runtime: shipping VAT key entirely absent from POST (stripped field) must not
// raise PHP warnings and must still enforce the B2B required rule.
$GLOBALS['wc_eori_vat_test_options']['woocommerce_eu_vat_number_b2b'] = 'yes';
$GLOBALS['wc_eori_vat_test_notices']                                  = array();
$captured_php_warnings                                                = array();
set_error_handler(
	static function ( $errno, $errstr ) use ( &$captured_php_warnings ) {
		$captured_php_warnings[] = $errstr;
		return true;
	},
	E_WARNING | E_NOTICE
);
WC_EU_VAT_Number::validate_checkout(
	array(
		'billing_country'           => 'US',
		'shipping_country'          => 'FR',
		'ship_to_different_address' => '1',
		'billing_vat_number'        => '',
		'billing_postcode'          => '10001',
		'shipping_postcode'         => '75001',
	),
	true
);
restore_error_handler();
$absent_key_required = array_filter(
	$GLOBALS['wc_eori_vat_test_notices'],
	static function ( $notice ) {
		return 'error' === $notice['type'] && false !== strpos( $notice['message'], 'required field' );
	}
);
eori_vat_test_assert_true( count( $absent_key_required ) >= 1, 'Absent shipping VAT POST key still triggers required-field notice.' );
eori_vat_test_assert_same( 0, count( $captured_php_warnings ), 'Absent shipping VAT POST key raises no PHP warnings/notices.' );
$passed += 11;

echo esc_html( sprintf( "Merged plugin tests passed (%d assertions).\n", $passed ) );
