<?php
/**
 * Authentication for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Auth
 */
class WP_MCP_Auth {

	/**
	 * Generate a new API token.
	 *
	 * @return string|WP_Error Plaintext token on success.
	 */
	public static function generate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Insufficient permissions.', 'wp-mcp-control' ), array( 'status' => 403 ) );
		}

		$token = bin2hex( random_bytes( 32 ) );
		update_option( 'wp_mcp_token_hash', wp_hash_password( $token ) );
		update_option( 'wp_mcp_token_created_at', current_time( 'mysql' ) );

		WP_MCP_Logger::log_action( 'token.generate', 'auth', 0, array(), 'success' );

		return $token;
	}

	/**
	 * Rotate the API token.
	 *
	 * @return string|WP_Error
	 */
	public static function rotate_token() {
		return self::generate_token();
	}

	/**
	 * Revoke the API token.
	 *
	 * @return bool|WP_Error
	 */
	public static function revoke_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Insufficient permissions.', 'wp-mcp-control' ), array( 'status' => 403 ) );
		}

		update_option( 'wp_mcp_token_hash', '' );
		update_option( 'wp_mcp_token_created_at', '' );

		WP_MCP_Logger::log_action( 'token.revoke', 'auth', 0, array(), 'success' );

		return true;
	}

	/**
	 * Check if a token is configured.
	 *
	 * @return bool
	 */
	public static function has_token() {
		$hash = get_option( 'wp_mcp_token_hash', '' );
		return ! empty( $hash );
	}

	/**
	 * Validate REST request authentication.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public static function validate_request( WP_REST_Request $request ) {
		$hash = get_option( 'wp_mcp_token_hash', '' );

		if ( empty( $hash ) ) {
			return new WP_Error(
				'no_token_configured',
				__( 'MCP API token is not configured.', 'wp-mcp-control' ),
				array( 'status' => 401 )
			);
		}

		$auth_header = $request->get_header( 'authorization' );
		if ( empty( $auth_header ) ) {
			return new WP_Error(
				'missing_auth',
				__( 'Authorization header is required.', 'wp-mcp-control' ),
				array( 'status' => 401 )
			);
		}

		if ( ! preg_match( '/Bearer\s+(.+)/i', $auth_header, $matches ) ) {
			return new WP_Error(
				'invalid_auth_format',
				__( 'Authorization header must be Bearer token.', 'wp-mcp-control' ),
				array( 'status' => 401 )
			);
		}

		$token = trim( $matches[1] );

		if ( ! wp_check_password( $token, $hash ) ) {
			WP_MCP_Logger::log_action( 'auth.failed', 'auth', 0, array( 'ip' => self::get_client_ip() ), 'error' );
			return new WP_Error(
				'invalid_token',
				__( 'Invalid API token.', 'wp-mcp-control' ),
				array( 'status' => 401 )
			);
		}

		$allowlist = get_option( 'wp_mcp_ip_allowlist', array() );
		if ( ! empty( $allowlist ) && is_array( $allowlist ) ) {
			$client_ip = self::get_client_ip();
			$allowed   = false;
			foreach ( $allowlist as $ip ) {
				$ip = trim( $ip );
				if ( $ip && $ip === $client_ip ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return new WP_Error(
					'ip_not_allowed',
					__( 'IP address is not in the allowlist.', 'wp-mcp-control' ),
					array( 'status' => 403 )
				);
			}
		}

		update_option( 'wp_mcp_last_request_at', current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts     = explode( ',', $forwarded );
			$ip        = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return $ip;
	}

	/**
	 * Register admin AJAX handlers.
	 */
	public static function register_ajax() {
		add_action( 'wp_ajax_wp_mcp_generate_token', array( __CLASS__, 'ajax_generate_token' ) );
		add_action( 'wp_ajax_wp_mcp_revoke_token', array( __CLASS__, 'ajax_revoke_token' ) );
	}

	/**
	 * AJAX: generate token.
	 */
	public static function ajax_generate_token() {
		check_ajax_referer( 'wp_mcp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-control' ) ), 403 );
		}

		$token = self::generate_token();
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( array( 'message' => $token->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'token'      => $token,
				'created_at' => get_option( 'wp_mcp_token_created_at' ),
				'message'    => __( 'Token generated. Copy it now — it will not be shown again.', 'wp-mcp-control' ),
			)
		);
	}

	/**
	 * AJAX: revoke token.
	 */
	public static function ajax_revoke_token() {
		check_ajax_referer( 'wp_mcp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-control' ) ), 403 );
		}

		$result = self::revoke_token();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'message' => __( 'Token revoked.', 'wp-mcp-control' ) ) );
	}
}
