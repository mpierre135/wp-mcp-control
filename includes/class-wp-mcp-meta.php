<?php
/**
 * Meta helpers for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Meta
 */
class WP_MCP_Meta {

	/**
	 * Public meta keys always exposed.
	 *
	 * @var array
	 */
	private static $public_keys = array( '_wp_page_template' );

	/**
	 * Get whitelisted post meta for API response.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_post_meta_array( $post_id ) {
		$meta  = get_post_meta( $post_id );
		$clean = array();
		$allowed_underscore = self::get_adapter_meta_keys();

		foreach ( $meta as $key => $values ) {
			if ( 0 === strpos( $key, '_' ) && ! in_array( $key, self::$public_keys, true ) && ! in_array( $key, $allowed_underscore, true ) ) {
				continue;
			}
			$clean[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}

		return $clean;
	}

	/**
	 * Collect adapter-declared meta keys.
	 *
	 * @return array
	 */
	public static function get_adapter_meta_keys() {
		$keys = array();
		foreach ( WP_MCP_Adapter_Registry::get_available_adapters() as $adapter ) {
			if ( method_exists( $adapter, 'get_meta_keys' ) ) {
				$keys = array_merge( $keys, $adapter::get_meta_keys() );
			}
		}
		return array_unique( $keys );
	}

	/**
	 * Union field catalogs from all adapters.
	 *
	 * @return array
	 */
	public static function get_unified_catalog() {
		$catalog = array();
		foreach ( WP_MCP_Adapter_Registry::get_available_adapters() as $adapter ) {
			$catalog[ $adapter::slug() ] = array(
				'label'  => $adapter::label(),
				'fields' => $adapter::get_field_catalog(),
			);
		}
		return $catalog;
	}

	/**
	 * Sanitize value by type.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Value.
	 * @return mixed|null
	 */
	public static function sanitize_by_type( $type, $value ) {
		switch ( $type ) {
			case 'text':
				return sanitize_text_field( $value );
			case 'html':
				return wp_kses_post( $value );
			case 'url':
				$url = esc_url_raw( $value );
				return ( $url && 0 !== strpos( strtolower( $url ), 'javascript:' ) ) ? $url : null;
			case 'bool':
				return (bool) $value;
			case 'int':
				return absint( $value );
			case 'float':
				return is_numeric( $value ) ? (float) $value : null;
			case 'array':
				return is_array( $value ) ? $value : null;
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Check if post type is MCP-allowed.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_allowed_post_type( $post_type ) {
		$allowed = get_option( 'wp_mcp_allowed_post_types', array( 'post', 'page', 'product' ) );
		if ( ! is_array( $allowed ) ) {
			$allowed = array( 'post', 'page', 'product' );
		}
		return in_array( $post_type, $allowed, true );
	}

	/**
	 * Get allowed post types list.
	 *
	 * @return array
	 */
	public static function get_allowed_post_types() {
		$allowed = get_option( 'wp_mcp_allowed_post_types', array( 'post', 'page', 'product' ) );
		return is_array( $allowed ) ? $allowed : array( 'post', 'page', 'product' );
	}
}
