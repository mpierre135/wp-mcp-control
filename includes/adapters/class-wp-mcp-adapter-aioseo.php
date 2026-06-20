<?php
/**
 * All in One SEO adapter for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Adapter_AIOSEO
 */
class WP_MCP_Adapter_AIOSEO extends WP_MCP_Adapter_Base {

	/**
	 * @inheritdoc
	 */
	public static function slug() {
		return 'aioseo';
	}

	/**
	 * @inheritdoc
	 */
	public static function label() {
		return 'All in One SEO';
	}

	/**
	 * @inheritdoc
	 */
	public static function is_available() {
		return defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\\Plugin\\AIOSEO' );
	}

	/**
	 * @inheritdoc
	 */
	public static function get_field_catalog() {
		$file = WP_MCP_CONTROL_PLUGIN_DIR . 'includes/seo-fields.json';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$data = json_decode( file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get SEO data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_post_seo( $post_id ) {
		$data = array();

		if ( class_exists( 'AIOSEO\\Plugin\\Common\\Models\\Post' ) ) {
			$aioseo = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
			if ( $aioseo ) {
				$data = array(
					'title'            => $aioseo->title ?? '',
					'description'      => $aioseo->description ?? '',
					'og_title'         => $aioseo->og_title ?? '',
					'og_description'   => $aioseo->og_description ?? '',
					'og_image'         => $aioseo->og_image_url ?? '',
					'canonical_url'    => $aioseo->canonical_url ?? '',
					'robots_noindex'   => (bool) ( $aioseo->robots_noindex ?? false ),
					'robots_nofollow'  => (bool) ( $aioseo->robots_nofollow ?? false ),
				);
			}
		} else {
			$keys = array(
				'title'           => '_aioseo_title',
				'description'     => '_aioseo_description',
				'og_title'        => '_aioseo_og_title',
				'og_description'  => '_aioseo_og_description',
				'og_image'        => '_aioseo_og_image',
				'canonical_url'   => '_aioseo_canonical_url',
			);
			foreach ( $keys as $field => $meta_key ) {
				$val = get_post_meta( $post_id, $meta_key, true );
				if ( $val ) {
					$data[ $field ] = $val;
				}
			}
		}

		return $data;
	}

	/**
	 * Update SEO for a post.
	 *
	 * @param int             $post_id Post ID.
	 * @param array           $fields  SEO fields.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function update_post_seo( $post_id, $fields, WP_REST_Request $request ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'aioseo_inactive', __( 'All in One SEO is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$clean = self::sanitize_fields( $fields );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array( 'dry_run' => true, 'post_id' => $post_id, 'fields' => $clean );
		}

		WP_MCP_Snapshots::create_snapshot( 'seo', $post_id, get_post( $post_id ) );

		if ( class_exists( 'AIOSEO\\Plugin\\Common\\Models\\Post' ) ) {
			$aioseo = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
			if ( ! $aioseo ) {
				$aioseo = new \AIOSEO\Plugin\Common\Models\Post();
				$aioseo->post_id = $post_id;
			}
			$map = array(
				'title'           => 'title',
				'description'     => 'description',
				'og_title'        => 'og_title',
				'og_description'  => 'og_description',
				'og_image'        => 'og_image_url',
				'canonical_url'   => 'canonical_url',
				'robots_noindex'  => 'robots_noindex',
				'robots_nofollow' => 'robots_nofollow',
			);
			foreach ( $clean as $key => $value ) {
				if ( isset( $map[ $key ] ) ) {
					$prop = $map[ $key ];
					$aioseo->$prop = $value;
				}
			}
			$aioseo->save();
		} else {
			$meta_map = array(
				'title'          => '_aioseo_title',
				'description'    => '_aioseo_description',
				'og_title'       => '_aioseo_og_title',
				'og_description' => '_aioseo_og_description',
				'og_image'       => '_aioseo_og_image',
				'canonical_url'  => '_aioseo_canonical_url',
			);
			foreach ( $clean as $key => $value ) {
				if ( isset( $meta_map[ $key ] ) ) {
					update_post_meta( $post_id, $meta_map[ $key ], $value );
				}
			}
		}

		WP_MCP_Logger::log_action( 'seo.update', 'seo', $post_id, array( 'fields' => array_keys( $clean ) ), 'success' );

		WP_MCP_Webhooks::fire_mcp_event( 'seo.updated', array( 'post_id' => $post_id, 'fields' => array_keys( $clean ) ), (string) $post_id );

		return array( 'post_id' => $post_id, 'updated' => true, 'fields' => $clean );
	}

	/**
	 * Audit pages for missing SEO.
	 *
	 * @return array
	 */
	public static function audit() {
		$issues = array();
		$pages  = get_posts( array(
			'post_type'      => array( 'page', 'post', 'product' ),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );

		$titles = array();

		foreach ( $pages as $post_id ) {
			$seo = self::get_post_seo( $post_id );
			$post = get_post( $post_id );

			if ( empty( $seo['description'] ) ) {
				$issues[] = array(
					'type'    => 'missing_description',
					'post_id' => $post_id,
					'title'   => $post->post_title,
				);
			}

			$seo_title = ! empty( $seo['title'] ) ? $seo['title'] : $post->post_title;
			if ( isset( $titles[ $seo_title ] ) ) {
				$issues[] = array(
					'type'    => 'duplicate_title',
					'post_id' => $post_id,
					'title'   => $seo_title,
				);
			}
			$titles[ $seo_title ] = $post_id;
		}

		return array( 'count' => count( $issues ), 'issues' => $issues );
	}
}
