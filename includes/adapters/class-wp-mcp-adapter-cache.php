<?php
/**
 * Cache adapter for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_Cache
 */
class WP_MCP_Adapter_Cache extends WP_MCP_Adapter_Base {

	/**
	 * @inheritdoc
	 */
	public static function slug() {
		return 'cache';
	}

	/**
	 * @inheritdoc
	 */
	public static function label() {
		return 'Cache';
	}

	/**
	 * @inheritdoc
	 */
	public static function is_available() {
		return self::detect_plugin() !== '';
	}

	/**
	 * Detect active cache plugin.
	 *
	 * @return string
	 */
	public static function detect_plugin() {
		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' ) ) {
			return 'litespeed';
		}
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			return 'wp-rocket';
		}
		if ( defined( 'W3TC' ) ) {
			return 'w3-total-cache';
		}
		if ( class_exists( 'WpeCommon' ) ) {
			return 'wp-engine';
		}
		return '';
	}

	/**
	 * Purge all caches.
	 *
	 * @return array|WP_Error
	 */
	public static function purge_all() {
		$plugin = self::detect_plugin();
		$result = array( 'plugin' => $plugin, 'purged' => false );

		switch ( $plugin ) {
			case 'litespeed':
				if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
					LiteSpeed_Cache_API::purge_all();
					$result['purged'] = true;
				} elseif ( function_exists( 'litespeed_purge_all' ) ) {
					litespeed_purge_all();
					$result['purged'] = true;
				} else {
					do_action( 'litespeed_purge_all' );
					$result['purged'] = true;
				}
				break;

			case 'wp-rocket':
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
					$result['purged'] = true;
				}
				break;

			case 'w3-total-cache':
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
					$result['purged'] = true;
				}
				break;

			default:
				wp_cache_flush();
				$result['plugin'] = 'object-cache';
				$result['purged'] = true;
		}

		return $result;
	}
}
