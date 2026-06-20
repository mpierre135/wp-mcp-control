<?php
/**
 * Gutenberg block integration for WP MCP Control.
 *
 * @package WP_MCP_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_Blocks
 */
class WP_MCP_Blocks {

	/**
	 * Parse post content into flat block index.
	 *
	 * @param string $content Post content.
	 * @return array
	 */
	public static function parse_blocks( $content ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}

		$tree = parse_blocks( $content );
		return self::flatten_blocks( $tree );
	}

	/**
	 * Flatten block tree with paths.
	 *
	 * @param array  $blocks Block tree.
	 * @param string $path   Current path.
	 * @return array
	 */
	public static function flatten_blocks( $blocks, $path = '' ) {
		$items = array();

		if ( ! is_array( $blocks ) ) {
			return $items;
		}

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = $path ? $path . '.' . $index : (string) $index;
			$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			$summary = array(
				'blockName' => $block['blockName'],
				'path'      => $current_path,
				'attrs'     => $attrs,
				'preview'   => self::get_block_preview( $block ),
			);

			$items[] = $summary;

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$items = array_merge( $items, self::flatten_blocks( $block['innerBlocks'], $current_path ) );
			}
		}

		return $items;
	}

	/**
	 * Get text preview from block.
	 *
	 * @param array $block Block.
	 * @return string
	 */
	private static function get_block_preview( $block ) {
		if ( ! empty( $block['innerHTML'] ) ) {
			return wp_trim_words( wp_strip_all_tags( $block['innerHTML'] ), 20, '...' );
		}
		if ( ! empty( $block['attrs']['content'] ) ) {
			return wp_trim_words( wp_strip_all_tags( $block['attrs']['content'] ), 20, '...' );
		}
		return '';
	}

	/**
	 * Find block by name and optional text match.
	 *
	 * @param string $content    Post content.
	 * @param string $block_name Block name.
	 * @param string $match_text Optional text to match.
	 * @return array|null
	 */
	public static function find_block( $content, $block_name, $match_text = '' ) {
		$flat = self::parse_blocks( $content );

		foreach ( $flat as $item ) {
			if ( $item['blockName'] !== $block_name ) {
				continue;
			}
			if ( $match_text && false === stripos( $item['preview'], $match_text ) ) {
				continue;
			}
			return $item;
		}

		return null;
	}

	/**
	 * Find block reference in tree by path.
	 *
	 * @param array  $blocks Block tree.
	 * @param string $path   Dot-separated path.
	 * @return array|null
	 */
	public static function find_block_ref( &$blocks, $path ) {
		$parts = explode( '.', $path );
		$current = &$blocks;

		foreach ( $parts as $i => $index ) {
			$idx = (int) $index;
			if ( ! isset( $current[ $idx ] ) || ! is_array( $current[ $idx ] ) ) {
				return null;
			}
			if ( $i === count( $parts ) - 1 ) {
				return array(
					'block' => &$current[ $idx ],
					'index' => $idx,
				);
			}
			if ( empty( $current[ $idx ]['innerBlocks'] ) ) {
				return null;
			}
			$current = &$current[ $idx ]['innerBlocks'];
		}

		return null;
	}

	/**
	 * Update block attributes by path.
	 *
	 * @param int             $post_id Post ID.
	 * @param string          $path    Block path.
	 * @param array           $attrs   Attributes to merge.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function update_block_attrs( $post_id, $path, $attrs, WP_REST_Request $request ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		if ( ! is_array( $attrs ) || empty( $attrs ) ) {
			return new WP_Error( 'missing_attrs', __( 'Attributes are required.', 'wp-mcp-control' ), array( 'status' => 400 ) );
		}

		$blocks = parse_blocks( $post->post_content );
		$ref    = self::find_block_ref( $blocks, $path );

		if ( ! $ref ) {
			return new WP_Error( 'block_not_found', __( 'Block not found at path.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$clean = array();
		foreach ( $attrs as $key => $value ) {
			$key = sanitize_key( $key );
			if ( $key ) {
				$clean[ $key ] = is_string( $value ) ? wp_kses_post( $value ) : $value;
			}
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run'   => true,
				'post_id'   => $post_id,
				'path'      => $path,
				'new_attrs' => $clean,
			);
		}

		WP_MCP_Snapshots::create_snapshot( $post->post_type, $post_id, $post );

		if ( ! isset( $ref['block']['attrs'] ) || ! is_array( $ref['block']['attrs'] ) ) {
			$ref['block']['attrs'] = array();
		}
		$ref['block']['attrs'] = array_merge( $ref['block']['attrs'], $clean );

		$new_content = serialize_blocks( $blocks );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			)
		);

		WP_MCP_Logger::log_action(
			'blocks.update',
			$post->post_type,
			$post_id,
			array( 'path' => $path, 'attrs' => array_keys( $clean ) ),
			'success'
		);

		return array(
			'post_id' => $post_id,
			'path'    => $path,
			'updated' => true,
			'attrs'   => $ref['block']['attrs'],
		);
	}

	/**
	 * Insert block pattern preset into post.
	 *
	 * @param int             $post_id Post ID.
	 * @param string          $preset  Preset name: hero, faq, columns.
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function insert_block_pattern( $post_id, $preset, WP_REST_Request $request ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$pattern = self::get_preset( $preset );
		if ( is_wp_error( $pattern ) ) {
			return $pattern;
		}

		if ( WP_MCP_REST::is_dry_run( $request ) ) {
			return array(
				'dry_run' => true,
				'post_id' => $post_id,
				'preset'  => $preset,
				'blocks'  => count( parse_blocks( $pattern ) ),
			);
		}

		WP_MCP_Snapshots::create_snapshot( $post->post_type, $post_id, $post );

		$content = trim( $post->post_content ) . "\n\n" . $pattern;
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);

		WP_MCP_Logger::log_action(
			'blocks.insert_pattern',
			$post->post_type,
			$post_id,
			array( 'preset' => $preset ),
			'success'
		);

		return array(
			'post_id' => $post_id,
			'preset'  => $preset,
			'inserted'=> true,
			'blocks'  => self::parse_blocks( $content ),
		);
	}

	/**
	 * Get block pattern markup for preset.
	 *
	 * @param string $preset Preset name.
	 * @return string|WP_Error
	 */
	public static function get_preset( $preset ) {
		$presets = array(
			'hero' => '<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Hero Headline</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Supporting hero text goes here.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Get Started</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->',

			'faq' => '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading">Frequently Asked Questions</h2>
<!-- /wp:heading -->

<!-- wp:details -->
<details class="wp-block-details"><summary>Question one?</summary><!-- wp:paragraph -->
<p>Answer to question one.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details -->
<details class="wp-block-details"><summary>Question two?</summary><!-- wp:paragraph -->
<p>Answer to question two.</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details --></div>
<!-- /wp:group -->',

			'columns' => '<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Column One</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Content for column one.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Column Two</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Content for column two.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Column Three</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Content for column three.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->',
		);

		if ( ! isset( $presets[ $preset ] ) ) {
			return new WP_Error(
				'invalid_preset',
				__( 'Invalid preset. Use hero, faq, or columns.', 'wp-mcp-control' ),
				array( 'status' => 400 )
			);
		}

		return $presets[ $preset ];
	}

	/**
	 * Get block structure for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public static function get_structure( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-mcp-control' ), array( 'status' => 404 ) );
		}

		$flat = self::parse_blocks( $post->post_content );

		return array(
			'post_id'     => (int) $post_id,
			'title'       => $post->post_title,
			'block_count' => count( $flat ),
			'blocks'      => $flat,
			'editor'      => WP_MCP_Elementor::is_elementor_page( $post_id ) ? 'elementor' : 'block',
		);
	}
}
