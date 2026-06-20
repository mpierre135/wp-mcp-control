<?php
/**
 * Settings endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Settings
 */
class WP_MCP_Endpoint_Settings {

	/**
	 * Safe settings whitelist.
	 *
	 * @var array
	 */
	private static $safe_settings = array(
		'blogname',
		'blogdescription',
		'posts_per_page',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
	);

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_settings' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Get safe settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		$data = array();
		foreach ( self::$safe_settings as $key ) {
			$data[ $key ] = get_option( $key );
		}
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Update safe settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( WP_MCP_Safe_Mode::is_active( $request ) && ! empty( $params ) ) {
			$blocked = array_diff( array_keys( $params ), self::$safe_settings );
			if ( ! empty( $blocked ) ) {
				return new WP_Error(
					'safe_mode_blocked',
					__( 'Some settings cannot be updated in safe mode.', 'wp-mcp-control' ),
					array( 'status' => 403, 'blocked' => $blocked )
				);
			}
		}

		$updated = array();

		foreach ( $params as $key => $value ) {
			if ( ! in_array( $key, self::$safe_settings, true ) ) {
				continue;
			}

			if ( WP_MCP_REST::is_dry_run( $request ) ) {
				$updated[ $key ] = $value;
				continue;
			}

			if ( in_array( $key, array( 'posts_per_page', 'page_on_front', 'page_for_posts' ), true ) ) {
				$value = absint( $value );
			} else {
				$value = sanitize_text_field( $value );
			}

			update_option( $key, $value );
			$updated[ $key ] = $value;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'would_update' => $updated ), 200 );
		}

		WP_MCP_Logger::log_action( 'settings.update', 'settings', 0, $updated, 'success' );

		return new WP_REST_Response( array( 'updated' => $updated ), 200 );
	}
}
