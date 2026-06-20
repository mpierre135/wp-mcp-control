<?php
/**
 * Base adapter for WP MCP Control plugin integrations.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_Base
 */
abstract class WP_MCP_Adapter_Base {

	/**
	 * Adapter slug.
	 *
	 * @return string
	 */
	abstract public static function slug();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public static function label();

	/**
	 * Whether adapter dependencies are active.
	 *
	 * @return bool
	 */
	abstract public static function is_available();

	/**
	 * Field catalog for sanitization.
	 *
	 * @return array
	 */
	public static function get_field_catalog() {
		return array();
	}

	/**
	 * Sanitize fields per catalog.
	 *
	 * @param array $fields Raw fields.
	 * @return array|WP_Error
	 */
	public static function sanitize_fields( $fields ) {
		$catalog = static::get_field_catalog();
		$clean   = array();

		foreach ( $fields as $key => $value ) {
			if ( ! isset( $catalog[ $key ] ) ) {
				continue;
			}
			$type = $catalog[ $key ]['type'] ?? 'text';
			$sanitized = WP_MCP_Meta::sanitize_by_type( $type, $value );
			if ( null !== $sanitized ) {
				$clean[ $key ] = $sanitized;
			}
		}

		if ( empty( $clean ) ) {
			return new WP_Error( 'no_valid_fields', __( 'No valid fields provided.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return $clean;
	}
}
