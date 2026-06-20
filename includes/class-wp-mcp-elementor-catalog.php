<?php
/**
 * Elementor widget catalog for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Elementor_Catalog
 */
class WP_MCP_Elementor_Catalog {

	/**
	 * Cached catalog.
	 *
	 * @var array|null
	 */
	private static $catalog = null;

	/**
	 * Load widget catalog from JSON.
	 *
	 * @return array
	 */
	public static function get_catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}

		$file = WP_MCP_CONTROL_PLUGIN_DIR . 'includes/elementor-widgets.json';
		if ( ! file_exists( $file ) ) {
			self::$catalog = array();
			return self::$catalog;
		}

		$json = file_get_contents( $file );
		$data = json_decode( $json, true );
		self::$catalog = is_array( $data ) ? $data : array();

		return self::$catalog;
	}

	/**
	 * Get widget definition.
	 *
	 * @param string $widget_type Widget type.
	 * @return array|null
	 */
	public static function get_definition( $widget_type ) {
		$catalog = self::get_catalog();
		return isset( $catalog[ $widget_type ] ) ? $catalog[ $widget_type ] : null;
	}

	/**
	 * Check if widget is editable.
	 *
	 * @param string $widget_type Widget type.
	 * @return bool
	 */
	public static function is_editable( $widget_type ) {
		$def = self::get_definition( $widget_type );
		return $def && ! empty( $def['editable'] );
	}

	/**
	 * Get allowed setting keys for a widget.
	 *
	 * @param string $widget_type Widget type.
	 * @return array
	 */
	public static function get_allowed_settings( $widget_type ) {
		$def = self::get_definition( $widget_type );
		if ( ! $def || empty( $def['settings'] ) ) {
			return array();
		}
		return array_keys( $def['settings'] );
	}

	/**
	 * Get public catalog for API.
	 *
	 * @return array
	 */
	public static function get_public_catalog() {
		$catalog = self::get_catalog();
		$public  = array();

		foreach ( $catalog as $type => $def ) {
			$public[] = array(
				'widget_type' => $type,
				'label'       => isset( $def['label'] ) ? $def['label'] : $type,
				'editable'    => ! empty( $def['editable'] ),
				'settings'    => isset( $def['settings'] ) ? array_keys( $def['settings'] ) : array(),
				'preview_field' => isset( $def['preview_field'] ) ? $def['preview_field'] : null,
			);
		}

		return $public;
	}

	/**
	 * Extract preview text from widget settings.
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $settings    Settings array.
	 * @return string
	 */
	public static function get_preview_text( $widget_type, $settings ) {
		$def = self::get_definition( $widget_type );
		if ( ! $def || empty( $def['preview_field'] ) ) {
			return '';
		}

		$field = $def['preview_field'];
		if ( ! isset( $settings[ $field ] ) ) {
			return '';
		}

		$value = $settings[ $field ];

		switch ( $field ) {
			case 'editor':
			case 'description_text':
			case 'description':
				return wp_trim_words( wp_strip_all_tags( $value ), 30 );

			case 'image':
				if ( is_array( $value ) && isset( $value['url'] ) ) {
					return $value['url'];
				}
				return is_string( $value ) ? $value : '';

			case 'icon_list':
				if ( is_array( $value ) ) {
					$texts = array();
					foreach ( $value as $item ) {
						if ( is_array( $item ) && ! empty( $item['text'] ) ) {
							$texts[] = $item['text'];
						}
					}
					return implode( ', ', array_slice( $texts, 0, 3 ) );
				}
				return '';

			default:
				return wp_strip_all_tags( (string) $value );
		}
	}

	/**
	 * Sanitize widget settings per catalog.
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $settings    Raw settings.
	 * @return array|WP_Error
	 */
	public static function sanitize_widget_settings( $widget_type, $settings ) {
		if ( ! self::is_editable( $widget_type ) ) {
			return new WP_Error(
				'widget_not_editable',
				sprintf(
					/* translators: %s: widget type */
					__( 'Widget type "%s" is not editable.', 'wp-mcp-control' ),
					$widget_type
				),
				array( 'status' => 400 )
			);
		}

		$def = self::get_definition( $widget_type );
		if ( ! $def || empty( $def['settings'] ) ) {
			return new WP_Error( 'unknown_widget', __( 'Unknown widget type.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$clean = array();

		foreach ( $settings as $key => $value ) {
			if ( ! isset( $def['settings'][ $key ] ) ) {
				continue;
			}

			$sanitizer = $def['settings'][ $key ];
			$sanitized = self::sanitize_field( $sanitizer, $value );

			if ( null !== $sanitized ) {
				$clean[ $key ] = $sanitized;
			}
		}

		if ( empty( $clean ) ) {
			return new WP_Error( 'no_valid_settings', __( 'No valid settings provided for this widget.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return $clean;
	}

	/**
	 * Sanitize a single field by type.
	 *
	 * @param string $type  Field sanitizer type.
	 * @param mixed  $value Value.
	 * @return mixed|null
	 */
	private static function sanitize_field( $type, $value ) {
		switch ( $type ) {
			case 'text':
				return sanitize_text_field( $value );

			case 'html':
				return wp_kses_post( $value );

			case 'url':
				$url = esc_url_raw( $value );
				if ( $url && 0 === strpos( strtolower( $url ), 'javascript:' ) ) {
					return null;
				}
				return $url;

			case 'link':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$link = array();
				if ( isset( $value['url'] ) ) {
					$url = esc_url_raw( $value['url'] );
					if ( $url && 0 !== strpos( strtolower( $url ), 'javascript:' ) ) {
						$link['url'] = $url;
					}
				}
				if ( isset( $value['is_external'] ) ) {
					$link['is_external'] = (bool) $value['is_external'];
				}
				if ( isset( $value['nofollow'] ) ) {
					$link['nofollow'] = (bool) $value['nofollow'];
				}
				return ! empty( $link ) ? $link : null;

			case 'image':
				if ( is_array( $value ) ) {
					$image = array();
					if ( ! empty( $value['id'] ) ) {
						$id = absint( $value['id'] );
						if ( get_post( $id ) && 'attachment' === get_post_type( $id ) ) {
							$image['id'] = $id;
							$image['url'] = wp_get_attachment_url( $id );
						}
					} elseif ( ! empty( $value['url'] ) ) {
						$url = esc_url_raw( $value['url'] );
						if ( $url ) {
							$image['url'] = $url;
						}
					}
					return ! empty( $image ) ? $image : null;
				}
				if ( is_numeric( $value ) ) {
					$id = absint( $value );
					if ( get_post( $id ) && 'attachment' === get_post_type( $id ) ) {
						return array(
							'id'  => $id,
							'url' => wp_get_attachment_url( $id ),
						);
					}
				}
				return null;

			case 'icon_list':
				if ( ! is_array( $value ) ) {
					return null;
				}
				$list = array();
				$count = 0;
				foreach ( $value as $item ) {
					if ( $count >= 20 ) {
						break;
					}
					if ( is_array( $item ) && isset( $item['text'] ) ) {
						$list[] = array(
							'text' => sanitize_text_field( $item['text'] ),
							'_id'  => isset( $item['_id'] ) ? sanitize_text_field( $item['_id'] ) : self::generate_id(),
						);
						$count++;
					}
				}
				return ! empty( $list ) ? $list : null;

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Generate Elementor-style element ID.
	 *
	 * @return string
	 */
	public static function generate_id() {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
	}

	/**
	 * Build default settings for a new widget.
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $overrides   Setting overrides.
	 * @return array|WP_Error
	 */
	public static function default_settings( $widget_type, $overrides = array() ) {
		$defaults = array();

		switch ( $widget_type ) {
			case 'heading':
			case 'raven-heading':
				$defaults = array( 'title' => 'New Heading', 'header_size' => 'h2' );
				if ( 'raven-heading' === $widget_type ) {
					$defaults = array( 'heading_text' => 'New Heading', 'html_tag' => 'h2' );
				}
				break;

			case 'text-editor':
				$defaults = array( 'editor' => '<p>New content</p>' );
				break;

			case 'button':
				$defaults = array( 'text' => 'Click Here', 'link' => array( 'url' => '#' ) );
				break;

			case 'raven-button':
				$defaults = array( 'button_text' => 'Click Here', 'link' => array( 'url' => '#' ) );
				break;

			case 'image':
			case 'raven-image':
				$defaults = array();
				break;

			case 'icon-box':
				$defaults = array( 'title_text' => 'Feature', 'description_text' => 'Description' );
				break;

			case 'raven-icon-box':
				$defaults = array( 'title' => 'Feature', 'description' => 'Description' );
				break;

			default:
				return new WP_Error( 'unsupported_widget', __( 'Cannot create default settings for this widget.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		return self::sanitize_widget_settings( $widget_type, array_merge( $defaults, $overrides ) );
	}
}
