<?php
/**
 * Adapter registry for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_Registry
 */
class WP_MCP_Adapter_Registry {

	/**
	 * Registered adapter class names.
	 *
	 * @var array|null
	 */
	private static $adapters = null;

	/**
	 * Load adapter classes.
	 */
	public static function init() {
		$dir = WP_MCP_CONTROL_PLUGIN_DIR . 'includes/adapters/';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . 'class-wp-mcp-adapter-*.php' );
		if ( ! $files ) {
			return;
		}

		foreach ( $files as $file ) {
			require_once $file;
		}

		self::$adapters = array(
			'WP_MCP_Adapter_Cache',
			'WP_MCP_Adapter_ACF',
			'WP_MCP_Adapter_AIOSEO',
			'WP_MCP_Adapter_WooCommerce',
			'WP_MCP_Adapter_Ninja_Forms',
		);
	}

	/**
	 * Get available (active) adapters.
	 *
	 * @return array Class names.
	 */
	public static function get_available_adapters() {
		if ( null === self::$adapters ) {
			self::init();
		}

		$available = array();
		foreach ( self::$adapters as $class ) {
			if ( class_exists( $class ) && $class::is_available() ) {
				$available[] = $class;
			}
		}
		return $available;
	}

	/**
	 * Check if adapter slug is available.
	 *
	 * @param string $slug Adapter slug.
	 * @return bool
	 */
	public static function adapter_available( $slug ) {
		foreach ( self::get_available_adapters() as $adapter ) {
			if ( $adapter::slug() === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get adapter by slug.
	 *
	 * @param string $slug Adapter slug.
	 * @return string|null Class name.
	 */
	public static function get_adapter( $slug ) {
		foreach ( self::get_available_adapters() as $adapter ) {
			if ( $adapter::slug() === $slug ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Public adapter summary for blueprint.
	 *
	 * @return array
	 */
	public static function get_adapter_summary() {
		$summary = array();
		foreach ( self::get_available_adapters() as $adapter ) {
			$summary[] = array(
				'slug'  => $adapter::slug(),
				'label' => $adapter::label(),
			);
		}
		return $summary;
	}
}
