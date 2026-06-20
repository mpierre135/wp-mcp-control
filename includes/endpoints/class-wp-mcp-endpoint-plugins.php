<?php
/**
 * Plugin management endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Plugins
 */
class WP_MCP_Endpoint_Plugins {

	/**
	 * Conflict groups (only one should be active).
	 *
	 * @var array
	 */
	private static $conflict_groups = array(
		'seo' => array(
			'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'wordpress-seo/wp-seo.php',
			'seo-by-rank-math/rank-math.php',
		),
		'cache' => array(
			'litespeed-cache/litespeed-cache.php',
			'wp-rocket/wp-rocket.php',
			'w3-total-cache/w3-total-cache.php',
		),
		'redirect' => array(
			'redirection/redirection.php',
			'seo-by-rank-math/rank-math.php',
		),
	);

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/plugins/conflicts',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_conflicts' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/plugins/updates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_updates' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/plugins/(?P<slug>[a-zA-Z0-9_/-]+)/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'activate_plugin' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/plugins/(?P<slug>[a-zA-Z0-9_/-]+)/deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'deactivate_plugin' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Ensure plugin admin functions are loaded.
	 */
	private static function load_plugin_admin() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Get active plugin slugs.
	 *
	 * @return array
	 */
	private static function get_active_slugs() {
		return get_option( 'active_plugins', array() );
	}

	/**
	 * Check plugin slug is on allowlist.
	 *
	 * @param string $slug Plugin slug.
	 * @return true|WP_Error
	 */
	private static function check_allowlist( $slug ) {
		$allowlist = get_option( 'wp_mcp_plugin_allowlist', array() );
		if ( ! is_array( $allowlist ) || empty( $allowlist ) ) {
			return new WP_Error(
				'allowlist_empty',
				__( 'Plugin allowlist is not configured.', 'wp-mcp-control' ),
				array( 'status' => 403 )
			);
		}
		if ( ! in_array( $slug, $allowlist, true ) ) {
			return new WP_Error(
				'plugin_not_allowed',
				__( 'Plugin is not on the MCP allowlist.', 'wp-mcp-control' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * List plugin conflicts.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_conflicts() {
		self::load_plugin_admin();
		$active   = self::get_active_slugs();
		$conflicts = array();

		foreach ( self::$conflict_groups as $group => $plugins ) {
			$active_in_group = array_values( array_intersect( $plugins, $active ) );
			if ( count( $active_in_group ) > 1 ) {
				$conflicts[] = array(
					'group'   => $group,
					'plugins' => $active_in_group,
				);
			}
		}

		return new WP_REST_Response( array( 'conflicts' => $conflicts ), 200 );
	}

	/**
	 * List available plugin updates.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_updates() {
		self::load_plugin_admin();

		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$items   = array();

		if ( $updates && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $slug => $data ) {
				$items[] = array(
					'slug'    => $slug,
					'version' => isset( $data->new_version ) ? $data->new_version : '',
					'package' => isset( $data->package ) ? (bool) $data->package : false,
				);
			}
		}

		return new WP_REST_Response( array( 'items' => $items, 'count' => count( $items ) ), 200 );
	}

	/**
	 * Activate plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function activate_plugin( WP_REST_Request $request ) {
		self::load_plugin_admin();

		$slug = sanitize_text_field( $request['slug'] );
		$check = self::check_allowlist( $slug );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$safe    = WP_MCP_Safe_Mode::check_confirm( 'plugin_activate', $request, $confirm );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$all = get_plugins();
		if ( ! isset( $all[ $slug ] ) ) {
			return new WP_Error( 'not_found', __( 'Plugin not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'slug' => $slug, 'action' => 'activate' ), 200 );
		}

		$result = activate_plugin( $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_Logger::log_action( 'plugin.activate', 'plugin', 0, array( 'slug' => $slug ), 'success' );

		return new WP_REST_Response( array( 'slug' => $slug, 'activated' => true ), 200 );
	}

	/**
	 * Deactivate plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function deactivate_plugin( WP_REST_Request $request ) {
		self::load_plugin_admin();

		$slug = sanitize_text_field( $request['slug'] );

		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$safe    = WP_MCP_Safe_Mode::check_confirm( 'plugin_deactivate', $request, $confirm );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$active = self::get_active_slugs();
		if ( ! in_array( $slug, $active, true ) ) {
			return new WP_Error( 'not_active', __( 'Plugin is not active.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'slug' => $slug, 'action' => 'deactivate' ), 200 );
		}

		deactivate_plugins( $slug );

		WP_MCP_Logger::log_action( 'plugin.deactivate', 'plugin', 0, array( 'slug' => $slug ), 'success' );

		return new WP_REST_Response( array( 'slug' => $slug, 'deactivated' => true ), 200 );
	}
}
