<?php
/**
 * Comments endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Comments
 */
class WP_MCP_Endpoint_Comments {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/comments',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_comments' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/comments/(?P<id>\d+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_comment' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/comments/(?P<id>\d+)/reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reply_comment' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Format comment.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return array
	 */
	private static function format_comment( WP_Comment $comment ) {
		return array(
			'id'         => (int) $comment->comment_ID,
			'post_id'    => (int) $comment->comment_post_ID,
			'author'     => $comment->comment_author,
			'email'      => self::mask_email( $comment->comment_author_email ),
			'content'    => $comment->comment_content,
			'status'     => wp_get_comment_status( $comment ),
			'date'       => $comment->comment_date_gmt,
			'parent'     => (int) $comment->comment_parent,
		);
	}

	/**
	 * Mask email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function mask_email( $email ) {
		if ( ! is_email( $email ) ) {
			return '';
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		return substr( $local, 0, 1 ) . '***@' . $domain;
	}

	/**
	 * List comments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_comments( WP_REST_Request $request ) {
		$params = $request->get_query_params();

		$args = array(
			'number' => min( 100, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 20 ) ),
			'offset' => max( 0, ( isset( $params['page'] ) ? (int) $params['page'] - 1 : 0 ) * ( isset( $params['per_page'] ) ? (int) $params['per_page'] : 20 ) ),
			'orderby'=> 'comment_date_gmt',
			'order'  => 'DESC',
		);

		if ( ! empty( $params['status'] ) ) {
			$args['status'] = sanitize_text_field( $params['status'] );
		}

		if ( ! empty( $params['post_id'] ) ) {
			$args['post_id'] = absint( $params['post_id'] );
		}

		$comments = get_comments( $args );
		$items    = array();

		foreach ( $comments as $comment ) {
			$items[] = self::format_comment( $comment );
		}

		$count_args = $args;
		$count_args['count'] = true;
		unset( $count_args['number'], $count_args['offset'] );

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => (int) get_comments( $count_args ),
				'page'     => max( 1, isset( $params['page'] ) ? (int) $params['page'] : 1 ),
				'per_page' => $args['number'],
			),
			200
		);
	}

	/**
	 * Update comment status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_comment( WP_REST_Request $request ) {
		$comment = get_comment( (int) $request['id'] );
		if ( ! $comment ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$status = isset( $params['status'] ) ? sanitize_text_field( $params['status'] ) : '';
		$allowed = array( 'approve', 'hold', 'spam', 'trash' );

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'invalid_status', __( 'status must be approve, hold, spam, or trash.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( in_array( $status, array( 'spam', 'trash' ), true ) ) {
			$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
			$check   = WP_MCP_Safe_Mode::check_confirm( 'delete_comment', $request, $confirm );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'id' => (int) $comment->comment_ID, 'status' => $status ), 200 );
		}

		switch ( $status ) {
			case 'approve':
				wp_set_comment_status( $comment->comment_ID, 'approve' );
				break;
			case 'hold':
				wp_set_comment_status( $comment->comment_ID, 'hold' );
				break;
			case 'spam':
				wp_spam_comment( $comment->comment_ID );
				break;
			case 'trash':
				wp_trash_comment( $comment->comment_ID );
				break;
		}

		WP_MCP_Logger::log_action( 'comment.update', 'comment', (int) $comment->comment_ID, array( 'status' => $status ), 'success' );

		return new WP_REST_Response(
			array(
				'id'      => (int) $comment->comment_ID,
				'status'  => $status,
				'updated' => true,
			),
			200
		);
	}

	/**
	 * Reply to comment as admin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reply_comment( WP_REST_Request $request ) {
		$parent = get_comment( (int) $request['id'] );
		if ( ! $parent ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		$content = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'content is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run'   => true,
					'parent_id' => (int) $parent->comment_ID,
					'content'   => $content,
				),
				200
			);
		}

		$user = wp_get_current_user();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $parent->comment_post_ID,
				'comment_parent'       => $parent->comment_ID,
				'comment_content'      => $content,
				'comment_author'       => $user->display_name ? $user->display_name : get_bloginfo( 'name' ),
				'comment_author_email' => $user->user_email ? $user->user_email : get_option( 'admin_email' ),
				'comment_approved'     => 1,
				'user_id'              => $user->ID,
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error( 'reply_failed', __( 'Failed to create reply.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action( 'comment.reply', 'comment', $comment_id, array( 'parent_id' => (int) $parent->comment_ID ), 'success' );

		return new WP_REST_Response(
			array(
				'id'        => $comment_id,
				'parent_id' => (int) $parent->comment_ID,
				'created'   => true,
			),
			201
		);
	}
}
