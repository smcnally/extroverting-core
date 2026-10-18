<?php
/**
 * Block Bindings for Extroverting event/city data.
 *
 * Exposes post meta and taxonomy to the Block Bindings API (WP 6.5+) so
 * any core block that supports bindings (Paragraph, Heading, Image, Button)
 * can render event fields without a custom block.
 *
 * Usage in a block template / pattern:
 *
 *   <!-- wp:paragraph {
 *     "metadata": {
 *       "bindings": {
 *         "content": {
 *           "source": "extroverting/event",
 *           "args": { "field": "ext_venue" }
 *         }
 *       }
 *     }
 *   } /-->
 *
 * Supported fields: ext_date, ext_time, ext_venue, ext_hood, ext_city,
 * ext_has_quiz, ext_quiz_provider, ext_cfe_key, ext_state, ext_launched,
 * ext_category, event_cat (term name), event_link.
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Bindings source.
 */
final class Ext_Block_Bindings {

	/**
	 * Hook into block bindings.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source( 'extroverting/event', array(
			'label'              => __( 'Extroverting Event', 'extroverting-core' ),
			'get_value_callback' => array( $this, 'get_value' ),
			'uses_context'       => array( 'postId', 'postType' ),
		) );

		register_block_bindings_source( 'extroverting/city', array(
			'label'              => __( 'Extroverting City', 'extroverting-core' ),
			'get_value_callback' => array( $this, 'get_city_value' ),
			'uses_context'       => array( 'postId', 'postType' ),
		) );
	}

	/**
	 * Resolve an event field binding.
	 *
	 * @param array     $args   Binding args { field }.
	 * @param \WP_Block $block  Block instance (provides context).
	 * @param string    $name   Attribute name (content, url, etc).
	 * @return string|null
	 */
	public function get_value( $args, $block, $name ) {
		$field  = $args['field'] ?? '';
		$post_id = $block->context['postId'] ?? 0;
		if ( ! $post_id || ! $field ) {
			return null;
		}

		// Allow preview in the editor: use the queried object when the
		// binding is evaluated outside a Loop (e.g. Site Editor canvas).
		if ( ! get_post( $post_id ) ) {
			$post_id = get_queried_object_id();
		}

		switch ( $field ) {
			case 'ext_date':
				$raw = get_post_meta( $post_id, 'ext_date', true );
				return $raw ? date_i18n( get_option( 'date_format' ), strtotime( $raw . ' 12:00:00' ) ) : null;
			case 'ext_date_raw':
				return get_post_meta( $post_id, 'ext_date', true ) ?: null;
			case 'ext_time':
				return get_post_meta( $post_id, 'ext_time', true ) ?: null;
			case 'ext_venue':
				return get_post_meta( $post_id, 'ext_venue', true ) ?: null;
			case 'ext_hood':
				return get_post_meta( $post_id, 'ext_hood', true ) ?: null;
			case 'ext_city':
				$city_id = (int) get_post_meta( $post_id, 'ext_city', true );
				return $city_id ? get_the_title( $city_id ) : null;
			case 'ext_city_id':
				return get_post_meta( $post_id, 'ext_city', true ) ?: null;
			case 'ext_has_quiz':
				return get_post_meta( $post_id, 'ext_has_quiz', true ) ? __( 'Quiz available', 'extroverting-core' ) : null;
			case 'event_cat':
				$terms = get_the_terms( $post_id, 'ext_event_cat' );
				return $terms && ! is_wp_error( $terms ) ? $terms[0]->name : null;
			case 'event_link':
				return get_permalink( $post_id ) ?: null;
			default:
				// Generic meta fall-through: sanitize the field name, then read.
				$allowed = array( 'ext_quiz_provider', 'ext_cfe_key', 'ext_source', 'ext_source_url', 'ext_ticket_url' );
				if ( in_array( $field, $allowed, true ) ) {
					return get_post_meta( $post_id, $field, true ) ?: null;
				}
				return null;
		}
	}

	/**
	 * Resolve a city field binding.
	 *
	 * @param array     $args   Binding args { field }.
	 * @param \WP_Block $block  Block instance.
	 * @param string    $name   Attribute name.
	 * @return string|null
	 */
	public function get_city_value( $args, $block, $name ) {
		$field   = $args['field'] ?? '';
		$post_id = $block->context['postId'] ?? 0;
		if ( ! $post_id || ! $field ) {
			return null;
		}

		switch ( $field ) {
			case 'ext_state':
				return get_post_meta( $post_id, 'ext_state', true ) ?: null;
			case 'ext_launched':
				return get_post_meta( $post_id, 'ext_launched', true ) ? __( 'Launched', 'extroverting-core' ) : null;
			case 'city_link':
				return get_permalink( $post_id ) ?: null;
			default:
				return null;
		}
	}
}
