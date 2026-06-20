<?php
/**
 * Revisions and snapshot diff endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Revisions
 */
class WP_MCP_Endpoint_Revisions {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/revisions/posts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_revisions' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/revisions/(?P<revision_id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_revision' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'restore_revision' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/revisions/(?P<revision_id>\d+)/diff',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'revision_diff' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/snapshots/(?P<id>\d+)/diff',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'snapshot_diff' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List revisions for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_revisions( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$revisions = wp_get_post_revisions( $post_id );
		$items     = array();

		foreach ( $revisions as $revision ) {
			$items[] = array(
				'id'       => (int) $revision->ID,
				'author'   => (int) $revision->post_author,
				'date'     => $revision->post_modified_gmt,
				'title'    => $revision->post_title,
			);
		}

		return new WP_REST_Response(
			array(
				'post_id'   => $post_id,
				'items'     => $items,
				'count'     => count( $items ),
			),
			200
		);
	}

	/**
	 * Get revision content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_revision( WP_REST_Request $request ) {
		$revision = get_post( (int) $request['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array(
				'id'         => (int) $revision->ID,
				'parent_id'  => (int) $revision->post_parent,
				'title'      => $revision->post_title,
				'content'    => $revision->post_content,
				'excerpt'    => $revision->post_excerpt,
				'date'       => $revision->post_modified_gmt,
			),
			200
		);
	}

	/**
	 * Diff revision vs current post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revision_diff( WP_REST_Request $request ) {
		$revision = get_post( (int) $request['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$current = get_post( $revision->post_parent );
		if ( ! $current ) {
			return new WP_Error( 'parent_not_found', __( 'Parent post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			self::build_diff(
				array(
					'title'   => $revision->post_title,
					'content' => $revision->post_content,
					'excerpt' => $revision->post_excerpt,
				),
				array(
					'title'   => $current->post_title,
					'content' => $current->post_content,
					'excerpt' => $current->post_excerpt,
				)
			),
			200
		);
	}

	/**
	 * Diff MCP snapshot vs current post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function snapshot_diff( WP_REST_Request $request ) {
		$snapshot = WP_MCP_Snapshots::get_snapshot( (int) $request['id'] );
		if ( ! $snapshot ) {
			return new WP_Error( 'not_found', __( 'Snapshot not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$current = get_post( $snapshot['object_id'] );
		if ( ! $current ) {
			return new WP_Error( 'object_not_found', __( 'Original object no longer exists.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$old = $snapshot['old_data'];

		return new WP_REST_Response(
			self::build_diff(
				array(
					'title'   => $old['title'] ?? '',
					'content' => $old['content'] ?? '',
					'excerpt' => $old['excerpt'] ?? '',
				),
				array(
					'title'   => $current->post_title,
					'content' => $current->post_content,
					'excerpt' => $current->post_excerpt,
				)
			),
			200
		);
	}

	/**
	 * Build simple field diff.
	 *
	 * @param array $old Old values.
	 * @param array $new New values.
	 * @return array
	 */
	private static function build_diff( $old, $new ) {
		$diff = array();
		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			$old_val = isset( $old[ $field ] ) ? $old[ $field ] : '';
			$new_val = isset( $new[ $field ] ) ? $new[ $field ] : '';
			if ( $old_val !== $new_val ) {
				$diff[ $field ] = array(
					'old' => $old_val,
					'new' => $new_val,
				);
			}
		}
		return array(
			'changed_fields' => array_keys( $diff ),
			'diff'           => $diff,
		);
	}

	/**
	 * Restore a revision.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function restore_revision( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$confirm = ! empty( $params['confirm'] );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'restore_revision', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$revision = get_post( (int) $request['revision_id'] );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new WP_Error( 'not_found', __( 'Revision not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$post_id = (int) $revision->post_parent;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'parent_not_found', __( 'Parent post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run'     => true,
					'revision_id' => (int) $revision->ID,
					'post_id'     => $post_id,
				),
				200
			);
		}

		WP_MCP_Snapshots::create_snapshot( $post->post_type, $post_id, $post );

		$result = wp_restore_post_revision( $revision->ID );
		if ( ! $result ) {
			return new WP_Error( 'restore_failed', __( 'Failed to restore revision.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action(
			'revision.restore',
			$post->post_type,
			$post_id,
			array( 'revision_id' => (int) $revision->ID ),
			'success'
		);

		return new WP_REST_Response(
			array(
				'revision_id' => (int) $revision->ID,
				'post_id'     => $post_id,
				'restored'    => true,
			),
			200
		);
	}
}
