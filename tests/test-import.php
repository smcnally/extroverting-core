<?php
/**
 * Importer tests: city/event upserts and idempotency.
 *
 * @package Extroverting\Core\Tests
 */

/**
 * Event import (dev/ops aid).
 */
class Test_Ext_Import extends WP_UnitTestCase {

	/**
	 * A minimal but realistic payload.
	 *
	 * @return array
	 */
	private function payload() {
		return array(
			'cities' => array(
				array( 'slug' => 'oklahoma-city', 'name' => 'Oklahoma City', 'state' => 'OK', 'launched' => true ),
				array( 'slug' => 'testville', 'name' => 'Testville', 'state' => 'CA', 'launched' => false ),
			),
			'events' => array(
				array(
					'key'         => 'patch-aaaa1111',
					'title'       => 'Riverside Concert',
					'city'        => 'oklahoma-city',
					'date'        => '2026-10-20',
					'time'        => '8:00 PM – 11:00 PM',
					'venue'       => 'River Stage',
					'category'    => 'Music',
					'description' => 'An evening of live sets by the river.',
					'source'      => 'patch',
					'source_url'  => 'https://example.com/concert',
				),
				array(
					'key'         => 'meetup-bbbb2222',
					'title'       => 'Testville Book Club',
					'city'        => 'testville',
					'date'        => '2026-10-21',
					'time'        => '6:30 PM',
					'venue'       => '',
					'category'    => 'Community',
					'description' => '',
				),
				array(
					// Unknown category must fall back to Other, not create a term.
					'key'      => 'patch-cccc3333',
					'title'    => 'Mystery Expo',
					'city'     => 'testville',
					'date'     => '2026-10-22',
					'time'     => 'All day',
					'venue'    => 'Convention Hall',
					'category' => 'Bogus Category',
				),
			),
		);
	}

	/**
	 * Count published posts of a type.
	 *
	 * @param string $type Post type.
	 * @return int
	 */
	private function count_posts( $type ) {
		$q = new WP_Query( array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$n = count( $q->posts );
		wp_reset_postdata();
		return $n;
	}

	/**
	 * First import creates cities and events with the expected meta.
	 */
	public function test_import_creates_cities_and_events() {
		$summary = extroverting_core_import_events( $this->payload() );

		$this->assertSame( 2, $summary['cities_created'] );
		$this->assertSame( 3, $summary['events_created'] );
		$this->assertSame( 0, $summary['skipped'] );

		$city = get_page_by_path( 'testville', OBJECT, 'ext_city' );
		$this->assertNotNull( $city );
		$this->assertFalse( (bool) get_post_meta( $city->ID, 'ext_launched', true ) );

		$event = get_page_by_path( 'riverside-concert', OBJECT, 'ext_event' );
		$this->assertNotNull( $event );
		$this->assertSame( '2026-10-20', get_post_meta( $event->ID, 'ext_date', true ) );
		$this->assertSame( 'River Stage', get_post_meta( $event->ID, 'ext_venue', true ) );
		$this->assertSame( 'patch-aaaa1111', get_post_meta( $event->ID, 'ext_cfe_key', true ) );

		$city_id = get_page_by_path( 'oklahoma-city', OBJECT, 'ext_city' )->ID;
		$this->assertSame( (string) $city_id, get_post_meta( $event->ID, 'ext_city', true ) );

		// Unknown category falls back to Other.
		$expo = get_page_by_path( 'mystery-expo', OBJECT, 'ext_event' );
		$terms = wp_get_post_terms( $expo->ID, 'ext_event_cat', array( 'fields' => 'names' ) );
		$this->assertSame( array( 'Other' ), $terms );
	}

	/**
	 * Running the same import twice must not duplicate anything.
	 */
	public function test_import_is_idempotent() {
		extroverting_core_import_events( $this->payload() );
		$cities_before = $this->count_posts( 'ext_city' );
		$events_before = $this->count_posts( 'ext_event' );

		$summary = extroverting_core_import_events( $this->payload() );

		$this->assertSame( 0, $summary['cities_created'] );
		$this->assertSame( 2, $summary['cities_updated'] );
		$this->assertSame( 0, $summary['events_created'] );
		$this->assertSame( 3, $summary['events_updated'] );
		$this->assertSame( $cities_before, $this->count_posts( 'ext_city' ) );
		$this->assertSame( $events_before, $this->count_posts( 'ext_event' ) );
	}

	/**
	 * Changed fields on a re-import update the existing post.
	 */
	public function test_import_updates_existing_event() {
		extroverting_core_import_events( $this->payload() );

		$payload = $this->payload();
		$payload['events'][0]['title']       = 'Riverside Concert (Rescheduled)';
		$payload['events'][0]['date']        = '2026-10-24';
		$summary                            = extroverting_core_import_events( $payload );

		$this->assertSame( 3, $summary['events_updated'] );
		// Slug stays stable when the title changes.
		$event = get_page_by_path( 'riverside-concert', OBJECT, 'ext_event' );
		$this->assertNotNull( $event );
		$this->assertSame( 'Riverside Concert (Rescheduled)', get_the_title( $event ) );
		$this->assertSame( '2026-10-24', get_post_meta( $event->ID, 'ext_date', true ) );
	}

	/**
	 * Events referencing an unknown city are skipped, not orphaned.
	 */
	public function test_import_skips_events_without_city() {
		$payload = $this->payload();
		unset( $payload['cities'][1] ); // drop testville.

		$summary = extroverting_core_import_events( $payload );

		$this->assertSame( 2, $summary['skipped'] );
		$this->assertSame( 1, $summary['events_created'] );
	}
}
