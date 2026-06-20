<?php
/**
 * WooCommerce adapter for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_WooCommerce
 */
class WP_MCP_Adapter_WooCommerce extends WP_MCP_Adapter_Base {

	/**
	 * @inheritdoc
	 */
	public static function slug() {
		return 'woocommerce';
	}

	/**
	 * @inheritdoc
	 */
	public static function label() {
		return 'WooCommerce';
	}

	/**
	 * @inheritdoc
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * @inheritdoc
	 */
	public static function get_field_catalog() {
		return array(
			'name'              => array( 'type' => 'text', 'label' => 'Product Name' ),
			'slug'              => array( 'type' => 'text', 'label' => 'Slug' ),
			'status'            => array( 'type' => 'text', 'label' => 'Status' ),
			'description'       => array( 'type' => 'html', 'label' => 'Description' ),
			'short_description' => array( 'type' => 'html', 'label' => 'Short Description' ),
			'regular_price'     => array( 'type' => 'text', 'label' => 'Regular Price' ),
			'sale_price'        => array( 'type' => 'text', 'label' => 'Sale Price' ),
			'sku'               => array( 'type' => 'text', 'label' => 'SKU' ),
			'stock_quantity'    => array( 'type' => 'int', 'label' => 'Stock Quantity' ),
			'manage_stock'      => array( 'type' => 'bool', 'label' => 'Manage Stock' ),
			'stock_status'      => array( 'type' => 'text', 'label' => 'Stock Status' ),
		);
	}

	/**
	 * Format product for API response.
	 *
	 * @param WC_Product $product Product.
	 * @param bool       $full    Include full details.
	 * @return array|null
	 */
	public static function format_product( $product, $full = false ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return null;
		}

		$data = array(
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'slug'         => $product->get_slug(),
			'type'         => $product->get_type(),
			'status'       => $product->get_status(),
			'sku'          => $product->get_sku(),
			'price'        => $product->get_price(),
			'regular_price'=> $product->get_regular_price(),
			'sale_price'   => $product->get_sale_price(),
			'stock_status' => $product->get_stock_status(),
			'permalink'    => get_permalink( $product->get_id() ),
		);

