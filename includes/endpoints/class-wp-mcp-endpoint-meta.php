<?php
/**
 * Meta and ACF endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Meta
 */
class WP_MCP_Endpoint_Meta {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/meta/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_catalog' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/acf/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_acf_fields' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_acf_fields' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Get unified meta field catalog.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_catalog() {
		WP_MCP_Adapter_Registry::init();
		return new WP_REST_Response( WP_MCP_Meta::get_unified_catalog(), 200 );
	}

	/**
	 * Get ACF fields for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_acf_fields( WP_REST_Request $request ) {
		if ( ! WP_MCP_Adapter_ACF::is_available() ) {
			return new WP_Error( 'acf_inactive', __( 'ACF is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'fields'  => WP_MCP_Adapter_ACF::get_fields_for_post( $post_id ),
				'catalog' => WP_MCP_Adapter_ACF::get_field_catalog(),
			),
			200
		);
	}

	/**
	 * Update ACF fields on a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_acf_fields( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : $params;

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return new WP_Error( 'missing_fields', __( 'fields object is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Adapter_ACF::update_fields( $post_id, $fields, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
