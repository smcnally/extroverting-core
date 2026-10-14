<?php
/**
 * PHPUnit bootstrap for Extroverting Core tests.
 *
 * Uses wp-phpunit (the WordPress core test library via Composer). The
 * WP_PHPUNIT__DIR constant is defined by vendor/autoload.php; the real
 * tests config location is passed via the WP_PHPUNIT__TESTS_CONFIG env
 * var (set in phpunit.xml.dist).
 *
 * @package Extroverting\Core\Tests
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR will be available.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv( 'WP_PHPUNIT__DIR' ) . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		// Load the plugin under test once WordPress is available.
		require dirname( __DIR__ ) . '/extroverting-core.php';
	}
);

// Start up the WP testing environment.
require getenv( 'WP_PHPUNIT__DIR' ) . '/includes/bootstrap.php';
