<?php
/**
 * Search endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Search
 */
class WP_MCP_Endpoint_Search {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Unified search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function search( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$query      = isset( $params['query'] ) ? sanitize_text_field( $params['query'] ) : '';
		$post_types = isset( $params['post_type'] ) ? (array) $params['post_type'] : array( 'page', 'post' );
		$status     = isset( $params['status'] ) ? sanitize_text_field( $params['status'] ) : 'any';
		$limit      = min( 100, max( 1, isset( $params['limit'] ) ? (int) $params['limit'] : 20 ) );
		$date_after = isset( $params['date_after'] ) ? sanitize_text_field( $params['date_after'] ) : '';
		$date_before= isset( $params['date_before'] ) ? sanitize_text_field( $params['date_before'] ) : '';

		$results = array();

		$search_types = array_intersect( $post_types, array( 'page', 'post', 'attachment' ) );
		if ( ! empty( $search_types ) ) {
			$args = array(
				'post_type'      => $search_types,
				'posts_per_page' => $limit,
				's'              => $query,
				'orderby'        => 'relevance',
			);

			if ( 'any' !== $status ) {
				$args['post_status'] = $status;
			} else {
				$args['post_status'] = array( 'publish', 'draft', 'pending', 'private' );
			}

			if ( $date_after ) {
				$args['date_query'][] = array( 'after' => $date_after );
			}
			if ( $date_before ) {
				$args['date_query'][] = array( 'before' => $date_before );
			}

			$query_obj = new WP_Query( $args );
			foreach ( $query_obj->posts as $post ) {
				$results[] = array(
					'type'    => $post->post_type,
					'id'      => (int) $post->ID,
					'title'   => $post->post_title,
					'url'     => get_permalink( $post->ID ),
					'status'  => $post->post_status,
					'excerpt' => wp_trim_words( $post->post_content, 30 ),
				);
			}
		}

		if ( in_array( 'category', $post_types, true ) || in_array( 'categories', $post_types, true ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'search'     => $query,
				'number'     => $limit,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$results[] = array(
						'type'  => 'category',
						'id'    => (int) $term->term_id,
						'title' => $term->name,
						'slug'  => $term->slug,
					);
				}
			}
		}

		if ( in_array( 'tag', $post_types, true ) || in_array( 'tags', $post_types, true ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'search'     => $query,
				'number'     => $limit,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$results[] = array(
						'type'  => 'tag',
						'id'    => (int) $term->term_id,
						'title' => $term->name,
						'slug'  => $term->slug,
					);
				}
			}
		}

		return new WP_REST_Response(
			array(
				'query'   => $query,
				'count'   => count( $results ),
				'results' => array_slice( $results, 0, $limit ),
			),
			200
		);
	}
}
