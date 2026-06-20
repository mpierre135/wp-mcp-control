<?php
/**
 * Elementor tree operations for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Elementor_Tree
 */
class WP_MCP_Elementor_Tree {

	/**
	 * Generate Elementor-style element ID.
	 *
	 * @return string
	 */
	public static function generate_element_id() {
		return WP_MCP_Elementor_Catalog::generate_id();
	}

	/**
	 * Detect layout mode from tree.
	 *
	 * @param array $tree Element tree.
	 * @return string section-column|container
	 */
	public static function detect_layout_mode( $tree ) {
		if ( ! is_array( $tree ) ) {
			return 'section-column';
		}

		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) || empty( $element['elType'] ) ) {
				continue;
			}
			if ( 'container' === $element['elType'] ) {
				return 'container';
			}
			if ( 'section' === $element['elType'] ) {
				return 'section-column';
			}
		}

		return 'section-column';
	}

	/**
	 * Find parent reference for an element.
	 *
	 * @param array  $tree       Element tree.
	 * @param string $element_id Child element ID.
	 * @param mixed  $parent     Parent reference.
	 * @return array|null
	 */
	public static function find_parent_ref( &$tree, $element_id, &$parent = null ) {
		if ( ! is_array( $tree ) ) {
			return null;
		}

		foreach ( $tree as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				foreach ( $element['elements'] as $child ) {
					if ( is_array( $child ) && isset( $child['id'] ) && $child['id'] === $element_id ) {
						return array(
							'parent' => &$element,
						);
					}
				}

				$found = self::find_parent_ref( $element['elements'], $element_id, $element );
				if ( $found ) {
					return $found;
				}
			}
		}
		unset( $element );

		return null;
	}

	/**
	 * Check structural write confirmation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_structural_confirm( WP_REST_Request $request ) {
		if ( ! WP_MCP_Safe_Mode::is_active( $request ) ) {
			return true;
		}

		$params  = $request->get_json_params();
		$confirm = false;
		if ( is_array( $params ) && isset( $params['confirm'] ) ) {
			$confirm = WP_MCP_Safe_Mode::parse_bool( $params['confirm'] );
		}

		if ( ! $confirm ) {
			return new WP_Error(
				'confirm_required',
				__( 'Structural Elementor changes require confirm=true when safe mode is enabled.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Insert widget into parent column/container.
	 *
	 * @param int             $post_id     Post ID.
	 * @param string          $parent_id   Parent element ID.
	 * @param string          $widget_type Widget type.
	 * @param array           $settings    Widget settings.
	 * @param WP_REST_Request $request     Request.
	 * @return array|WP_Error
	 */
	public static function insert_widget( $post_id, $parent_id, $widget_type, $settings, WP_REST_Request $request ) {
		$confirm_check = self::check_structural_confirm( $request );
		if ( is_wp_error( $confirm_check ) ) {
			return $confirm_check;
		}

		$data = WP_MCP_Elementor::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$parent_ref = WP_MCP_Elementor::find_element_ref( $data, $parent_id );
		if ( ! $parent_ref ) {
			return new WP_Error( 'parent_not_found', __( 'Parent element not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$parent = &$parent_ref['element'];
		$el_type = isset( $parent['elType'] ) ? $parent['elType'] : '';

		if ( ! in_array( $el_type, array( 'column', 'container' ), true ) ) {
			return new WP_Error(
				'invalid_parent',
				__( 'Widgets can only be inserted into column or container elements.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $settings ) ) {
			$clean = WP_MCP_Elementor_Catalog::default_settings( $widget_type );
		} else {
			$clean = WP_MCP_Elementor_Catalog::sanitize_widget_settings( $widget_type, $settings );
		}

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$new_id   = self::generate_element_id();
		$new_widget = array(
			'id'         => $new_id,
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $clean,
			'elements'   => array(),
		);

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'    => true,
				'post_id'    => (int) $post_id,
				'parent_id'  => $parent_id,
				'element_id' => $new_id,
				'widget'     => $new_widget,
				'action'     => 'insert_widget',
			);
		}

		WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

		if ( ! isset( $parent['elements'] ) || ! is_array( $parent['elements'] ) ) {
			$parent['elements'] = array();
		}
		$parent['elements'][] = $new_widget;

		$saved = WP_MCP_Elementor::save_data( $post_id, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.insert_widget',
			'elementor',
			$post_id,
			array(
				'parent_id'   => $parent_id,
				'element_id'  => $new_id,
				'widget_type' => $widget_type,
			),
			'success'
		);

		return array(
			'post_id'     => (int) $post_id,
			'parent_id'   => $parent_id,
			'element_id'  => $new_id,
			'widget_type' => $widget_type,
			'inserted'    => true,
		);
	}

	/**
	 * Insert section scaffold with widgets.
	 *
	 * @param int             $post_id   Post ID.
	 * @param string          $position  start|end.
	 * @param array           $children  Widget definitions.
	 * @param WP_REST_Request $request   Request.
	 * @return array|WP_Error
	 */
	public static function insert_section( $post_id, $position, $children, WP_REST_Request $request ) {
		$confirm_check = self::check_structural_confirm( $request );
		if ( is_wp_error( $confirm_check ) ) {
			return $confirm_check;
		}

		$data = WP_MCP_Elementor::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$mode    = self::detect_layout_mode( $data );
		$scaffold = self::build_scaffold( $mode, $children );

		if ( is_wp_error( $scaffold ) ) {
			return $scaffold;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'  => true,
				'post_id'  => (int) $post_id,
				'position' => $position,
				'mode'     => $mode,
				'scaffold' => $scaffold,
				'action'   => 'insert_section',
			);
		}

		WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

		if ( 'start' === $position ) {
			array_unshift( $data, $scaffold );
		} else {
			$data[] = $scaffold;
		}

		$saved = WP_MCP_Elementor::save_data( $post_id, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.insert_section',
			'elementor',
			$post_id,
			array(
				'position'    => $position,
				'layout_mode' => $mode,
				'section_id'  => $scaffold['id'],
			),
			'success'
		);

		return array(
			'post_id'    => (int) $post_id,
			'section_id' => $scaffold['id'],
			'layout_mode' => $mode,
			'inserted'   => true,
		);
	}

	/**
	 * Build section or container scaffold with widgets.
	 *
	 * @param string $mode     Layout mode.
	 * @param array  $children Widget definitions.
	 * @return array|WP_Error
	 */
	public static function build_scaffold( $mode, $children ) {
		$widgets = array();

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) || empty( $child['widget_type'] ) ) {
				continue;
			}

			$widget_type = sanitize_text_field( $child['widget_type'] );
			$settings    = isset( $child['settings'] ) && is_array( $child['settings'] ) ? $child['settings'] : array();

			if ( empty( $settings ) ) {
				$clean = WP_MCP_Elementor_Catalog::default_settings( $widget_type );
			} else {
				$clean = WP_MCP_Elementor_Catalog::sanitize_widget_settings( $widget_type, $settings );
			}

			if ( is_wp_error( $clean ) ) {
				return $clean;
			}

			$widgets[] = array(
				'id'         => self::generate_element_id(),
				'elType'     => 'widget',
				'widgetType' => $widget_type,
				'settings'   => $clean,
				'elements'   => array(),
			);
		}

		if ( empty( $widgets ) ) {
			return new WP_Error( 'no_widgets', __( 'At least one widget is required for section scaffold.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		if ( 'container' === $mode ) {
			return array(
				'id'       => self::generate_element_id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
				),
				'elements' => $widgets,
			);
		}

		$column_id  = self::generate_element_id();
		$section_id = self::generate_element_id();

		return array(
			'id'       => $section_id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $widgets,
				),
			),
		);
	}

	/**
	 * Remove element from tree.
	 *
	 * @param int             $post_id    Post ID.
	 * @param string          $element_id Element ID.
	 * @param bool            $confirm    Confirm flag.
	 * @param WP_REST_Request $request    Request.
	 * @return array|WP_Error
	 */
	public static function remove_element( $post_id, $element_id, $confirm, WP_REST_Request $request ) {
		if ( WP_MCP_Safe_Mode::is_active( $request ) && ! $confirm ) {
			return new WP_Error(
				'confirm_required',
				__( 'Removing elements requires confirm=true when safe mode is enabled.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		$data = WP_MCP_Elementor::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ref = WP_MCP_Elementor::find_element_ref( $data, $element_id );
		if ( ! $ref ) {
			return new WP_Error( 'element_not_found', __( 'Element not found in Elementor data.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$removed = $ref['element'];

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'    => true,
				'post_id'    => (int) $post_id,
				'element_id' => $element_id,
				'removed'    => array(
					'id'         => $removed['id'] ?? $element_id,
					'elType'     => $removed['elType'] ?? '',
					'widgetType' => $removed['widgetType'] ?? '',
				),
				'action'     => 'remove_element',
			);
		}

		WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

		$deleted = self::remove_from_tree( $data, $element_id );
		if ( ! $deleted ) {
			return new WP_Error( 'remove_failed', __( 'Failed to remove element from tree.', 'wp-mcp-control' ), array( 'status' => 500 ) );
		}

		$saved = WP_MCP_Elementor::save_data( $post_id, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.remove_element',
			'elementor',
			$post_id,
			array( 'element_id' => $element_id ),
			'success'
		);

		return array(
			'post_id'    => (int) $post_id,
			'element_id' => $element_id,
			'removed'    => true,
		);
	}

	/**
	 * Remove element from tree by ID.
	 *
	 * @param array  $tree       Element tree.
	 * @param string $element_id Element ID.
	 * @return bool
	 */
	public static function remove_from_tree( &$tree, $element_id ) {
		if ( ! is_array( $tree ) ) {
			return false;
		}

		foreach ( $tree as $index => &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
				unset( $tree[ $index ] );
				$tree = array_values( $tree );
				return true;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				if ( self::remove_from_tree( $element['elements'], $element_id ) ) {
					return true;
				}
			}
		}
		unset( $element );

		return false;
	}

	/**
	 * Clone element subtree with new IDs.
	 *
	 * @param int             $post_id    Post ID.
	 * @param string          $element_id Element ID.
	 * @param WP_REST_Request $request    Request.
	 * @return array|WP_Error
	 */
	public static function clone_element( $post_id, $element_id, WP_REST_Request $request ) {
		$confirm_check = self::check_structural_confirm( $request );
		if ( is_wp_error( $confirm_check ) ) {
			return $confirm_check;
		}

		$data = WP_MCP_Elementor::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$ref = WP_MCP_Elementor::find_element_ref( $data, $element_id );
		if ( ! $ref ) {
			return new WP_Error( 'element_not_found', __( 'Element not found in Elementor data.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$source   = $ref['element'];
		$clone    = self::deep_clone_element( $source );
		$clone_id = $clone['id'];

		$parent_ref = self::find_parent_ref( $data, $element_id );
		if ( ! $parent_ref ) {
			// Top-level element — append after source.
			if ( WP_MCP_REST::is_dry_run( $request ) ) {
				return array(
					'dry_run'       => true,
					'post_id'       => (int) $post_id,
					'source_id'     => $element_id,
					'clone_id'      => $clone_id,
					'action'        => 'clone_element',
				);
			}

			WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

			foreach ( $data as $index => $element ) {
				if ( is_array( $element ) && isset( $element['id'] ) && $element['id'] === $element_id ) {
					array_splice( $data, $index + 1, 0, array( $clone ) );
					break;
				}
			}
		} else {
			$parent = &$parent_ref['parent'];

			if ( WP_MCP_REST::is_dry_run( $request ) ) {
				return array(
					'dry_run'   => true,
					'post_id'   => (int) $post_id,
					'source_id' => $element_id,
					'clone_id'  => $clone_id,
					'action'    => 'clone_element',
				);
			}

			WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

			if ( ! isset( $parent['elements'] ) || ! is_array( $parent['elements'] ) ) {
				$parent['elements'] = array();
			}

			foreach ( $parent['elements'] as $index => $child ) {
				if ( is_array( $child ) && isset( $child['id'] ) && $child['id'] === $element_id ) {
					array_splice( $parent['elements'], $index + 1, 0, array( $clone ) );
					break;
				}
			}
		}

		$saved = WP_MCP_Elementor::save_data( $post_id, $data );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.clone_element',
			'elementor',
			$post_id,
			array(
				'source_id' => $element_id,
				'clone_id'  => $clone_id,
			),
			'success'
		);

		return array(
			'post_id'   => (int) $post_id,
			'source_id' => $element_id,
			'clone_id'  => $clone_id,
			'cloned'    => true,
		);
	}

	/**
	 * Deep clone element with remapped IDs.
	 *
	 * @param array $element Source element.
	 * @return array
	 */
	public static function deep_clone_element( $element ) {
		$clone = $element;
		$clone['id'] = self::generate_element_id();

		if ( ! empty( $clone['elements'] ) && is_array( $clone['elements'] ) ) {
			$clone['elements'] = array_map( array( __CLASS__, 'deep_clone_element' ), $clone['elements'] );
		}

		return $clone;
	}

	/**
	 * Remap all element IDs in tree.
	 *
	 * @param array $tree Element tree.
	 * @return array
	 */
	public static function remap_element_ids( $tree ) {
		if ( ! is_array( $tree ) ) {
			return array();
		}

		$result = array();
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element['id'] = self::generate_element_id();
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::remap_settings_ids( $element['settings'] );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::remap_element_ids( $element['elements'] );
			}
			$result[] = $element;
		}

		return $result;
	}

	/**
	 * Remap nested _id fields inside settings repeaters.
	 *
	 * @param array $settings Settings array.
	 * @return array
	 */
	public static function remap_settings_ids( $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$settings[ $key ] = self::remap_repeater_ids( $value );
			}
		}

		return $settings;
	}

	/**
	 * Remap _id keys inside repeater item arrays.
	 *
	 * @param array $items Repeater items.
	 * @return array
	 */
	private static function remap_repeater_ids( $items ) {
		$is_list = array_keys( $items ) === range( 0, count( $items ) - 1 );

		if ( $is_list ) {
			$remapped = array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					$remapped[] = $item;
					continue;
				}
				if ( isset( $item['_id'] ) ) {
					$item['_id'] = self::generate_element_id();
				}
				foreach ( $item as $sub_key => $sub_value ) {
					if ( is_array( $sub_value ) ) {
						$item[ $sub_key ] = self::remap_repeater_ids( $sub_value );
					}
				}
				$remapped[] = $item;
			}
			return $remapped;
		}

		foreach ( $items as $sub_key => $sub_value ) {
			if ( '_id' === $sub_key && is_string( $sub_value ) ) {
				$items[ $sub_key ] = self::generate_element_id();
			} elseif ( is_array( $sub_value ) ) {
				$items[ $sub_key ] = self::remap_repeater_ids( $sub_value );
			}
		}

		return $items;
	}

	/**
	 * Deep-clone an element tree with fresh structural and repeater IDs.
	 *
	 * @param array $tree Source tree.
	 * @return array
	 */
	public static function deep_clone_tree( $tree ) {
		$json = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return self::remap_element_ids( self::deep_clone_elements( $tree ) );
		}

		$cloned = json_decode( $json, true );
		if ( ! is_array( $cloned ) ) {
			return self::remap_element_ids( self::deep_clone_elements( $tree ) );
		}

		return self::remap_element_ids( $cloned );
	}

	/**
	 * Deep-clone elements preserving settings values.
	 *
	 * @param array $elements Source elements.
	 * @return array
	 */
	private static function deep_clone_elements( $elements ) {
		if ( ! is_array( $elements ) ) {
			return array();
		}

		$clone = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$item = $element;
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$item['elements'] = self::deep_clone_elements( $element['elements'] );
			}
			$clone[] = $item;
		}

		return $clone;
	}

	/**
	 * Clear page canvas content while keeping Elementor page shell.
	 *
	 * Header/footer theme builder templates are separate and unaffected.
	 *
	 * @param int             $post_id Post ID.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function clear_page_canvas( $post_id, WP_REST_Request $request ) {
		$confirm_check = self::check_structural_confirm( $request );
		if ( is_wp_error( $confirm_check ) ) {
			return $confirm_check;
		}

		if ( ! WP_MCP_Elementor::is_elementor_page( $post_id ) ) {
			return new WP_Error( 'not_elementor', __( 'Not an Elementor page.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$data = WP_MCP_Elementor::get_data( $post_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$removed = count( $data );

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'         => true,
				'post_id'         => (int) $post_id,
				'sections_removed' => $removed,
				'action'          => 'clear_page_canvas',
			);
		}

		WP_MCP_Snapshots::create_snapshot( 'elementor', $post_id, get_post( $post_id ) );

		$saved = WP_MCP_Elementor::save_data( $post_id, array() );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		WP_MCP_Logger::log_action(
			'elementor.clear_page',
			'elementor',
			$post_id,
			array( 'sections_removed' => $removed ),
			'success'
		);

		return array(
			'post_id'          => (int) $post_id,
			'cleared'          => true,
			'sections_removed' => $removed,
		);
	}
}
