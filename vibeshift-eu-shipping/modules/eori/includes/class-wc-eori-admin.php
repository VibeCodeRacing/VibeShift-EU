<?php
/**
 * EORI admin integration.
 *
 * @package vibeshift-eu-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens and settings.
 */
class WC_EORI_Admin {
	/**
	 * Settings definition.
	 *
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_init', array( __CLASS__, 'init_form_fields' ) );
		add_action( 'woocommerce_settings_shipping_options_end', array( __CLASS__, 'admin_settings' ) );
		add_action( 'woocommerce_update_options_shipping', array( __CLASS__, 'save_admin_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 30 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'show_column' ), 5, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'show_column' ), 5, 2 );
		add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_billing_fields' ) );
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'add_customer_meta_fields' ) );
	}

	/**
	 * Initialize settings.
	 *
	 * @return void
	 */
	public static function init_form_fields() {
		$countries = WC()->countries ? WC()->countries->get_countries() : array();

		self::$settings = array(
			array(
				'type' => 'sectionend',
			),
			array(
				'type'  => 'title',
				'title' => __( 'EORI Number Validation', 'vibeshift-eu-shipping' ),
				'desc'  => __( 'Collect and validate EORI numbers at checkout for configured shipping destinations.', 'vibeshift-eu-shipping' ),
				'id'    => 'eori_number_validation',
			),
			array(
				'name'        => __( 'EORI Field Label', 'vibeshift-eu-shipping' ),
				'desc'        => __( 'The label that appears at checkout for the EORI number field.', 'vibeshift-eu-shipping' ),
				'id'          => 'woocommerce_eori_field_label',
				'type'        => 'text',
				'default'     => __( 'EORI number', 'vibeshift-eu-shipping' ),
				'placeholder' => __( 'EORI number', 'vibeshift-eu-shipping' ),
				'desc_tip'    => true,
			),
			array(
				'name'     => __( 'EORI Field Description', 'vibeshift-eu-shipping' ),
				'desc'     => __( 'The description that appears below the EORI number field.', 'vibeshift-eu-shipping' ),
				'id'       => 'woocommerce_eori_field_description',
				'type'     => 'text',
				'default'  => __( 'Required when the shipping destination is configured for EORI validation.', 'vibeshift-eu-shipping' ),
				'desc_tip' => true,
			),
			array(
				'name'              => __( 'Required Shipping Countries', 'vibeshift-eu-shipping' ),
				'desc'              => __( 'Orders shipping to these countries require a valid EORI number.', 'vibeshift-eu-shipping' ),
				'id'                => 'woocommerce_eori_required_countries',
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 450px;',
				'default'           => wc_eori_get_default_required_countries(),
				'options'           => $countries,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Select countries', 'vibeshift-eu-shipping' ),
				),
			),
			array(
				'name'        => __( 'Validation Cache Duration', 'vibeshift-eu-shipping' ),
				'desc'        => __( 'How long to cache definitive valid/invalid registry responses, in seconds.', 'vibeshift-eu-shipping' ),
				'id'          => 'woocommerce_eori_cache_duration',
				'type'        => 'number',
				'default'     => defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
				'placeholder' => '86400',
				'desc_tip'    => true,
			),
			array(
				'name'        => __( 'Validation HTTP Timeout', 'vibeshift-eu-shipping' ),
				'desc'        => __( 'Timeout for HMRC and EU registry validation requests, in seconds.', 'vibeshift-eu-shipping' ),
				'id'          => 'woocommerce_eori_http_timeout',
				'type'        => 'number',
				'default'     => 15,
				'placeholder' => '15',
				'desc_tip'    => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'eori_number_validation',
			),
		);
	}

	/**
	 * Output settings.
	 *
	 * @return void
	 */
	public static function admin_settings() {
		if ( empty( self::$settings ) ) {
			self::init_form_fields();
		}
		woocommerce_admin_fields( self::$settings );
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function save_admin_settings() {
		global $current_section;

		if ( ! $current_section ) {
			if ( empty( self::$settings ) ) {
				self::init_form_fields();
			}
			woocommerce_update_options( self::$settings );
		}
	}

	/**
	 * Add EORI field to admin billing fields.
	 *
	 * @param array $fields Billing fields.
	 * @return array
	 */
	public static function admin_billing_fields( $fields ) {
		global $theorder;

		$fields['eori_number'] = array(
			'label' => __( 'EORI number', 'vibeshift-eu-shipping' ),
			'show'  => false,
			'id'    => '_eori_number',
			'value' => is_object( $theorder ) ? wc_eori_get_eori_from_order( $theorder ) : '',
		);

		return $fields;
	}

	/**
	 * Add customer EORI field.
	 *
	 * @param array $fields Customer meta fields.
	 * @return array
	 */
	public static function add_customer_meta_fields( $fields ) {
		if ( isset( $fields['billing']['fields'] ) ) {
			$fields['billing']['fields']['eori_number'] = array(
				'label'       => __( 'EORI number', 'vibeshift-eu-shipping' ),
				'description' => __( 'Stored EORI number used to prefill checkout.', 'vibeshift-eu-shipping' ),
			);
		}

		return $fields;
	}

	/**
	 * Add EORI metabox.
	 *
	 * @return void
	 */
	public static function add_meta_boxes() {
		if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';
		} else {
			$screen = 'shop_order';
		}

		add_meta_box( 'wc_eori_number', __( 'EORI Validation', 'vibeshift-eu-shipping' ), array( __CLASS__, 'output_meta_box' ), $screen, 'side' );
	}

	/**
	 * Output EORI metabox.
	 *
	 * @return void
	 */
	public static function output_meta_box() {
		global $post;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce admin order global.
		global $theorder;

		$order = ( is_object( $theorder ) ) ? $theorder : null;
		if ( ! $order && $post ) {
			$order = wc_get_order( $post->ID );
		}

		if ( ! $order ) {
			return;
		}

		$data    = wc_eori_get_order_validation_data( $order );
		$country = wc_eori_get_destination_country_from_order( $order );
		?>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Required', 'vibeshift-eu-shipping' ); ?></th>
					<td><?php echo wc_eori_is_country_required( $country ) ? esc_html__( 'Yes', 'vibeshift-eu-shipping' ) : esc_html__( 'No', 'vibeshift-eu-shipping' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Destination', 'vibeshift-eu-shipping' ); ?></th>
					<td><?php echo esc_html( $country ? $country : __( 'Unknown', 'vibeshift-eu-shipping' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'EORI', 'vibeshift-eu-shipping' ); ?></th>
					<td><?php echo $data['eori'] ? esc_html( $data['eori'] ) : esc_html__( 'Not provided', 'vibeshift-eu-shipping' ); ?></td>
				</tr>
				<?php if ( $data['eori'] ) : ?>
					<tr>
						<th><?php esc_html_e( 'Valid', 'vibeshift-eu-shipping' ); ?></th>
						<td>
							<?php
							if ( ! $data['validated'] ) {
								esc_html_e( 'Validation failed', 'vibeshift-eu-shipping' );
							} else {
								echo $data['valid'] ? '<span style="color:green">&#10004;</span>' : '<span style="color:red">&#10008;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Provider', 'vibeshift-eu-shipping' ); ?></th>
						<td><?php echo esc_html( strtoupper( $data['provider'] ) ); ?></td>
					</tr>
					<?php if ( $data['company_name'] ) : ?>
						<tr>
							<th><?php esc_html_e( 'Company', 'vibeshift-eu-shipping' ); ?></th>
							<td><?php echo esc_html( $data['company_name'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $data['company_address'] ) : ?>
						<tr>
							<th><?php esc_html_e( 'Address', 'vibeshift-eu-shipping' ); ?></th>
							<td><?php echo esc_html( $data['company_address'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $data['error'] ) : ?>
						<tr>
							<th><?php esc_html_e( 'Error', 'vibeshift-eu-shipping' ); ?></th>
							<td><?php echo esc_html( $data['error'] ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Add order list column.
	 *
	 * @param array $existing_columns Existing columns.
	 * @return array
	 */
	public static function add_column( $existing_columns ) {
		$columns = array();

		foreach ( $existing_columns as $key => $label ) {
			$columns[ $key ] = $label;

			if ( 'shipping_address' === $key ) {
				$columns['eori_number'] = __( 'EORI', 'vibeshift-eu-shipping' );
			}
		}

		if ( ! isset( $columns['eori_number'] ) ) {
			$columns['eori_number'] = __( 'EORI', 'vibeshift-eu-shipping' );
		}

		return $columns;
	}

	/**
	 * Show order list column.
	 *
	 * @param string       $column Column key.
	 * @param int|WC_Order $order Order ID or object.
	 * @return void
	 */
	public static function show_column( $column, $order ) {
		if ( 'eori_number' !== $column ) {
			return;
		}

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		$data = wc_eori_get_order_validation_data( $order );
		if ( empty( $data['eori'] ) ) {
			echo '<span class="na">&ndash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo esc_html( $data['eori'] ) . ' ';
		if ( $data['validated'] && $data['valid'] ) {
			echo '<span style="color:green">&#10004;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! $data['validated'] ) {
			esc_html_e( '(validation failed)', 'vibeshift-eu-shipping' );
		} else {
			echo '<span style="color:red">&#10008;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
