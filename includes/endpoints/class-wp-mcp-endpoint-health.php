<?php
/**
 * Health endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Health
 */
class WP_MCP_Endpoint_Health {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Handle health check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array(
				'status'           => 'ok',
				'plugin_version'   => WP_MCP_CONTROL_VERSION,
				'wordpress_version'=> get_bloginfo( 'version' ),
				'site_url'         => get_site_url(),
				'rest_available'   => true,
				'token_configured' => WP_MCP_Auth::has_token(),
				'safe_mode'        => WP_MCP_Safe_Mode::is_active( $request ),
				'dry_run'          => WP_MCP_REST::is_dry_run( $request ),
				'rate_limit'       => (int) get_option( 'wp_mcp_rate_limit', 60 ),
				'rate_remaining'   => WP_MCP_Rate_Limit::remaining( $request ),
				'last_request_at'  => get_option( 'wp_mcp_last_request_at', '' ),
				'php_version'      => PHP_VERSION,
			),
			200
		);
	}
}
