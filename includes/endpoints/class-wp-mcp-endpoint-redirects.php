<?php
/**
 * Redirects endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Redirects
 */
class WP_MCP_Endpoint_Redirects {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/redirects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_redirects' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_redirect' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_redirect' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_redirect' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * List redirects.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_redirects() {
		global $wpdb;
		$table = $wpdb->prefix . 'wp_mcp_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::format_row( $row );
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Create redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_redirect( WP_REST_Request $request ) {
		global $wpdb;

		$params      = $request->get_json_params();
		$source_path = isset( $params['source_path'] ) ? sanitize_text_field( $params['source_path'] ) : '';
		$target_url  = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : '';

		if ( empty( $source_path ) || empty( $target_url ) ) {
			return new WP_Error( 'missing_fields', __( 'source_path and target_url are required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$source_path = WP_MCP_REST::normalize_path( $source_path );
		$status_code = isset( $params['status_code'] ) ? absint( $params['status_code'] ) : 301;
		if ( ! in_array( $status_code, array( 301, 302, 307, 308 ), true ) ) {
			$status_code = 301;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'source_path' => $source_path, 'target_url' => $target_url ), 200 );
		}

		$table = $wpdb->prefix . 'wp_mcp_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'source_path' => $source_path,
				'target_url'  => $target_url,
				'status_code' => $status_code,
				'enabled'     => isset( $params['enabled'] ) ? (int) (bool) $params['enabled'] : 1,
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $result ) {
			return new WP_Error( 'insert_failed', __( 'Failed to create redirect.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action( 'redirect.create', 'redirect', $wpdb->insert_id, array( 'source' => $source_path ), 'success' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );

		return new WP_REST_Response( self::format_row( $row ), 201 );
	}

	/**
	 * Update redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_redirect( WP_REST_Request $request ) {
		global $wpdb;

		$id    = (int) $request['id'];
		$table = $wpdb->prefix . 'wp_mcp_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'Redirect not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params = $request->get_json_params();
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$format = array( '%s' );

		if ( isset( $params['source_path'] ) ) {
			$update['source_path'] = WP_MCP_REST::normalize_path( sanitize_text_field( $params['source_path'] ) );
			$format[] = '%s';
		}
		if ( isset( $params['target_url'] ) ) {
			$update['target_url'] = esc_url_raw( $params['target_url'] );
			$format[] = '%s';
		}
		if ( isset( $params['status_code'] ) ) {
			$update['status_code'] = absint( $params['status_code'] );
			$format[] = '%d';
		}
		if ( isset( $params['enabled'] ) ) {
			$update['enabled'] = (int) (bool) $params['enabled'];
			$format[] = '%d';
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'id' => $id, 'update' => $update ), 200 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		WP_MCP_Logger::log_action( 'redirect.update', 'redirect', $id, $update, 'success' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return new WP_REST_Response( self::format_row( $row ), 200 );
	}

	/**
	 * Delete redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_redirect( WP_REST_Request $request ) {
		global $wpdb;

		$id     = (int) $request['id'];
		$params = $request->get_json_params();

		if ( empty( $params['confirm'] ) ) {
			return new WP_Error( 'confirm_required', __( 'Deletion requires confirm=true.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'id' => $id ), 200 );
		}

		$table = $wpdb->prefix . 'wp_mcp_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			return new WP_Error( 'not_found', __( 'Redirect not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		WP_MCP_Logger::log_action( 'redirect.delete', 'redirect', $id, array(), 'success' );

		return new WP_REST_Response( array( 'id' => $id, 'deleted' => true ), 200 );
	}

	/**
	 * Format redirect row.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private static function format_row( $row ) {
		return array(
			'id'          => (int) $row['id'],
			'source_path' => $row['source_path'],
			'target_url'  => $row['target_url'],
			'status_code' => (int) $row['status_code'],
			'enabled'     => (bool) $row['enabled'],
			'created_at'  => $row['created_at'],
			'updated_at'  => $row['updated_at'],
		);
	}
}
