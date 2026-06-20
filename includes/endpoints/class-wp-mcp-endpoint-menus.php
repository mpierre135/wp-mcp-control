<?php
/**
 * Menus endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Menus
 */
class WP_MCP_Endpoint_Menus {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/menus',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_menus' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/menus/(?P<id>\d+)/items',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_menu_items' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_menu_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/menus/items/(?P<item_id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_menu_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_menu_item' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/menus/locations',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'assign_locations' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List menus.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_menus() {
		$menus = wp_get_nav_menus();
		$items = array();

		foreach ( $menus as $menu ) {
			$items[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => (int) $menu->count,
			);
		}

		$locations = get_registered_nav_menus();
		$assigned  = get_nav_menu_locations();

		return new WP_REST_Response(
			array(
				'menus'     => $items,
				'locations' => $locations,
				'assigned'  => $assigned,
			),
			200
		);
	}

	/**
	 * Get menu items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_menu_items( WP_REST_Request $request ) {
		$menu_id = (int) $request['id'];
		$menu    = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu ) {
			return new WP_Error( 'not_found', __( 'Menu not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$items = wp_get_nav_menu_items( $menu_id );
		$data  = array();

		if ( $items ) {
			foreach ( $items as $item ) {
				$data[] = self::format_menu_item( $item );
			}
		}

		return new WP_REST_Response( array( 'items' => $data ), 200 );
	}

	/**
	 * Create menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_menu_item( WP_REST_Request $request ) {
		$menu_id = (int) $request['id'];
		$params  = $request->get_json_params();

		if ( ! wp_get_nav_menu_object( $menu_id ) ) {
			return new WP_Error( 'not_found', __( 'Menu not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$title = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'Title is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'menu_id' => $menu_id, 'title' => $title ), 200 );
		}

		$item_data = array(
			'menu-item-title'     => $title,
			'menu-item-status'    => 'publish',
			'menu-item-type'      => isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : 'custom',
			'menu-item-url'       => isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '',
			'menu-item-object-id' => isset( $params['object_id'] ) ? absint( $params['object_id'] ) : 0,
			'menu-item-object'    => isset( $params['object'] ) ? sanitize_text_field( $params['object'] ) : 'custom',
			'menu-item-parent-id' => isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : 0,
			'menu-item-position'  => isset( $params['position'] ) ? absint( $params['position'] ) : 0,
		);

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $item_data );

		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		WP_MCP_Logger::log_action( 'menu.item.create', 'menu', $item_id, array( 'menu_id' => $menu_id ), 'success' );

		$item = get_post( $item_id );
		return new WP_REST_Response( self::format_menu_item( $item ), 201 );
	}

	/**
	 * Update menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_menu_item( WP_REST_Request $request ) {
		$item_id = (int) $request['item_id'];
		$item    = get_post( $item_id );

		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new WP_Error( 'not_found', __( 'Menu item not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$params  = $request->get_json_params();
		$menu_id = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );
		$menus   = wp_get_object_terms( $item_id, 'nav_menu' );
		$menu    = ! empty( $menus ) ? (int) $menus[0]->term_id : 0;

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'item_id' => $item_id ), 200 );
		}

		$item_data = array(
			'menu-item-title'     => isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : $item->post_title,
			'menu-item-status'    => 'publish',
			'menu-item-url'       => isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : get_post_meta( $item_id, '_menu_item_url', true ),
			'menu-item-parent-id' => isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true ),
		);

		$result = wp_update_nav_menu_item( $menu, $item_id, $item_data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_Logger::log_action( 'menu.item.update', 'menu', $item_id, $params, 'success' );

		return new WP_REST_Response( self::format_menu_item( get_post( $item_id ) ), 200 );
	}

	/**
	 * Delete menu item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_menu_item( WP_REST_Request $request ) {
		$item_id = (int) $request['item_id'];
		$params  = $request->get_json_params();

		if ( empty( $params['confirm'] ) ) {
			return new WP_Error( 'confirm_required', __( 'Deletion requires confirm=true.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'item_id' => $item_id ), 200 );
		}

		$result = wp_delete_post( $item_id, true );
		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete menu item.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		WP_MCP_Logger::log_action( 'menu.item.delete', 'menu', $item_id, array(), 'success' );

		return new WP_REST_Response( array( 'id' => $item_id, 'deleted' => true ), 200 );
	}

	/**
	 * Assign menu locations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function assign_locations( WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$locations = isset( $params['locations'] ) && is_array( $params['locations'] ) ? $params['locations'] : array();

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response( array( 'dry_run' => true, 'locations' => $locations ), 200 );
		}

		$current = get_nav_menu_locations();

		foreach ( $locations as $location => $menu_id ) {
			$location = sanitize_key( $location );
			$current[ $location ] = absint( $menu_id );
		}

		set_theme_mod( 'nav_menu_locations', $current );

		WP_MCP_Logger::log_action( 'menu.locations.update', 'menu', 0, $locations, 'success' );

		return new WP_REST_Response( array( 'assigned' => $current ), 200 );
	}

	/**
	 * Format menu item.
	 *
	 * @param WP_Post $item Menu item post.
	 * @return array
	 */
	private static function format_menu_item( $item ) {
		if ( is_object( $item ) && isset( $item->ID ) ) {
			$id = (int) $item->ID;
		} else {
			$id = (int) $item->ID;
		}

		return array(
			'id'        => $id,
			'title'     => $item->title ?? $item->post_title,
			'url'       => $item->url ?? get_post_meta( $id, '_menu_item_url', true ),
			'type'      => $item->type ?? get_post_meta( $id, '_menu_item_type', true ),
			'object_id' => (int) ( $item->object_id ?? get_post_meta( $id, '_menu_item_object_id', true ) ),
			'parent_id' => (int) ( $item->menu_item_parent ?? get_post_meta( $id, '_menu_item_menu_item_parent', true ) ),
			'position'  => (int) ( $item->menu_order ?? $item->menu_order ),
		);
	}
}
