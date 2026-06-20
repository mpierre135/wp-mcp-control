<?php
/**
 * SEO endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Seo
 */
class WP_MCP_Endpoint_Seo {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/seo/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_page_seo' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_page_seo' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/seo/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'seo_audit' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Get SEO data for a page/post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_page_seo( WP_REST_Request $request ) {
		if ( ! WP_MCP_Adapter_AIOSEO::is_available() ) {
			return new WP_Error( 'aioseo_inactive', __( 'All in One SEO is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'post_id' => $post_id,
				'title'   => $post->post_title,
				'seo'     => WP_MCP_Adapter_AIOSEO::get_post_seo( $post_id ),
				'catalog' => WP_MCP_Adapter_AIOSEO::get_field_catalog(),
			),
			200
		);
	}

	/**
	 * Update SEO for a page/post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_page_seo( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		$fields = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : $params;

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return new WP_Error( 'missing_fields', __( 'SEO fields are required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Adapter_AIOSEO::update_post_seo( $post_id, $fields, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Run SEO audit.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function seo_audit() {
		if ( ! WP_MCP_Adapter_AIOSEO::is_available() ) {
			return new WP_Error( 'aioseo_inactive', __( 'All in One SEO is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		return new WP_REST_Response( WP_MCP_Adapter_AIOSEO::audit(), 200 );
	}
}
