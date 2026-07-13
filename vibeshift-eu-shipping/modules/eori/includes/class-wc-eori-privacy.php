<?php
/**
 * EORI privacy integration.
 *
 * @package vibeshift-eu-shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Abstract_Privacy' ) ) {
	return;
}

/**
 * EORI privacy exporter/eraser.
 */
class WC_EORI_Privacy extends WC_Abstract_Privacy {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->name = __( 'EORI', 'vibeshift-eu-shipping' );
		$this->add_exporter( 'vibeshift-eu-shipping-order-data', __( 'EORI Order Data', 'vibeshift-eu-shipping' ), array( $this, 'order_data_exporter' ) );
		$this->add_exporter( 'vibeshift-eu-shipping-customer-data', __( 'EORI Customer Data', 'vibeshift-eu-shipping' ), array( $this, 'customer_data_exporter' ) );
		$this->add_eraser( 'vibeshift-eu-shipping-order-data', __( 'EORI Order Data', 'vibeshift-eu-shipping' ), array( $this, 'order_data_eraser' ) );
		$this->add_eraser( 'vibeshift-eu-shipping-customer-data', __( 'EORI Customer Data', 'vibeshift-eu-shipping' ), array( $this, 'customer_data_eraser' ) );
	}

	/**
	 * Privacy message.
	 *
	 * @return string
	 */
	public function get_privacy_message() {
		return wpautop( __( 'By using this extension, you may store EORI numbers and validation details on customer and order records, and share EORI numbers with external government validation services.', 'vibeshift-eu-shipping' ) );
	}

	/**
	 * Export order EORI data.
	 *
	 * @param string $email_address Email.
	 * @param int    $page Page.
	 * @return array
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();
		$orders         = wc_get_orders(
			array(
				'billing_email' => $email_address,
				'limit'         => 10,
				'page'          => $page,
				'paginate'      => true,
				'meta_key'      => '_eori_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		foreach ( $orders->orders as $order ) {
			$validation = wc_eori_get_order_validation_data( $order );
			if ( empty( $validation['eori'] ) ) {
				continue;
			}

			$data_to_export[] = array(
				'group_id'    => 'woocommerce_orders',
				'group_label' => __( 'Orders', 'vibeshift-eu-shipping' ),
				'item_id'     => 'order-' . $order->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'EORI number', 'vibeshift-eu-shipping' ),
						'value' => $validation['eori'],
					),
					array(
						'name'  => __( 'EORI validation provider', 'vibeshift-eu-shipping' ),
						'value' => $validation['provider'],
					),
					array(
						'name'  => __( 'EORI validation date', 'vibeshift-eu-shipping' ),
						'value' => $validation['validation_date'],
					),
					array(
						'name'  => __( 'EORI company name', 'vibeshift-eu-shipping' ),
						'value' => $validation['company_name'],
					),
					array(
						'name'  => __( 'EORI company address', 'vibeshift-eu-shipping' ),
						'value' => $validation['company_address'],
					),
				),
			);
		}

		if ( $page >= $orders->max_num_pages ) {
			$done = true;
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Export customer EORI data.
	 *
	 * @param string $email_address Email.
	 * @return array
	 */
	public function customer_data_exporter( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$eori = get_user_meta( $user->ID, WC_EORI_Number::CUSTOMER_META_KEY, true );
		if ( ! $eori ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'woocommerce_customer',
					'group_label' => __( 'Customer Data', 'vibeshift-eu-shipping' ),
					'item_id'     => 'user',
					'data'        => array(
						array(
							'name'  => __( 'EORI number', 'vibeshift-eu-shipping' ),
							'value' => $eori,
						),
					),
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erase order EORI data.
	 *
	 * @param string $email_address Email.
	 * @param int    $page Page.
	 * @return array
	 */
	public function order_data_eraser( $email_address, $page = 1 ) {
		$orders = wc_get_orders(
			array(
				'billing_email' => $email_address,
				'limit'         => 10,
				'page'          => $page,
				'paginate'      => true,
				'meta_key'      => '_eori_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$items_removed  = false;
		$messages       = array();
		$meta_to_delete = array(
			'_eori_number',
			'_eori_number_is_validated',
			'_eori_number_is_valid',
			'_eori_validation_provider',
			'_eori_validation_date',
			'_eori_company_name',
			'_eori_company_address',
			'_eori_validation_error',
			WC_EORI_Number::BLOCK_FIELD_META_KEY,
		);

		foreach ( $orders->orders as $order ) {
			foreach ( $meta_to_delete as $meta_key ) {
				$order->delete_meta_data( $meta_key );
			}
			$order->save_meta_data();
			$items_removed = true;
			$messages[]    = sprintf(
				/* translators: %d: order ID */
				__( 'Removed EORI data from order %d.', 'vibeshift-eu-shipping' ),
				$order->get_id()
			);
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => $page >= $orders->max_num_pages,
		);
	}

	/**
	 * Erase customer EORI data.
	 *
	 * @param string $email_address Email.
	 * @return array
	 */
	public function customer_data_eraser( $email_address ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = false;
		if ( get_user_meta( $user->ID, WC_EORI_Number::CUSTOMER_META_KEY, true ) ) {
			delete_user_meta( $user->ID, WC_EORI_Number::CUSTOMER_META_KEY );
			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'EORI customer data erased.', 'vibeshift-eu-shipping' ) ) : array(),
			'done'           => true,
		);
	}
}

new WC_EORI_Privacy();
