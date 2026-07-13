<?php
/**
 * Handles EU VAT Number Privacy
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
 * EU VAT Privacy Class
 */
class WC_EU_VAT_Privacy extends WC_Abstract_Privacy {
	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( '', 5, 9 );

		// Initialize data exporters and erasers.
		add_action( 'init', array( $this, 'register_erasers_exporters' ) );
	}

	/**
	 * Initial registration of privacy erasers and exporters.
	 */
	public function register_erasers_exporters() {
		$this->name = __( 'EU VAT', 'vibeshift-eu-shipping' );

		$this->add_exporter( 'vibeshift-eu-shipping-order-data', __( 'EU VAT Order Data', 'vibeshift-eu-shipping' ), array( $this, 'order_data_exporter' ) );
		$this->add_exporter( 'vibeshift-eu-shipping-customer-data', __( 'EU VAT Customer Data', 'vibeshift-eu-shipping' ), array( $this, 'customer_data_exporter' ) );

		$this->add_eraser( 'vibeshift-eu-shipping-customer-data', __( 'EU VAT Customer Data', 'vibeshift-eu-shipping' ), array( $this, 'customer_data_eraser' ) );
		$this->add_eraser( 'vibeshift-eu-shipping-order-data', __( 'EU VAT Order Data', 'vibeshift-eu-shipping' ), array( $this, 'order_data_eraser' ) );

		if ( class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscriptions_Core_Plugin' ) ) {
			$this->add_eraser( 'vibeshift-eu-shipping-subscriptions-data', __( 'EU VAT Subscriptions Data', 'vibeshift-eu-shipping' ), array( $this, 'subscriptions_data_eraser' ) );
		}
	}

	/**
	 * Returns a list of orders.
	 *
	 * @param string $email_address Email address of customer.
	 * @param int    $page Current page.
	 * @param string $post_type Default: order. Set to 'subscription' if querying for subscription orders.
	 *
	 * @return array WP_Post
	 */
	protected function get_orders( $email_address, $page, $post_type = 'order' ) {
		$user = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.

		// Default Query Args.
		$order_query = array(
			'status' => 'any',
			'limit'  => 10,
			'page'   => $page,
		);

		// Additional query args to retrieve WooCommerce orders.
		if ( $user instanceof WP_User ) {
			$order_query['customer_id'] = (int) $user->ID;
		} else {
			$order_query['billing_email'] = $email_address;
		}

		// Using wc_get_orders for WC orders & data store for subscriptions.
		if ( 'subscription' === $post_type ) {
			$result = WC_Data_Store::load( $post_type )->get_orders( $order_query );
		} else {
			$result = wc_get_orders( $order_query );
		}

		return $result;
	}

	/**
	 * Gets the message of the privacy to display.
	 */
	public function get_privacy_message() {
		return wpautop( __( 'By using this extension, you may store VAT numbers and related validation data on customer and order records, and share VAT numbers with external government validation services (such as VIES or HMRC).', 'vibeshift-eu-shipping' ) );
	}

	/**
	 * Handle exporting data for Orders.
	 *
	 * @param string $email_address E-mail address to export.
	 * @param int    $page          Pagination of data.
	 *
	 * @return array
	 */
	public function order_data_exporter( $email_address, $page = 1 ) {
		$done           = false;
		$data_to_export = array();

		$orders = $this->get_orders( $email_address, (int) $page );

		$done = true;

		if ( 0 < count( $orders ) ) {

			/** @var \WC_Order $order */
			foreach ( $orders as $order ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_orders',
					'group_label' => __( 'Orders', 'vibeshift-eu-shipping' ),
					'item_id'     => 'order-' . $order->get_id(),
					'data'        => array(
						array(
							'name'  => __( 'EU VAT number', 'vibeshift-eu-shipping' ),
							'value' => $order->get_meta( '_vat_number', true ),
						),
						array(
							'name'  => __( 'EU Billing VAT number', 'vibeshift-eu-shipping' ),
							'value' => $order->get_meta( '_billing_vat_number', true ),
						),
						array(
							'name'  => __( 'EU VAT country', 'vibeshift-eu-shipping' ),
							'value' => $order->get_meta( '_customer_ip_country', true ),
						),
					),
				);
			}

			$done = 10 > count( $orders );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Finds and exports customer data by email address.
	 *
	 * @since 3.4.0
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 *
	 * @return array An array of personal data in name value pairs
	 */
	public function customer_data_exporter( $email_address, $page ) {
		$user           = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
		$data_to_export = array();

		if ( $user instanceof WP_User ) {
			$data_to_export[] = array(
				'group_id'    => 'woocommerce_customer',
				'group_label' => __( 'Customer Data', 'vibeshift-eu-shipping' ),
				'item_id'     => 'user',
				'data'        => array(
					array(
						'name'  => get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'vibeshift-eu-shipping' ) ),
						'value' => get_user_meta( $user->ID, 'vat_number', true ),
					),
				),
			);
		}

		return array(
			'data' => $data_to_export,
			'done' => true,
		);
	}

	/**
	 * Finds and erases customer data by email address.
	 *
	 * @since 3.4.0
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 *
	 * @return array An array of personal data in name value pairs
	 */
	public function customer_data_eraser( $email_address, $page ) {
		$page = (int) $page;
		$user = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.

		$vat_number = get_user_meta( $user->ID, 'vat_number', true );

		$items_removed = false;
		$messages      = array();

		if ( ! empty( $vat_number ) ) {
			$items_removed = true;
			delete_user_meta( $user->ID, 'vat_number' );
			$messages[] = __( 'EU VAT User Data Erased.', 'vibeshift-eu-shipping' );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Finds and erases order data by email address.
	 *
	 * @since 3.4.0
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs
	 */
	public function order_data_eraser( $email_address, $page ) {
		$orders = $this->get_orders( $email_address, (int) $page );
		return $this->maybe_erase_order_data( $orders );
	}

	/**
	 * Finds and erases Subscription order data by email address.
	 *
	 * @since 2.3.26
	 *
	 * @param string $email_address The user email address.
	 * @param int    $page Page.
	 * @return array An array of personal data in name value pairs.
	 */
	public function subscriptions_data_eraser( $email_address, $page ) {
		$subscriptions = $this->get_orders( $email_address, (int) $page, 'subscription' );
		return $this->maybe_erase_order_data( $subscriptions );
	}

	/**
	 * Erase EU VAT data from WooCommerce/Subscription orders.
	 *
	 * @since 2.3.26
	 *
	 * @param array $orders WooCommerce orders.
	 *
	 * @return array
	 */
	protected function maybe_erase_order_data( $orders ) {
		$items_removed  = false;
		$items_retained = false;
		$messages       = array();
		$done           = true;

		foreach ( (array) $orders as $order ) {
			list( $removed, $retained, $msgs ) = $this->maybe_handle_order( $order );

			$items_removed  |= $removed;
			$items_retained |= $retained;
			$messages        = array_merge( $messages, $msgs );
		}

		// Tell core if we have more orders to work on still.
		$done = count( $orders ) < 10;

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Handle eraser of data tied to Orders
	 *
	 * @param WC_Order $order WooCommerce order/subscription object.
	 *
	 * @return array Array of order data removed/retained along with corresponding message.
	 */
	protected function maybe_handle_order( $order ) {
		$order_id = $order->get_id();
		$message  = array();

		$vat_number = $order->get_meta( '_billing_vat_number', true );
		$vat_number = ( ! empty( $vat_number ) ) ? $vat_number : $order->get_meta( '_vat_number', true ); // Compat with version < 2.3.21.
		$ip_country = $order->get_meta( '_customer_ip_country', true );

		if ( empty( $vat_number ) && empty( $ip_country ) ) {
			return array( false, false, array() );
		}

		$order->delete_meta_data( '_billing_vat_number' );
		$order->delete_meta_data( '_vat_number' ); // Compat with version < 2.3.21.
		$order->delete_meta_data( '_customer_ip_country' );

		$order->save();

		// Set default post type to 'order'.
		$post_type = 'order';

		// Check whether the order is a subscription.
		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) {
			$post_type = 'subscription';
		}

		// translators: Post type, Order ID.
		$message[] = sprintf( __( 'Removed EU VAT data from %1$s %2$s.', 'vibeshift-eu-shipping' ), $post_type, $order_id );

		return array( true, false, $message );
	}
}

new WC_EU_VAT_Privacy();
