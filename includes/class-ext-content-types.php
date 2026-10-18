<?php
/**
 * Content types: cities, events, retailers.
 *
 * Events are the atomic unit of the calendar. Cities group events.
 * Retailers are favorite-storable places near home/work/venue (D5-agnostic:
 * the source of truth for external retailer data is a pluggable service;
 * this CPT is the local, owner-controlled list).
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers CPTs, taxonomies, and meta.
 */
final class Ext_Content_Types {

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public function register_types() {
		$this->register_cities();
		$this->register_events();
		$this->register_retailers();
		$this->register_taxonomies();
	}

	/**
	 * City lander content type.
	 *
	 * @return void
	 */
	private function register_cities() {
		register_post_type(
			'ext_city',
			array(
				'labels'       => array(
					'name'          => __( 'Cities', 'extroverting-core' ),
					'singular_name' => __( 'City', 'extroverting-core' ),
					'add_new_item'  => __( 'Add New City', 'extroverting-core' ),
				),
				'public'       => true,
				'rewrite'      => array(
					'slug'       => 'cities',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'menu_icon'    => 'dashicons-location-alt',
				'show_in_rest' => true,
			)
		);

		register_post_meta( 'ext_city', 'ext_state', array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'string',
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		) );
		register_post_meta( 'ext_city', 'ext_launched', array(
			'show_in_rest' => true,
			'single'       => true,
			'type'         => 'boolean',
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		) );
	}

	/**
	 * Event content type.
	 *
	 * @return void
	 */
	private function register_events() {
		register_post_type(
			'ext_event',
			array(
				'labels'       => array(
					'name'          => __( 'Events', 'extroverting-core' ),
					'singular_name' => __( 'Event', 'extroverting-core' ),
					'add_new_item'  => __( 'Add New Event', 'extroverting-core' ),
				),
				'public'       => true,
				'rewrite'      => array(
					'slug'       => 'events',
					'with_front' => false,
				),
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'menu_icon'    => 'dashicons-calendar-alt',
				'show_in_rest' => true,
			)
		);

		// Event meta: everything the detail page and lists need.
		$meta = array(
			'ext_date'        => 'string', // Y-m-d.
			'ext_time'        => 'string', // Human "6:00 PM – 10:00 PM".
			'ext_venue'       => 'string',
			'ext_hood'        => 'string', // Neighborhood.
			'ext_city'        => 'string', // City post ID (string for REST).
			'ext_has_quiz'    => 'boolean', // L1 seam — provider-agnostic flag only.
			'ext_quiz_provider' => 'string', // e.g. "gpiq"; empty when no quiz.
		);
		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'ext_event',
				$key,
				array(
					'show_in_rest' => true,
					'single'       => true,
					'type'         => $type,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Retailer content type.
	 *
	 * @return void
	 */
	private function register_retailers() {
		register_post_type(
			'ext_retailer',
			array(
				'labels'       => array(
					'name'          => __( 'Retailers', 'extroverting-core' ),
					'singular_name' => __( 'Retailer', 'extroverting-core' ),
					'add_new_item'  => __( 'Add New Retailer', 'extroverting-core' ),
				),
				'public'       => false,
				'publicly_queryable' => false,
				'show_ui'      => true,
				'supports'     => array( 'title', 'editor' ),
				'menu_icon'    => 'dashicons-store',
				'show_in_rest' => true,
			)
		);

		$meta = array(
			'ext_category' => 'string', // General / Retail / Food / Snacks …
			'ext_zip'      => 'string', // Anchor ZIP for "near me" matching.
		);
		foreach ( $meta as $key => $type ) {
			register_post_meta(
				'ext_retailer',
				$key,
				array(
					'show_in_rest' => true,
					'single'       => true,
					'type'         => $type,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Event categories.
	 *
	 * @return void
	 */
	private function register_taxonomies() {
		register_taxonomy(
			'ext_event_cat',
			'ext_event',
			array(
				'labels'            => array(
					'name'          => __( 'Event Categories', 'extroverting-core' ),
					'singular_name' => __( 'Event Category', 'extroverting-core' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => 'event-category',
					'with_front' => false,
				),
			)
		);
	}

	/**
	 * Query events for a city, optionally filtered by category.
	 *
	 * @param int    $city_id City post ID.
	 * @param string $cat     Tax term slug (empty = all).
	 * @param string $sort    soon | az | added.
	 * @param bool   $upcoming_only Only events on/after today.
	 * @return \WP_Query
	 */
	public static function event_query( $city_id, $cat = '', $sort = 'soon', $upcoming_only = true ) {
		$args = array(
			'post_type'           => 'ext_event',
			'posts_per_page'      => -1,
			'meta_query'          => array(
				array(
					'key'   => 'ext_city',
					'value' => (string) $city_id,
				),
			),
			'no_found_rows'       => true,
		);

		if ( '' !== $cat ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'ext_event_cat',
					'field'    => 'slug',
					'terms'    => $cat,
				),
			);
		}

		if ( 'az' === $sort ) {
			$args['orderby'] = 'title';
			$args['order']   = 'ASC';
		} elseif ( 'added' === $sort ) {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		} else {
			$args['meta_key'] = 'ext_date';
			$args['orderby']  = 'meta_value';
			$args['order']    = 'ASC';
		}

		if ( $upcoming_only ) {
			$args['meta_query'][] = array(
				'key'   => 'ext_date',
				'value' => gmdate( 'Y-m-d' ),
				'compare' => '>=',
			);
		}

		return new \WP_Query( $args );
	}
}
