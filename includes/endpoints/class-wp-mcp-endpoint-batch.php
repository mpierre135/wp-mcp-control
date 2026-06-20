<?php
/**
 * Batch operations endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Batch
 */
class WP_MCP_Endpoint_Batch {

	/**
	 * Allowed batch paths.
	 *
	 * @var array
	 */
	private static $allowed_paths = array(
		'/pages', '/posts', '/media', '/categories', '/tags', '/elementor',
	);

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Handle batch request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		$params     = $request->get_json_params();
		$operations = isset( $params['operations'] ) && is_array( $params['operations'] ) ? $params['operations'] : array();

		if ( empty( $operations ) ) {
			return new WP_Error( 'no_operations', __( 'No operations provided.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( count( $operations ) > 20 ) {
			return new WP_Error( 'too_many_operations', __( 'Maximum 20 operations per batch.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$validation_errors = array();
		foreach ( $operations as $index => $op ) {
			$error = self::validate_operation( $op, $index );
			if ( $error ) {
				$validation_errors[] = $error;
			}
		}

		if ( ! empty( $validation_errors ) ) {
			return new WP_Error(
				'validation_failed',
				__( 'Batch validation failed.', 'wp-mcp-control' ),
				array( 'status' => 400, 'errors' => $validation_errors )
			);
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run'    => true,
					'operations' => count( $operations ),
					'validated'  => true,
				),
				200
			);
		}

		$results = array();
		foreach ( $operations as $index => $op ) {
			$results[] = self::execute_operation( $op, $request, $index );
		}

		WP_MCP_Logger::log_action( 'batch.execute', 'batch', 0, array( 'count' => count( $operations ) ), 'success' );

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	/**
	 * Validate a single operation.
	 *
	 * @param array $op    Operation.
	 * @param int   $index Index.
	 * @return array|null Error or null.
	 */
	private static function validate_operation( $op, $index ) {
		$method = isset( $op['method'] ) ? strtoupper( sanitize_text_field( $op['method'] ) ) : '';
		$path   = isset( $op['path'] ) ? sanitize_text_field( $op['path'] ) : '';

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'DELETE' ), true ) ) {
			return array( 'index' => $index, 'error' => 'Invalid method' );
		}

		if ( empty( $path ) || strpos( $path, '/wp-mcp/v1' ) !== 0 ) {
			$full_path = '/wp-mcp/v1' . ( strpos( $path, '/' ) === 0 ? $path : '/' . $path );
		} else {
			$full_path = $path;
		}

		$relative = str_replace( '/wp-mcp/v1', '', $full_path );
		$allowed  = false;

		foreach ( self::$allowed_paths as $prefix ) {
			if ( strpos( $relative, $prefix ) === 0 ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			return array( 'index' => $index, 'error' => 'Path not allowed in batch: ' . $relative );
		}

		if ( in_array( $method, array( 'DELETE', 'PUT' ), true ) && empty( $op['body']['confirm'] ) && 'DELETE' === $method ) {
			return array( 'index' => $index, 'error' => 'DELETE requires confirm=true in body' );
		}

		return null;
	}

	/**
	 * Execute a single operation.
	 *
	 * @param array           $op      Operation.
	 * @param WP_REST_Request $request Parent request.
	 * @param int             $index   Index.
	 * @return array
	 */
	private static function execute_operation( $op, WP_REST_Request $request, $index ) {
		$method = strtoupper( $op['method'] );
		$path   = isset( $op['path'] ) ? $op['path'] : '';
		$body   = isset( $op['body'] ) ? $op['body'] : array();

		if ( strpos( $path, '/wp-mcp/v1' ) !== 0 ) {
			$path = '/wp-mcp/v1' . ( strpos( $path, '/' ) === 0 ? $path : '/' . $path );
		}

		$sub_request = new WP_REST_Request( $method, $path );
		if ( ! empty( $body ) ) {
			$sub_request->set_body( wp_json_encode( $body ) );
			$sub_request->set_header( 'Content-Type', 'application/json' );
		}

		$sub_request->set_header( 'Authorization', $request->get_header( 'authorization' ) );
		$sub_request->set_header( 'X-WP-MCP-Dry-Run', $request->get_header( 'x-wp-mcp-dry-run' ) );
		$sub_request->set_header( 'X-WP-MCP-Safe-Mode', $request->get_header( 'x-wp-mcp-safe-mode' ) );

		$response = rest_do_request( $sub_request );
		$status   = $response->get_status();
		$data     = $response->get_data();

		return array(
			'index'  => $index,
			'method' => $method,
			'path'   => $path,
			'status' => $status,
			'data'   => $data,
			'success'=> $status >= 200 && $status < 300,
		);
	}
}
