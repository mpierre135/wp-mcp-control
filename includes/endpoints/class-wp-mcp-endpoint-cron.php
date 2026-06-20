<?php
/**
 * Cron events endpoint.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Cron
 */
class WP_MCP_Endpoint_Cron {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/cron/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_events' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * List scheduled cron events (read-only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_events( WP_REST_Request $request ) {
		$params = $request->get_query_params();
		$hook   = isset( $params['hook'] ) ? sanitize_text_field( $params['hook'] ) : '';

		$crons = _get_cron_array();
		$items = array();

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				if ( ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( $hooks as $hook_name => $events ) {
					if ( $hook && $hook_name !== $hook ) {
						continue;
					}
					if ( ! is_array( $events ) ) {
						continue;
					}
					foreach ( $events as $hash => $event ) {
						$items[] = array(
							'hook'      => $hook_name,
							'timestamp' => (int) $timestamp,
							'scheduled' => gmdate( 'c', $timestamp ),
							'schedule'  => isset( $event['schedule'] ) ? $event['schedule'] : 'single',
							'interval'  => isset( $event['interval'] ) ? (int) $event['interval'] : 0,
							'args'      => isset( $event['args'] ) ? $event['args'] : array(),
						);
					}
				}
			}
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $a['timestamp'] - $b['timestamp'];
			}
		);

		$per_page = min( 100, max( 1, isset( $params['per_page'] ) ? (int) $params['per_page'] : 50 ) );
		$page     = max( 1, isset( $params['page'] ) ? (int) $params['page'] : 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = count( $items );
		$paged    = array_slice( $items, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'items'    => $paged,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}
}
