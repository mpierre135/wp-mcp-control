<?php
/**
 * WooCommerce REST endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_WooCommerce
 */
class WP_MCP_Endpoint_WooCommerce {

	/**
	 * Register routes.
	 */
	public static function register() {
		if ( ! class_exists( 'WP_MCP_Adapter_WooCommerce' ) ) {
			return;
		}

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/products',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_products' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_product' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/products/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_product' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_product' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_product' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/products/(?P<id>\d+)/price',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_price' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/orders/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_order' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_order' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/orders/(?P<id>\d+)/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_order_note' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/orders/(?P<id>\d+)/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_refund' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/bookings/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_booking_products' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/woocommerce/bookings/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_booking' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Ensure WooCommerce is active.
	 *
	 * @return true|WP_Error
	 */
	private static function require_wc() {
		if ( ! WP_MCP_Adapter_WooCommerce::is_available() ) {
			return new WP_Error( 'woocommerce_inactive', __( 'WooCommerce is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * List products.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_products( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return new WP_REST_Response( WP_MCP_Adapter_WooCommerce::list_products( $request->get_query_params() ), 200 );
	}

	/**
	 * Get product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_product( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = WP_MCP_Adapter_WooCommerce::get_product( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Create product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_product( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = WP_MCP_Adapter_WooCommerce::create_product( $params, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status = ! empty( $result['dry_run'] ) ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Update product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_product( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = WP_MCP_Adapter_WooCommerce::update_product( (int) $request['id'], $params, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_product( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = WP_MCP_Adapter_WooCommerce::delete_product( (int) $request['id'], $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Update product price.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_price( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = WP_MCP_Adapter_WooCommerce::update_price( (int) $request['id'], $params, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List orders.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_orders( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return new WP_REST_Response( WP_MCP_Adapter_WooCommerce::list_orders( $request->get_query_params() ), 200 );
	}

	/**
	 * Get order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_order( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = WP_MCP_Adapter_WooCommerce::get_order( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Update order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_order( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = WP_MCP_Adapter_WooCommerce::update_order( (int) $request['id'], $params, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Add order note.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_order_note( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$note     = isset( $params['note'] ) ? $params['note'] : '';
		$customer = ! empty( $params['customer_note'] );
		$result   = WP_MCP_Adapter_WooCommerce::add_order_note( (int) $request['id'], $note, $customer, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Create refund.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_refund( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$result = WP_MCP_Adapter_WooCommerce::create_refund( (int) $request['id'], $params, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List booking products.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_booking_products() {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = WP_MCP_Adapter_WooCommerce::list_booking_products();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_booking( WP_REST_Request $request ) {
		$check = self::require_wc();
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$result = WP_MCP_Adapter_WooCommerce::get_booking( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
