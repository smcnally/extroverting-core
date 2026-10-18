<?php
/**
 * Plugin bootstrap.
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires all core subsystems together.
 */
final class Extroverting_Core {

	/**
	 * Singleton instance.
	 *
	 * @var Extroverting_Core|null
	 */
	private static $instance = null;

	/**
	 * Subsystem instances.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Whether init() has already run (init is idempotent).
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the singleton.
	 *
	 * @return Extroverting_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire and boot every subsystem.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->services['content']   = new Ext_Content_Types();
		$this->services['auth']      = new Ext_Auth();
		$this->services['saves']     = new Ext_Saves();
		$this->services['age_gate']  = new Ext_Age_Gate();
		$this->services['consent']   = new Ext_Consent();
		$this->services['rest']      = new Ext_REST( $this );
		$this->services['bindings']  = new Ext_Block_Bindings();
		$this->services['settings']  = new Ext_Settings();

		add_action( 'init', array( $this->services['content'], 'register_types' ) );
		add_action( 'init', array( $this->services['auth'], 'init' ), 5 );
		add_action( 'init', array( $this->services['age_gate'], 'init' ) );
		add_action( 'init', array( $this->services['consent'], 'init' ) );
		add_action( 'init', array( $this->services['bindings'], 'init' ) );
		add_action( 'rest_api_init', array( $this->services['rest'], 'register_routes' ) );
		add_action( 'rest_api_init', array( $this->services['age_gate'], 'allow_session_cookie' ) );

		// Admin settings (AWS SES SMS + dev mode).
		add_action( 'init', array( $this->services['settings'], 'init' ) );

		// SMS transport: use AWS SES v2 when configured, else the no-op.
		add_filter( 'extroverting/otp/sms_transport', array( $this, 'pick_sms_transport' ) );
		// Dev mode: admin checkbox overrides the WP_DEBUG default.
		add_filter( 'extroverting/otp/dev_mode', array( $this, 'dev_mode_from_option' ) );

		$theme = wp_get_theme();
		if ( 'Extroverting' === $theme->get( 'Name' ) ) {
			add_filter( 'template_include', array( $this->services['auth'], 'filter_template_include' ), 20 );
			add_filter( 'template_include', array( $this->services['age_gate'], 'filter_template_include' ), 25 );
		}
	}

	/**
	 * Get a subsystem.
	 *
	 * @param string $key Service key.
	 * @return object|null
	 */
	public function get( $key ) {
		return $this->services[ $key ] ?? null;
	}

	/**
	 * Activation: rewrite flush after CPTs exist on first load.
	 *
	 * @return void
	 */
	public static function activate() {
		// Load CPT registration once so rewrite rules exist.
		( new Ext_Content_Types() )->register_types();
		flush_rewrite_rules();
		update_option( 'extroverting_core_version', EXT_CORE_VERSION );
	}

	/**
	 * Deactivation: remove the cron hook (cron is idempotent by design).
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'extroverting_daily' );
	}

	/**
	 * Pick the SMS transport: AWS SES v2 when credentials + sender are set,
	 * otherwise the no-op (which logs the code).
	 *
	 * @param Ext_OTP_Transport $t Default (no-op) transport.
	 * @return Ext_OTP_Transport
	 */
	public function pick_sms_transport( $t ) {
		$access = get_option( 'ext_aws_access_key', '' ) ?: getenv( 'AWS_ACCESS_KEY_ID' );
		$secret = get_option( 'ext_aws_secret_key', '' ) ?: getenv( 'AWS_SECRET_ACCESS_KEY' );
		$from   = get_option( 'ext_aws_from_number', '' ) ?: getenv( 'AWS_SMS_FROM' );
		if ( $access && $secret && $from ) {
			return new Ext_Aws_Sms_Transport();
		}
		return $t;
	}

	/**
	 * Resolve the OTP dev mode from the admin checkbox.
	 *
	 * The checkbox forces ON (value 1) or OFF (value 0). When the option is
	 * absent, returns null so Ext_Auth::is_dev_mode() falls back to WP_DEBUG.
	 *
	 * @param mixed $mode Filter value (null default).
	 * @return bool|null
	 */
	public function dev_mode_from_option( $mode ) {
		$opt = get_option( 'ext_otp_dev_mode', null );
		if ( null === $opt ) {
			return null; // Let is_dev_mode() use the WP_DEBUG default.
		}
		return (bool) $opt;
	}
}
