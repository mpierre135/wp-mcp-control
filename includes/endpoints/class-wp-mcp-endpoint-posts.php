<?php
/**
 * Posts endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Posts
 */
class WP_MCP_Endpoint_Posts {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/posts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_posts' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_post' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_post' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_post' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_post' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * List posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_posts( WP_REST_Request $request ) {
		$data = WP_MCP_REST::list_posts( 'post', $request );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get single post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( WP_MCP_REST::format_post( $post, true ), 200 );
	}

	/**
	 * Create post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_post( WP_REST_Request $request ) {
		$result = WP_MCP_REST::save_post_from_request( 'post', $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status = ! empty( $result['dry_run'] ) ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Update post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		$result = WP_MCP_REST::save_post_from_request( 'post', $request, (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		$result = WP_MCP_REST::delete_post( (int) $request['id'], $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
