<?php
/**
 * Plugin Name: VibeShift EU Shipping
 * Plugin URI: https://vibecoderacing.ai
 * Requires Plugins: woocommerce
 * Description: Collect and validate EORI numbers and EU VAT numbers during WooCommerce checkout. Merges EORI validation (with Flexible Checkout Fields–compatible field keys and labeled email meta) with EU VAT number collection, validation, and B2B exemption.
 * Version: 1.2.0
 * Update URI: https://github.com/VibeCodeRacing/VibeShift-EU
 * Author: Vibe Code Racing
 * Author URI: https://vibecoderacing.ai
 * Text Domain: vibeshift-eu-shipping
 * Domain Path: /languages
 * Requires at least: 6.8
 * WC requires at least: 10.4
 * Requires PHP: 7.4
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package vibeshift-eu-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_EORI_VAT_VERSION', '1.2.0' );
define( 'WC_EORI_VAT_FILE', __FILE__ );
define( 'WC_EORI_VAT_ABSPATH', __DIR__ . '/' );
// plugin_basename() is unavailable under the CLI test harness; the fallback
// matches WordPress's basename for a normally-installed copy of the plugin.
define(
	'WC_EORI_VAT_PLUGIN_BASENAME',
	function_exists( 'plugin_basename' )
		? plugin_basename( __FILE__ )
		: basename( __DIR__ ) . '/' . basename( __FILE__ )
);

// GitHub Releases updater: must run even when WooCommerce dependency
// checks fail, so updates can deliver fixes to a broken install.
require_once __DIR__ . '/includes/class-vibeshift-github-updater.php';
Vibeshift_GitHub_Updater::init();

// EORI module constants (paths resolve into modules/eori/).
if ( ! defined( 'WC_EORI_VERSION' ) ) {
	define( 'WC_EORI_VERSION', '1.0.8' );
}
if ( ! defined( 'WC_EORI_FILE' ) ) {
	define( 'WC_EORI_FILE', __FILE__ );
}
if ( ! defined( 'WC_EORI_ABSPATH' ) ) {
	define( 'WC_EORI_ABSPATH', __DIR__ . '/modules/eori/' );
}

// EU VAT module constants (paths resolve into modules/eu-vat/).
if ( ! defined( 'WC_EU_VAT_VERSION' ) ) {
	define( 'WC_EU_VAT_VERSION', '3.1.0' );
}
if ( ! defined( 'WC_EU_VAT_FILE' ) ) {
	// Point into the EU VAT module root so plugin_dir_path() finds templates/.
	define( 'WC_EU_VAT_FILE', __DIR__ . '/modules/eu-vat/eu-vat-number.php' );
}
if ( ! defined( 'WC_EU_ABSPATH' ) ) {
	define( 'WC_EU_ABSPATH', __DIR__ . '/modules/eu-vat/' );
}

/**
 * Merged plugin bootstrap: one Plugin Name, both EORI and EU VAT capabilities.
 */
class WC_EORI_VAT_Number_Init {
	const WC_MIN_VERSION = '10.4';

