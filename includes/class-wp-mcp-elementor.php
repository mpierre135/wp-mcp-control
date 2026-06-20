<?php
/**
 * Elementor integration for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Elementor
 */
class WP_MCP_Elementor {

	/**
	 * Check if Elementor plugin is active.
	 *
	 * @return bool
	 */
	public static function is_elementor_active() {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Check if a post is built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_elementor_page( $post_id ) {
		$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
		if ( 'builder' === $edit_mode ) {
			return true;
		}

		$data = get_post_meta( $post_id, '_elementor_data', true );
		return ! empty( $data );
	}

	/**
	 * Get raw Elementor data array for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function get_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( ! self::is_elementor_page( $post_id ) ) {
			return new WP_Error(
				'not_elementor',
				__( 'This page is not built with Elementor.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'invalid_elementor_data',
				__( 'Elementor data is corrupted or invalid JSON.', 'wp-mcp-control' ),
				array( 'status' => 500 )
			);
		}

		return $data;
	}

	/**
	 * Get page structure summary.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function get_structure( $post_id ) {
		$data = self::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$post = get_post( $post_id );

		return array(
			'post_id'           => (int) $post_id,
			'title'             => $post->post_title,
			'is_elementor'      => true,
			'elementor_version' => get_post_meta( $post_id, '_elementor_version', true ),
			'edit_mode'         => get_post_meta( $post_id, '_elementor_edit_mode', true ),
			'layout_mode'       => WP_MCP_Elementor_Tree::detect_layout_mode( $data ),
			'element_count'     => count( self::flatten_elements( $data ) ),
			'elements'          => $data,
		);
	}

	/**
	 * List flat element index for a page.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $widget_type Optional filter by widget type.
	 * @return array|WP_Error
	 */
	public static function list_elements( $post_id, $widget_type = '' ) {
		$data = self::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$flat = self::flatten_elements( $data );

		if ( $widget_type ) {
			$flat = array_values(
				array_filter(
					$flat,
					function ( $item ) use ( $widget_type ) {
						return isset( $item['widgetType'] ) && $item['widgetType'] === $widget_type;
					}
				)
			);
		}

		return array(
			'post_id'     => (int) $post_id,
			'widget_type' => $widget_type ? $widget_type : null,
			'count'       => count( $flat ),
			'items'       => $flat,
		);
	}

	/**
	 * Get widget catalog.
	 *
	 * @return array
	 */
	public static function get_widget_catalog() {
		return WP_MCP_Elementor_Catalog::get_public_catalog();
	}

	/**
	 * Flatten element tree into a list with paths.
	 *
	 * @param array  $elements Element tree.
	 * @param string $path     Current path.
	 * @return array
	 */
	public static function flatten_elements( $elements, $path = '' ) {
		$items = array();

		if ( ! is_array( $elements ) ) {
			return $items;
		}

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) || empty( $element['id'] ) ) {
				continue;
			}

			$current_path = $path ? $path . '.' . $index : (string) $index;
			$el_type      = isset( $element['elType'] ) ? $element['elType'] : '';
			$widget_type  = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
			$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			$summary = array(
				'id'          => $element['id'],
				'path'        => $current_path,
				'elType'      => $el_type,
				'widgetType'  => $widget_type,
				'is_editable' => ( 'widget' === $el_type && WP_MCP_Elementor_Catalog::is_editable( $widget_type ) ),
			);

			if ( 'widget' === $el_type && $widget_type ) {
				$preview = WP_MCP_Elementor_Catalog::get_preview_text( $widget_type, $settings );
				if ( $preview ) {
					$summary['text'] = $preview;
				}
			}