		if ( $full ) {
			$data['description']       = $product->get_description();
			$data['short_description'] = $product->get_short_description();
			$data['manage_stock']      = $product->get_manage_stock();
			$data['stock_quantity']    = $product->get_stock_quantity();
			$data['categories']        = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
			$data['tags']              = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'ids' ) );
			$data['featured']          = $product->is_featured();
			$data['virtual']           = $product->is_virtual();
			$data['downloadable']      = $product->is_downloadable();
		}

		return $data;
	}

	/**
	 * List products.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_products( $args = array() ) {
		$defaults = array(
			'status'   => 'any',
			'search'   => '',
			'page'     => 1,
			'per_page' => 20,
		);
		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'status' => 'any' === $args['status'] ? array( 'publish', 'draft', 'pending', 'private' ) : sanitize_text_field( $args['status'] ),
			'limit'  => min( 100, max( 1, (int) $args['per_page'] ) ),
			'page'   => max( 1, (int) $args['page'] ),
			'return' => 'objects',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = sanitize_text_field( $args['search'] );
		}

		$products = wc_get_products( $query_args );
		$items    = array();

		foreach ( $products as $product ) {
			$formatted = self::format_product( $product, false );
			if ( $formatted ) {
				$items[] = $formatted;
			}
		}

		$count_query = wc_get_products(
			array_merge(
				$query_args,
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);

		return array(
			'items'    => $items,
			'total'    => is_array( $count_query ) ? count( $count_query ) : 0,
			'page'     => (int) $args['page'],
			'per_page' => (int) $args['per_page'],
		);
	}

	/**
	 * Get single product.
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error
	 */
	public static function get_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		return self::format_product( $product, true );
	}

	/**
	 * Apply product fields to WC_Product instance.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $fields  Fields.
	 */
	private static function apply_product_fields( $product, $fields ) {
		$map = array(
			'name'              => 'set_name',
			'slug'              => 'set_slug',
			'status'            => 'set_status',
			'description'       => 'set_description',
			'short_description' => 'set_short_description',
			'regular_price'     => 'set_regular_price',
			'sale_price'        => 'set_sale_price',
			'sku'               => 'set_sku',
			'stock_quantity'    => 'set_stock_quantity',
			'manage_stock'      => 'set_manage_stock',
			'stock_status'      => 'set_stock_status',
		);

		foreach ( $fields as $key => $value ) {
			if ( isset( $map[ $key ] ) && method_exists( $product, $map[ $key ] ) ) {
				$product->{$map[ $key ]}( $value );
			}
		}

		if ( isset( $fields['featured'] ) ) {
			$product->set_featured( (bool) $fields['featured'] );
		}
	}

	/**
	 * Create product.
	 *
	 * @param array           $fields  Product fields.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_product( $fields, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'woocommerce_inactive', __( 'WooCommerce is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$clean = self::sanitize_fields( $fields );
		if ( is_wp_error( $clean ) ) {
			if ( empty( $fields['name'] ) ) {
				return new WP_Error( 'missing_name', __( 'Product name is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
			}
			$clean = array( 'name' => sanitize_text_field( $fields['name'] ) );
			foreach ( $fields as $key => $value ) {
				if ( 'name' !== $key && isset( self::get_field_catalog()[ $key ] ) ) {
					$sanitized = WP_MCP_Meta::sanitize_by_type( self::get_field_catalog()[ $key ]['type'], $value );
					if ( null !== $sanitized ) {
						$clean[ $key ] = $sanitized;
					}
				}
			}
		}

		if ( empty( $clean['name'] ) ) {
			return new WP_Error( 'missing_name', __( 'Product name is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'fields' => $clean );
		}

		$product = new WC_Product_Simple();
		self::apply_product_fields( $product, $clean );
		$product_id = $product->save();

		if ( ! $product_id ) {
			return new WP_Error( 'create_failed', __( 'Failed to create product.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action( 'woocommerce.product.create', 'product', $product_id, array( 'name' => $clean['name'] ), 'success' );

		return self::format_product( wc_get_product( $product_id ), true );
	}

	/**
	 * Update product.
	 *
	 * @param int             $product_id Product ID.
	 * @param array           $fields     Fields.
	 * @param WP_REST_Request $request    Request.
	 * @return array|WP_Error
	 */
	public static function update_product( $product_id, $fields, WP_REST_Request $request ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$clean = array();
		$catalog = self::get_field_catalog();
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $catalog[ $key ] ) ) {
				continue;
			}
			$sanitized = WP_MCP_Meta::sanitize_by_type( $catalog[ $key ]['type'], $value );
			if ( null !== $sanitized ) {
				$clean[ $key ] = $sanitized;
			}
		}

		if ( empty( $clean ) ) {
			return new WP_Error( 'no_valid_fields', __( 'No valid fields provided.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'product_id' => $product_id, 'fields' => $clean );
		}

		WP_MCP_Snapshots::create_snapshot( 'product', $product_id, get_post( $product_id ) );
		self::apply_product_fields( $product, $clean );
		$product->save();

		WP_MCP_Logger::log_action( 'woocommerce.product.update', 'product', $product_id, array( 'fields' => array_keys( $clean ) ), 'success' );

		return self::format_product( wc_get_product( $product_id ), true );
	}

	/**
	 * Delete product.
	 *
	 * @param int             $product_id Product ID.
	 * @param WP_REST_Request $request    Request.
	 * @return array|WP_Error
	 */
	public static function delete_product( $product_id, WP_REST_Request $request ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params  = $request->get_json_params();
		$force   = ! empty( $params['force'] );
		$confirm = ! empty( $params['confirm'] );

		if ( ! $confirm ) {
			return new WP_Error( 'confirm_required', __( 'Deletion requires confirm=true.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'product_id' => $product_id, 'force' => $force );
		}

		WP_MCP_Snapshots::create_snapshot( 'product', $product_id, get_post( $product_id ) );

		if ( $force ) {
			$result = wp_delete_post( $product_id, true );
		} else {
			$result = wp_trash_post( $product_id );
		}

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete product.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action( 'woocommerce.product.delete', 'product', $product_id, array( 'force' => $force ), 'success' );

		return array( 'id' => $product_id, 'deleted' => true, 'force' => $force );
	}

	/**
	 * Update product price.
	 *
	 * @param int             $product_id    Product ID.
	 * @param array           $price_fields  Price fields.
	 * @param WP_REST_Request $request       Request.
	 * @return array|WP_Error
	 */
	public static function update_price( $product_id, $price_fields, WP_REST_Request $request ) {
		$fields = array();
		if ( isset( $price_fields['regular_price'] ) ) {
			$fields['regular_price'] = $price_fields['regular_price'];
		}
		if ( isset( $price_fields['sale_price'] ) ) {
			$fields['sale_price'] = $price_fields['sale_price'];
		}
		if ( isset( $price_fields['price'] ) ) {
			$fields['regular_price'] = $price_fields['price'];
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'missing_price', __( 'regular_price or price is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return self::update_product( $product_id, $fields, $request );
	}

	/**
	 * Format order for API response.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $full  Full details.
	 * @return array|null
	 */
	public static function format_order( $order, $full = false ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return null;
		}

		$data = array(
			'id'            => $order->get_id(),
			'number'        => $order->get_order_number(),
			'status'        => $order->get_status(),
			'currency'      => $order->get_currency(),
			'total'         => $order->get_total(),
			'date_created'  => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'customer_id'   => $order->get_customer_id(),
			'billing_email' => $order->get_billing_email(),
		);

		if ( $full ) {
			$data['billing']  = $order->get_address( 'billing' );
			$data['shipping'] = $order->get_address( 'shipping' );
			$data['payment_method']       = $order->get_payment_method();
			$data['payment_method_title'] = $order->get_payment_method_title();
			$data['customer_note']        = $order->get_customer_note();
			$data['line_items']           = array();

			foreach ( $order->get_items() as $item_id => $item ) {
				$data['line_items'][] = array(
					'id'           => $item_id,
					'name'         => $item->get_name(),
					'product_id'   => $item->get_product_id(),
					'quantity'     => $item->get_quantity(),
					'subtotal'     => $item->get_subtotal(),
					'total'        => $item->get_total(),
				);
			}

			$data['shipping_lines'] = array();
			foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
				$data['shipping_lines'][] = array(
					'id'    => $item_id,
					'method'=> $item->get_method_title(),
					'total' => $item->get_total(),
				);
			}
		}

		return $data;
	}

	/**
	 * List orders.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function list_orders( $args = array() ) {
		$defaults = array(
			'status'   => 'any',
			'search'   => '',
			'page'     => 1,
			'per_page' => 20,
		);
		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'limit'   => min( 100, max( 1, (int) $args['per_page'] ) ),
			'page'    => max( 1, (int) $args['page'] ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		if ( 'any' !== $args['status'] ) {
			$query_args['status'] = sanitize_text_field( $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = sanitize_text_field( $args['search'] );
		}

		$orders = wc_get_orders( $query_args );
		$items  = array();

		foreach ( $orders as $order ) {
			$formatted = self::format_order( $order, false );
			if ( $formatted ) {
				$items[] = $formatted;
			}
		}

		$count_args = array_merge( $query_args, array( 'limit' => -1, 'return' => 'ids' ) );
		$total      = wc_get_orders( $count_args );

		return array(
			'items'    => $items,
			'total'    => is_array( $total ) ? count( $total ) : 0,
			'page'     => (int) $args['page'],
			'per_page' => (int) $args['per_page'],
		);
	}

	/**
	 * Get order.
	 *
	 * @param int $order_id Order ID.
	 * @return array|WP_Error
	 */
	public static function get_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		return self::format_order( $order, true );
	}

	/**
	 * Snapshot order before mutation.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function snapshot_order( $order ) {
		$post = get_post( $order->get_id() );
		if ( $post ) {
			WP_MCP_Snapshots::create_snapshot( 'shop_order', $order->get_id(), $post );
		}
	}

	/**
	 * Update order (full write).
	 *
	 * @param int             $order_id Order ID.
	 * @param array           $fields   Fields.
	 * @param WP_REST_Request $request  Request.
	 * @return array|WP_Error
	 */
	public static function update_order( $order_id, $fields, WP_REST_Request $request ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'wc_update_order', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'order_id' => $order_id, 'fields' => $fields );
		}

		self::snapshot_order( $order );

		if ( isset( $fields['status'] ) ) {
			$order->set_status( sanitize_text_field( $fields['status'] ) );
		}

		if ( isset( $fields['customer_note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $fields['customer_note'] ) );
		}

		if ( ! empty( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
			$order->set_address( self::sanitize_address( $fields['billing'] ), 'billing' );
		}

		if ( ! empty( $fields['shipping'] ) && is_array( $fields['shipping'] ) ) {
			$order->set_address( self::sanitize_address( $fields['shipping'] ), 'shipping' );
		}

		if ( ! empty( $fields['line_items'] ) && is_array( $fields['line_items'] ) ) {
			foreach ( $fields['line_items'] as $line ) {
				if ( empty( $line['id'] ) ) {
					continue;
				}
				$item = $order->get_item( absint( $line['id'] ) );
				if ( ! $item ) {
					continue;
				}
				if ( isset( $line['quantity'] ) ) {
					$item->set_quantity( absint( $line['quantity'] ) );
					$item->save();
				}
			}
			$order->calculate_totals();
		}

		$order->save();

		WP_MCP_Logger::log_action(
			'woocommerce.order.update',
			'shop_order',
			$order_id,
			array( 'status' => $order->get_status(), 'fields' => array_keys( $fields ) ),
			'success'
		);

		return self::format_order( wc_get_order( $order_id ), true );
	}

	/**
	 * Sanitize address array.
	 *
	 * @param array $address Address.
	 * @return array
	 */
	private static function sanitize_address( $address ) {
		$clean = array();
		$keys  = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		foreach ( $keys as $key ) {
			if ( isset( $address[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $address[ $key ] );
			}
		}
		return $clean;
	}

	/**
	 * Add order note.
	 *
	 * @param int             $order_id Order ID.
	 * @param string          $note     Note text.
	 * @param bool            $customer Whether customer-visible.
	 * @param WP_REST_Request $request  Request.
	 * @return array|WP_Error
	 */
	public static function add_order_note( $order_id, $note, $customer, WP_REST_Request $request ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$note = sanitize_textarea_field( $note );
		if ( empty( $note ) ) {
			return new WP_Error( 'missing_note', __( 'Note text is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'order_id' => $order_id, 'note' => $note );
		}

		self::snapshot_order( $order );

		$note_id = $order->add_order_note( $note, (bool) $customer, true );

		WP_MCP_Logger::log_action( 'woocommerce.order.note', 'shop_order', $order_id, array( 'note_id' => $note_id ), 'success' );

		return array(
			'order_id' => $order_id,
			'note_id'  => $note_id,
			'added'    => true,
		);
	}

	/**
	 * Create refund.
	 *
	 * @param int             $order_id Order ID.
	 * @param array           $args     Refund args.
	 * @param WP_REST_Request $request  Request.
	 * @return array|WP_Error
	 */
	public static function create_refund( $order_id, $args, WP_REST_Request $request ) {
		if ( ! get_option( 'wp_mcp_allow_wc_refunds', false ) ) {
			return new WP_Error(
				'refunds_disabled',
				__( 'WooCommerce refunds are disabled in plugin settings.', 'wp-mcp-control' ),
				array( 'status' => 403 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'wc_refund_order', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$amount  = isset( $args['amount'] ) ? wc_format_decimal( $args['amount'] ) : 0;
		$reason  = isset( $args['reason'] ) ? sanitize_text_field( $args['reason'] ) : '';
		$restock = ! empty( $args['restock'] );

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'  => true,
				'order_id' => $order_id,
				'amount'   => $amount,
				'reason'   => $reason,
			);
		}

		self::snapshot_order( $order );

		$refund = wc_create_refund(
			array(
				'order_id' => $order_id,
				'amount'   => $amount ? $amount : $order->get_total(),
				'reason'   => $reason,
				'line_items' => array(),
			)
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		if ( $restock && method_exists( $refund, 'get_id' ) ) {
			wc_restock_refunded_items( $refund->get_id() );
		}

		WP_MCP_Logger::log_action(
			'woocommerce.order.refund',
			'shop_order',
			$order_id,
			array( 'refund_id' => $refund->get_id(), 'amount' => $amount ),
			'success'
		);

		return array(
			'order_id'  => $order_id,
			'refund_id' => $refund->get_id(),
			'amount'    => $refund->get_amount(),
			'created'   => true,
		);
	}

	/**
	 * List WC Bookings products.
	 *
	 * @return array|WP_Error
	 */
	public static function list_booking_products() {
		if ( ! class_exists( 'WC_Bookings' ) && ! post_type_exists( 'wc_booking' ) ) {
			return new WP_Error( 'bookings_inactive', __( 'WooCommerce Bookings is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$products = wc_get_products(
			array(
				'type'   => 'booking',
				'limit'  => 100,
				'return' => 'objects',
			)
		);

		$items = array();
		foreach ( $products as $product ) {
			$formatted = self::format_product( $product, true );
			if ( $formatted ) {
				$items[] = $formatted;
			}
		}

		return array( 'items' => $items, 'count' => count( $items ) );
	}

	/**
	 * Get booking details.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array|WP_Error
	 */
	public static function get_booking( $booking_id ) {
		if ( ! class_exists( 'WC_Booking' ) && ! get_post( $booking_id ) ) {
			return new WP_Error( 'bookings_inactive', __( 'WooCommerce Bookings is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$booking = null;
		if ( class_exists( 'WC_Booking' ) ) {
			$booking = new WC_Booking( $booking_id );
			if ( ! $booking->get_id() ) {
				return new WP_Error( 'not_found', __( 'Booking not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
			}

			return array(
				'id'         => $booking->get_id(),
				'product_id' => $booking->get_product_id(),
				'order_id'   => $booking->get_order_id(),
				'status'     => $booking->get_status(),
				'start'      => $booking->get_start() ? gmdate( 'c', $booking->get_start() ) : '',
				'end'        => $booking->get_end() ? gmdate( 'c', $booking->get_end() ) : '',
				'customer_id'=> $booking->get_customer_id(),
			);
		}

		$post = get_post( $booking_id );
		if ( ! $post || 'wc_booking' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Booking not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		return array(
			'id'     => $post->ID,
			'status' => $post->post_status,
			'title'  => $post->post_title,
			'meta'   => WP_MCP_Meta::get_post_meta_array( $post->ID ),
		);
	}
}
