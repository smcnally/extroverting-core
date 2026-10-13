<?php
/**
 * Smoke + unit tests for Extroverting Core.
 *
 * @package Extroverting\Core\Tests
 */

/**
 * Plugin bootstraps and registers its content types.
 */
class Test_Ext_Core_Smoke extends WP_UnitTestCase {

	/**
	 * The plugin's CPTs are registered on init.
	 */
	public function test_content_types_are_registered() {
		$this->assertTrue( post_type_exists( 'ext_city' ), 'ext_city CPT should exist.' );
		$this->assertTrue( post_type_exists( 'ext_event' ), 'ext_event CPT should exist.' );
		$this->assertTrue( post_type_exists( 'ext_retailer' ), 'ext_retailer CPT should exist.' );
	}

	/**
	 * The event category taxonomy is registered.
	 */
	public function test_event_taxonomy_is_registered() {
		$terms = get_taxonomies( array( 'object_type' => array( 'ext_event' ) ), 'names' );
		$this->assertContains( 'ext_event_cat', $terms, 'ext_event_cat taxonomy should exist.' );
	}
}

/**
 * Contact channel detection (pure functions).
 */
class Test_Ext_Detect_Channel extends WP_UnitTestCase {

	public function test_detects_email() {
		$this->assertSame( 'email', \Extroverting\Core\ext_detect_channel( 'jane@example.com' ) );
	}

	public function test_detects_phone_with_separators() {
		$this->assertSame( 'phone', \Extroverting\Core\ext_detect_channel( '(555) 123-4567' ) );
		$this->assertSame( 'phone', \Extroverting\Core\ext_detect_channel( '555.123.4567' ) );
		$this->assertSame( 'phone', \Extroverting\Core\ext_detect_channel( '5551234567' ) );
	}

	public function test_unknown_for_garbage() {
		$this->assertSame( 'unknown', \Extroverting\Core\ext_detect_channel( '' ) );
		$this->assertSame( 'unknown', \Extroverting\Core\ext_detect_channel( 'not a contact' ) );
	}

	public function test_normalizes_phone_to_e164() {
		$this->assertSame( '+15551234567', \Extroverting\Core\ext_normalize_phone( '(555) 123-4567' ) );
		$this->assertSame( '+15551234567', \Extroverting\Core\ext_normalize_phone( '15551234567' ) );
	}
}
