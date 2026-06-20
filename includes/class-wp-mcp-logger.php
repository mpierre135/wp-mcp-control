<?php
/**
 * Activity logger for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Logger
 */
class WP_MCP_Logger {

	/**
	 * Log an MCP action.
	 *
	 * @param string $action      Action name.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $payload     Additional data.
	 * @param string $status      Status (success, error, dry_run).
	 * @return int|false Insert ID or false.
	 */
	public static function log_action( $action, $object_type, $object_id, $payload = array(), $status = 'success' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_mcp_activity_log';

		$request_id = '';
		if ( ! empty( $payload['request_id'] ) ) {
			$request_id = sanitize_text_field( $payload['request_id'] );
			unset( $payload['request_id'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'action'      => sanitize_text_field( $action ),
				'object_type' => sanitize_text_field( $object_type ),
				'object_id'   => absint( $object_id ),
				'user_id'     => get_current_user_id(),
				'ip'          => WP_MCP_Auth::get_client_ip(),
				'request_id'  => $request_id,
				'payload'     => wp_json_encode( $payload ),
				'status'      => sanitize_text_field( $status ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get activity log entries.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'page'        => 1,
			'per_page'    => 50,
			'action'      => '',
			'object_type' => '',
		);

		$args  = wp_parse_args( $args, $defaults );
		$table = $wpdb->prefix . 'wp_mcp_activity_log';
		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$values[] = sanitize_text_field( $args['action'] );
		}

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$values[] = sanitize_text_field( $args['object_type'] );
		}

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_values ), ARRAY_A );

		$items = array();
		foreach ( $rows as $row ) {
			$payload = json_decode( $row['payload'], true );
			$items[] = array(
				'id'          => (int) $row['id'],
				'action'      => $row['action'],
				'object_type' => $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'ip'          => $row['ip'],
				'request_id'  => $row['request_id'],
				'payload'     => is_array( $payload ) ? $payload : array(),
				'status'      => $row['status'],
				'created_at'  => $row['created_at'],
			);
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}
}
