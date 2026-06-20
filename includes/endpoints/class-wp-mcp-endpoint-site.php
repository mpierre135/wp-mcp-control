<?php
/**
 * Site info endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Site
 */
class WP_MCP_Endpoint_Site {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/site-info',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'site_info' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_themes' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_plugins' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Get site info.
	 *
	 * @return WP_REST_Response
	 */
	public static function site_info() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$theme         = wp_get_theme();
		$all_plugins   = get_plugins();
		$active_slugs  = get_option( 'active_plugins', array() );
		$active_plugins = array();

		foreach ( $active_slugs as $slug ) {
			if ( isset( $all_plugins[ $slug ] ) ) {
				$active_plugins[] = array(
					'slug'    => $slug,
					'name'    => $all_plugins[ $slug ]['Name'],
					'version' => $all_plugins[ $slug ]['Version'],
				);
			}
		}

		$installed = array();
		foreach ( $all_plugins as $slug => $plugin ) {
			$installed[] = array(
				'slug'    => $slug,
				'name'    => $plugin['Name'],
				'version' => $plugin['Version'],
				'active'  => in_array( $slug, $active_slugs, true ),
			);
		}

		return new WP_REST_Response(
			array(
				'site_name'          => get_bloginfo( 'name' ),
				'site_url'           => get_site_url(),
				'home_url'           => get_home_url(),
				'active_theme'       => array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'slug'    => $theme->get_stylesheet(),
				),
				'wordpress_version'  => get_bloginfo( 'version' ),
				'php_version'        => PHP_VERSION,
				'installed_plugins'  => $installed,
				'active_plugins'     => $active_plugins,
				'permalink_structure'=> get_option( 'permalink_structure' ),
				'show_on_front'      => get_option( 'show_on_front' ),
				'page_on_front'      => (int) get_option( 'page_on_front' ),
				'page_for_posts'     => (int) get_option( 'page_for_posts' ),
			),
			200
		);
	}

	/**
	 * List themes (read-only).
	 *
	 * @return WP_REST_Response
	 */
	public static function list_themes() {
		$themes = wp_get_themes();
		$items  = array();

		foreach ( $themes as $slug => $theme ) {
			$items[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( get_stylesheet() === $slug ),
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * List plugins (read-only).
	 *
	 * @return WP_REST_Response
	 */
	public static function list_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins  = get_plugins();
		$active_slugs = get_option( 'active_plugins', array() );
		$items        = array();

		foreach ( $all_plugins as $slug => $plugin ) {
			$items[] = array(
				'slug'    => $slug,
				'name'    => $plugin['Name'],
				'version' => $plugin['Version'],
				'active'  => in_array( $slug, $active_slugs, true ),
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}
}