	/**
	 * Whether the EU VAT module passed dependency checks this request.
	 *
	 * @var bool
	 */
	private $eu_vat_ready = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 9 );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'eu_vat_block_init' ) );
		add_action( 'init', array( $this, 'localization' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_feature_compatibility' ) );

		register_activation_hook( __FILE__, array( $this, 'install' ) );
	}

	/**
	 * Initialize both modules after plugins load.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->has_standalone_plugin_conflict() ) {
			add_action( 'admin_notices', array( $this, 'standalone_plugin_conflict_notice' ) );
			return;
		}

		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_inactive_notice' ) );
			return;
		}

		if ( ! $this->is_woocommerce_version_supported() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_wrong_version_notice' ) );
			return;
		}

		$this->init_eori();
		$this->init_eu_vat();

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load EORI collection, validation, FCF-compatible fields, and email meta.
	 *
	 * @return void
	 */
	private function init_eori() {
		if ( version_compare( get_option( 'woocommerce_eori_version', '0' ), WC_EORI_VERSION, '<' ) ) {
			$this->install_eori();
		}

		include_once WC_EORI_ABSPATH . 'includes/wc-eori-functions.php';
		include_once WC_EORI_ABSPATH . 'includes/class-wc-eori-validator.php';
		include_once WC_EORI_ABSPATH . 'includes/class-wc-eori-number.php';
		include_once WC_EORI_ABSPATH . 'includes/class-wc-eori-privacy.php';

		WC_EORI_Number::init();

		if ( is_admin() ) {
			include_once WC_EORI_ABSPATH . 'includes/class-wc-eori-admin.php';
			WC_EORI_Admin::init();
		}
	}

	/**
	 * Load EU VAT collection, validation, exemption, reports when deps allow.
	 *
	 * @return void
	 */
	private function init_eu_vat() {
		if ( ! $this->is_soap_supported() ) {
			add_action( 'admin_notices', array( $this, 'requires_soap_notice' ) );
			return;
		}

		if ( ! $this->is_taxes_enabled() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_tax_disabled_notice' ) );
			return;
		}

		$this->eu_vat_ready = true;

		if ( version_compare( get_option( 'woocommerce_eu_vat_version', '0' ), WC_EU_VAT_VERSION, '<' ) ) {
			add_action( 'init', array( $this, 'install_eu_vat' ) );
		}

		if ( ! defined( 'WC_EU_VAT_PLUGIN_URL' ) ) {
			define( 'WC_EU_VAT_PLUGIN_URL', untrailingslashit( plugins_url( '/modules/eu-vat', __FILE__ ) ) );
		}

		include_once WC_EU_ABSPATH . 'includes/wc-eu-vat-functions.php';
		include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-privacy.php';

		if ( ! class_exists( 'WC_EU_VAT_Number' ) ) {
			include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-number.php';
			include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-my-account.php';
		}

		if ( is_admin() ) {
			include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-admin.php';
			include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-reports.php';
		}

		$this->filter_woocpayments_compatibility();
		add_filter( 'woocommerce_get_order_item_totals', 'wc_eu_vat_maybe_add_zero_tax_display', 10, 3 );
		add_filter(
			'__experimental_woocommerce_blocks_add_data_attributes_to_block',
			function ( $allowed_blocks ) {
				if ( $this->is_woocommerce_blocks_active() && $this->is_woocommerce_blocks_version_supported() ) {
					$allowed_blocks[] = 'woocommerce/eu-vat-number';
				}
				return $allowed_blocks;
			},
			10,
			1
		);
	}

	/**
	 * Register EU VAT checkout block integration when Blocks are available.
	 *
	 * @return void
	 */
	public function eu_vat_block_init() {
		if ( $this->has_standalone_plugin_conflict() ) {
			return;
		}

		if ( ! $this->eu_vat_ready && ! $this->can_init_eu_vat_blocks() ) {
			return;
		}

		if ( ! $this->is_woocommerce_blocks_active() || ! $this->is_woocommerce_blocks_version_supported() ) {
			return;
		}

		if ( ! defined( 'WC_EU_VAT_PLUGIN_URL' ) ) {
			define( 'WC_EU_VAT_PLUGIN_URL', untrailingslashit( plugins_url( '/modules/eu-vat', __FILE__ ) ) );
		}

		include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-extend-store-endpoint.php';
		include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-blocks.php';

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $integration_registry ) {
				$integration_registry->register( new WC_EU_VAT_Blocks_Integration() );
			}
		);

		if ( ! class_exists( 'WC_EU_VAT_Number' ) ) {
			include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-number.php';
			include_once WC_EU_ABSPATH . 'includes/class-wc-eu-vat-my-account.php';
		}

		add_action(
			'wp_enqueue_scripts',
			function () {
				if ( class_exists( 'WC_EU_VAT_Number' ) ) {
					WC_EU_VAT_Number::localize_wc_eu_vat_params( 'wc-blocks-eu-vat-scripts-frontend' );
				}
			}
		);

		add_action(
			'init',
			function () {
				register_block_type(
					WC_EU_ABSPATH . 'block.json',
					array(
						'attributes' => array(
							'title'          => array(
								'type'    => 'string',
								'default' => __( 'VAT Number', 'vibeshift-eu-shipping' ),
							),
							'description'    => array(
								'type'    => 'string',
								'default' => '',
							),
							'showStepNumber' => array(
								'type'    => 'boolean',
								'default' => true,
							),
						),
					)
				);
			}
		);

		$extend = new WC_EU_VAT_Extend_Store_Endpoint();
		$extend->init();
	}

	/**
	 * Whether EU VAT blocks can initialize even if plugins_loaded ran first.
	 *
	 * @return bool
	 */
	private function can_init_eu_vat_blocks() {
		return $this->is_woocommerce_active()
			&& $this->is_woocommerce_version_supported()
			&& $this->is_soap_supported()
			&& $this->is_taxes_enabled();
	}

	/**
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || class_exists( 'woocommerce' );
	}

	/**
	 * @return bool
	 */
	public function is_woocommerce_version_supported() {
		return version_compare( get_option( 'woocommerce_db_version', '0' ), self::WC_MIN_VERSION, '>=' );
	}

	/**
	 * @return bool
	 */
	public function is_woocommerce_blocks_active() {
		return class_exists( 'Automattic\WooCommerce\Blocks\Package' );
	}

	/**
	 * @return bool
	 */
	public function is_woocommerce_blocks_version_supported() {
		if ( ! $this->is_woocommerce_blocks_active() ) {
			return false;
		}
		return version_compare(
			\Automattic\WooCommerce\Blocks\Package::get_version(),
			'7.3.0',
			'>='
		);
	}

	/**
	 * @return bool
	 */
	public function is_taxes_enabled() {
		return function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();
	}

	/**
	 * @return bool
	 */
	public function is_soap_supported() {
		return class_exists( 'SoapClient' );
	}

	/**
	 * Whether a superseded standalone plugin bootstrap is loaded.
	 *
	 * The merged plugin ships the same module classes/functions as the
	 * standalone EORI and EU VAT plugins. Running them together can register
	 * duplicate checkout hooks or load same-named functions from different
	 * files, so keep the merged package inactive until the old plugins are
	 * deactivated.
	 *
	 * @return bool
	 */
	public function has_standalone_plugin_conflict() {
		return class_exists( 'WC_EORI_Number_Init', false ) || class_exists( 'WC_EU_VAT_Number_Init', false );
	}

	/**
	 * Admin notice shown when standalone modules are still active.
	 *
	 * @return void
	 */
	public function standalone_plugin_conflict_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'VibeShift EU Shipping is inactive.', 'vibeshift-eu-shipping' ) . '</strong> ' . esc_html__( 'Deactivate the standalone EORI Number and EU VAT Number plugins before using the merged plugin.', 'vibeshift-eu-shipping' ) . '</p></div>';
	}

	/**
	 * Load the merged package text domain.
	 *
	 * Both modules now share the single "vibeshift-eu-shipping" text
	 * domain, so a single load is sufficient. Translations shipped inside the
	 * package live in the /languages folder referenced by the Domain Path
	 * header; WordPress.org-hosted translations are loaded automatically.
	 *
	 * @return void
	 */
	public function localization() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Self-distributed plugin; loads bundled /languages (Domain Path).
		load_plugin_textdomain( 'vibeshift-eu-shipping', false, dirname( plugin_basename( WC_EORI_VAT_FILE ) ) . '/languages' );
	}

	/**
	 * Activation / first-run install for both modules.
	 *
	 * @return void
	 */
	public function install() {
		$this->install_eori();
		$this->install_eu_vat();
		update_option( 'woocommerce_eori_vat_version', WC_EORI_VAT_VERSION );
	}

	/**
	 * EORI option defaults (from standalone EORI plugin).
	 *
	 * @return void
	 */
	public function install_eori() {
		$previous_version = get_option( 'woocommerce_eori_version', '0' );
		update_option( 'woocommerce_eori_version', WC_EORI_VERSION );

		include_once WC_EORI_ABSPATH . 'includes/wc-eori-functions.php';

		if ( false === get_option( 'woocommerce_eori_required_countries', false ) ) {
			update_option( 'woocommerce_eori_required_countries', wc_eori_get_default_required_countries() );
		}

		if ( false === get_option( 'woocommerce_eori_cache_duration', false ) ) {
			update_option( 'woocommerce_eori_cache_duration', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
		}

		if ( false === get_option( 'woocommerce_eori_http_timeout', false ) ) {
			update_option( 'woocommerce_eori_http_timeout', 15 );
		}

		unset( $previous_version );
	}

	/**
	 * EU VAT rewrite endpoint install.
	 *
	 * @return void
	 */
	public function install_eu_vat() {
		update_option( 'woocommerce_eu_vat_version', WC_EU_VAT_VERSION );
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			add_rewrite_endpoint( 'vat-number', EP_ROOT | EP_PAGES );
			flush_rewrite_rules();
		}
	}

	/**
	 * Settings links for both domains.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function plugin_action_links( $actions ) {
		$custom_actions = array(
			'eori_settings' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ),
				esc_html__( 'EORI Settings', 'vibeshift-eu-shipping' )
			),
			'vat_settings'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=tax' ) ),
				esc_html__( 'VAT Settings', 'vibeshift-eu-shipping' )
			),
		);

		return array_merge( $custom_actions, $actions );
	}

	/**
	 * WooCommerce inactive notice.
	 *
	 * @return void
	 */
	public function woocommerce_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="error"><p><strong>' . esc_html__( 'VibeShift EU Shipping is inactive.', 'vibeshift-eu-shipping' ) . '</strong> ' . esc_html__( 'WooCommerce must be active.', 'vibeshift-eu-shipping' ) . '</p></div>';
		}
	}

	/**
	 * Wrong WooCommerce version notice.
	 *
	 * @return void
	 */
	public function woocommerce_wrong_version_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="error"><p><strong>' . esc_html__( 'VibeShift EU Shipping is inactive.', 'vibeshift-eu-shipping' ) . '</strong> ';
			printf(
				/* translators: %s: minimum WooCommerce version */
				esc_html__( 'WooCommerce must be at least version %s.', 'vibeshift-eu-shipping' ),
				esc_html( self::WC_MIN_VERSION )
			);
			echo '</p></div>';
		}
	}

	/**
	 * SOAP missing notice (EU VAT module only).
	 *
	 * @return void
	 */
	public function requires_soap_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="error"><p><strong>' . esc_html__( 'EU VAT module is inactive.', 'vibeshift-eu-shipping' ) . '</strong> ' . esc_html__( 'SOAP support is required for VIES validation. EORI validation remains available.', 'vibeshift-eu-shipping' ) . '</p></div>';
		}
	}

	/**
	 * Taxes disabled notice (EU VAT module only).
	 *
	 * @return void
	 */
	public function woocommerce_tax_disabled_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( $this->is_taxes_enabled() ) {
			return;
		}
		$show_notice = apply_filters( 'woocommerce_eu_vat_show_tax_disabled_notice', true );
		if ( ! $show_notice ) {
			return;
		}
		echo '<div class="error"><p><strong>' . esc_html__( 'EU VAT module is inactive.', 'vibeshift-eu-shipping' ) . '</strong> ';
		printf(
			/* translators: %1$s: Settings link start %2$s: Link end */
			esc_html__( 'Enable tax rates under %1$sWooCommerce > Settings%2$s. EORI validation remains available.', 'vibeshift-eu-shipping' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings' ) ) . '">',
			'</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Soft-compat with express payment methods when B2B VAT is on (from EU VAT plugin).
	 *
	 * @return void
	 */
	private function filter_woocpayments_compatibility() {
		if ( is_admin() ) {
			return;
		}
		if ( 'no' === get_option( 'woocommerce_eu_vat_number_b2b', 'no' ) ) {
			return;
		}
		if ( 'no' === get_option( 'woocommerce_eu_vat_number_prevent_incompatible_payment_methods', 'no' ) ) {
			return;
		}

		if ( function_exists( 'wcpay_init' ) ) {
			add_filter(
				'option_woocommerce_woocommerce_payments_settings',
				function ( $payment_options ) {
					$payment_options['payment_request']   = 'no';
					$payment_options['platform_checkout'] = 'no';
					return $payment_options;
				}
			);
		}

		if ( function_exists( 'woocommerce_gateway_stripe' ) ) {
			add_filter( 'wc_stripe_hide_payment_request_on_product_page', '__return_true', 999 );
			add_filter( 'wc_stripe_show_payment_request_on_cart', '__return_false', 999 );
			add_filter( 'wc_stripe_show_payment_request_on_checkout', '__return_false', 999 );
		}

		if ( class_exists( 'WooCommerce_Square_Loader' ) ) {
			add_filter(
				'option_woocommerce_square_credit_card_settings',
				function ( $option ) {
					$option['enable_digital_wallets'] = 'no';
					return $option;
				},
				999
			);
		}

		if ( class_exists( 'WC_PayPal_Braintree_Loader' ) ) {
			add_filter( 'wc_braintree_paypal_cart_checkout_enabled', '__return_false', 999 );
			add_filter( 'wc_braintree_paypal_product_buy_now_enabled', '__return_false', 999 );
		}
	}

	/**
	 * Declare HPOS / product block editor compatibility for the merged package.
	 *
	 * @return void
	 */
	public function declare_woocommerce_feature_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
		}
	}
}

// Define plugin URL early when plugins_url is available (after plugins_loaded URL is safer;
// also defined in init_eu_vat when module loads).
if ( function_exists( 'plugins_url' ) && ! defined( 'WC_EU_VAT_PLUGIN_URL' ) ) {
	define( 'WC_EU_VAT_PLUGIN_URL', untrailingslashit( plugins_url( '/modules/eu-vat', __FILE__ ) ) );
}

new WC_EORI_VAT_Number_Init();
