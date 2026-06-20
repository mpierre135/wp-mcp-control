<?php
/**
 * REST API router for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_REST
 */
class WP_MCP_REST {

	/**
	 * Current dry-run state for request.
	 *
	 * @var bool|null
	 */
	private static $dry_run = null;

	/**
	 * Initialize REST routes.
	 */
	public static function init() {
		$endpoints = array(
			'class-wp-mcp-endpoint-health.php',
			'class-wp-mcp-endpoint-site.php',
			'class-wp-mcp-endpoint-pages.php',
			'class-wp-mcp-endpoint-posts.php',
			'class-wp-mcp-endpoint-media.php',
			'class-wp-mcp-endpoint-taxonomies.php',
			'class-wp-mcp-endpoint-menus.php',
			'class-wp-mcp-endpoint-search.php',
			'class-wp-mcp-endpoint-batch.php',
			'class-wp-mcp-endpoint-logs.php',
			'class-wp-mcp-endpoint-redirects.php',
			'class-wp-mcp-endpoint-export.php',
			'class-wp-mcp-endpoint-settings.php',
			'class-wp-mcp-endpoint-elementor.php',
		);

		foreach ( $endpoints as $file ) {
			require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/endpoints/' . $file;
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 */
	public static function register_routes() {
		WP_MCP_Endpoint_Health::register();
		WP_MCP_Endpoint_Site::register();
		WP_MCP_Endpoint_Pages::register();
		WP_MCP_Endpoint_Posts::register();
		WP_MCP_Endpoint_Media::register();
		WP_MCP_Endpoint_Taxonomies::register();
		WP_MCP_Endpoint_Menus::register();
		WP_MCP_Endpoint_Search::register();
		WP_MCP_Endpoint_Batch::register();
		WP_MCP_Endpoint_Logs::register();
		WP_MCP_Endpoint_Redirects::register();
		WP_MCP_Endpoint_Export::register();
		WP_MCP_Endpoint_Settings::register();
		WP_MCP_Endpoint_Elementor::register();
	}

	/**
	 * Permission callback for all routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_callback( WP_REST_Request $request ) {
		self::init_request_context( $request );

		$size_check = self::check_request_size( $request );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		$auth = WP_MCP_Auth::validate_request( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		if ( ! WP_MCP_Rate_Limit::check( $request ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests. Please slow down.', 'wp-mcp-control' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Initialize per-request context.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function init_request_context( WP_REST_Request $request ) {
		self::$dry_run = self::resolve_dry_run( $request );
	}

	/**
	 * Resolve dry-run state.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return bool
	 */
	public static function is_dry_run( WP_REST_Request $request = null ) {
		if ( null !== self::$dry_run ) {
			return self::$dry_run;
		}
		return self::resolve_dry_run( $request );
	}

	/**
	 * Resolve dry-run from header or option.
	 *
	 * @param WP_REST_Request|null $request Request.
	 * @return bool
	 */
	private static function resolve_dry_run( WP_REST_Request $request = null ) {
		if ( $request ) {
			$header = $request->get_header( 'x-wp-mcp-dry-run' );
			if ( null !== $header && '' !== $header ) {
				return WP_MCP_Safe_Mode::parse_bool( $header );
			}
		}
		return (bool) get_option( 'wp_mcp_dry_run', false );
	}

	/**
	 * Check request body size.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_request_size( WP_REST_Request $request ) {
		$max_bytes = 1048576; // 1MB.
		$body      = $request->get_body();

		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error(
				'request_too_large',
				__( 'Request body exceeds maximum allowed size.', 'wp-mcp-control' ),
				array( 'status' => 413 )
			);
		}

		return true;
	}

	/**
	 * Sanitize HTML content.
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	public static function sanitize_content( $content ) {
		return wp_kses_post( $content );
	}

	/**
	 * Normalize URL path for redirects.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		$path = '/' . trim( $path, '/' );
		if ( '/' === $path ) {
			return '/';
		}
		return untrailingslashit( $path );
	}

	/**
	 * Format post for API response.
	 *
	 * @param WP_Post $post    Post object.
	 * @param bool    $full    Include full content.
	 * @return array
	 */
	public static function format_post( WP_Post $post, $full = false ) {
		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		$template     = get_page_template_slug( $post->ID );

		$data = array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'type'           => $post->post_type,
			'url'            => get_permalink( $post->ID ),
			'parent'         => (int) $post->post_parent,
			'template'       => $template ? $template : '',
			'modified'       => $post->post_modified_gmt,
			'featured_media' => (int) $thumbnail_id,
		);

		if ( $full ) {
			$data['excerpt']  = $post->post_excerpt;
			$data['content']  = array(
				'raw'      => $post->post_content,
				'rendered' => apply_filters( 'the_content', $post->post_content ),
			);
			$data['meta']     = self::get_post_meta_array( $post->ID );

			if ( 'post' === $post->post_type ) {
				$data['categories'] = wp_get_post_categories( $post->ID );
				$data['tags']       = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
				$data['sticky']     = is_sticky( $post->ID );
			}
		}

		return $data;
	}

	/**
	 * Get all post meta as associative array.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_post_meta_array( $post_id ) {
		$meta  = get_post_meta( $post_id );
		$clean = array();

		foreach ( $meta as $key => $values ) {
			if ( 0 === strpos( $key, '_' ) && ! in_array( $key, array( '_wp_page_template' ), true ) ) {
				continue;
			}
			$clean[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}

		return $clean;
	}

	/**
	 * Create or update post from request params.
	 *
	 * @param string          $post_type Post type.
	 * @param WP_REST_Request $request   Request.
	 * @param int             $post_id   Existing post ID for update.
	 * @return array|WP_Error
	 */
	public static function save_post_from_request( $post_type, WP_REST_Request $request, $post_id = 0 ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$post_data = array(
			'post_type' => $post_type,
		);

		if ( $post_id ) {
			$post_data['ID'] = $post_id;
		}

		if ( isset( $params['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $params['title'] );
		}

		if ( isset( $params['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $params['slug'] );
		}

		if ( isset( $params['status'] ) ) {
			$allowed_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
			$status           = sanitize_text_field( $params['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$post_data['post_status'] = $status;
			}
		} elseif ( ! $post_id ) {
			$post_data['post_status'] = 'draft';
		}

		if ( isset( $params['content'] ) ) {
			$post_data['post_content'] = self::sanitize_content( $params['content'] );
		}

		if ( isset( $params['excerpt'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $params['excerpt'] );
		}

		if ( isset( $params['parent'] ) ) {
			$post_data['post_parent'] = absint( $params['parent'] );
		}

		if ( self::is_dry_run( $request ) ) {
			return array(
				'dry_run'   => true,
				'post_data' => $post_data,
				'meta'      => $params['meta'] ?? array(),
			);
		}

		if ( $post_id ) {
			$existing = get_post( $post_id );
			if ( $existing ) {
				WP_MCP_Snapshots::create_snapshot( $post_type, $post_id, $existing );
			}
			$result = wp_update_post( $post_data, true );
		} else {
			$result = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		if ( isset( $params['template'] ) && 'page' === $post_type ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $params['template'] ) );
		}

		if ( isset( $params['featured_media'] ) ) {
			$media_id = absint( $params['featured_media'] );
			if ( $media_id ) {
				set_post_thumbnail( $post_id, $media_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $key => $value ) {
				$key = sanitize_key( $key );
				if ( $key ) {
					update_post_meta( $post_id, $key, is_string( $value ) ? sanitize_text_field( $value ) : $value );
				}
			}
		}

		if ( 'post' === $post_type ) {
			if ( isset( $params['categories'] ) && is_array( $params['categories'] ) ) {
				wp_set_post_categories( $post_id, array_map( 'absint', $params['categories'] ) );
			}
			if ( isset( $params['tags'] ) && is_array( $params['tags'] ) ) {
				wp_set_post_tags( $post_id, array_map( 'absint', $params['tags'] ) );
			}
			if ( isset( $params['sticky'] ) ) {
				if ( $params['sticky'] ) {
					stick_post( $post_id );
				} else {
					unstick_post( $post_id );
				}
			}
		}

		$post = get_post( $post_id );
		$action = $request->get_method() === 'POST' && ! $request['id'] ? 'create' : 'update';

		WP_MCP_Logger::log_action(
			$post_type . '.' . $action,
			$post_type,
			$post_id,
			array( 'title' => $post->post_title, 'status' => $post->post_status ),
			'success'
		);

		return self::format_post( $post, true );
	}

	/**
	 * Delete post with trash/force semantics.
	 *
	 * @param int             $post_id Post ID.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function delete_post( $post_id, WP_REST_Request $request ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_query_params();
		}

		$force   = ! empty( $params['force'] );
		$confirm = ! empty( $params['confirm'] );

		if ( $force ) {
			$check = WP_MCP_Safe_Mode::check_force_delete( $request, true, $confirm );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		} else {
			$confirm = true;
		}

		if ( ! $confirm && ! $force ) {
			return new WP_Error(
				'confirm_required',
				__( 'Deletion requires confirm=true.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		if ( self::is_dry_run( $request ) ) {
			return array(
				'dry_run'  => true,
				'id'       => $post_id,
				'force'    => $force,
				'would_do' => $force ? 'permanent_delete' : 'trash',
			);
		}

		WP_MCP_Snapshots::create_snapshot( $post->post_type, $post_id, $post );

		if ( $force ) {
			$result = wp_delete_post( $post_id, true );
		} else {
			$result = wp_trash_post( $post_id );
		}

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete post.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action(
			$post->post_type . '.delete',
			$post->post_type,
			$post_id,
			array( 'force' => $force ),
			'success'
		);

		return array(
			'id'      => $post_id,
			'deleted' => true,
			'force'   => $force,
			'status'  => $force ? 'deleted' : 'trash',
		);
	}

	/**
	 * List posts of a type.
	 *
	 * @param string          $post_type Post type.
	 * @param WP_REST_Request $request   Request.
	 * @return array
	 */
	public static function list_posts( $post_type, WP_REST_Request $request ) {
		$params = $request->get_query_params();

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => isset( $params['status'] ) && 'any' !== $params['status']
				? sanitize_text_field( $params['status'] )
				: array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => min( 100, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 20 ) ),
			'paged'          => max( 1, isset( $params['page'] ) ? (int) $params['page'] : 1 ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::format_post( $post, false );
		}

		return array(
			'items'    => $items,
			'total'    => (int) $query->found_posts,
			'page'     => $args['paged'],
			'per_page' => $args['posts_per_page'],
		);
	}
}
