<?php
/**
 * Saved events, favorite retailers, and saved routes (D9: not city-locked).
 *
 * Stored as user meta arrays so a user's calendar survives city changes
 * and works from "near me" (Home/Work ZIP).
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Saves service.
 */
final class Ext_Saves {

	/**
	 * Toggle an event as saved for the current (or given) user.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User (defaults to current).
	 * @return bool True if now saved, false if now unsaved.
	 */
	public function toggle_event( $event_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$saved = $this->get_saved_events( $user_id );
		if ( in_array( (int) $event_id, array_map( 'intval', $saved ), true ) ) {
			$saved = array_values( array_diff( array_map( 'intval', $saved ), array( (int) $event_id ) ) );
			update_user_meta( $user_id, 'ext_saved_events', $saved );
			do_action( 'extroverting_event_unsaved', $event_id, $user_id );
			return false;
		}
		$saved[] = (int) $event_id;
		update_user_meta( $user_id, 'ext_saved_events', $saved );
		do_action( 'extroverting_event_saved', $event_id, $user_id );
		return true;
	}

	/**
	 * All saved event IDs for a user.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public function get_saved_events( $user_id ) {
		$saved = get_user_meta( $user_id, 'ext_saved_events', true );
		return is_array( $saved ) ? array_map( 'intval', $saved ) : array();
	}

	/**
	 * Whether a user has saved an event.
	 *
	 * @param int $event_id Event.
	 * @param int $user_id  User.
	 * @return bool
	 */
	public function is_saved( $event_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		return in_array( (int) $event_id, $this->get_saved_events( $user_id ), true );
	}

	/**
	 * Saved event objects for a user, upcoming or past.
	 *
	 * @param int  $user_id      User.
	 * @param bool $upcoming_only True = only events on/after today.
	 * @return \WP_Post[]
	 */
	public function saved_event_posts( $user_id, $upcoming_only = true ) {
		$ids = $this->get_saved_events( $user_id );
		if ( empty( $ids ) ) {
			return array();
		}
		$args = array(
			'post_type'      => 'ext_event',
			'post__in'       => $ids,
			'orderby'        => 'meta_value',
			'meta_key'       => 'ext_date',
			'posts_per_page' => -1,
		);
		if ( $upcoming_only ) {
			$args['meta_query'] = array(
				array(
					'key'     => 'ext_date',
					'value'   => gmdate( 'Y-m-d' ),
					'compare' => '>=',
				),
			);
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}
		$q     = new \WP_Query( $args );
		$posts = $q->posts;
		wp_reset_postdata();
		return $posts;
	}

	/**
	 * Toggle a retailer as a favorite.
	 *
	 * @param int $retailer_id Retailer post ID.
	 * @param int $user_id     User.
	 * @return bool True if now favorited.
	 */
	public function toggle_retailer( $retailer_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$favs = get_user_meta( $user_id, 'ext_favorite_retailers', true );
		$favs = is_array( $favs ) ? array_map( 'intval', $favs ) : array();
		if ( in_array( (int) $retailer_id, $favs, true ) ) {
			$favs = array_values( array_diff( $favs, array( (int) $retailer_id ) ) );
			update_user_meta( $user_id, 'ext_favorite_retailers', $favs );
			return false;
		}
		$favs[] = (int) $retailer_id;
		update_user_meta( $user_id, 'ext_favorite_retailers', $favs );
		return true;
	}

	/**
	 * Save a route (origin → venue).
	 *
	 * @param int    $user_id   User.
	 * @param string $origin    'home' | 'work'.
	 * @param int    $event_id  Destination event.
	 * @param array  $detail    { dist, eta } from the directions service.
	 * @return void
	 */
	public function save_route( $user_id, $origin, $event_id, $detail = array() ) {
		$routes = get_user_meta( $user_id, 'ext_routes', true );
		$routes = is_array( $routes ) ? $routes : array();
		$key    = $origin . ':' . (int) $event_id;
		$routes[ $key ] = array(
			'origin'   => in_array( $origin, array( 'home', 'work' ), true ) ? $origin : 'home',
			'event_id' => (int) $event_id,
			'dist'     => sanitize_text_field( $detail['dist'] ?? '' ),
			'eta'      => sanitize_text_field( $detail['eta'] ?? '' ),
			'saved_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		update_user_meta( $user_id, 'ext_routes', $routes );
	}

	/**
	 * All saved routes for a user.
	 *
	 * @param int $user_id User.
	 * @return array<string, array>
	 */
	public function get_routes( $user_id ) {
		$routes = get_user_meta( $user_id, 'ext_routes', true );
		return is_array( $routes ) ? $routes : array();
	}

	/**
	 * Remove a route.
	 *
	 * @param int    $user_id User.
	 * @param string $origin  home|work.
	 * @param int    $event_id Destination.
	 * @return void
	 */
	public function remove_route( $user_id, $origin, $event_id ) {
		$routes = $this->get_routes( $user_id );
		unset( $routes[ $origin . ':' . (int) $event_id ] );
		update_user_meta( $user_id, 'ext_routes', $routes );
	}
}
