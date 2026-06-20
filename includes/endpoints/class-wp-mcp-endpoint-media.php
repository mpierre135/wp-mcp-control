<?php
/**
 * Media endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Media
 */
class WP_MCP_Endpoint_Media {

	/**
	 * Allowed MIME types.
	 *
	 * @var array
	 */
	private static $allowed_mimes = array(
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
		'video/mp4', 'video/webm', 'application/pdf',
	);

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/media',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_media' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_media' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_media' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_media' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/media/upload-url',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_from_url' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/media/upload-base64',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_from_base64' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_media( WP_REST_Request $request ) {
		$params = $request->get_query_params();
		$args   = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( 100, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 20 ) ),
			'paged'          => max( 1, isset( $params['page'] ) ? (int) $params['page'] : 1 ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::format_media( $post );
		}

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => (int) $query->found_posts,
				'page'     => $args['paged'],
				'per_page' => $args['posts_per_page'],
			),
			200
		);
	}

	/**
	 * Get media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_media( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Media not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::format_media( $post, true ), 200 );
	}

	/**
	 * Upload from URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_from_url( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$url    = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '';

		if ( empty( $url ) ) {
			return new WP_Error( 'missing_url', __( 'URL is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'url' => $url ), 200 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$result = self::sideload_file( $tmp, $params );
		if ( is_wp_error( $result ) ) {
			@unlink( $tmp );
			return $result;
		}

		WP_MCP_Logger::log_action( 'media.upload', 'media', $result['id'], array( 'source' => 'url' ), 'success' );

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Upload from base64.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_from_base64( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$data   = isset( $params['data'] ) ? $params['data'] : '';
		$filename = isset( $params['filename'] ) ? sanitize_file_name( $params['filename'] ) : 'upload.jpg';

		if ( empty( $data ) ) {
			return new WP_Error( 'missing_data', __( 'Base64 data is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( preg_match( '/^data:([^;]+);base64,/', $data, $matches ) ) {
			$data = substr( $data, strpos( $data, ',' ) + 1 );
		}

		$decoded = base64_decode( $data, true );
		if ( false === $decoded ) {
			return new WP_Error( 'invalid_base64', __( 'Invalid base64 data.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$max_bytes = (int) get_option( 'wp_mcp_max_upload_bytes', 10485760 );
		if ( strlen( $decoded ) > $max_bytes ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds maximum upload size.', 'wp-mcp-control' ), array( 'status' => 413 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'filename' => $filename, 'size' => strlen( $decoded ) ), 200 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'temp_failed', __( 'Could not create temp file.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $decoded );

		$result = self::sideload_file( $tmp, $params, $filename );
		if ( is_wp_error( $result ) ) {
			@unlink( $tmp );
			return $result;
		}

		WP_MCP_Logger::log_action( 'media.upload', 'media', $result['id'], array( 'source' => 'base64' ), 'success' );

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Sideload file into media library.
	 *
	 * @param string $tmp_file Temp file path.
	 * @param array  $params   Metadata params.
	 * @param string $filename Optional filename.
	 * @return array|WP_Error
	 */
	private static function sideload_file( $tmp_file, $params, $filename = '' ) {
		$max_bytes = (int) get_option( 'wp_mcp_max_upload_bytes', 10485760 );
		$size      = filesize( $tmp_file );

		if ( $size > $max_bytes ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds maximum upload size.', 'wp-mcp-control' ), array( 'status' => 413 ) );
		}

		$filetype = wp_check_filetype( $filename ? $filename : basename( $tmp_file ) );
		if ( ! in_array( $filetype['type'], self::$allowed_mimes, true ) ) {
			return new WP_Error( 'invalid_mime', __( 'File type is not allowed.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$file_array = array(
			'name'     => $filename ? $filename : basename( $tmp_file ),
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! empty( $params['title'] ) ) {
			wp_update_post( array(
				'ID'         => $attachment_id,
				'post_title' => sanitize_text_field( $params['title'] ),
			) );
		}

		if ( ! empty( $params['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
		}

		if ( ! empty( $params['caption'] ) ) {
			wp_update_post( array(
				'ID'           => $attachment_id,
				'post_excerpt' => sanitize_textarea_field( $params['caption'] ),
			) );
		}

		if ( ! empty( $params['description'] ) ) {
			wp_update_post( array(
				'ID'           => $attachment_id,
				'post_content' => sanitize_textarea_field( $params['description'] ),
			) );
		}

		$post = get_post( $attachment_id );
		return self::format_media( $post, true );
	}

	/**
	 * Update media metadata.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_media( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Media not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'id' => (int) $request['id'], 'params' => $params ), 200 );
		}

		WP_MCP_Snapshots::create_snapshot( 'media', $post->ID, $post );

		$update = array( 'ID' => $post->ID );
		if ( isset( $params['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['caption'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $params['caption'] );
		}
		if ( isset( $params['description'] ) ) {
			$update['post_content'] = sanitize_textarea_field( $params['description'] );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		if ( isset( $params['alt'] ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', sanitize_text_field( $params['alt'] ) );
		}

		WP_MCP_Logger::log_action( 'media.update', 'media', $post->ID, $params, 'success' );

		return new WP_REST_Response( self::format_media( get_post( $post->ID ), true ), 200 );
	}

	/**
	 * Delete media.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_media( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Media not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		$result = WP_MCP_REST::delete_post( (int) $request['id'], $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Format media for response.
	 *
	 * @param WP_Post $post Post.
	 * @param bool    $full Full details.
	 * @return array
	 */
	private static function format_media( WP_Post $post, $full = false ) {
		$data = array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'url'       => wp_get_attachment_url( $post->ID ),
			'mime_type' => $post->post_mime_type,
			'modified'  => $post->post_modified_gmt,
		);

		if ( $full ) {
			$data['alt']         = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
			$data['caption']     = $post->post_excerpt;
			$data['description'] = $post->post_content;
			$meta                = wp_get_attachment_metadata( $post->ID );
			$data['width']       = $meta['width'] ?? null;
			$data['height']      = $meta['height'] ?? null;
		}

		return $data;
	}
}
