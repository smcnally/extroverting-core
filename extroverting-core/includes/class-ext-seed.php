<?php
/**
 * Sample content seeder (dev aid).
 *
 * Populates the OC city + the six sample events from the mockup so the
 * theme is clickable end-to-end on a fresh install. Run from:
 *
 *   wp eval "extroverting_core_seed_sample();"
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Seed sample content.
 *
 * @return array Summary of created IDs.
 */
function extroverting_core_seed_sample() {
	$created = array();

	// City: Oklahoma City.
	$city = array_find_by_slug( 'ext_city', 'oklahoma-city' );
	if ( ! $city ) {
		$city_id = wp_insert_post( array(
			'post_type'   => 'ext_city',
			'post_title'  => 'Oklahoma City',
			'post_name'   => 'oklahoma-city',
			'post_status' => 'publish',
			'post_content' => 'Everything on in the city, sorted by what\'s next. Save the ones you care about and we\'ll keep them in your calendar.',
		) );
		if ( is_wp_error( $city_id ) ) {
			return array( 'error' => $city_id->get_error_message() );
		}
		update_post_meta( $city_id, 'ext_state', 'OK' );
		update_post_meta( $city_id, 'ext_launched', true );
		$city = $city_id;
		$created['city'] = $city_id;
	}

	// Ensure a default category exists.
	$cat_id = wp_insert_term( 'Community', 'ext_event_cat' );
	if ( is_wp_error( $cat_id ) ) {
		$cat_id = (int) $cat_id->terms[0];
	}

	$events = array(
		array(
			'title' => 'Bricktown First Fridays', 'venue' => 'Bricktown Arts District',
			'hood' => 'Downtown', 'cat' => 'Community', 'quiz' => false,
			'days' => 0, 'time' => '6:00 PM – 10:00 PM',
			'desc' => 'Open-studio art walk along the river. Local galleries, street performers, and pop-up food stalls through the evening.',
		),
		array(
			'title' => 'OKC Live at The Midtown', 'venue' => 'The Midtown',
			'hood' => 'Midtown', 'cat' => 'Music', 'quiz' => true,
			'days' => 1, 'time' => '8:00 PM – 11:30 PM',
			'desc' => 'An evening of live sets on the Midtown stage. Bring a friend and stay for the late show.',
		),
		array(
			'title' => 'Norman Farmers Market', 'venue' => 'Civic Center Plaza',
			'hood' => 'Norman', 'cat' => 'Food & Drink', 'quiz' => false,
			'days' => 2, 'time' => '9:00 AM – 1:00 PM',
			'desc' => 'Produce, baked goods, and local makers. Cash and card accepted at most stalls.',
		),
		array(
			'title' => 'Mabee Park Summer Series', 'venue' => 'Mabee Garden',
			'hood' => 'Midtown', 'cat' => 'Outdoors', 'quiz' => false,
			'days' => 3, 'time' => '5:30 PM – 8:00 PM',
			'desc' => 'Family-friendly evening in the garden with lawn games and a sunset viewing point.',
		),
		array(
			'title' => 'Downtown OKC Night Market', 'venue' => 'Myriad Convention Center Plaza',
			'hood' => 'Downtown', 'cat' => 'Food & Drink', 'quiz' => true,
			'days' => 4, 'time' => '5:00 PM – 9:00 PM',
			'desc' => 'Night market with local vendors, food trucks, and live music on the plaza.',
		),
		array(
			'title' => 'Edmond Wine Festival', 'venue' => 'Edmond Expo Center',
			'hood' => 'Edmond', 'cat' => 'Food & Drink', 'quiz' => false,
			'days' => 6, 'time' => '2:00 PM – 6:00 PM',
			'desc' => 'Regional and local wineries with tastings and live acoustic sets.',
		),
	);

	foreach ( $events as $e ) {
		$slug  = sanitize_title( $e['title'] );
		$exist = get_page_by_path( $slug, OBJECT, 'ext_event' );
		if ( $exist ) {
			$created[ $slug ] = $exist->ID;
			continue;
		}
		$term = get_term_by( 'name', $e['cat'], 'ext_event_cat' );
		if ( ! $term ) {
			$t = wp_insert_term( $e['cat'], 'ext_event_cat' );
			$term = is_wp_error( $t ) ? null : get_term( $t['term_id'], 'ext_event_cat' );
		}
		$id = wp_insert_post( array(
			'post_type'    => 'ext_event',
			'post_title'   => $e['title'],
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_content' => $e['desc'],
			'tax_input'    => array( 'ext_event_cat' => array( $term->term_id ) ),
		) );
		if ( is_wp_error( $id ) ) {
			continue;
		}
		$date = gmdate( 'Y-m-d', strtotime( '+' . $e['days'] . ' days' ) );
		update_post_meta( $id, 'ext_date', $date );
		update_post_meta( $id, 'ext_time', $e['time'] );
		update_post_meta( $id, 'ext_venue', $e['venue'] );
		update_post_meta( $id, 'ext_hood', $e['hood'] );
		update_post_meta( $id, 'ext_city', (string) $city );
		update_post_meta( $id, 'ext_has_quiz', $e['quiz'] );
		update_post_meta( $id, 'ext_quiz_provider', $e['quiz'] ? 'gpiq' : '' );
		$created[ $slug ] = $id;
	}

	// Sample retailers (anchor ZIP 73102).
	$retailers = array(
		array( 'Corner Supply Co.', 'General', '0.4 mi' ),
		array( 'Riverside Goods', 'Retail', '1.1 mi' ),
		array( 'The Local Pantry', 'Food', '1.8 mi' ),
		array( 'Midtown Mercantile', 'General', '0.6 mi' ),
		array( 'Union Supply', 'Retail', '2.3 mi' ),
	);
	foreach ( $retailers as $r ) {
		$slug = sanitize_title( $r[0] );
		if ( get_page_by_path( $slug, OBJECT, 'ext_retailer' ) ) {
			continue;
		}
		$id = wp_insert_post( array(
			'post_type'   => 'ext_retailer',
			'post_title'  => $r[0],
			'post_name'   => $slug,
			'post_status' => 'publish',
		) );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, 'ext_category', $r[1] );
			update_post_meta( $id, 'ext_zip', '73102' );
			$created[ 'retailer-' . $slug ] = $id;
		}
	}

	return $created;
}

/**
 * Find a post by slug within a CPT.
 *
 * @param string $post_type CPT.
 * @param string $slug      Slug.
 * @return \WP_Post|null
 */
function array_find_by_slug( $post_type, $slug ) {
	$q = new \WP_Query( array(
		'post_type'      => $post_type,
		'name'           => $slug,
		'posts_per_page' => 1,
		'post_status'    => 'any',
	) );
	$found = $q->posts;
	wp_reset_postdata();
	return $found ? $found[0] : null;
}
