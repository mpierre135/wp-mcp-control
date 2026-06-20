<?php
/**
 * Snapshots for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Snapshots
 */
class WP_MCP_Snapshots {

	/**
	 * Create a snapshot before destructive update.
	 *
	 * @param string   $object_type Object type (page, post, media, etc.).
	 * @param int      $object_id   Object ID.
	 * @param WP_Post|null $post    Post object.
	 * @return int|false Snapshot ID.
	 */
	public static function create_snapshot( $object_type, $object_id, $post = null ) {
		global $wpdb;

		if ( ! $post ) {
			$post = get_post( $object_id );
		}

		if ( ! $post ) {
			return false;
		}

		$meta = get_post_meta( $object_id );
		$clean_meta = array();
		foreach ( $meta as $key => $values ) {
			$clean_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
		}

		$old_data = array(
			'title'           => $post->post_title,
			'content'         => $post->post_content,
			'excerpt'         => $post->post_excerpt,
			'status'          => $post->post_status,
			'slug'            => $post->post_name,
			'parent'          => (int) $post->post_parent,
			'featured_media'  => (int) get_post_thumbnail_id( $object_id ),
			'meta'            => $clean_meta,
			'post_type'       => $post->post_type,
		);

		$table = $wpdb->prefix . 'wp_mcp_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'object_type' => sanitize_text_field( $object_type ),
				'object_id'   => absint( $object_id ),
				'old_data'    => wp_json_encode( $old_data ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Restore a snapshot.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array|WP_Error Restored data.
	 */
	public static function restore( $snapshot_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_mcp_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE snapshot_id = %d",
				$snapshot_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'snapshot_not_found', __( 'Snapshot not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $row['restored_at'] ) ) {
			return new WP_Error( 'snapshot_already_restored', __( 'Snapshot was already restored.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$old_data = json_decode( $row['old_data'], true );
		if ( ! is_array( $old_data ) ) {
			return new WP_Error( 'invalid_snapshot', __( 'Invalid snapshot data.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		$object_id = (int) $row['object_id'];
		$post      = get_post( $object_id );

		if ( ! $post ) {
			return new WP_Error( 'object_not_found', __( 'Original object no longer exists.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( WP_MCP_REST::is_dry_run() ) {
			return array(
				'dry_run'     => true,
				'snapshot_id' => $snapshot_id,
				'object_id'   => $object_id,
				'would_restore' => $old_data,
			);
		}

		$update = array(
			'ID'           => $object_id,
			'post_title'   => $old_data['title'] ?? $post->post_title,
			'post_content' => $old_data['content'] ?? $post->post_content,
			'post_excerpt' => $old_data['excerpt'] ?? $post->post_excerpt,
			'post_status'  => $old_data['status'] ?? $post->post_status,
			'post_name'    => $old_data['slug'] ?? $post->post_name,
			'post_parent'  => $old_data['parent'] ?? $post->post_parent,
		);

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $old_data['featured_media'] ) ) {
			set_post_thumbnail( $object_id, (int) $old_data['featured_media'] );
		}

		if ( ! empty( $old_data['meta'] ) && is_array( $old_data['meta'] ) ) {
			foreach ( $old_data['meta'] as $key => $value ) {
				update_post_meta( $object_id, $key, $value );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'restored_at' => current_time( 'mysql' ) ),
			array( 'snapshot_id' => $snapshot_id ),
			array( '%s' ),
			array( '%d' )
		);

		WP_MCP_Logger::log_action( 'snapshot.restore', $row['object_type'], $object_id, array( 'snapshot_id' => $snapshot_id ), 'success' );

		return array(
			'snapshot_id' => $snapshot_id,
			'object_id'   => $object_id,
			'restored'    => true,
			'data'        => $old_data,
		);
	}

	/**
	 * Get snapshot by ID.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array|null
	 */
	public static function get_snapshot( $snapshot_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wp_mcp_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE snapshot_id = %d",
				$snapshot_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$old_data = json_decode( $row['old_data'], true );

		return array(
			'snapshot_id' => (int) $row['snapshot_id'],
			'object_type' => $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'old_data'    => is_array( $old_data ) ? $old_data : array(),
			'created_at'  => $row['created_at'],
			'restored_at' => $row['restored_at'],
		);
	}
}
