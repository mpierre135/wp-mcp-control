<?php
/**
 * Audit, blueprint, and cache endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Audit
 */
class WP_MCP_Endpoint_Audit {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/blueprint',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'blueprint' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'site_audit' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/audit/security',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'security_posture' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/cache/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'purge_cache' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		if ( is_multisite() ) {
			register_rest_route(
				'wp-mcp/v1',
				'/multisite/sites',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'multisite_sites' ),
					'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
				)
			);
		}

		register_rest_route(
			'wp-mcp/v1',
			'/i18n/posts/(?P<id>\d+)/translations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'i18n_translations' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Site blueprint for agent discovery.
	 *
	 * @return WP_REST_Response
	 */
	public static function blueprint() {
		WP_MCP_Adapter_Registry::init();

		$elementor_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_elementor_edit_mode',
						'value'   => 'builder',
						'compare' => '=',
					),
				),
			)
		);

		$menus = wp_get_nav_menus();
		$menu_summary = array();
		foreach ( $menus as $menu ) {
			$menu_summary[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'count' => (int) $menu->count,
			);
		}

		$theme = wp_get_theme();

		return new WP_REST_Response(
			array(
				'site_name'         => get_bloginfo( 'name' ),
				'home_url'          => get_home_url(),
				'homepage_id'       => (int) get_option( 'page_on_front' ),
				'posts_page_id'     => (int) get_option( 'page_for_posts' ),
				'active_theme'      => array(
					'name' => $theme->get( 'Name' ),
					'slug' => $theme->get_stylesheet(),
				),
				'menus'             => $menu_summary,
				'adapters'          => WP_MCP_Adapter_Registry::get_adapter_summary(),
				'elementor'         => array(
					'active'      => WP_MCP_Elementor::is_elementor_active(),
					'page_count'  => count( $elementor_pages ),
				),
				'woocommerce'       => array(
					'active' => class_exists( 'WooCommerce' ),
				),
				'cache'             => array(
					'plugin' => WP_MCP_Adapter_Cache::detect_plugin(),
				),
				'allowed_post_types'=> WP_MCP_Meta::get_allowed_post_types(),
				'plugin_version'    => WP_MCP_CONTROL_VERSION,
			),
			200
		);
	}

	/**
	 * Site content audit.
	 *
	 * @return WP_REST_Response
	 */
	public static function site_audit() {
		$issues = array();

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		);

		foreach ( $pages as $page ) {
			$content = trim( wp_strip_all_tags( $page->post_content ) );
			if ( strlen( $content ) < 50 && ! WP_MCP_Elementor::is_elementor_page( $page->ID ) ) {
				$issues[] = array(
					'type'    => 'empty_or_short_content',
					'post_id' => $page->ID,
					'title'   => $page->post_title,
				);
			}
		}

		if ( class_exists( 'WP_MCP_Adapter_AIOSEO' ) && WP_MCP_Adapter_AIOSEO::is_available() ) {
			$seo_audit = WP_MCP_Adapter_AIOSEO::audit();
			foreach ( $seo_audit['issues'] as $issue ) {
				$issues[] = $issue;
			}
		}

		$drafts = wp_count_posts( 'page' );
		if ( isset( $drafts->draft ) && $drafts->draft > 10 ) {
			$issues[] = array(
				'type'  => 'draft_backlog',
				'count' => (int) $drafts->draft,
			);
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $attachments as $attachment ) {
			$issues[] = array(
				'type'    => 'missing_alt_text',
				'post_id' => $attachment->ID,
				'title'   => $attachment->post_title,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wp_mcp_redirects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$redirects = $wpdb->get_results( "SELECT source_path, target_url FROM {$table} WHERE enabled = 1" );
		$paths = array();
		foreach ( $redirects as $row ) {
			if ( isset( $paths[ $row->target_url ] ) && $paths[ $row->target_url ] === $row->source_path ) {
				$issues[] = array(
					'type'        => 'redirect_loop',
					'source_path' => $row->source_path,
					'target_url'  => $row->target_url,
				);
			}
			$paths[ $row->source_path ] = $row->target_url;
		}

		return new WP_REST_Response(
			array(
				'count'  => count( $issues ),
				'issues' => $issues,
			),
			200
		);
	}

	/**
	 * Security posture summary.
	 *
	 * @return WP_REST_Response
	 */
	public static function security_posture() {
		$token_created = get_option( 'wp_mcp_token_created_at', '' );
		$token_age_days = null;

		if ( $token_created ) {
			$created_ts = strtotime( $token_created );
			if ( $created_ts ) {
				$token_age_days = (int) floor( ( time() - $created_ts ) / DAY_IN_SECONDS );
			}
		}

		return new WP_REST_Response(
			array(
				'safe_mode'              => (bool) get_option( 'wp_mcp_safe_mode', true ),
				'dry_run'                => (bool) get_option( 'wp_mcp_dry_run', false ),
				'allow_force_delete'     => (bool) get_option( 'wp_mcp_allow_force_delete', false ),
				'allow_wc_refunds'       => (bool) get_option( 'wp_mcp_allow_wc_refunds', false ),
				'rate_limit'             => (int) get_option( 'wp_mcp_rate_limit', 60 ),
				'ip_allowlist'           => get_option( 'wp_mcp_ip_allowlist', array() ),
				'ip_allowlist_enabled'   => ! empty( get_option( 'wp_mcp_ip_allowlist', array() ) ),
				'token_created_at'       => $token_created,
				'token_age_days'         => $token_age_days,
				'cors_origins'           => get_option( 'wp_mcp_cors_origins', array() ),
				'plugin_version'         => WP_MCP_CONTROL_VERSION,
			),
			200
		);
	}

	/**
	 * Purge site cache.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function purge_cache( WP_REST_Request $request ) {
		$confirm = WP_MCP_Safe_Mode::confirm_from_request( $request );
		$check   = WP_MCP_Safe_Mode::check_confirm( 'purge_cache', $request, $confirm );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return new WP_REST_Response(
				array(
					'dry_run' => true,
					'plugin'  => WP_MCP_Adapter_Cache::detect_plugin(),
				),
				200
			);
		}

		$result = WP_MCP_Adapter_Cache::purge_all();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_MCP_Logger::log_action( 'cache.purge', 'cache', 0, $result, 'success' );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * List multisite sites.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function multisite_sites() {
		if ( ! is_multisite() ) {
			return new WP_Error( 'not_multisite', __( 'Not a multisite installation.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$sites = get_sites( array( 'number' => 100 ) );
		$list  = array();

		foreach ( $sites as $site ) {
			$list[] = array(
				'blog_id' => (int) $site->blog_id,
				'domain'  => $site->domain,
				'path'    => $site->path,
				'url'     => get_home_url( $site->blog_id ),
			);
		}

		return new WP_REST_Response( array( 'count' => count( $list ), 'sites' => $list ), 200 );
	}

	/**
	 * Get post translations (WPML/Polylang).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function i18n_translations( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$translations = array();

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$map = pll_get_post_translations( $post_id );
			if ( is_array( $map ) ) {
				foreach ( $map as $lang => $id ) {
					$translations[] = array( 'language' => $lang, 'post_id' => (int) $id );
				}
			}
		} elseif ( function_exists( 'wpml_get_language_information' ) ) {
			$info = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $info ) ) {
				$translations[] = array(
					'language' => $info['language_code'] ?? '',
					'post_id'  => $post_id,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'post_id'      => $post_id,
				'plugin'       => function_exists( 'pll_get_post_translations' ) ? 'polylang' : ( defined( 'ICL_SITEPRESS_VERSION' ) ? 'wpml' : 'none' ),
				'translations' => $translations,
			),
			200
		);
	}
}
