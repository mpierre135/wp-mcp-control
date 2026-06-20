<?php
/**
 * Pages endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Pages
 */
class WP_MCP_Endpoint_Pages {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/pages',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_pages' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_page' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_page' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_page' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_page' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * List pages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_pages( WP_REST_Request $request ) {
		$data = WP_MCP_REST::list_posts( 'page', $request );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get single page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_page( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( WP_MCP_REST::format_post( $post, true ), 200 );
	}

	/**
	 * Create page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_page( WP_REST_Request $request ) {
		$result = WP_MCP_REST::save_post_from_request( 'page', $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status = ! empty( $result['dry_run'] ) ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Update page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_page( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		$result = WP_MCP_REST::save_post_from_request( 'page', $request, (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_page( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'page' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Page not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		$result = WP_MCP_REST::delete_post( (int) $request['id'], $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
