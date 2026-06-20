<?php
/**
 * Admin settings page for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Admin
 */
class WP_MCP_Admin {

	/**
	 * Initialize admin.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		WP_MCP_Auth::register_ajax();
	}

	/**
	 * Add settings menu.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'WP MCP Control', 'wp-mcp-control' ),
			__( 'WP MCP Control', 'wp-mcp-control' ),
			'manage_options',
			'wp-mcp-control',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public static function register_settings() {
		register_setting( 'wp_mcp_control', 'wp_mcp_safe_mode', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'wp_mcp_control', 'wp_mcp_dry_run', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
		register_setting( 'wp_mcp_control', 'wp_mcp_allow_force_delete', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
		register_setting( 'wp_mcp_control', 'wp_mcp_rate_limit', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 60,
		) );
		register_setting( 'wp_mcp_control', 'wp_mcp_max_upload_bytes', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10485760,
		) );
		register_setting( 'wp_mcp_control', 'wp_mcp_cors_origins', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_lines' ),
			'default'           => array(),
		) );
		register_setting( 'wp_mcp_control', 'wp_mcp_ip_allowlist', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_lines' ),
			'default'           => array(),
		) );
	}

	/**
	 * Sanitize textarea lines to array.
	 *
	 * @param mixed $value Input value.
	 * @return array
	 */
	public static function sanitize_lines( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}
		$lines = explode( "\n", (string) $value );
		$clean = array();
		foreach ( $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( $line ) {
				$clean[] = $line;
			}
		}
		return $clean;
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_wp-mcp-control' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-admin',
			WP_MCP_CONTROL_PLUGIN_URL . 'assets/admin.css',
			array(),
			WP_MCP_CONTROL_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-admin',
			WP_MCP_CONTROL_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			WP_MCP_CONTROL_VERSION,
			true
		);

		wp_localize_script( 'wp-mcp-admin', 'wpMcpAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wp_mcp_admin' ),
		) );
	}

	/**
	 * Render settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$site_url       = get_site_url();
		$has_token      = WP_MCP_Auth::has_token();
		$token_created  = get_option( 'wp_mcp_token_created_at', '' );
		$last_request   = get_option( 'wp_mcp_last_request_at', '' );
		$safe_mode      = get_option( 'wp_mcp_safe_mode', true );
		$dry_run        = get_option( 'wp_mcp_dry_run', false );
		$cors_origins   = get_option( 'wp_mcp_cors_origins', array() );
		$ip_allowlist   = get_option( 'wp_mcp_ip_allowlist', array() );
		$rate_limit     = get_option( 'wp_mcp_rate_limit', 60 );
		$max_upload     = get_option( 'wp_mcp_max_upload_bytes', 10485760 );
		$force_delete   = get_option( 'wp_mcp_allow_force_delete', false );
		$rest_url       = rest_url( 'wp-mcp/v1/health' );
		$mcp_server_path = '/absolute/path/to/wp-mcp-control/mcp-server/dist/index.js';

		$logs = WP_MCP_Logger::get_logs( array( 'per_page' => 20 ) );

		$cursor_config = wp_json_encode( array(
			'mcpServers' => array(
				'wp-mcp-control' => array(
					'command' => 'node',
					'args'    => array( $mcp_server_path ),
					'env'     => array(
						'WP_MCP_SITE_URL'  => $site_url,
						'WP_MCP_TOKEN'     => 'paste-token-here',
						'WP_MCP_SAFE_MODE' => $safe_mode ? 'true' : 'false',
						'WP_MCP_DRY_RUN'   => $dry_run ? 'true' : 'false',
					),
				),
			),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$claude_config = wp_json_encode( array(
			'mcpServers' => array(
				'wp-mcp-control' => array(
					'command' => 'node',
					'args'    => array( $mcp_server_path ),
					'env'     => array(
						'WP_MCP_SITE_URL'  => $site_url,
						'WP_MCP_TOKEN'     => 'paste-token-here',
						'WP_MCP_SAFE_MODE' => $safe_mode ? 'true' : 'false',
						'WP_MCP_DRY_RUN'   => $dry_run ? 'true' : 'false',
					),
				),
			),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		?>
		<div class="wrap wp-mcp-admin">
			<h1><?php esc_html_e( 'WP MCP Control', 'wp-mcp-control' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Secure MCP integration for Cursor, Claude Desktop, and other MCP-compatible IDEs.', 'wp-mcp-control' ); ?></p>

			<div class="wp-mcp-grid">
				<div class="wp-mcp-card">
					<h2><?php esc_html_e( 'Connection Status', 'wp-mcp-control' ); ?></h2>
					<table class="widefat">
						<tr>
							<th><?php esc_html_e( 'REST API', 'wp-mcp-control' ); ?></th>
							<td><span class="wp-mcp-status wp-mcp-status-ok"><?php esc_html_e( 'Available', 'wp-mcp-control' ); ?></span></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'API Token', 'wp-mcp-control' ); ?></th>
							<td>
								<?php if ( $has_token ) : ?>
									<span class="wp-mcp-status wp-mcp-status-ok"><?php esc_html_e( 'Configured', 'wp-mcp-control' ); ?></span>
									<?php if ( $token_created ) : ?>
										<small>(<?php echo esc_html( sprintf( __( 'Created: %s', 'wp-mcp-control' ), $token_created ) ); ?>)</small>
									<?php endif; ?>
								<?php else : ?>
									<span class="wp-mcp-status wp-mcp-status-warn"><?php esc_html_e( 'Not configured', 'wp-mcp-control' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Last MCP Request', 'wp-mcp-control' ); ?></th>
							<td><?php echo $last_request ? esc_html( $last_request ) : esc_html__( 'Never', 'wp-mcp-control' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Health Endpoint', 'wp-mcp-control' ); ?></th>
							<td><code><?php echo esc_html( $rest_url ); ?></code></td>
						</tr>
					</table>
				</div>

				<div class="wp-mcp-card">
					<h2><?php esc_html_e( 'Token Management', 'wp-mcp-control' ); ?></h2>
					<p><?php esc_html_e( 'Generate a secure API token for MCP authentication. The token is shown only once.', 'wp-mcp-control' ); ?></p>
					<p>
						<button type="button" class="button button-primary" id="wp-mcp-generate-token">
							<?php echo $has_token ? esc_html__( 'Rotate Token', 'wp-mcp-control' ) : esc_html__( 'Generate Token', 'wp-mcp-control' ); ?>
						</button>
						<?php if ( $has_token ) : ?>
							<button type="button" class="button" id="wp-mcp-revoke-token"><?php esc_html_e( 'Revoke Token', 'wp-mcp-control' ); ?></button>
						<?php endif; ?>
					</p>
					<div id="wp-mcp-token-result" class="wp-mcp-token-box" style="display:none;">
						<p><strong><?php esc_html_e( 'Your API Token (copy now):', 'wp-mcp-control' ); ?></strong></p>
						<code id="wp-mcp-token-value"></code>
					</div>
				</div>
			</div>

			<form method="post" action="options.php" class="wp-mcp-settings-form">
				<?php settings_fields( 'wp_mcp_control' ); ?>

				<div class="wp-mcp-card">
					<h2><?php esc_html_e( 'Security Settings', 'wp-mcp-control' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Safe Mode', 'wp-mcp-control' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wp_mcp_safe_mode" value="1" <?php checked( $safe_mode ); ?> />
									<?php esc_html_e( 'Block destructive operations (force delete, plugin/theme changes, admin user creation)', 'wp-mcp-control' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Dry Run', 'wp-mcp-control' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wp_mcp_dry_run" value="1" <?php checked( $dry_run ); ?> />
									<?php esc_html_e( 'Validate requests without making changes', 'wp-mcp-control' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Allow Force Delete', 'wp-mcp-control' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wp_mcp_allow_force_delete" value="1" <?php checked( $force_delete ); ?> />
									<?php esc_html_e( 'Allow permanent deletion when safe mode is off and confirm=true', 'wp-mcp-control' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Rate Limit', 'wp-mcp-control' ); ?></th>
							<td>
								<input type="number" name="wp_mcp_rate_limit" value="<?php echo esc_attr( $rate_limit ); ?>" min="0" max="1000" />
								<p class="description"><?php esc_html_e( 'Requests per minute per token/IP (0 = unlimited)', 'wp-mcp-control' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Max Upload Size', 'wp-mcp-control' ); ?></th>
							<td>
								<input type="number" name="wp_mcp_max_upload_bytes" value="<?php echo esc_attr( $max_upload ); ?>" min="1048576" />
								<p class="description"><?php esc_html_e( 'Maximum media upload size in bytes (default: 10MB)', 'wp-mcp-control' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'CORS Allowed Origins', 'wp-mcp-control' ); ?></th>
							<td>
								<textarea name="wp_mcp_cors_origins" rows="4" class="large-text"><?php echo esc_textarea( is_array( $cors_origins ) ? implode( "\n", $cors_origins ) : '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One origin per line (e.g. https://cursor.sh). Leave empty to disallow browser CORS.', 'wp-mcp-control' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'IP Allowlist', 'wp-mcp-control' ); ?></th>
							<td>
								<textarea name="wp_mcp_ip_allowlist" rows="4" class="large-text"><?php echo esc_textarea( is_array( $ip_allowlist ) ? implode( "\n", $ip_allowlist ) : '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One IP per line. Leave empty to allow all IPs.', 'wp-mcp-control' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</div>
			</form>

			<div class="wp-mcp-card">
				<h2><?php esc_html_e( 'Recent MCP Activity', 'wp-mcp-control' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'wp-mcp-control' ); ?></th>
							<th><?php esc_html_e( 'Action', 'wp-mcp-control' ); ?></th>
							<th><?php esc_html_e( 'Object', 'wp-mcp-control' ); ?></th>
							<th><?php esc_html_e( 'IP', 'wp-mcp-control' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wp-mcp-control' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $logs['items'] ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'No activity yet.', 'wp-mcp-control' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $logs['items'] as $log ) : ?>
								<tr>
									<td><?php echo esc_html( $log['created_at'] ); ?></td>
									<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
									<td><?php echo esc_html( $log['object_type'] . ' #' . $log['object_id'] ); ?></td>
									<td><?php echo esc_html( $log['ip'] ); ?></td>
									<td><?php echo esc_html( $log['status'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="wp-mcp-card">
				<h2><?php esc_html_e( 'MCP Server Configuration', 'wp-mcp-control' ); ?></h2>

				<h3><?php esc_html_e( 'Installation', 'wp-mcp-control' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Upload this plugin to wp-content/plugins/ and activate it.', 'wp-mcp-control' ); ?></li>
					<li><?php esc_html_e( 'Generate an API token above.', 'wp-mcp-control' ); ?></li>
					<li><?php esc_html_e( 'Install MCP server dependencies:', 'wp-mcp-control' ); ?>
						<pre><code>cd mcp-server
npm install
npm run build</code></pre>
					</li>
					<li><?php esc_html_e( 'Add the config below to Cursor or Claude Desktop.', 'wp-mcp-control' ); ?></li>
					<li><?php esc_html_e( 'Restart your IDE and test with: "Run wp_health_check and list all WordPress pages."', 'wp-mcp-control' ); ?></li>
				</ol>

				<h3><?php esc_html_e( 'Cursor Config', 'wp-mcp-control' ); ?></h3>
				<p><?php esc_html_e( 'Add to ~/.cursor/mcp.json (update the path to your mcp-server/dist/index.js):', 'wp-mcp-control' ); ?></p>
				<pre class="wp-mcp-config"><code><?php echo esc_html( $cursor_config ); ?></code></pre>

				<h3><?php esc_html_e( 'Claude Desktop Config', 'wp-mcp-control' ); ?></h3>
				<p><?php esc_html_e( 'Add to ~/Library/Application Support/Claude/claude_desktop_config.json (macOS):', 'wp-mcp-control' ); ?></p>
				<pre class="wp-mcp-config"><code><?php echo esc_html( $claude_config ); ?></code></pre>
			</div>

			<details class="wp-mcp-card">
				<summary><h2 style="display:inline"><?php esc_html_e( 'Troubleshooting', 'wp-mcp-control' ); ?></h2></summary>
				<ul>
					<li><strong>401 Unauthorized:</strong> <?php esc_html_e( 'Check token is correct and not revoked.', 'wp-mcp-control' ); ?></li>
					<li><strong>403 Forbidden:</strong> <?php esc_html_e( 'Safe mode may be blocking the action, or IP not in allowlist.', 'wp-mcp-control' ); ?></li>
					<li><strong>429 Rate Limited:</strong> <?php esc_html_e( 'Reduce request frequency or increase rate limit.', 'wp-mcp-control' ); ?></li>
					<li><strong>SSL errors:</strong> <?php esc_html_e( 'Ensure your site has a valid SSL certificate.', 'wp-mcp-control' ); ?></li>
				</ul>
			</details>
		</div>
		<?php
	}
}