			$items[] = $summary;

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$items = array_merge( $items, self::flatten_elements( $element['elements'], $current_path ) );
			}
		}

		return $items;
	}

	/**
	 * Find element reference in tree by ID.
	 *
	 * @param array  $elements   Element tree.
	 * @param string $element_id Element ID.
	 * @return array|null Array with element reference key path or null.
	 */
	public static function find_element_ref( &$elements, $element_id ) {
		if ( ! is_array( $elements ) ) {
			return null;
		}

		foreach ( $elements as $index => &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
				return array(
					'element' => &$element,
					'index'   => $index,
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find_element_ref( $element['elements'], $element_id );
				if ( $found ) {
					return $found;
				}
			}
		}
		unset( $element );

		return null;
	}

	/**
	 * Find first widget matching type and optional text.
	 *
	 * @param array  $elements    Element tree.
	 * @param string $widget_type Widget type.
	 * @param string $match_text  Optional text to match.
	 * @return array|null
	 */
	public static function find_widget( $elements, $widget_type, $match_text = '' ) {
		$flat = self::flatten_elements( $elements );

		foreach ( $flat as $item ) {
			if ( empty( $item['widgetType'] ) || $item['widgetType'] !== $widget_type ) {
				continue;
			}

			if ( $match_text && isset( $item['text'] ) ) {
				if ( false === stripos( $item['text'], $match_text ) ) {
					continue;
				}
			}

			return $item;
		}

		return null;
	}

	/**
	 * Sanitize settings for a widget type via catalog.
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $settings    Settings to sanitize.
	 * @return array|WP_Error
	 */
	public static function sanitize_settings( $widget_type, $settings ) {
		return WP_MCP_Elementor_Catalog::sanitize_widget_settings( $widget_type, $settings );
	}

	/**
	 * Update element settings by element ID.
	 *
	 * @param int             $post_id    Post ID.
	 * @param string          $element_id Element ID.
	 * @param array           $settings   Settings to merge.
	 * @param WP_REST_Request $request    Request for dry-run.
	 * @return array|WP_Error
	 */
	public static function update_element( $post_id, $element_id, $settings, WP_REST_Request $request ) {
		$data = self::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ref = self::find_element_ref( $data, $element_id );
		if ( ! $ref ) {
			return new WP_Error( 'element_not_found', __( 'Element not found in Elementor data.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$element = &$ref['element'];

		if ( ! isset( $element['elType'] ) || 'widget' !== $element['elType'] ) {
			return new WP_Error( 'not_a_widget', __( 'Only widget elements can be updated.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
		$clean       = self::sanitize_settings( $widget_type, $settings );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$old_settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'      => true,
				'post_id'      => (int) $post_id,
				'element_id'   => $element_id,
				'widget_type'  => $widget_type,
				'old_settings' => array_intersect_key( $old_settings, $clean ),
				'new_settings' => $clean,
			);
		}

		WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

		if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			$element['settings'] = array();
		}

		$element['settings'] = array_merge( $element['settings'], $clean );

		$saved = self::save_data( $post_id, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.update_element',
			'elementor',
			$post_id,
			array(
				'element_id'  => $element_id,
				'widget_type' => $widget_type,
				'settings'    => array_keys( $clean ),
			),
			'success'
		);

		return array(
			'post_id'      => (int) $post_id,
			'element_id'   => $element_id,
			'widget_type'  => $widget_type,
			'updated'      => true,
			'new_settings' => $clean,
		);
	}

	/**
	 * Update text by finding a heading or text-editor widget.
	 *
	 * @param int             $post_id     Post ID.
	 * @param string          $widget_type heading|text-editor|raven-heading.
	 * @param string          $new_text    New text content.
	 * @param string          $match_text  Optional text to find widget.
	 * @param WP_REST_Request $request     Request.
	 * @return array|WP_Error
	 */
	public static function update_text( $post_id, $widget_type, $new_text, $match_text, WP_REST_Request $request ) {
		if ( ! WP_MCP_Elementor_Catalog::is_editable( $widget_type ) ) {
			return new WP_Error( 'invalid_widget_type', __( 'Widget type is not editable.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$data = self::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$found = self::find_widget( $data, $widget_type, $match_text );
		if ( ! $found ) {
			return new WP_Error(
				'widget_not_found',
				__( 'No matching widget found. Provide match_text to target a specific element.', 'wp-mcp-control' ),
				array( 'status' => 404 )
			);
		}

		$settings_key = self::get_text_setting_key( $widget_type );
		if ( ! $settings_key ) {
			return new WP_Error( 'invalid_widget_type', __( 'Widget type does not support text updates.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$def   = WP_MCP_Elementor_Catalog::get_definition( $widget_type );
		$field = isset( $def['settings'][ $settings_key ] ) ? $def['settings'][ $settings_key ] : 'text';
		$value = 'html' === $field ? wp_kses_post( $new_text ) : sanitize_text_field( $new_text );

		return self::update_element(
			$post_id,
			$found['id'],
			array( $settings_key => $value ),
			$request
		);
	}

	/**
	 * Get primary text setting key for a widget type.
	 *
	 * @param string $widget_type Widget type.
	 * @return string|null
	 */
	private static function get_text_setting_key( $widget_type ) {
		$map = array(
			'heading'       => 'title',
			'text-editor'   => 'editor',
			'raven-heading' => 'heading_text',
		);

		return isset( $map[ $widget_type ] ) ? $map[ $widget_type ] : null;
	}

	/**
	 * Update button widget by match text.
	 *
	 * @param int             $post_id    Post ID.
	 * @param string          $match_text Button text to find.
	 * @param string          $new_text   New button text.
	 * @param string          $url        Optional new URL.
	 * @param WP_REST_Request $request    Request.
	 * @return array|WP_Error
	 */
	public static function update_button( $post_id, $match_text, $new_text, $url, WP_REST_Request $request ) {
		$data = self::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$button_types = array( 'button', 'raven-button' );
		$found        = null;

		foreach ( $button_types as $type ) {
			$found = self::find_widget( $data, $type, $match_text );
			if ( $found ) {
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'button_not_found', __( 'No matching button found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$widget_type = $found['widgetType'];
		$settings    = array();

		if ( 'raven-button' === $widget_type ) {
			if ( $new_text ) {
				$settings['button_text'] = $new_text;
			}
		} else {
			if ( $new_text ) {
				$settings['text'] = $new_text;
			}
		}

		if ( $url ) {
			$settings['link'] = array( 'url' => $url );
		}

		if ( empty( $settings ) ) {
			return new WP_Error( 'missing_params', __( 'new_text or url is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return self::update_element( $post_id, $found['id'], $settings, $request );
	}

	/**
	 * Update image widget by match URL or element ID.
	 *
	 * @param int             $post_id    Post ID.
	 * @param string          $element_id Optional element ID.
	 * @param string          $match_url  Optional image URL to find.
	 * @param int             $image_id   Optional attachment ID.
	 * @param string          $image_url  Optional image URL.
	 * @param string          $caption    Optional caption.
	 * @param WP_REST_Request $request    Request.
	 * @return array|WP_Error
	 */
	public static function update_image( $post_id, $element_id, $match_url, $image_id, $image_url, $caption, WP_REST_Request $request ) {
		$data = self::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$found_id = $element_id;

		if ( ! $found_id ) {
			$image_types = array( 'image', 'raven-image' );
			$found       = null;

			foreach ( $image_types as $type ) {
				$flat = self::flatten_elements( $data );
				foreach ( $flat as $item ) {
					if ( empty( $item['widgetType'] ) || $item['widgetType'] !== $type ) {
						continue;
					}
					if ( $match_url && isset( $item['text'] ) && false !== stripos( $item['text'], $match_url ) ) {
						$found = $item;
						break 2;
					}
				}
			}

			if ( ! $found ) {
				return new WP_Error( 'image_not_found', __( 'No matching image found. Provide element_id or match_url.', 'wp-mcp-control' ), array( 'status' => 404 ) );
			}

			$found_id = $found['id'];
		}

		$ref = self::find_element_ref( $data, $found_id );
		if ( ! $ref ) {
			return new WP_Error( 'element_not_found', __( 'Element not found in Elementor data.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$widget_type = isset( $ref['element']['widgetType'] ) ? $ref['element']['widgetType'] : '';
		$settings    = array();

		if ( $image_id || $image_url ) {
			$image_value = array();
			if ( $image_id ) {
				$image_value['id'] = $image_id;
			}
			if ( $image_url ) {
				$image_value['url'] = $image_url;
			}
			$settings['image'] = $image_value;
		}

		if ( $caption ) {
			$settings['caption'] = $caption;
		}

		if ( empty( $settings ) ) {
			return new WP_Error( 'missing_params', __( 'image_id, image_url, or caption is required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return self::update_element( $post_id, $found_id, $settings, $request );
	}

	/**
	 * Save Elementor data and regenerate assets.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Element tree.
	 * @return true|WP_Error
	 */
	public static function save_data( $post_id, $data ) {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			return new WP_Error( 'encode_failed', __( 'Failed to encode Elementor data.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		self::regenerate_assets( $post_id );

		return true;
	}

	/**
	 * Clear Elementor CSS cache so changes appear on frontend.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function regenerate_assets( $post_id ) {
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_page_assets' );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;

			if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				$plugin->files_manager->clear_cache();
			}

			if ( isset( $plugin->documents ) ) {
				$document = $plugin->documents->get( $post_id, false );
				if ( $document && method_exists( $document, 'delete_meta' ) ) {
					$document->delete_meta( '_elementor_css' );
				}
			}
		}
	}
}
