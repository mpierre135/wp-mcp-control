<?php
/**
 * Custom webhook dispatcher for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Webhooks
 */
class WP_MCP_Webhooks {

	const CRON_HOOK = 'wp_mcp_deliver_webhook';

	/**
	 * Initialize webhook hooks and cron handler.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_event_hooks' ), 25 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_scheduled_delivery' ), 10, 4 );
	}

	/**
	 * Webhooks table name.
	 *
	 * @return string
	 */
	public static function webhooks_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_mcp_webhooks';
	}

	/**
	 * Deliveries table name.
	 *
	 * @return string
	 */
	public static function deliveries_table() {
		global $wpdb;
		return $wpdb->prefix . 'wp_mcp_webhook_deliveries';
	}

	/**
	 * Max custom webhooks per site.
	 *
	 * @return int
	 */
	public static function max_webhooks() {
		return max( 1, (int) get_option( 'wp_mcp_max_webhooks', 25 ) );
	}

	/**
	 * Topic catalog.
	 *
	 * @return array
	 */
	public static function get_topic_catalog() {
		$topics = array(
			array( 'topic' => 'post.created', 'label' => 'Post created', 'category' => 'content' ),
			array( 'topic' => 'post.updated', 'label' => 'Post updated', 'category' => 'content' ),
			array( 'topic' => 'post.deleted', 'label' => 'Post deleted', 'category' => 'content' ),
			array( 'topic' => 'post.status_changed', 'label' => 'Post status changed', 'category' => 'content' ),
			array( 'topic' => 'page.created', 'label' => 'Page created', 'category' => 'content' ),
			array( 'topic' => 'page.updated', 'label' => 'Page updated', 'category' => 'content' ),
			array( 'topic' => 'page.deleted', 'label' => 'Page deleted', 'category' => 'content' ),
			array( 'topic' => 'page.status_changed', 'label' => 'Page status changed', 'category' => 'content' ),
			array( 'topic' => 'comment.created', 'label' => 'Comment created', 'category' => 'comments' ),
			array( 'topic' => 'comment.approved', 'label' => 'Comment approved', 'category' => 'comments' ),
			array( 'topic' => 'comment.spam', 'label' => 'Comment marked spam', 'category' => 'comments' ),
			array( 'topic' => 'comment.trashed', 'label' => 'Comment trashed', 'category' => 'comments' ),
			array( 'topic' => 'user.created', 'label' => 'User created', 'category' => 'users' ),
			array( 'topic' => 'product.created', 'label' => 'Product created', 'category' => 'woocommerce' ),
			array( 'topic' => 'product.updated', 'label' => 'Product updated', 'category' => 'woocommerce' ),
			array( 'topic' => 'product.deleted', 'label' => 'Product deleted', 'category' => 'woocommerce' ),
			array( 'topic' => 'order.created', 'label' => 'Order created', 'category' => 'woocommerce' ),
			array( 'topic' => 'order.updated', 'label' => 'Order updated', 'category' => 'woocommerce' ),
			array( 'topic' => 'order.status_changed', 'label' => 'Order status changed', 'category' => 'woocommerce' ),
			array( 'topic' => 'form.submission', 'label' => 'Form submission', 'category' => 'forms' ),
			array( 'topic' => 'cache.purged', 'label' => 'Cache purged', 'category' => 'mcp' ),
			array( 'topic' => 'redirect.created', 'label' => 'Redirect created', 'category' => 'mcp' ),
			array( 'topic' => 'seo.updated', 'label' => 'SEO meta updated', 'category' => 'mcp' ),
			array( 'topic' => 'acf.updated', 'label' => 'ACF fields updated', 'category' => 'mcp' ),
		);

		$allowed = get_option( 'wp_mcp_allowed_post_types', array( 'post', 'page', 'product' ) );
		if ( is_array( $allowed ) ) {
			foreach ( $allowed as $cpt ) {
				$cpt = sanitize_key( $cpt );
				if ( in_array( $cpt, array( 'post', 'page', 'product', 'attachment' ), true ) ) {
					continue;
				}
				$topics[] = array( 'topic' => 'cpt.' . $cpt . '.created', 'label' => ucfirst( $cpt ) . ' created', 'category' => 'cpt' );
				$topics[] = array( 'topic' => 'cpt.' . $cpt . '.updated', 'label' => ucfirst( $cpt ) . ' updated', 'category' => 'cpt' );
				$topics[] = array( 'topic' => 'cpt.' . $cpt . '.deleted', 'label' => ucfirst( $cpt ) . ' deleted', 'category' => 'cpt' );
				$topics[] = array( 'topic' => 'cpt.' . $cpt . '.status_changed', 'label' => ucfirst( $cpt ) . ' status changed', 'category' => 'cpt' );
			}
		}

		if ( get_option( 'wp_mcp_allow_wildcard_webhooks', false ) ) {
			$topics[] = array( 'topic' => '*', 'label' => 'All events (wildcard)', 'category' => 'admin' );
		}

		return $topics;
	}

	/**
	 * Generate webhook signing secret.
	 *
	 * @return string
	 */
	public static function generate_secret() {
		return 'whsec_' . bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Encrypt secret for storage.
	 *
	 * @param string $secret Plain secret.
	 * @return string
	 */
	public static function encrypt_secret( $secret ) {
		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
		$enc = openssl_encrypt( $secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $enc );
	}

	/**
	 * Decrypt stored secret.
	 *
	 * @param string $encrypted Encrypted secret.
	 * @return string|false
	 */
	public static function decrypt_secret( $encrypted ) {
		$key = substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );
		$raw = base64_decode( $encrypted, true );
		if ( false === $raw ) {
			return false;
		}
		return openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	}

	/**
	 * Validate webhook delivery URL.
	 *
	 * @param string $url URL.
	 * @return true|WP_Error
	 */
	public static function validate_url( $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'Webhook URL is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_url', __( 'Invalid webhook URL.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$allow_http = (bool) get_option( 'wp_mcp_allow_http_webhooks', false );
		if ( 'https' !== strtolower( $parsed['scheme'] ) && ! $allow_http ) {
			return new WP_Error( 'https_required', __( 'Webhook URL must use HTTPS.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_scheme', __( 'Webhook URL must use HTTP or HTTPS.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$validated = wp_http_validate_url( $url );
		if ( ! $validated ) {
			return new WP_Error( 'invalid_url', __( 'Webhook URL failed validation.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$host = strtolower( $parsed['host'] );
		if ( 'localhost' === $host || ( strlen( $host ) > 6 && '.local' === substr( $host, -6 ) ) ) {
			return new WP_Error( 'ssrf_blocked', __( 'Webhook URL host is not allowed.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$ip = gethostbyname( $host );
		if ( $ip && $ip === $host && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'ssrf_blocked', __( 'Webhook URL resolves to a private or reserved IP.', 'wp-mcp-control' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	/**
	 * Validate topics array.
	 *
	 * @param array $topics Topics.
	 * @return array|WP_Error
	 */
	public static function validate_topics( $topics ) {
		if ( ! is_array( $topics ) || empty( $topics ) ) {
			return new WP_Error( 'invalid_topics', __( 'At least one topic is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$allowed = array();
		foreach ( self::get_topic_catalog() as $item ) {
			$allowed[] = $item['topic'];
		}

		$clean = array();
		foreach ( $topics as $topic ) {
			$topic = sanitize_text_field( $topic );
			if ( in_array( $topic, $allowed, true ) ) {
				$clean[] = $topic;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		if ( empty( $clean ) ) {
			return new WP_Error( 'invalid_topics', __( 'No valid topics provided.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return $clean;
	}

	/**
	 * List webhooks.
	 *
	 * @return array
	 */
	public static function list_webhooks() {
		global $wpdb;
		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::format_webhook( $row );
		}
		return array( 'items' => $items, 'count' => count( $items ) );
	}

	/**
	 * Get webhook by ID.
	 *
	 * @param int $id Webhook ID.
	 * @return array|WP_Error
	 */
	public static function get_webhook( $id ) {
		global $wpdb;
		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}
		return self::format_webhook( $row );
	}

	/**
	 * Create webhook.
	 *
	 * @param array $data Webhook data.
	 * @return array|WP_Error
	 */
	public static function create_webhook( $data ) {
		global $wpdb;

		$count = self::list_webhooks();
		if ( $count['count'] >= self::max_webhooks() ) {
			return new WP_Error( 'limit_reached', __( 'Maximum webhook limit reached.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$url  = isset( $data['url'] ) ? $data['url'] : '';
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Webhook name is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$url_check = self::validate_url( $url );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}

		$topics = self::validate_topics( isset( $data['topics'] ) ? $data['topics'] : array() );
		if ( is_wp_error( $topics ) ) {
			return $topics;
		}

		$secret = self::generate_secret();
		$enabled = ! isset( $data['enabled'] ) || (bool) $data['enabled'];

		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'name'         => $name,
				'url'          => esc_url_raw( $url ),
				'secret'       => self::encrypt_secret( $secret ),
				'topics'       => wp_json_encode( $topics ),
				'enabled'      => $enabled ? 1 : 0,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create webhook.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		$id = (int) $wpdb->insert_id;
		WP_MCP_Logger::log_action( 'webhook.create', 'webhook', $id, array( 'name' => $name, 'topics' => $topics ), 'success' );

		$formatted = self::format_webhook( self::get_row( $id ) );
		$formatted['secret'] = $secret;
		return $formatted;
	}

	/**
	 * Update webhook.
	 *
	 * @param int   $id   Webhook ID.
	 * @param array $data Update data.
	 * @return array|WP_Error
	 */
	public static function update_webhook( $id, $data ) {
		global $wpdb;

		$row = self::get_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$format = array( '%s' );

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
			$format[]       = '%s';
		}
		if ( isset( $data['url'] ) ) {
			$url_check = self::validate_url( $data['url'] );
			if ( is_wp_error( $url_check ) ) {
				return $url_check;
			}
			$update['url'] = esc_url_raw( $data['url'] );
			$format[]    = '%s';
		}
		if ( isset( $data['topics'] ) ) {
			$topics = self::validate_topics( $data['topics'] );
			if ( is_wp_error( $topics ) ) {
				return $topics;
			}
			$update['topics'] = wp_json_encode( $topics );
			$format[]         = '%s';
		}
		if ( isset( $data['enabled'] ) ) {
			$update['enabled'] = (bool) $data['enabled'] ? 1 : 0;
			$format[]          = '%d';
		}

		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		WP_MCP_Logger::log_action( 'webhook.update', 'webhook', $id, array_keys( $data ), 'success' );

		return self::format_webhook( self::get_row( $id ) );
	}

	/**
	 * Delete webhook.
	 *
	 * @param int $id Webhook ID.
	 * @return array|WP_Error
	 */
	public static function delete_webhook( $id ) {
		global $wpdb;

		$row = self::get_row( $id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		WP_MCP_Logger::log_action( 'webhook.delete', 'webhook', $id, array(), 'success' );

		return array( 'id' => $id, 'deleted' => true );
	}

	/**
	 * Get raw row.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	private static function get_row( $id ) {
		global $wpdb;
		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * Format webhook for API.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	public static function format_webhook( $row ) {
		$topics = json_decode( $row['topics'], true );
		if ( ! is_array( $topics ) ) {
			$topics = array();
		}
		return array(
			'id'         => (int) $row['id'],
			'name'       => $row['name'],
			'url'        => $row['url'],
			'topics'     => $topics,
			'enabled'    => (bool) $row['enabled'],
			'secret'     => 'whsec_****',
			'created_at' => $row['created_at'],
			'updated_at' => $row['updated_at'],
		);
	}

	/**
	 * List deliveries for webhook.
	 *
	 * @param int   $webhook_id Webhook ID.
	 * @param array $args       Query args.
	 * @return array|WP_Error
	 */
	public static function list_deliveries( $webhook_id, $args = array() ) {
		if ( ! self::get_row( $webhook_id ) ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		global $wpdb;
		$table    = self::deliveries_table();
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE webhook_id = %d", $webhook_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE webhook_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$webhook_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'            => (int) $row['id'],
				'webhook_id'    => (int) $row['webhook_id'],
				'topic'         => $row['topic'],
				'event_id'      => $row['event_id'],
				'response_code' => (int) $row['response_code'],
				'status'        => $row['status'],
				'attempts'      => (int) $row['attempts'],
				'created_at'    => $row['created_at'],
			);
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Send test ping.
	 *
	 * @param int $webhook_id Webhook ID.
	 * @return array|WP_Error
	 */
	public static function send_test( $webhook_id ) {
		$row = self::get_row( $webhook_id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$payload = array(
			'test'    => true,
			'message' => 'WP MCP Control webhook test ping',
		);

		return self::deliver( (int) $webhook_id, 'test.ping', 'test', $payload, $row );
	}

	/**
	 * Dispatch event to matching webhooks.
	 *
	 * @param string $topic   Event topic.
	 * @param array  $payload Event payload.
	 * @param string $event_id Optional event ID.
	 */
	public static function dispatch( $topic, $payload, $event_id = '' ) {
		global $wpdb;
		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1", ARRAY_A );
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$topics = json_decode( $row['topics'], true );
			if ( ! is_array( $topics ) ) {
				continue;
			}
			if ( ! self::topic_matches( $topics, $topic ) ) {
				continue;
			}
			self::schedule_delivery( (int) $row['id'], $topic, $event_id, $payload );
		}
	}

	/**
	 * Check if webhook topics match event topic.
	 *
	 * @param array  $webhook_topics Webhook topics.
	 * @param string $topic          Event topic.
	 * @return bool
	 */
	public static function topic_matches( $webhook_topics, $topic ) {
		if ( in_array( '*', $webhook_topics, true ) ) {
			return (bool) get_option( 'wp_mcp_allow_wildcard_webhooks', false );
		}
		if ( in_array( $topic, $webhook_topics, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Schedule async delivery.
	 *
	 * @param int    $webhook_id Webhook ID.
	 * @param string $topic      Topic.
	 * @param string $event_id   Event ID.
	 * @param array  $payload    Payload.
	 */
	public static function schedule_delivery( $webhook_id, $topic, $event_id, $payload ) {
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $webhook_id, $topic, $event_id, $payload ) );
	}

	/**
	 * Cron handler for delivery.
	 *
	 * @param int    $webhook_id Webhook ID.
	 * @param string $topic      Topic.
	 * @param string $event_id   Event ID.
	 * @param array  $payload    Payload.
	 */
	public static function process_scheduled_delivery( $webhook_id, $topic, $event_id, $payload ) {
		self::deliver( $webhook_id, $topic, $event_id, $payload );
	}

	/**
	 * Deliver webhook payload.
	 *
	 * @param int         $webhook_id Webhook ID.
	 * @param string      $topic      Topic.
	 * @param string      $event_id   Event ID.
	 * @param array       $payload    Payload.
	 * @param array|null  $row        Optional row.
	 * @param int         $attempt    Attempt number.
	 * @return array|WP_Error
	 */
	public static function deliver( $webhook_id, $topic, $event_id, $payload, $row = null, $attempt = 1 ) {
		if ( null === $row ) {
			$row = self::get_row( $webhook_id );
		}
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Webhook not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$secret = self::decrypt_secret( $row['secret'] );
		if ( ! $secret ) {
			return new WP_Error( 'secret_error', __( 'Could not decrypt webhook secret.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		$body = array(
			'topic'     => $topic,
			'site_url'  => get_site_url(),
			'timestamp' => gmdate( 'c' ),
			'data'      => $payload,
		);
		$json = wp_json_encode( $body );
		$sig  = hash_hmac( 'sha256', $json, $secret );

		$response = wp_remote_post(
			$row['url'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'       => 'application/json',
					'X-WP-MCP-Event'     => $topic,
					'X-WP-MCP-Signature' => 'sha256=' . $sig,
					'User-Agent'         => 'WP-MCP-Control/' . WP_MCP_CONTROL_VERSION,
				),
				'body'    => $json,
			)
		);

		$code   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$resp_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
		$status = ( $code >= 200 && $code < 300 ) ? 'success' : 'failed';

		$delivery_id = self::log_delivery( $webhook_id, $topic, $event_id, $row['url'], $code, $resp_body, $status, $attempt );

		if ( 'failed' === $status && $attempt < 2 ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK, array( $webhook_id, $topic, $event_id, $payload ) );
		}

		return array(
			'delivery_id'   => $delivery_id,
			'webhook_id'    => $webhook_id,
			'topic'         => $topic,
			'response_code' => $code,
			'status'        => $status,
		);
	}

	/**
	 * Log delivery attempt.
	 *
	 * @param int    $webhook_id    Webhook ID.
	 * @param string $topic         Topic.
	 * @param string $event_id      Event ID.
	 * @param string $url           URL.
	 * @param int    $response_code Response code.
	 * @param string $response_body Response body.
	 * @param string $status        Status.
	 * @param int    $attempts      Attempts.
	 * @return int
	 */
	private static function log_delivery( $webhook_id, $topic, $event_id, $url, $response_code, $response_body, $status, $attempts ) {
		global $wpdb;
		$table = self::deliveries_table();
		$body  = substr( (string) $response_body, 0, 2048 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'webhook_id'    => $webhook_id,
				'topic'         => sanitize_text_field( $topic ),
				'event_id'      => sanitize_text_field( (string) $event_id ),
				'request_url'   => esc_url_raw( $url ),
				'response_code' => (int) $response_code,
				'response_body' => $body,
				'status'        => $status,
				'attempts'      => $attempts,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Count enabled custom webhooks.
	 *
	 * @return int
	 */
	public static function count_webhooks() {
		global $wpdb;
		$table = self::webhooks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Register WordPress/WC/NF event hooks.
	 */
	public static function register_event_hooks() {
		if ( self::count_webhooks() < 1 ) {
			return;
		}

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ), 10, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_insert_comment' ), 10, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'on_transition_comment_status' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 10, 1 );
		add_action( 'ninja_forms_after_submission', array( __CLASS__, 'on_form_submission' ), 10, 1 );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_new_product', array( __CLASS__, 'on_wc_product_created' ), 10, 1 );
			add_action( 'woocommerce_update_product', array( __CLASS__, 'on_wc_product_updated' ), 10, 1 );
			add_action( 'before_delete_post', array( __CLASS__, 'on_wc_product_deleted' ), 5, 1 );
			add_action( 'woocommerce_new_order', array( __CLASS__, 'on_wc_order_created' ), 10, 1 );
			add_action( 'woocommerce_update_order', array( __CLASS__, 'on_wc_order_updated' ), 10, 1 );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_wc_order_status_changed' ), 10, 4 );
		}
	}

	/**
	 * Resolve content topic prefix for post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function topic_prefix_for_post_type( $post_type ) {
		if ( 'page' === $post_type ) {
			return 'page';
		}
		if ( 'post' === $post_type ) {
			return 'post';
		}
		if ( 'product' === $post_type ) {
			return 'product';
		}
		return 'cpt.' . $post_type;
	}

	/**
	 * Build post payload.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function post_payload( $post ) {
		return array(
			'id'      => (int) $post->ID,
			'type'    => $post->post_type,
			'title'   => $post->post_title,
			'status'  => $post->post_status,
			'url'     => get_permalink( $post ),
			'updated' => $post->post_modified_gmt,
		);
	}

	/**
	 * save_post handler.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update flag.
	 */
	public static function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$prefix = self::topic_prefix_for_post_type( $post->post_type );
		$topic  = $update ? $prefix . '.updated' : $prefix . '.created';
		if ( 'product' === $post->post_type ) {
			return;
		}

		self::dispatch( $topic, self::post_payload( $post ), (string) $post_id );
	}

	/**
	 * before_delete_post handler.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$prefix = self::topic_prefix_for_post_type( $post->post_type );
		$topic  = ( false !== strpos( $prefix, 'cpt.' ) ) ? $prefix . '.deleted' : $prefix . '.deleted';
		if ( 'product' === $post->post_type ) {
			return;
		}
		self::dispatch( $topic, self::post_payload( $post ), (string) $post_id );
	}

	/**
	 * transition_post_status handler.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'product' === $post->post_type ) {
			return;
		}
		$prefix = self::topic_prefix_for_post_type( $post->post_type );
		$suffix = ( false !== strpos( $prefix, 'cpt.' ) ) ? '.status_changed' : '.status_changed';
		self::dispatch(
			$prefix . $suffix,
			array_merge( self::post_payload( $post ), array( 'old_status' => $old_status, 'new_status' => $new_status ) ),
			(string) $post->ID
		);
	}

	/**
	 * wp_insert_comment handler.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment.
	 */
	public static function on_insert_comment( $comment_id, $comment ) {
		self::dispatch(
			'comment.created',
			array(
				'id'      => (int) $comment_id,
				'post_id' => (int) $comment->comment_post_ID,
				'status'  => $comment->comment_approved,
				'author'  => $comment->comment_author,
			),
			(string) $comment_id
		);
	}

	/**
	 * transition_comment_status handler.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public static function on_transition_comment_status( $new_status, $old_status, $comment ) {
		$map = array(
			'approved' => 'comment.approved',
			'spam'     => 'comment.spam',
			'trash'    => 'comment.trashed',
		);
		if ( ! isset( $map[ $new_status ] ) ) {
			return;
		}
		self::dispatch(
			$map[ $new_status ],
			array(
				'id'         => (int) $comment->comment_ID,
				'post_id'    => (int) $comment->comment_post_ID,
				'old_status' => $old_status,
				'new_status' => $new_status,
			),
			(string) $comment->comment_ID
		);
	}

	/**
	 * user_register handler.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		self::dispatch(
			'user.created',
			array(
				'id'       => (int) $user_id,
				'username' => $user->user_login,
				'role'     => ! empty( $user->roles ) ? $user->roles[0] : '',
			),
			(string) $user_id
		);
	}

	/**
	 * Ninja Forms submission handler.
	 *
	 * @param array $data Submission data.
	 */
	public static function on_form_submission( $data ) {
		$form_id = isset( $data['form_id'] ) ? (int) $data['form_id'] : 0;
		self::dispatch(
			'form.submission',
			array(
				'form_id' => $form_id,
				'fields'  => isset( $data['fields'] ) ? $data['fields'] : array(),
			),
			(string) $form_id
		);
	}

	/**
	 * WC product created.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function on_wc_product_created( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::dispatch( 'product.created', WP_MCP_Adapter_WooCommerce::format_product( $product, true ), (string) $product_id );
	}

	/**
	 * WC product updated.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function on_wc_product_updated( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::dispatch( 'product.updated', WP_MCP_Adapter_WooCommerce::format_product( $product, true ), (string) $product_id );
	}

	/**
	 * WC product deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_wc_product_deleted( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}
		self::dispatch(
			'product.deleted',
			array( 'id' => (int) $post_id, 'title' => $post->post_title ),
			(string) $post_id
		);
	}

	/**
	 * WC order created.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_wc_order_created( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::dispatch( 'order.created', self::order_payload( $order ), (string) $order_id );
	}

	/**
	 * WC order updated.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_wc_order_updated( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::dispatch( 'order.updated', self::order_payload( $order ), (string) $order_id );
	}

	/**
	 * WC order status changed.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @param object $order      Order object.
	 */
	public static function on_wc_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		self::dispatch(
			'order.status_changed',
			array_merge( self::order_payload( $order ), array( 'old_status' => $old_status, 'new_status' => $new_status ) ),
			(string) $order_id
		);
	}

	/**
	 * Build safe order payload without payment details.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function order_payload( $order ) {
		return array(
			'id'       => $order->get_id(),
			'status'   => $order->get_status(),
			'total'    => $order->get_total(),
			'currency' => $order->get_currency(),
			'customer' => $order->get_billing_email(),
		);
	}

	/**
	 * Fire MCP-specific webhook events from adapters.
	 *
	 * @param string $topic   Topic.
	 * @param array  $payload Payload.
	 * @param string $event_id Event ID.
	 */
	public static function fire_mcp_event( $topic, $payload, $event_id = '' ) {
		self::dispatch( $topic, $payload, $event_id );
	}
}
