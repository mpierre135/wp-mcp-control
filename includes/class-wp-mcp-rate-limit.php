<?php
/**
 * Rate limiting for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Rate_Limit
 */
class WP_MCP_Rate_Limit {

	/**
	 * Check rate limit for request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if allowed.
	 */
	public static function check( WP_REST_Request $request ) {
		$limit = (int) get_option( 'wp_mcp_rate_limit', 60 );
		if ( $limit <= 0 ) {
			return true;
		}

		$auth_header = $request->get_header( 'authorization' );
		$token_part  = '';
		if ( preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			$token_part = substr( hash( 'sha256', trim( $matches[1] ) ), 0, 16 );
		}

		$ip  = WP_MCP_Auth::get_client_ip();
		$key = 'wp_mcp_rl_' . md5( $token_part . '|' . $ip );

		$data = get_transient( $key );
		if ( false === $data ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$elapsed = time() - (int) $data['start'];
		if ( $elapsed >= 60 ) {
			$data = array(
				'count' => 0,
				'start' => time(),
			);
		}

		$data['count']++;
		set_transient( $key, $data, 120 );

		return $data['count'] <= $limit;
	}

	/**
	 * Get remaining requests in current window.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return int
	 */
	public static function remaining( WP_REST_Request $request ) {
		$limit = (int) get_option( 'wp_mcp_rate_limit', 60 );
		if ( $limit <= 0 ) {
			return 999;
		}

		$auth_header = $request->get_header( 'authorization' );
		$token_part  = '';
		if ( preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			$token_part = substr( hash( 'sha256', trim( $matches[1] ) ), 0, 16 );
		}

		$ip  = WP_MCP_Auth::get_client_ip();
		$key = 'wp_mcp_rl_' . md5( $token_part . '|' . $ip );
		$data = get_transient( $key );

		if ( false === $data ) {
			return $limit;
		}

		return max( 0, $limit - (int) $data['count'] );
	}
}
