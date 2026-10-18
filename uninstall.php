<?php
/**
 * Uninstall cleanup for Extroverting Core.
 *
 * Removes plugin options and scheduled cron events. Does NOT delete
 * ext_city / ext_event / ext_retailer content — that data is owned by
 * the site and should survive a plugin removal. Users who want a full
 * wipe can run `wp post delete $(wp post list --post_type=ext_event,ext_city,ext_retailer --format=ids)` first.
 *
 * @package Extroverting\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Options set by Ext_Settings + Extroverting_Core::activate.
delete_option( 'extroverting_core_version' );
delete_option( 'extroverting_crm_consent_text' );
delete_option( 'ext_aws_region' );
delete_option( 'ext_aws_access_key' );
delete_option( 'ext_aws_secret_key' );
delete_option( 'ext_aws_session_token' );
delete_option( 'ext_aws_from_number' );
delete_option( 'ext_otp_dev_mode' );

// Sweep any leftover OTP/cool-down transients (prefixed ext_otp_).
global $wpdb;
$likes = array( 'ext_otp_%', 'ext_cfe_key' );
foreach ( $likes as $like ) {
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
	);
}

// Cron.
wp_clear_scheduled_hook( 'extroverting_daily' );
wp_clear_scheduled_hook( 'ext_ai_enrichment_cron' );
flush_rewrite_rules();
