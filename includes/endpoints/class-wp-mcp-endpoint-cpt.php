<?php
/**
 * Custom post types and taxonomies endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Cpt
 */
class WP_MCP_Endpoint_Cpt {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/post-types',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_post_types' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/post-types/(?P<type>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_items' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/post-types/(?P<type>[a-zA-Z0-9_-]+)/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/taxonomies',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_taxonomies' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_taxonomy_terms' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_taxonomy_term' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/taxonomies/(?P<taxonomy>[a-zA-Z0-9_-]+)/terms/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_taxonomy_term' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_taxonomy_term' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Validate post type is allowed.
	 *
	 * @param string $post_type Post type.
	 * @return true|WP_Error
	 */
	private static function validate_post_type( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'invalid_post_type', __( 'Post type does not exist.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}
		if ( ! WP_MCP_Meta::is_allowed_post_type( $post_type ) ) {
			return new WP_Error(
				'post_type_not_allowed',
				__( 'Post type is not allowed via MCP.', 'wp-mcp-control' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * List allowed post types.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_post_types() {
		$allowed = WP_MCP_Meta::get_allowed_post_types();
		$items   = array();

		foreach ( $allowed as $slug ) {
			$obj = get_post_type_object( $slug );
			if ( ! $obj ) {
				continue;
			}
			$items[] = array(
				'slug'        => $slug,
				'name'        => $obj->labels->name,
				'description' => $obj->description,
				'public'      => $obj->public,
				'hierarchical'=> $obj->hierarchical,
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * List items of a post type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_items( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$check = self::validate_post_type( $type );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$data = WP_MCP_REST::list_posts( $type, $request );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Get single item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$check = self::validate_post_type( $type );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = get_post( (int) $request['id'] );
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'not_found', __( 'Item not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( WP_MCP_REST::format_post( $post, true ), 200 );
	}

	/**
	 * Create item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$check = self::validate_post_type( $type );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = WP_MCP_REST::save_post_from_request( $type, $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = ! empty( $result['dry_run'] ) ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	/**
	 * Update item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$check = self::validate_post_type( $type );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = get_post( (int) $request['id'] );
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'not_found', __( 'Item not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$result = WP_MCP_REST::save_post_from_request( $type, $request, (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_item( WP_REST_Request $request ) {
		$type = sanitize_key( $request['type'] );
		$check = self::validate_post_type( $type );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = get_post( (int) $request['id'] );
		if ( ! $post || $post->post_type !== $type ) {
			return new WP_Error( 'not_found', __( 'Item not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$result = WP_MCP_REST::delete_post( (int) $request['id'], $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List all public taxonomies.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_taxonomies() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$items      = array();

		foreach ( $taxonomies as $tax ) {
			$items[] = array(
				'slug'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => $tax->hierarchical,
				'object_types' => $tax->object_type,
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * List terms for a taxonomy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_taxonomy_terms( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return WP_MCP_Endpoint_Taxonomies::list_terms( $taxonomy, $request );
	}

	/**
	 * Create taxonomy term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_taxonomy_term( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return WP_MCP_Endpoint_Taxonomies::create_term( $taxonomy, $request );
	}

	/**
	 * Update taxonomy term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_taxonomy_term( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return WP_MCP_Endpoint_Taxonomies::update_term( $taxonomy, $request );
	}

	/**
	 * Delete taxonomy term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_taxonomy_term( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( $request['taxonomy'] );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return WP_MCP_Endpoint_Taxonomies::delete_term( $taxonomy, $request );
	}
}
