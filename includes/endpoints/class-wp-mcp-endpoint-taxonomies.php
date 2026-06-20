<?php
/**
 * Taxonomies endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Taxonomies
 */
class WP_MCP_Endpoint_Taxonomies {

	/**
	 * Register routes.
	 */
	public static function register() {
		$taxonomies = array( 'categories' => 'category', 'tags' => 'post_tag' );

		foreach ( $taxonomies as $route => $taxonomy ) {
			register_rest_route(
				'wp-mcp/v1',
				'/' . $route,
				array(
					array(
						'methods'             => 'GET',
						'callback'            => function( $request ) use ( $taxonomy ) {
							return self::list_terms( $taxonomy, $request );
						},
						'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
					),
					array(
						'methods'             => 'POST',
						'callback'            => function( $request ) use ( $taxonomy ) {
							return self::create_term( $taxonomy, $request );
						},
						'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
					),
				)
			);

			register_rest_route(
				'wp-mcp/v1',
				'/' . $route . '/(?P<id>\d+)',
				array(
					array(
						'methods'             => 'PUT',
						'callback'            => function( $request ) use ( $taxonomy ) {
							return self::update_term( $taxonomy, $request );
						},
						'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
					),
					array(
						'methods'             => 'DELETE',
						'callback'            => function( $request ) use ( $taxonomy ) {
							return self::delete_term( $taxonomy, $request );
						},
						'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
					),
				)
			);
		}
	}

	/**
	 * List terms.
	 *
	 * @param string          $taxonomy Taxonomy.
	 * @param WP_REST_Request $request  Request.
	 * @return WP_REST_Response
	 */
	public static function list_terms( $taxonomy, WP_REST_Request $request ) {
		$params = $request->get_query_params();
		$args   = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => min( 100, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 100 ) ),
			'offset'     => max( 0, ( isset( $params['page'] ) ? (int) $params['page'] - 1 : 0 ) * ( isset( $params['per_page'] ) ? (int) $params['per_page'] : 100 ) ),
		);

		if ( ! empty( $params['search'] ) ) {
			$args['search'] = sanitize_text_field( $params['search'] );
		}

		$terms = get_terms( $args );
		$items = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$items[] = self::format_term( $term );
			}
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Create term.
	 *
	 * @param string          $taxonomy Taxonomy.
	 * @param WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_term( $taxonomy, WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$name   = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';

		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Name is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'name' => $name ), 200 );
		}

		$args = array();
		if ( ! empty( $params['slug'] ) ) {
			$args['slug'] = sanitize_title( $params['slug'] );
		}
		if ( ! empty( $params['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $params['description'] );
		}
		if ( 'category' === $taxonomy && isset( $params['parent'] ) ) {
			$args['parent'] = absint( $params['parent'] );
		}

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term = get_term( $result['term_id'], $taxonomy );
		WP_MCP_Logger::log_action( $taxonomy . '.create', $taxonomy, $result['term_id'], array( 'name' => $name ), 'success' );

		return new WP_REST_Response( self::format_term( $term ), 201 );
	}

	/**
	 * Update term.
	 *
	 * @param string          $taxonomy Taxonomy.
	 * @param WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_term( $taxonomy, WP_REST_Request $request ) {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Term not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'id' => $term_id, 'params' => $params ), 200 );
		}

		$args = array();
		if ( isset( $params['name'] ) ) {
			$args['name'] = sanitize_text_field( $params['name'] );
		}
		if ( isset( $params['slug'] ) ) {
			$args['slug'] = sanitize_title( $params['slug'] );
		}
		if ( isset( $params['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $params['description'] );
		}
		if ( 'category' === $taxonomy && isset( $params['parent'] ) ) {
			$args['parent'] = absint( $params['parent'] );
		}

		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_Logger::log_action( $taxonomy . '.update', $taxonomy, $term_id, $args, 'success' );

		return new WP_REST_Response( self::format_term( get_term( $term_id, $taxonomy ) ), 200 );
	}

	/**
	 * Delete term.
	 *
	 * @param string          $taxonomy Taxonomy.
	 * @param WP_REST_Request $request  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_term( $taxonomy, WP_REST_Request $request ) {
		$term_id = (int) $request['id'];
		$term    = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'not_found', __( 'Term not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params  = $request->get_json_params();
		$confirm = ! empty( $params['confirm'] );

		if ( ! $confirm ) {
			return new WP_Error( 'confirm_required', __( 'Deletion requires confirm=true.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'id' => $term_id ), 200 );
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_Logger::log_action( $taxonomy . '.delete', $taxonomy, $term_id, array(), 'success' );

		return new WP_REST_Response( array( 'id' => $term_id, 'deleted' => true ), 200 );
	}

	/**
	 * Format term.
	 *
	 * @param WP_Term $term Term.
	 * @return array
	 */
	private static function format_term( WP_Term $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
		);
	}
}
