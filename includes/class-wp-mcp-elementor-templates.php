<?php
/**
 * Elementor template / page duplication for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Elementor_Templates
 */
class WP_MCP_Elementor_Templates {

	/**
	 * Elementor meta keys to copy (excluding _elementor_data — saved via decode/encode pipeline).
	 *
	 * @var array
	 */
	private static $meta_keys = array(
		'_elementor_edit_mode',
		'_elementor_version',
		'_elementor_template_type',
		'_elementor_page_settings',
		'_elementor_page_assets',
		'_wp_page_template',
	);

	/**
	 * Duplicate an Elementor page.
	 *
	 * @param int             $source_id Source page ID.
	 * @param string          $title     New title.
	 * @param string          $slug      New slug.
	 * @param string          $status    Post status.
	 * @param WP_REST_Request $request   Request.
	 * @return array|WP_Error
	 */
	public static function duplicate_page( $source_id, $title, $slug, $status, WP_REST_Request $request ) {
		$confirm_check = WP_MCP_Elementor_Tree::check_structural_confirm( $request );
		if ( is_wp_error( $confirm_check ) ) {
			return $confirm_check;
		}

		$source = get_post( $source_id );
		if ( ! $source || 'page' !== $source->post_type ) {
			return new WP_Error( 'not_found', __( 'Source page not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( ! WP_MCP_Elementor::is_elementor_page( $source_id ) ) {
			return new WP_Error(
				'not_elementor',
				__( 'Source page is not built with Elementor.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		$new_title  = $title ? sanitize_text_field( $title ) : $source->post_title . ' (Copy)';
		$new_slug   = $slug ? sanitize_title( $slug ) : '';
		$new_status = $status ? sanitize_text_field( $status ) : 'draft';

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			$new_status = 'draft';
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'   => true,
				'source_id' => (int) $source_id,
				'title'     => $new_title,
				'slug'      => $new_slug,
				'status'    => $new_status,
				'action'    => 'duplicate_page',
			);
		}

		$source_data = WP_MCP_Elementor::get_data( $source_id );
		if ( is_wp_error( $source_data ) ) {
			return $source_data;
		}

		$post_data = array(
			'post_type'    => 'page',
			'post_title'   => $new_title,
			'post_status'  => $new_status,
			'post_content' => $source->post_content,
			'post_excerpt' => $source->post_excerpt,
		);

		if ( $new_slug ) {
			$post_data['post_name'] = $new_slug;
		}

		$new_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		self::copy_elementor_meta( $source_id, $new_id );

		$cloned = WP_MCP_Elementor_Tree::deep_clone_tree( $source_data );
		$saved  = WP_MCP_Elementor::save_data( $new_id, $cloned );
		if ( is_wp_error( $saved ) ) {
			wp_delete_post( $new_id, true );
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.duplicate_page',
			'page',
			$new_id,
			array(
				'source_id' => $source_id,
				'title'     => $new_title,
			),
			'success'
		);

		return array(
			'source_id'  => (int) $source_id,
			'post_id'    => (int) $new_id,
			'title'      => $new_title,
			'status'     => $new_status,
			'url'        => get_permalink( $new_id ),
			'duplicated' => true,
		);
	}

	/**
	 * Create a blank Elementor page with header/footer template only.
	 *
	 * @param string          $title    Page title.
	 * @param string          $slug     Optional slug.
	 * @param string          $status   Post status.
	 * @param string          $template Page template slug.
	 * @param WP_REST_Request $request  Request.
	 * @return array|WP_Error
	 */
	public static function create_blank_page( $title, $slug, $status, $template, WP_REST_Request $request ) {
		$confirm_check = WP_MCP_Elementor_Tree::check_structural_confirm( $request );
		if ( is_wp_error( $confirm_check ) ) {
			return $confirm_check;
		}

		if ( ! WP_MCP_Elementor::is_elementor_active() ) {
			return new WP_Error( 'elementor_inactive', __( 'Elementor plugin is not active.', 'wp-mcp-control' ), array( 'status' => 503 ) );
		}

		$new_title  = sanitize_text_field( $title );
		$new_slug   = $slug ? sanitize_title( $slug ) : '';
		$new_status = $status ? sanitize_text_field( $status ) : 'draft';
		$template   = $template ? sanitize_text_field( $template ) : 'elementor_header_footer';

		if ( '' === $new_title ) {
			return new WP_Error( 'missing_title', __( 'title is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$allowed_statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			$new_status = 'draft';
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'  => true,
				'title'    => $new_title,
				'slug'     => $new_slug,
				'status'   => $new_status,
				'template' => $template,
				'action'   => 'create_blank_page',
			);
		}

		$post_data = array(
			'post_type'   => 'page',
			'post_title'  => $new_title,
			'post_status' => $new_status,
		);

		if ( $new_slug ) {
			$post_data['post_name'] = $new_slug;
		}

		$new_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		update_post_meta( $new_id, '_wp_page_template', $template );
		update_post_meta( $new_id, '_elementor_edit_mode', 'builder' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $new_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		$saved = WP_MCP_Elementor::save_data( $new_id, array() );
		if ( is_wp_error( $saved ) ) {
			wp_delete_post( $new_id, true );
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.create_blank_page',
			'page',
			$new_id,
			array(
				'title'    => $new_title,
				'template' => $template,
			),
			'success'
		);

		return array(
			'post_id'  => (int) $new_id,
			'title'    => $new_title,
			'status'   => $new_status,
			'template' => $template,
			'url'      => get_permalink( $new_id ),
			'created'  => true,
		);
	}

	/**
	 * Copy Elementor meta from one post to another (excluding _elementor_data).
	 *
	 * @param int $from_id Source post ID.
	 * @param int $to_id   Target post ID.
	 */
	public static function copy_elementor_meta( $from_id, $to_id ) {
		foreach ( self::$meta_keys as $key ) {
			$value = get_post_meta( $from_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				update_post_meta( $to_id, $key, $value );
			}
		}
	}
}
