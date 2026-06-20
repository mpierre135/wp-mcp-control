<?php
/**
 * Safe mode for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Safe_Mode
 */
class WP_MCP_Safe_Mode {

	/**
	 * Check if safe mode is active.
	 *
	 * @param WP_REST_Request|null $request Optional request for header override.
	 * @return bool
	 */
	public static function is_active( WP_REST_Request $request = null ) {
		if ( $request ) {
			$header = $request->get_header( 'x-wp-mcp-safe-mode' );
			if ( null !== $header && '' !== $header ) {
				return self::parse_bool( $header );
			}
		}

		return (bool) get_option( 'wp_mcp_safe_mode', true );
	}

	/**
	 * Parse boolean value.
	 *
	 * @param mixed $value Value to parse.
	 * @return bool
	 */
	public static function parse_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( (string) $value );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Block destructive action if safe mode is on.
	 *
	 * @param string             $action  Action type.
	 * @param WP_REST_Request    $request Request.
	 * @return true|WP_Error
	 */
	public static function check_action( $action, WP_REST_Request $request ) {
		if ( ! self::is_active( $request ) ) {
			return true;
		}

		$blocked_actions = array(
			'force_delete',
			'delete_plugin',
			'delete_theme',
			'create_admin_user',
			'edit_php_file',
			'install_plugin',
			'install_theme',
			'activate_destructive_plugin',
			'db_search_replace',
			'edit_wp_config',
			'export_customer_pii_bulk',
		);

		if ( in_array( $action, $blocked_actions, true ) ) {
			return new WP_Error(
				'safe_mode_blocked',
				sprintf(
					/* translators: %s: action name */
					__( 'Action "%s" is blocked while safe mode is enabled.', 'wp-mcp-control' ),
					$action
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check force delete permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $force   Force flag.
	 * @param bool            $confirm Confirm flag.
	 * @return true|WP_Error
	 */
	public static function check_force_delete( WP_REST_Request $request, $force, $confirm ) {
		if ( ! $force ) {
			return true;
		}

		$safe_check = self::check_action( 'force_delete', $request );
		if ( is_wp_error( $safe_check ) ) {
			return $safe_check;
		}

		if ( ! $confirm ) {
			return new WP_Error(
				'confirm_required',
				__( 'Permanent deletion requires confirm=true.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		if ( ! get_option( 'wp_mcp_allow_force_delete', false ) ) {
			return new WP_Error(
				'force_delete_disabled',
				__( 'Permanent deletion is disabled in plugin settings.', 'wp-mcp-control' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Actions requiring confirm when safe mode is on.
	 *
	 * @var array
	 */
	private static $confirm_actions = array(
		'wc_update_order',
		'wc_refund_order',
		'plugin_activate',
		'plugin_deactivate',
		'create_user',
		'delete_comment',
		'purge_cache',
		'restore_revision',
	);

	/**
	 * Check confirm flag for sensitive actions in safe mode.
	 *
	 * @param string          $action  Action name.
	 * @param WP_REST_Request $request Request.
	 * @param bool            $confirm Confirm flag from params.
	 * @return true|WP_Error
	 */
	public static function check_confirm( $action, WP_REST_Request $request, $confirm ) {
		$blocked = self::check_action( $action, $request );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		if ( ! self::is_active( $request ) ) {
			return true;
		}

		if ( in_array( $action, self::$confirm_actions, true ) && ! $confirm ) {
			return new WP_Error(
				'confirm_required',
				sprintf(
					/* translators: %s: action name */
					__( 'Action "%s" requires confirm=true when safe mode is enabled.', 'wp-mcp-control' ),
					$action
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Parse confirm from request JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function confirm_from_request( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( is_array( $params ) && isset( $params['confirm'] ) ) {
			return self::parse_bool( $params['confirm'] );
		}
		return false;
	}
}
