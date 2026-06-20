<?php
/**
 * Plugin Name: WP MCP Control
 * Plugin URI: https://github.com/mpierre135/wp-mcp-control
 * Description: Secure REST API and MCP integration for managing WordPress content from Cursor, Claude Desktop, and other MCP-compatible IDEs.
 * Version: 2.0.0
 * Author: WP MCP Control
 * Author URI: https://github.com/mpierre135
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-mcp-control
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MCP_CONTROL_VERSION', '2.0.0' );
define( 'WP_MCP_CONTROL_PLUGIN_FILE', __FILE__ );
define( 'WP_MCP_CONTROL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_CONTROL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_MCP_CONTROL_DB_VERSION', '1.0.0' );

require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-auth.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-rate-limit.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-logger.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-snapshots.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-safe-mode.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-adapter-base.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-adapter-registry.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-meta.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-blocks.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-cors.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-elementor-catalog.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-elementor-tree.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-elementor-templates.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-elementor.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-rest.php';
require_once WP_MCP_CONTROL_PLUGIN_DIR . 'includes/class-wp-mcp-admin.php';

/**
 * Main plugin bootstrap.
 */
final class WP_MCP_Control {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_Control|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_Control
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		register_activation_hook( WP_MCP_CONTROL_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( WP_MCP_CONTROL_PLUGIN_FILE, array( $this, 'deactivate' ) );

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'template_redirect', array( $this, 'handle_redirects' ), 0 );
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		$this->create_tables();
		$this->set_default_options();
		update_option( 'wp_mcp_db_version', WP_MCP_CONTROL_DB_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Initialize plugin components.
	 */
	public function init() {
		if ( version_compare( get_option( 'wp_mcp_db_version', '' ), WP_MCP_CONTROL_DB_VERSION, '<' ) ) {
			$this->create_tables();
			update_option( 'wp_mcp_db_version', WP_MCP_CONTROL_DB_VERSION );
		}

		WP_MCP_Adapter_Registry::init();
		WP_MCP_CORS::init();
		WP_MCP_REST::init();
		WP_MCP_Admin::init();
	}

	/**
	 * Create custom database tables.
	 */
	private function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$activity_table  = $wpdb->prefix . 'wp_mcp_activity_log';
		$snapshots_table = $wpdb->prefix . 'wp_mcp_snapshots';
		$redirects_table = $wpdb->prefix . 'wp_mcp_redirects';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_activity = "CREATE TABLE {$activity_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action varchar(100) NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NOT NULL DEFAULT '',
			request_id varchar(64) NOT NULL DEFAULT '',
			payload longtext,
			status varchar(20) NOT NULL DEFAULT 'success',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY object_type (object_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_snapshots = "CREATE TABLE {$snapshots_table} (
			snapshot_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(50) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			old_data longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			restored_at datetime DEFAULT NULL,
			PRIMARY KEY  (snapshot_id),
			KEY object_lookup (object_type, object_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_redirects = "CREATE TABLE {$redirects_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(500) NOT NULL,
			target_url varchar(2000) NOT NULL,
			status_code smallint(5) unsigned NOT NULL DEFAULT 301,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY source_path (source_path(191)),
			KEY enabled (enabled)
		) {$charset_collate};";

		dbDelta( $sql_activity );
		dbDelta( $sql_snapshots );
		dbDelta( $sql_redirects );
	}

	/**
	 * Set default plugin options.
	 */
	private function set_default_options() {
		$defaults = array(
			'wp_mcp_token_hash'           => '',
			'wp_mcp_token_created_at'     => '',
			'wp_mcp_safe_mode'            => true,
			'wp_mcp_dry_run'              => false,
			'wp_mcp_cors_origins'         => array(),
			'wp_mcp_ip_allowlist'         => array(),
			'wp_mcp_rate_limit'           => 60,
			'wp_mcp_max_upload_bytes'     => 10485760,
			'wp_mcp_allow_force_delete'   => false,
			'wp_mcp_allow_wc_refunds'     => false,
			'wp_mcp_allow_admin_users'    => false,
			'wp_mcp_allowed_post_types'   => array( 'post', 'page', 'product' ),
			'wp_mcp_plugin_allowlist'     => array(),
			'wp_mcp_last_request_at'      => '',
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}

	/**
	 * Handle built-in redirects on template_redirect.
	 */
	public function handle_redirects() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		global $wpdb;

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! $path ) {
			return;
		}

		$normalized = WP_MCP_REST::normalize_path( $path );
		$table      = $wpdb->prefix . 'wp_mcp_redirects';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$redirect = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT target_url, status_code FROM {$table} WHERE source_path = %s AND enabled = 1 LIMIT 1",
				$normalized
			)
		);

		if ( $redirect && ! empty( $redirect->target_url ) ) {
			$code = in_array( (int) $redirect->status_code, array( 301, 302, 307, 308 ), true )
				? (int) $redirect->status_code
				: 301;
			wp_safe_redirect( esc_url_raw( $redirect->target_url ), $code );
			exit;
		}
	}
}

WP_MCP_Control::instance();
