<?php
/**
 * Export and sitemap endpoints.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Endpoint_Export
 */
class WP_MCP_Endpoint_Export {

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			'wp-mcp/v1',
			'/export/structure',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'export_structure' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);

		register_rest_route(
			'wp-mcp/v1',
			'/export/sitemap',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'export_sitemap' ),
				'permission_callback' => array( 'WP_MCP_REST', 'permission_callback' ),
			)
		);
	}

	/**
	 * Export site structure.
	 *
	 * @return WP_REST_Response
	 */
	public static function export_structure() {
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		) );

		$page_tree = self::build_page_tree( $pages );

		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
		) );

		$post_items = array();
		foreach ( $posts as $post ) {
			$post_items[] = array(
				'id'    => (int) $post->ID,
				'title' => $post->post_title,
				'slug'  => $post->post_name,
				'url'   => get_permalink( $post->ID ),
			);
		}

		$menus = wp_get_nav_menus();
		$menu_data = array();
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			$menu_data[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'items' => $items ? count( $items ) : 0,
			);
		}

		$categories = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		$tags       = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );

		global $wpdb;
		$redirect_table = $wpdb->prefix . 'wp_mcp_redirects';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$redirects = $wpdb->get_results( "SELECT source_path, target_url, status_code, enabled FROM {$redirect_table}", ARRAY_A );

		return new WP_REST_Response(
			array(
				'site_url'    => get_site_url(),
				'exported_at' => current_time( 'c' ),
				'pages'       => $page_tree,
				'posts'       => $post_items,
				'menus'       => $menu_data,
				'categories'  => is_array( $categories ) ? array_map( function( $t ) {
					return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
				}, $categories ) : array(),
				'tags'        => is_array( $tags ) ? array_map( function( $t ) {
					return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
				}, $tags ) : array(),
				'redirects'   => $redirects ? $redirects : array(),
			),
			200
		);
	}

	/**
	 * Build hierarchical page tree.
	 *
	 * @param array $pages Pages.
	 * @param int   $parent Parent ID.
	 * @return array
	 */
	private static function build_page_tree( $pages, $parent = 0 ) {
		$tree = array();
		foreach ( $pages as $page ) {
			if ( (int) $page->post_parent === $parent ) {
				$tree[] = array(
					'id'       => (int) $page->ID,
					'title'    => $page->post_title,
					'slug'     => $page->post_name,
					'status'   => $page->post_status,
					'url'      => get_permalink( $page->ID ),
					'children' => self::build_page_tree( $pages, (int) $page->ID ),
				);
			}
		}
		return $tree;
	}

	/**
	 * Export sitemap.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function export_sitemap( WP_REST_Request $request ) {
		$params = $request->get_query_params();
		$format = isset( $params['format'] ) ? sanitize_text_field( $params['format'] ) : 'json';

		$posts = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		$urls = array();
		$urls[] = array(
			'loc'        => home_url( '/' ),
			'lastmod'    => current_time( 'c' ),
			'changefreq' => 'daily',
			'priority'   => '1.0',
		);

		foreach ( $posts as $post ) {
			$urls[] = array(
				'loc'        => get_permalink( $post->ID ),
				'lastmod'    => mysql2date( 'c', $post->post_modified_gmt, false ),
				'changefreq' => 'post' === $post->post_type ? 'weekly' : 'monthly',
				'priority'   => 'page' === $post->post_type ? '0.8' : '0.6',
			);
		}

		if ( 'xml' === $format ) {
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
			$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
			foreach ( $urls as $url ) {
				$xml .= '  <url>' . "\n";
				$xml .= '    <loc>' . esc_url( $url['loc'] ) . '</loc>' . "\n";
				$xml .= '    <lastmod>' . esc_html( $url['lastmod'] ) . '</lastmod>' . "\n";
				$xml .= '  </url>' . "\n";
			}
			$xml .= '</urlset>';

			return new WP_REST_Response( array( 'format' => 'xml', 'content' => $xml ), 200 );
		}

		return new WP_REST_Response( array( 'format' => 'json', 'urls' => $urls, 'count' => count( $urls ) ), 200 );
	}
}
