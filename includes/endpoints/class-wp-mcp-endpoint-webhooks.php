<?php
/**
 * Custom webhooks REST endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Webhooks
 */
class WP_MCP_Endpoint_Webhooks {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/webhooks/topics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_topics' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/webhooks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_webhooks' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_webhook' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/webhooks/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_webhook' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_webhook' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_webhook' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/webhooks/(?P<id>\d+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_webhook' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/webhooks/(?P<id>\d+)/deliveries',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_deliveries' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List topic catalog.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_topics() {
		return new WP_REST_Response(
			array(
				'topics' => WP_MCP_Webhooks::get_topic_catalog(),
				'count'  => count( WP_MCP_Webhooks::get_topic_catalog() ),
			),
			200
		);
	}

	/**
	 * List webhooks.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_webhooks() {
		return new WP_REST_Response( WP_MCP_Webhooks::list_webhooks(), 200 );
	}

	/**
	 * Get webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_webhook( WP_REST_Request $request ) {
		$result = WP_MCP_Webhooks::get_webhook( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Create webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_webhook( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'create_webhook', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run' => true,
					'action'  => 'create_webhook',
					'params'  => $params,
				),
				200
			);
		}

		$result = WP_MCP_Webhooks::create_webhook( is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Update webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_webhook( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run' => true,
					'id'      => (int) $request['id'],
					'params'  => $params,
				),
				200
			);
		}

		$result = WP_MCP_Webhooks::update_webhook( (int) $request['id'], is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_webhook( WP_REST_Request $request ) {
		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'delete_webhook', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run' => true,
					'id'      => (int) $request['id'],
					'action'  => 'delete_webhook',
				),
				200
			);
		}

		$result = WP_MCP_Webhooks::delete_webhook( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Test webhook delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test_webhook( WP_REST_Request $request ) {
		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'test_webhook', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run' => true,
					'id'      => (int) $request['id'],
					'action'  => 'test_webhook',
				),
				200
			);
		}

		$result = WP_MCP_Webhooks::send_test( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List webhook deliveries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_deliveries( WP_REST_Request $request ) {
		$result = WP_MCP_Webhooks::list_deliveries( (int) $request['id'], $request->get_query_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
