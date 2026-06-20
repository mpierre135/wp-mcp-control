<?php
/**
 * Forms endpoint (Ninja Forms).
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Forms
 */
class WP_MCP_Endpoint_Forms {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_forms' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/forms/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_form' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/forms/(?P<id>\d+)/notifications',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_notifications' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/forms/(?P<id>\d+)/submissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_submissions' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List forms.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_forms() {
		$result = WP_MCP_Adapter_Ninja_Forms::list_forms();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_form( WP_REST_Request $request ) {
		$result = WP_MCP_Adapter_Ninja_Forms::get_form( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Update form notifications.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_notifications( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$updates = isset( $params['actions'] ) && is_array( $params['actions'] ) ? $params['actions'] : $params;

		$result = WP_MCP_Adapter_Ninja_Forms::update_notifications( (int) $request['id'], $updates, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List form submissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_submissions( WP_REST_Request $request ) {
		$result = WP_MCP_Adapter_Ninja_Forms::list_submissions( (int) $request['id'], $request->get_query_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
