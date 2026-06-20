<?php
/**
 * Widgets and sidebars endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Widgets
 */
class WP_MCP_Endpoint_Widgets {

	/**
	 * Editable widget types.
	 *
	 * @var array
	 */
	private static $editable_widgets = array( 'text', 'custom_html' );

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/widgets/sidebars',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_sidebars' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/widgets/instances',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_instances' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/widgets/instances/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'update_instance' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List registered sidebars.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_sidebars() {
		global $wp_registered_sidebars;

		$items = array();
		foreach ( $wp_registered_sidebars as $id => $sidebar ) {
			$items[] = array(
				'id'          => $id,
				'name'        => $sidebar['name'],
				'description' => isset( $sidebar['description'] ) ? $sidebar['description'] : '',
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * List widget instances.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_instances( WP_REST_Request $request ) {
		global $wp_registered_widgets, $wp_registered_sidebars;

		$params      = $request->get_query_params();
		$sidebar_id  = isset( $params['sidebar'] ) ? sanitize_text_field( $params['sidebar'] ) : '';
		$sidebars    = wp_get_sidebars_widgets();
		$items       = array();

		foreach ( $sidebars as $sb_id => $widget_ids ) {
			if ( $sidebar_id && $sb_id !== $sidebar_id ) {
				continue;
			}
			if ( ! is_array( $widget_ids ) ) {
				continue;
			}

			foreach ( $widget_ids as $widget_id ) {
				if ( ! isset( $wp_registered_widgets[ $widget_id ] ) ) {
					continue;
				}

				$widget = $wp_registered_widgets[ $widget_id ];
				$base   = self::get_widget_base( $widget_id );
				$opts   = self::get_widget_options( $base, $widget_id );

				$items[] = array(
					'id'       => $widget_id,
					'sidebar'  => $sb_id,
					'type'     => $base,
					'title'    => isset( $widget['name'] ) ? $widget['name'] : $base,
					'editable' => in_array( $base, self::$editable_widgets, true ),
					'options'  => $opts,
				);
			}
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Get widget base from instance ID.
	 *
	 * @param string $widget_id Widget ID.
	 * @return string
	 */
	private static function get_widget_base( $widget_id ) {
		$parts = explode( '-', $widget_id );
		array_pop( $parts );
		return implode( '-', $parts );
	}

	/**
	 * Get widget instance options.
	 *
	 * @param string $base      Widget base.
	 * @param string $widget_id Widget ID.
	 * @return array
	 */
	private static function get_widget_options( $base, $widget_id ) {
		$instances = get_option( 'widget_' . $base, array() );
		if ( ! is_array( $instances ) ) {
			return array();
		}

		$parts = explode( '-', $widget_id );
		$num   = (int) array_pop( $parts );

		return isset( $instances[ $num ] ) && is_array( $instances[ $num ] ) ? $instances[ $num ] : array();
	}

	/**
	 * Update widget instance.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_instance( WP_REST_Request $request ) {
		$widget_id = sanitize_text_field( $request['id'] );
		$base      = self::get_widget_base( $widget_id );

		if ( ! in_array( $base, self::$editable_widgets, true ) ) {
			return new WP_Error(
				'widget_not_editable',
				__( 'Only text and custom_html widgets can be updated.', 'wp-mcp-control' ),
				array( 'status' => 403 )
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$parts     = explode( '-', $widget_id );
		$num       = (int) array_pop( $parts );
		$instances = get_option( 'widget_' . $base, array() );

		if ( ! isset( $instances[ $num ] ) ) {
			return new WP_Error( 'not_found', __( 'Widget instance not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run'   => true,
					'widget_id' => $widget_id,
					'params'    => $params,
				),
				200
			);
		}

		if ( isset( $params['title'] ) ) {
			$instances[ $num ]['title'] = sanitize_text_field( $params['title'] );
		}

		if ( 'text' === $base && isset( $params['text'] ) ) {
			$instances[ $num ]['text'] = wp_kses_post( $params['text'] );
		}

		if ( 'custom_html' === $base && isset( $params['content'] ) ) {
			$instances[ $num ]['content'] = wp_kses_post( $params['content'] );
		}

		update_option( 'widget_' . $base, $instances );

		WP_MCP_Logger::log_action( 'widget.update', 'widget', $num, array( 'widget_id' => $widget_id, 'type' => $base ), 'success' );

		return new WP_REST_Response(
			array(
				'widget_id' => $widget_id,
				'updated'   => true,
				'options'   => $instances[ $num ],
			),
			200
		);
	}
}
