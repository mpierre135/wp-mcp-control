<?php
/**
 * Uninstall WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'wp_mcp_activity_log',
	$wpdb->prefix . 'wp_mcp_snapshots',
	$wpdb->prefix . 'wp_mcp_redirects',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'wp_mcp_token_hash',
	'wp_mcp_token_created_at',
	'wp_mcp_safe_mode',
	'wp_mcp_dry_run',
	'wp_mcp_cors_origins',
	'wp_mcp_ip_allowlist',
	'wp_mcp_rate_limit',
	'wp_mcp_max_upload_bytes',
	'wp_mcp_allow_force_delete',
	'wp_mcp_last_request_at',
	'wp_mcp_db_version',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
