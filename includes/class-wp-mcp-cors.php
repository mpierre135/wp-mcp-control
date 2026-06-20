<?php
/**
 * CORS handling for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_CORS
 */
class WP_MCP_CORS {

	/**
	 * Initialize CORS hooks.
	 */
	public static function init() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'handle_preflight' ), 15 );
	}

	/**
	 * Handle OPTIONS preflight for wp-mcp routes.
	 */
	public static function handle_preflight() {
		if ( 'OPTIONS' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( strpos( $uri, '/wp-json/wp-mcp/' ) === false ) {
			return;
		}

		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = self::is_origin_allowed( $origin );

		if ( $origin && $allowed ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-MCP-Dry-Run, X-WP-MCP-Safe-Mode' );
			header( 'Access-Control-Max-Age: 86400' );
		}

		status_header( 204 );
		exit;
	}

	/**
	 * Add CORS headers to REST responses.
	 *
	 * @param bool             $served  Whether request was served.
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function add_cors_headers( $served, $result, $request, $server ) {
		if ( strpos( $request->get_route(), '/wp-mcp/' ) === false ) {
			return $served;
		}

		$origin  = $request->get_header( 'origin' );
		$allowed = self::is_origin_allowed( $origin );

		if ( $origin && $allowed ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-MCP-Dry-Run, X-WP-MCP-Safe-Mode' );
		}

		return $served;
	}

	/**
	 * Check if origin is in allowlist.
	 *
	 * @param string $origin Origin header.
	 * @return bool
	 */
	public static function is_origin_allowed( $origin ) {
		if ( empty( $origin ) ) {
			return false;
		}

		$origins = get_option( 'wp_mcp_cors_origins', array() );
		if ( empty( $origins ) || ! is_array( $origins ) ) {
			return false;
		}

		$origin = untrailingslashit( $origin );

		foreach ( $origins as $allowed ) {
			$allowed = untrailingslashit( trim( $allowed ) );
			if ( $allowed && $allowed === $origin ) {
				return true;
			}
		}

		return false;
	}
}
