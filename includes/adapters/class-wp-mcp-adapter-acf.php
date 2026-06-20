<?php
/**
 * ACF adapter for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_ACF
 */
class WP_MCP_Adapter_ACF extends WP_MCP_Adapter_Base {

	/**
	 * @inheritdoc
	 */
	public static function slug() {
		return 'acf';
	}

	/**
	 * @inheritdoc
	 */
	public static function label() {
		return 'Advanced Custom Fields';
	}

	/**
	 * @inheritdoc
	 */
	public static function is_available() {
		return function_exists( 'get_field' ) && function_exists( 'acf_get_field_groups' );
	}

	/**
	 * @inheritdoc
	 */
	public static function get_field_catalog() {
		$cached = get_transient( 'wp_mcp_acf_catalog' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$catalog = array();
		$file    = WP_MCP_CONTROL_PLUGIN_DIR . 'includes/acf-fields.json';
		if ( file_exists( $file ) ) {
			$starter = json_decode( file_get_contents( $file ), true );
			if ( is_array( $starter ) ) {
				$catalog = $starter;
			}
		}

		if ( self::is_available() ) {
			$groups = acf_get_field_groups();
			foreach ( $groups as $group ) {
				$fields = acf_get_fields( $group );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $field ) {
					if ( empty( $field['name'] ) ) {
						continue;
					}
					$type = in_array( $field['type'], array( 'textarea', 'wysiwyg' ), true ) ? 'html' : 'text';
					$catalog[ $field['name'] ] = array(
						'type'  => $type,
						'label' => $field['label'] ?? $field['name'],
						'key'   => $field['key'] ?? '',
					);
				}
			}
		}

		set_transient( 'wp_mcp_acf_catalog', $catalog, DAY_IN_SECONDS );
		return $catalog;
	}

	/**
	 * Get ACF fields for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_fields_for_post( $post_id ) {
		if ( ! self::is_available() ) {
			return array();
		}

		$catalog = self::get_field_catalog();
		$data    = array();

		foreach ( array_keys( $catalog ) as $name ) {
			$value = get_field( $name, $post_id, false );
			if ( null !== $value && '' !== $value ) {
				$data[ $name ] = $value;
			}
		}

		return $data;
	}

	/**
	 * Update ACF fields on a post.
	 *
	 * @param int             $post_id Post ID.
	 * @param array           $fields  Fields to update.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function update_fields( $post_id, $fields, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'acf_inactive', __( 'ACF is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$clean = self::sanitize_fields( $fields );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'post_id' => $post_id, 'fields' => $clean );
		}

		WP_MCP_Snapshots::create_snapshot( 'acf', $post_id, get_post( $post_id ) );

		foreach ( $clean as $name => $value ) {
			update_field( $name, $value, $post_id );
		}

		WP_MCP_Logger::log_action( 'acf.update', 'acf', $post_id, array( 'fields' => array_keys( $clean ) ), 'success' );

		return array( 'post_id' => $post_id, 'updated' => true, 'fields' => $clean );
	}
}
