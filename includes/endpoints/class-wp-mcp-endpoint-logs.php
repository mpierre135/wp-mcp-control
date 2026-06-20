<?php
/**
 * Activity log and restore endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Logs
 */
class WP_MCP_Endpoint_Logs {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/activity-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_activity_log' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'restore' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Get activity log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_activity_log( WP_REST_Request $request ) {
		$params = $request->get_query_params();
		$data   = WP_MCP_Logger::get_logs( $params );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Restore snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore( WP_REST_Request $request ) {
		$params      = $request->get_json_params();
		$snapshot_id = isset( $params['snapshot_id'] ) ? absint( $params['snapshot_id'] ) : 0;

		if ( ! $snapshot_id ) {
			return new WP_Error( 'missing_snapshot_id', __( 'snapshot_id is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( empty( $params['confirm'] ) ) {
			return new WP_Error( 'confirm_required', __( 'Restore requires confirm=true.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Snapshots::restore( $snapshot_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}
}
