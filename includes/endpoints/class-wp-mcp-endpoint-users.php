<?php
/**
 * Users endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Users
 */
class WP_MCP_Endpoint_Users {

	/**
	 * Allowed roles for creation without admin override.
	 *
	 * @var array
	 */
	private static $safe_roles = array( 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/users',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_users' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_user' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/users/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_user' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/roles',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_roles' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Format user for response.
	 *
	 * @param WP_User $user          User.
	 * @param bool    $include_email Include email.
	 * @return array
	 */
	private static function format_user( WP_User $user, $include_email = false ) {
		$data = array(
			'id'           => (int) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
		);

		if ( $include_email ) {
			$data['email'] = $user->user_email;
		}

		return $data;
	}

	/**
	 * List users.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_users( WP_REST_Request $request ) {
		$params         = $request->get_query_params();
		$include_email  = ! empty( $params['include_email'] );
		$per_page       = min( 100, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 20 ) );
		$page           = max( 1, isset( $params['page'] ) ? (int) $params['page'] : 1 );

		$args = array(
			'number' => $per_page,
			'paged'  => $page,
			'orderby'=> 'registered',
			'order'  => 'DESC',
		);

		if ( ! empty( $params['search'] ) ) {
			$args['search']         = '*' . sanitize_text_field( $params['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		if ( ! empty( $params['role'] ) ) {
			$args['role'] = sanitize_text_field( $params['role'] );
		}

		$query = new WP_User_Query( $args );
		$items = array();

		foreach ( $query->get_results() as $user ) {
			$items[] = self::format_user( $user, $include_email );
		}

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => (int) $query->get_total(),
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * Get user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_user( WP_REST_Request $request ) {
		$user = get_user_by( 'id', (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$include_email = ! empty( $request->get_query_params()['include_email'] );
		return new WP_REST_Response( self::format_user( $user, $include_email ), 200 );
	}

	/**
	 * List roles.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_roles() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}

		$items = array();
		foreach ( $wp_roles->roles as $slug => $role ) {
			$items[] = array(
				'slug'        => $slug,
				'name'        => $role['name'],
				'capabilities'=> array_keys( $role['capabilities'] ),
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/**
	 * Create user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_user( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$username = isset( $params['username'] ) ? sanitize_user( $params['username'] ) : '';
		$email    = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$role     = isset( $params['role'] ) ? sanitize_text_field( $params['role'] ) : 'author';

		if ( empty( $username ) || empty( $email ) ) {
			return new WP_Error( 'missing_fields', __( 'username and email are required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'create_user', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( 'administrator' === $role ) {
			if ( WP_MCP_Safe_Mode::is_active( $request ) || ! get_option( 'wp_mcp_allow_admin_users', false ) ) {
				return new WP_Error(
					'admin_not_allowed',
					__( 'Administrator creation is not allowed in safe mode.', 'wp-mcp-control' ),
					array( 'status' => 403 )
				);
			}
			if ( ! $confirm ) {
				return new WP_Error( 'confirm_required', __( 'Administrator creation requires confirm=true.', 'wp-mcp-control' ), array( 'status' => 400 ) );
			}
		} elseif ( ! in_array( $role, self::$safe_roles, true ) ) {
			return new WP_Error( 'invalid_role', __( 'Role must be editor, author, contributor, or subscriber.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run'  => true,
					'username' => $username,
					'email'    => $email,
					'role'     => $role,
				),
				200
			);
		}

		$password = isset( $params['password'] ) ? $params['password'] : wp_generate_password( 16, true );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => isset( $params['display_name'] ) ? sanitize_text_field( $params['display_name'] ) : $username,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		WP_MCP_Logger::log_action( 'user.create', 'user', $user_id, array( 'username' => $username, 'role' => $role ), 'success' );

		$user = get_user_by( 'id', $user_id );
		return new WP_REST_Response( self::format_user( $user, true ), 201 );
	}
}
