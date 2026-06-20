<?php
/**
 * Ninja Forms adapter for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_Ninja_Forms
 */
class WP_MCP_Adapter_Ninja_Forms extends WP_MCP_Adapter_Base {

	/**
	 * PII field keys to mask in submissions.
	 *
	 * @var array
	 */
	private static $pii_keys = array(
		'email',
		'e-mail',
		'phone',
		'telephone',
		'mobile',
		'address',
		'street',
		'ssn',
		'password',
		'name',
		'first_name',
		'last_name',
		'fullname',
	);

	/**
	 * @inheritdoc
	 */
	public static function slug() {
		return 'ninja-forms';
	}

	/**
	 * @inheritdoc
	 */
	public static function label() {
		return 'Ninja Forms';
	}

	/**
	 * @inheritdoc
	 */
	public static function is_available() {
		return class_exists( 'Ninja_Forms' ) || function_exists( 'Ninja_Forms' );
	}

	/**
	 * List forms.
	 *
	 * @return array|WP_Error
	 */
	public static function list_forms() {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$forms = Ninja_Forms()->form()->get_forms();
		$items = array();

		foreach ( $forms as $form ) {
			$items[] = array(
				'id'     => (int) $form->get_id(),
				'title'  => $form->get_setting( 'title' ),
				'created'=> $form->get_setting( 'created_at' ),
			);
		}

		return array( 'items' => $items, 'count' => count( $items ) );
	}

	/**
	 * Get form with fields and actions.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error
	 */
	public static function get_form( $form_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$fields = array();
		foreach ( $form->get_fields() as $field ) {
			$fields[] = array(
				'id'    => (int) $field->get_id(),
				'type'  => $field->get_setting( 'type' ),
				'label' => $field->get_setting( 'label' ),
				'key'   => $field->get_setting( 'key' ),
			);
		}

		$actions = array();
		foreach ( $form->get_actions() as $action ) {
			$settings = $action->get_settings();
			$actions[] = array(
				'id'     => (int) $action->get_id(),
				'type'   => $action->get_setting( 'type' ),
				'label'  => $action->get_setting( 'label' ),
				'active' => (bool) $action->get_setting( 'active' ),
				'to'     => isset( $settings['to'] ) ? self::mask_email( $settings['to'] ) : '',
				'subject'=> isset( $settings['subject'] ) ? $settings['subject'] : '',
			);
		}

		return array(
			'id'      => (int) $form_id,
			'title'   => $form->get_setting( 'title' ),
			'fields'  => $fields,
			'actions' => $actions,
		);
	}

	/**
	 * Update notification actions on a form.
	 *
	 * @param int             $form_id Form ID.
	 * @param array           $updates Notification updates keyed by action ID.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function update_notifications( $form_id, $updates, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return new WP_Error( 'missing_updates', __( 'Notification updates are required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'form_id' => $form_id, 'updates' => $updates );
		}

		$updated = array();

		foreach ( $form->get_actions() as $action ) {
			$action_id = (string) $action->get_id();
			if ( ! isset( $updates[ $action_id ] ) && ! isset( $updates[ (int) $action_id ] ) ) {
				continue;
			}

			$patch = isset( $updates[ $action_id ] ) ? $updates[ $action_id ] : $updates[ (int) $action_id ];
			if ( ! is_array( $patch ) ) {
				continue;
			}

			if ( isset( $patch['to'] ) ) {
				$action->update_setting( 'to', sanitize_email( $patch['to'] ) );
			}
			if ( isset( $patch['subject'] ) ) {
				$action->update_setting( 'subject', sanitize_text_field( $patch['subject'] ) );
			}
			if ( isset( $patch['active'] ) ) {
				$action->update_setting( 'active', (bool) $patch['active'] );
			}

			$action->save();
			$updated[] = (int) $action->get_id();
		}

		WP_MCP_Logger::log_action( 'ninja_forms.notifications.update', 'ninja_form', $form_id, array( 'actions' => $updated ), 'success' );

		return array( 'form_id' => $form_id, 'updated_actions' => $updated );
	}

	/**
	 * List form submissions with masked PII.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $args    Query args.
	 * @return array|WP_Error
	 */
	public static function list_submissions( $form_id, $args = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$defaults = array(
			'page'     => 1,
			'per_page' => 20,
		);
		$args = wp_parse_args( $args, $defaults );
		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );

