<?php
/**
 * Gutenberg blocks endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Blocks
 */
class WP_MCP_Endpoint_Blocks {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/blocks/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_blocks' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_block' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/blocks/pages/(?P<id>\d+)/patterns',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'insert_pattern' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Get block structure for a page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_blocks( WP_REST_Request $request ) {
		$result = WP_MCP_Blocks::get_structure( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Update block attributes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_block( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$path  = isset( $params['path'] ) ? sanitize_text_field( $params['path'] ) : '';
		$attrs = isset( $params['attrs'] ) && is_array( $params['attrs'] ) ? $params['attrs'] : array();

		if ( ! $path && ! empty( $params['block_name'] ) ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post ) {
				return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
			}
			$match_text = isset( $params['match_text'] ) ? $params['match_text'] : '';
			$found      = WP_MCP_Blocks::find_block( $post->post_content, sanitize_text_field( $params['block_name'] ), $match_text );
			if ( ! $found ) {
				return new WP_Error( 'block_not_found', __( 'No matching block found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
			}
			$path = $found['path'];
		}

		if ( ! $path ) {
			return new WP_Error( 'missing_path', __( 'path or block_name is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Blocks::update_block_attrs( (int) $request['id'], $path, $attrs, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Insert block pattern preset.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function insert_pattern( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$preset = isset( $params['preset'] ) ? sanitize_text_field( $params['preset'] ) : '';
		if ( ! $preset ) {
			return new WP_Error( 'missing_preset', __( 'preset is required (hero, faq, columns).', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Blocks::insert_block_pattern( (int) $request['id'], $preset, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
