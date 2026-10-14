<?php
/**
 * Plugin Name:       Extroverting Core
 * Plugin URI:        https://extroverting.example/
 * Description:       L0 core for Extroverting — the multi-city local events calendar. City/event/retailer content types, passwordless OTP accounts, saved events, routes, CRM consent, and the on-demand age gate. Brand-neutral: contains no quiz logic (that lives in a pluggable quiz provider).
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Mean Business
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       extroverting-core
 *
 * @package Extroverting\Core
 */

defined( 'ABSPATH' ) || exit;

define( 'EXT_CORE_VERSION', '0.1.0' );
define( 'EXT_CORE_FILE', __FILE__ );
define( 'EXT_CORE_DIR', plugin_dir_path( __FILE__ ) );

require_once EXT_CORE_DIR . 'includes/class-extroverting-core.php';
require_once EXT_CORE_DIR . 'includes/class-ext-content-types.php';
require_once EXT_CORE_DIR . 'includes/class-ext-auth.php';
require_once EXT_CORE_DIR . 'includes/class-ext-otp-transports.php';
require_once EXT_CORE_DIR . 'includes/class-ext-aws-sms-transport.php';
require_once EXT_CORE_DIR . 'includes/class-ext-saves.php';
require_once EXT_CORE_DIR . 'includes/class-ext-age-gate.php';
require_once EXT_CORE_DIR . 'includes/class-ext-consent.php';
require_once EXT_CORE_DIR . 'includes/class-ext-rest.php';
require_once EXT_CORE_DIR . 'includes/class-ext-settings.php';
require_once EXT_CORE_DIR . 'includes/class-ext-seed.php';

register_activation_hook( __FILE__, array( 'Extroverting\\Core\\Extroverting_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Extroverting\\Core\\Extroverting_Core', 'deactivate' ) );

/**
 * Boot.
 *
 * @return void
 */
function extroverting_core() {
	Extroverting\Core\Extroverting_Core::instance()->init();
}
extroverting_core();

/**
 * Global alias for the namespaced sample-content seeder (dev aid), so the
 * documented `wp eval "extroverting_core_seed_sample();"` works as written.
 *
 * @return array Summary of created IDs.
 */
function extroverting_core_seed_sample() {
	return \Extroverting\Core\extroverting_core_seed_sample();
}