		$submissions = Ninja_Forms()->form( $form_id )->get_subs(
			array(
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);

		$items = array();
		foreach ( $submissions as $sub ) {
			$fields = array();
			if ( method_exists( $sub, 'get_field_values' ) ) {
				$raw = $sub->get_field_values();
			} elseif ( is_array( $sub ) ) {
				$raw = $sub;
			} else {
				$raw = array();
			}

			foreach ( $raw as $key => $value ) {
				$fields[ $key ] = self::maybe_mask_field( $key, $value );
			}

			$items[] = array(
				'id'         => isset( $sub->_seq_num ) ? (int) $sub->_seq_num : ( isset( $sub['id'] ) ? (int) $sub['id'] : 0 ),
				'date'       => isset( $sub->_date_submitted ) ? $sub->_date_submitted : '',
				'fields'     => $fields,
			);
		}

		return array(
			'form_id'  => $form_id,
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Mask PII field value if key matches.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function maybe_mask_field( $key, $value ) {
		$key_lower = strtolower( (string) $key );
		foreach ( self::$pii_keys as $pii ) {
			if ( false !== strpos( $key_lower, $pii ) ) {
				return self::mask_value( $value );
			}
		}
		return $value;
	}

	/**
	 * Mask a scalar value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function mask_value( $value ) {
		$str = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
		if ( strlen( $str ) <= 4 ) {
			return '****';
		}
		return substr( $str, 0, 2 ) . str_repeat( '*', max( 0, strlen( $str ) - 4 ) ) . substr( $str, -2 );
	}

	/**
	 * Mask email for display.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private static function mask_email( $email ) {
		if ( ! is_email( $email ) ) {
			return self::mask_value( $email );
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		return substr( $local, 0, 1 ) . '***@' . $domain;
	}

	/**
	 * Supported webhook action types.
	 *
	 * @return array
	 */
	private static function webhook_action_types() {
		return array( 'webhook', 'WebHook', 'webhooks' );
	}

	/**
	 * Check if action is a webhook type.
	 *
	 * @param object $action NF action.
	 * @return bool
	 */
	private static function is_webhook_action( $action ) {
		$type = $action->get_setting( 'type' );
		return in_array( $type, self::webhook_action_types(), true );
	}

	/**
	 * Format webhook action for API.
	 *
	 * @param object $action NF action.
	 * @return array
	 */
	private static function format_webhook_action( $action ) {
		$settings = $action->get_settings();
		$url      = '';
		if ( isset( $settings['webhook_url'] ) ) {
			$url = $settings['webhook_url'];
		} elseif ( isset( $settings['url'] ) ) {
			$url = $settings['url'];
		}

		return array(
			'id'      => (int) $action->get_id(),
			'type'    => $action->get_setting( 'type' ),
			'label'   => $action->get_setting( 'label' ),
			'active'  => (bool) $action->get_setting( 'active' ),
			'url'     => esc_url_raw( $url ),
			'method'  => isset( $settings['method'] ) ? $settings['method'] : ( isset( $settings['http_method'] ) ? $settings['http_method'] : 'POST' ),
			'headers' => isset( $settings['headers'] ) ? $settings['headers'] : array(),
		);
	}

	/**
	 * List webhook actions on a form.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error
	 */
	public static function list_webhook_actions( $form_id ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$items = array();
		foreach ( $form->get_actions() as $action ) {
			if ( self::is_webhook_action( $action ) ) {
				$items[] = self::format_webhook_action( $action );
			}
		}

		if ( empty( $items ) && ! self::webhook_actions_supported() ) {
			return array(
				'form_id'   => (int) $form_id,
				'items'     => array(),
				'count'     => 0,
				'supported' => false,
			);
		}

		return array(
			'form_id'   => (int) $form_id,
			'items'     => $items,
			'count'     => count( $items ),
			'supported' => self::webhook_actions_supported(),
		);
	}

	/**
	 * Whether webhook action type exists in NF install.
	 *
	 * @return bool
	 */
	public static function webhook_actions_supported() {
		if ( ! self::is_available() ) {
			return false;
		}
		return class_exists( 'NF_Actions_WebHook' ) || class_exists( 'NF_Actions_Webhook' );
	}

	/**
	 * Create webhook action on form.
	 *
	 * @param int             $form_id Form ID.
	 * @param array           $data    Settings.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_webhook_action( $form_id, $data, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}
		if ( ! self::webhook_actions_supported() ) {
			return new WP_Error( 'webhook_unsupported', __( 'Ninja Forms webhook actions are not available.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$url = isset( $data['url'] ) ? $data['url'] : '';
		$url_check = WP_MCP_Webhooks::validate_url( $url );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'form_id' => $form_id, 'data' => $data );
		}

		$action = Ninja_Forms()->form( $form_id )->get()->new_action( 'webhook' );
		if ( ! $action ) {
			return new WP_Error( 'create_failed', __( 'Could not create webhook action.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		$action->update_setting( 'label', isset( $data['label'] ) ? sanitize_text_field( $data['label'] ) : 'Webhook' );
		$action->update_setting( 'active', ! isset( $data['active'] ) || (bool) $data['active'] );
		$action->update_setting( 'webhook_url', esc_url_raw( $url ) );
		$action->update_setting( 'method', isset( $data['method'] ) ? sanitize_text_field( $data['method'] ) : 'post' );
		if ( isset( $data['headers'] ) && is_array( $data['headers'] ) ) {
			$action->update_setting( 'headers', $data['headers'] );
		}
		$action->save();

		WP_MCP_Logger::log_action( 'ninja_forms.webhook.create', 'ninja_form', $form_id, array( 'action_id' => $action->get_id() ), 'success' );

		return self::format_webhook_action( $action );
	}

	/**
	 * Update webhook action.
	 *
	 * @param int             $form_id   Form ID.
	 * @param int             $action_id Action ID.
	 * @param array           $data      Settings.
	 * @param WP_REST_Request $request   Request.
	 * @return array|WP_Error
	 */
	public static function update_webhook_action( $form_id, $action_id, $data, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$action = null;
		foreach ( $form->get_actions() as $item ) {
			if ( (int) $item->get_id() === (int) $action_id && self::is_webhook_action( $item ) ) {
				$action = $item;
				break;
			}
		}

		if ( ! $action ) {
			return new WP_Error( 'not_found', __( 'Webhook action not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'form_id' => $form_id, 'action_id' => $action_id, 'data' => $data );
		}

		if ( isset( $data['label'] ) ) {
			$action->update_setting( 'label', sanitize_text_field( $data['label'] ) );
		}
		if ( isset( $data['active'] ) ) {
			$action->update_setting( 'active', (bool) $data['active'] );
		}
		if ( isset( $data['url'] ) ) {
			$url_check = WP_MCP_Webhooks::validate_url( $data['url'] );
			if ( is_wp_error( $url_check ) ) {
				return $url_check;
			}
			$action->update_setting( 'webhook_url', esc_url_raw( $data['url'] ) );
		}
		if ( isset( $data['method'] ) ) {
			$action->update_setting( 'method', sanitize_text_field( $data['method'] ) );
		}
		if ( isset( $data['headers'] ) && is_array( $data['headers'] ) ) {
			$action->update_setting( 'headers', $data['headers'] );
		}
		$action->save();

		WP_MCP_Logger::log_action( 'ninja_forms.webhook.update', 'ninja_form', $form_id, array( 'action_id' => $action_id ), 'success' );

		return self::format_webhook_action( $action );
	}

	/**
	 * Delete webhook action.
	 *
	 * @param int             $form_id   Form ID.
	 * @param int             $action_id Action ID.
	 * @param WP_REST_Request $request   Request.
	 * @return array|WP_Error
	 */
	public static function delete_webhook_action( $form_id, $action_id, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'ninja_forms_inactive', __( 'Ninja Forms is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$form = Ninja_Forms()->form()->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$found = false;
		foreach ( $form->get_actions() as $action ) {
			if ( (int) $action->get_id() === (int) $action_id && self::is_webhook_action( $action ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Webhook action not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'form_id' => $form_id, 'action_id' => $action_id, 'deleted' => true );
		}

		Ninja_Forms()->form( $form_id )->get()->delete_action( $action_id );

		WP_MCP_Logger::log_action( 'ninja_forms.webhook.delete', 'ninja_form', $form_id, array( 'action_id' => $action_id ), 'success' );

		return array( 'form_id' => $form_id, 'action_id' => $action_id, 'deleted' => true );
	}
}
