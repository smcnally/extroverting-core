<?php
/**
 * Event import (dev/ops aid).
 *
 * Imports cities + events from a cfe-events export JSON file into the
 * ext_city / ext_event content types. Idempotent: re-running updates the
 * same posts (matched on the ext_cfe_key meta) instead of duplicating them.
 *
 * Data files live in data/events-YYYY-MM-DD.json, produced by
 * tools/export-cfe-events.py from the cfe-events research pipeline.
 *
 * Run from:
 *
 *   wp eval "print_r( extroverting_core_import_events() );"
 *
 * or with an explicit file / array:
 *
 *   wp eval 'print_r( extroverting_core_import_events( "data/events-2026-10-14.json" ) );'
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Import cities and events.
 *
 * @param string|array|null $data JSON file path (relative to the plugin
 *                                root or absolute), a decoded array with
 *                                { cities: [], events: [] }, or null to use
 *                                the newest data/events-*.json in the repo.
 * @return array Summary: cities_created, cities_updated, events_created,
 *               events_updated, skipped, file (when applicable).
 */
function extroverting_core_import_events( $data = null ) {
	if ( null === $data ) {
		$file = extroverting_latest_data_file();
		if ( ! $file ) {
			return array( 'error' => 'No data/events-*.json file found in the plugin.' );
		}
		$data = $file;
	}

	if ( is_string( $data ) ) {
		// Relative paths resolve against the plugin root (one level above includes/).
		$path = ( 0 === strpos( $data, '/' ) ) ? $data : dirname( __DIR__ ) . '/' . $data;
		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local ops aid.
		if ( false === $raw ) {
			return array( 'error' => "Cannot read data file: {$data}" );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['events'] ) ) {
			return array( 'error' => "Data file is not a valid import payload: {$data}" );
		}
	}

	if ( empty( $data['events'] ) ) {
		return array( 'error' => 'Import payload must be an array with a non-empty events list.' );
	}

	$summary = array(
		'cities_created' => 0,
		'cities_updated' => 0,
		'events_created' => 0,
		'events_updated' => 0,
		'skipped'        => 0,
	);

	// 1) Cities: upsert by slug.
	$city_ids = array();
	foreach ( (array) ( $data['cities'] ?? array() ) as $c ) {
		if ( empty( $c['slug'] ) || empty( $c['name'] ) ) {
			continue;
		}
		$slug = sanitize_title( (string) $c['slug'] );
		$post = array_find_by_slug( 'ext_city', $slug );

		if ( ! $post ) {
			$id = wp_insert_post( array(
				'post_type'    => 'ext_city',
				'post_title'   => sanitize_text_field( (string) $c['name'] ),
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_content' => "Everything on in the city, sorted by what's next. Save the ones you care about and we'll keep them in your calendar.",
			) );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			$post      = get_post( $id );
			$summary['cities_created']++;
		} else {
			$summary['cities_updated']++;
		}

		update_post_meta( $post->ID, 'ext_state', sanitize_text_field( (string) ( $c['state'] ?? '' ) ) );
		update_post_meta( $post->ID, 'ext_launched', ! empty( $c['launched'] ) );
		$city_ids[ $slug ] = $post->ID;
	}

	// 2) Events: match on the ext_cfe_key meta (one query for all existing keys).
	global $wpdb;
	$existing = array();
	$rows     = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'ext_cfe_key'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- single one-shot lookup for idempotent upserts.
	foreach ( (array) $rows as $row ) {
		$existing[ (string) $row['meta_value'] ] = (int) $row['post_id'];
	}

	foreach ( (array) $data['events'] as $e ) {
		if ( empty( $e['key'] ) || empty( $e['title'] ) || empty( $e['city'] ) || empty( $e['date'] ) ) {
			$summary['skipped']++;
			continue;
		}
		$slug = sanitize_title( (string) $e['city'] );
		if ( ! isset( $city_ids[ $slug ] ) ) {
			$summary['skipped']++;
			continue;
		}

		$category = extroverting_import_category( (string) ( $e['category'] ?? 'Other' ) );

		$post_id = isset( $existing[ (string) $e['key'] ] ) ? $existing[ (string) $e['key'] ] : 0;
		if ( $post_id ) {
			wp_update_post( array(
				'ID'           => $post_id,
				'post_title'   => sanitize_text_field( (string) $e['title'] ),
				'post_content' => wp_strip_all_tags( (string) ( $e['description'] ?? '' ) ),
			) );
			$summary['events_updated']++;
		} else {
			$post_id = wp_insert_post( array(
				'post_type'    => 'ext_event',
				'post_title'   => sanitize_text_field( (string) $e['title'] ),
				'post_name'    => sanitize_title( (string) $e['title'] ),
				'post_status'  => 'publish',
				'post_content' => wp_strip_all_tags( (string) ( $e['description'] ?? '' ) ),
			) );
			if ( is_wp_error( $post_id ) ) {
				$summary['skipped']++;
				continue;
			}
			$summary['events_created']++;
		}

		update_post_meta( $post_id, 'ext_date', sanitize_text_field( (string) $e['date'] ) );
		update_post_meta( $post_id, 'ext_time', sanitize_text_field( (string) ( $e['time'] ?? '' ) ) );
		update_post_meta( $post_id, 'ext_venue', sanitize_text_field( (string) ( $e['venue'] ?? '' ) ) );
		update_post_meta( $post_id, 'ext_city', (string) $city_ids[ $slug ] );
		update_post_meta( $post_id, 'ext_cfe_key', sanitize_text_field( (string) $e['key'] ) );
		update_post_meta( $post_id, 'ext_source', sanitize_text_field( (string) ( $e['source'] ?? '' ) ) );
		update_post_meta( $post_id, 'ext_source_url', esc_url_raw( (string) ( $e['source_url'] ?? '' ) ) );
		update_post_meta( $post_id, 'ext_ticket_url', esc_url_raw( (string) ( $e['ticket_url'] ?? '' ) ) );

		$term = get_term_by( 'name', $category, 'ext_event_cat' );
		if ( ! $term ) {
			$t = wp_insert_term( $category, 'ext_event_cat' );
			$term = is_wp_error( $t ) ? null : get_term( (int) $t['term_id'], 'ext_event_cat' );
		}
		if ( $term instanceof \WP_Term ) {
			wp_set_object_terms( $post_id, array( $term->term_id ), 'ext_event_cat' );
		}
	}

	return $summary;
}

/**
 * Newest data/events-*.json in the plugin root.
 *
 * @return string|null Absolute path, or null when none exists.
 */
function extroverting_latest_data_file() {
	// This file lives in includes/, so the plugin root (and data/) is one level up.
	$dir = dirname( __DIR__ ) . '/data';
	if ( ! is_dir( $dir ) ) {
		return null;
	}
	$files = glob( $dir . '/events-*.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- local ops aid.
	if ( ! $files ) {
		return null;
	}
	rsort( $files ); // events-YYYY-MM-DD.json sorts chronologically.
	return $files[0];
}

/**
 * Whitelist the category term so arbitrary data can't create terms.
 *
 * @param string $category Requested category name.
 * @return string A known ext_event_cat term name.
 */
function extroverting_import_category( $category ) {
	$known = array( 'Community', 'Music', 'Food & Drink', 'Outdoors', 'Arts', 'Comedy', 'Other' );
	return in_array( $category, $known, true ) ? $category : 'Other';
}
