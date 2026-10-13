<?php
/**
 * Test configuration for the WordPress test library.
 *
 * Values come from environment variables so CI can point at its own
 * database. Defaults match a local `docker run mysql:8.0` with no auth.
 *
 * @package Extroverting\Core\Tests
 */

/* Path to the WordPress codebase (roots/wordpress-core-installer places it in wordpress/). Trailing slash required. */
define( 'ABSPATH', dirname( __DIR__ ) . '/wordpress/' );

define( 'WP_DEFAULT_THEME', 'default' );

define( 'WP_DEBUG', true );

// ** MySQL settings ** //
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'wp_phpunit_tests' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASS' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 */
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );

$table_prefix = 'wpphpunittests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Extroverting Core Tests' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );
