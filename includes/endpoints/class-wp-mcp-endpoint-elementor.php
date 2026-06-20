<?php
/**
 * Elementor REST endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Elementor
 */
class WP_MCP_Endpoint_Elementor {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/elementor/widgets',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_widget_catalog' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_structure' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/elements',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_elements' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/widgets',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'find_widgets' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/elements/(?P<element_id>[a-f0-9]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_element' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'remove_element' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/elements/(?P<element_id>[a-f0-9]+)/clone',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'clone_element' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/text',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_text' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/button',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_button' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/image',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_image' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/elements',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'insert_widget' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/sections',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'insert_section' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'duplicate_page' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/parent',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'find_parent' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/elementor/pages/(?P<id>\d+)/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'regenerate' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Ensure Elementor is active.
	 *
	 * @return true|WP_Error
	 */
	private static function require_elementor() {
		if ( ! WP_MCP_Elementor::is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor plugin is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * GET widget catalog.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_widget_catalog() {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return new WP_REST_Response(
			array(
				'widgets' => WP_MCP_Elementor::get_widget_catalog(),
				'count'   => count( WP_MCP_Elementor::get_widget_catalog() ),
			),
			200
		);
	}

	/**
	 * GET structure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_structure( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = WP_MCP_Elementor::get_structure( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET flat element list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_elements( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = WP_MCP_Elementor::list_elements( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET widgets filtered by type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function find_widgets( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$widget_type = $request->get_param( 'widget_type' );
		if ( ! $widget_type ) {
			return new WP_Error( 'missing_widget_type', __( 'widget_type query param is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor::list_elements( (int) $request['id'], sanitize_text_field( $widget_type ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * PUT update element settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_element( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params   = $request->get_json_params();
		$settings = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();

		if ( empty( $settings ) ) {
			return new WP_Error( 'missing_settings', __( 'settings object is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor::update_element(
			(int) $request['id'],
			sanitize_text_field( $request['element_id'] ),
			$settings,
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * PUT update text by widget search.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_text( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$widget_type = isset( $params['widget_type'] ) ? sanitize_text_field( $params['widget_type'] ) : 'heading';
		$new_text    = isset( $params['new_text'] ) ? $params['new_text'] : '';
		$match_text  = isset( $params['match_text'] ) ? sanitize_text_field( $params['match_text'] ) : '';

		if ( '' === $new_text ) {
			return new WP_Error( 'missing_new_text', __( 'new_text is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( '' === $match_text ) {
			return new WP_Error( 'missing_match_text', __( 'match_text is required to target a specific widget.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor::update_text(
			(int) $request['id'],
			$widget_type,
			$new_text,
			$match_text,
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * PUT update button.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_button( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$match_text = isset( $params['match_text'] ) ? sanitize_text_field( $params['match_text'] ) : '';
		$new_text   = isset( $params['new_text'] ) ? sanitize_text_field( $params['new_text'] ) : '';
		$url        = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '';

		if ( '' === $match_text ) {
			return new WP_Error( 'missing_match_text', __( 'match_text is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor::update_button(
			(int) $request['id'],
			$match_text,
			$new_text,
			$url,
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * PUT update image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_image( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$result = WP_MCP_Elementor::update_image(
			(int) $request['id'],
			isset( $params['element_id'] ) ? sanitize_text_field( $params['element_id'] ) : '',
			isset( $params['match_url'] ) ? esc_url_raw( $params['match_url'] ) : '',
			isset( $params['image_id'] ) ? absint( $params['image_id'] ) : 0,
			isset( $params['image_url'] ) ? esc_url_raw( $params['image_url'] ) : '',
			isset( $params['caption'] ) ? sanitize_text_field( $params['caption'] ) : '',
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST insert widget.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function insert_widget( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$parent_id   = isset( $params['parent_id'] ) ? sanitize_text_field( $params['parent_id'] ) : '';
		$widget_type = isset( $params['widget_type'] ) ? sanitize_text_field( $params['widget_type'] ) : '';
		$settings    = isset( $params['settings'] ) && is_array( $params['settings'] ) ? $params['settings'] : array();

		if ( '' === $parent_id || '' === $widget_type ) {
			return new WP_Error( 'missing_params', __( 'parent_id and widget_type are required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor_Tree::insert_widget(
			(int) $request['id'],
			$parent_id,
			$widget_type,
			$settings,
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST insert section.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function insert_section( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$position = isset( $params['position'] ) ? sanitize_text_field( $params['position'] ) : 'end';
		$children = isset( $params['children'] ) && is_array( $params['children'] ) ? $params['children'] : array();

		if ( ! in_array( $position, array( 'start', 'end' ), true ) ) {
			$position = 'end';
		}

		if ( empty( $children ) ) {
			return new WP_Error( 'missing_children', __( 'children array with widget definitions is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor_Tree::insert_section(
			(int) $request['id'],
			$position,
			$children,
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * DELETE remove element.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_element( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params  = $request->get_json_params();
		$confirm = false;
		if ( is_array( $params ) && isset( $params['confirm'] ) ) {
			$confirm = WP_MCP_Safe_Mode::parse_bool( $params['confirm'] );
		}

		$result = WP_MCP_Elementor_Tree::remove_element(
			(int) $request['id'],
			sanitize_text_field( $request['element_id'] ),
			$confirm,
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST clone element.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function clone_element( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = WP_MCP_Elementor_Tree::clone_element(
			(int) $request['id'],
			sanitize_text_field( $request['element_id'] ),
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST duplicate page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function duplicate_page( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$source_id = isset( $params['source_id'] ) ? absint( $params['source_id'] ) : 0;
		if ( ! $source_id ) {
			return new WP_Error( 'missing_source_id', __( 'source_id is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$result = WP_MCP_Elementor_Templates::duplicate_page(
			$source_id,
			isset( $params['title'] ) ? $params['title'] : '',
			isset( $params['slug'] ) ? $params['slug'] : '',
			isset( $params['status'] ) ? $params['status'] : 'draft',
			$request
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET find insert parent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function find_parent( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = WP_MCP_Elementor::find_insert_parent( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST regenerate Elementor CSS.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function regenerate( WP_REST_Request $request ) {
		$check = self::require_elementor();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = WP_MCP_Elementor::regenerate_page( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}
}
