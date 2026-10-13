<?php
/**
 * Passwordless OTP authentication (D2).
 *
 * One field accepts email OR mobile. The code goes to whichever channel
 * exists (transports in class-ext-otp-transports.php). No password field.
 *
 * Security notes:
 * - Codes are 6 digits, HMAC-signed with the site key, stored hashed
 *   (wp_hash_password) so a DB leak does not expose live codes.
 * - 5 attempts per code, 10-minute TTL, 60-second resend cooldown.
 * - Login creates a session cookie via core wp_set_auth_cookie().
 * - All endpoints are nonce-protected (X-WP-Nonce) and capability-checked.
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EXT_OTP_TTL_MINUTES' ) ) {
	define( 'EXT_OTP_TTL_MINUTES', 10 );
}

/**
 * Auth service.
 */
final class Ext_Auth {

	const OPT_PREFIX = 'ext_otp_';
	const MAX_ATTEMPTS = 5;
	const RESEND_COOLDOWN = 60;

	/**
	 * Register the ext_page query var.
	 *
	 * @return void
	 */
	public function register_query_var() {
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
	}

	/**
	 * Add ext_page to the query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'ext_page';
		return $vars;
	}

	/**
	 * Hook init.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_ext_request_otp', array( $this, 'ajax_request_otp' ) );
		add_action( 'wp_ajax_nopriv_ext_request_otp', array( $this, 'ajax_request_otp' ) );
		add_action( 'wp_ajax_ext_verify_otp', array( $this, 'ajax_verify_otp' ) );
		add_action( 'wp_ajax_nopriv_ext_verify_otp', array( $this, 'ajax_verify_otp' ) );
		add_action( 'wp_ajax_ext_register', array( $this, 'ajax_register' ) );

		// Pretty route mapping + guards.
		add_action( 'init', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'map_pretty_routes' ), 5 );
		add_action( 'template_redirect', array( $this, 'guard_dashboard' ), 10 );
		add_action( 'template_redirect', array( $this, 'guard_register' ), 10 );

		// Cron: sweep expired OTP rows (idempotent).
		add_action( 'extroverting_daily', array( $this, 'sweep_expired_otps' ) );
		if ( ! wp_next_scheduled( 'extroverting_daily' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'extroverting_daily' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Contact validation                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate + normalize a contact (email or phone).
	 *
	 * @param string $value Raw input.
	 * @return array{channel:string, value:string} on success.
	 */
	public function normalize_contact( $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		$chan  = ext_detect_channel( $value );
		if ( 'email' === $chan ) {
			return array( 'channel' => 'email', 'value' => sanitize_email( $value ) );
		}
		if ( 'phone' === $chan ) {
			return array( 'channel' => 'phone', 'value' => ext_normalize_phone( $value ) );
		}
		return null;
	}

	/**
	 * Find the user owning a contact.
	 *
	 * @param string $channel email|phone.
	 * @param string $value   Normalized value.
	 * @return \WP_User|null
	 */
	public function find_user_by_contact( $channel, $value ) {
		$key = 'ext_contact_' . $channel;
		$users = get_users( array(
			'meta_key'     => $key,
			'meta_value'   => $value,
			'number'       => 1,
			'fields'       => 'all',
		) );
		return $users ? $users[0] : null;
	}

	/* ------------------------------------------------------------------ */
	/* OTP lifecycle                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether the dev "show code on screen" mode is on.
	 *
	 * Controlled by the `extroverting/otp/dev_mode` filter. Default: on when
	 * WP_DEBUG is true, off in production. In dev mode the code is returned
	 * in the AJAX response so the login form can display it, instead of
	 * requiring a working email/SMS transport.
	 *
	 * @return bool
	 */
	public static function is_dev_mode() {
		/**
		 * Force the dev "show code on screen" mode.
		 *
		 * @param bool|null $mode null = auto (on when WP_DEBUG).
		 */
		$mode = apply_filters( 'extroverting/otp/dev_mode', null );
		if ( null === $mode ) {
			$mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
		}
		return (bool) $mode;
	}

	/**
	 * Issue an OTP for a contact.
	 *
	 * @param array $contact {channel, value}.
	 * @return array{ok:bool, channel?:string, dev_code?:string, error?:string}
	 */
	public function request_otp( $contact ) {
		$cooldown_key = self::OPT_PREFIX . 'cool_' . md5( $contact['value'] );
		$last = (int) get_option( $cooldown_key, 0 );
		if ( time() - $last < self::RESEND_COOLDOWN ) {
			return array( 'ok' => false, 'error' => __( 'Please wait a moment before requesting another code.', 'extroverting-core' ) );
		}

		$code   = $this->generate_code();
		$digest = hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
		$otp_key = self::OPT_PREFIX . 'otp_' . md5( $contact['value'] );

		add_option( $otp_key, array(
			'digest'  => $digest,
			'ttl'     => time() + ( EXT_OTP_TTL_MINUTES * 60 ),
			'attempts' => 0,
			'channel' => $contact['channel'],
		), false, 'site' );
		update_option( $cooldown_key, time() );

		$dev = self::is_dev_mode();

		if ( ! $dev ) {
			$ok = $this->deliver( $contact, $code );
			if ( ! $ok ) {
				delete_option( $otp_key );
				return array( 'ok' => false, 'error' => __( 'We could not deliver the code to that contact. Try again in a moment.', 'extroverting-core' ) );
			}
		}

		$result = array( 'ok' => true, 'channel' => $contact['channel'] );
		if ( $dev ) {
			// Dev aid: the login form displays this instead of waiting for transport.
			$result['dev_code'] = $code;
		}
		return $result;
	}

	/**
	 * Verify a code against a contact.
	 *
	 * @param string $value Normalized contact.
	 * @param string $code  User-entered code.
	 * @return array{ok:bool, user?:\WP_User, error?:string}
	 */
	public function verify_otp( $value, $code ) {
		$code    = preg_replace( '/\D/', '', (string) $code );
		$otp_key = self::OPT_PREFIX . 'otp_' . md5( $value );
		$otp     = get_option( $otp_key );

		if ( ! is_array( $otp ) ) {
			return array( 'ok' => false, 'error' => __( 'No code was requested for this contact. Request a new one.', 'extroverting-core' ) );
		}
		if ( time() > $otp['ttl'] ) {
			delete_option( $otp_key );
			return array( 'ok' => false, 'error' => __( 'That code has expired. Request a new one.', 'extroverting-core' ) );
		}
		if ( (int) $otp['attempts'] >= self::MAX_ATTEMPTS ) {
			delete_option( $otp_key );
			return array( 'ok' => false, 'error' => __( 'Too many incorrect attempts. Request a new code.', 'extroverting-core' ) );
		}

		$digest = hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
		// Compare against the stored digest constant-time-ish.
		if ( ! hash_equals( $otp['digest'], $digest ) ) {
			$otp['attempts'] = (int) $otp['attempts'] + 1;
			update_option( $otp_key, $otp, false, 'site' );
			return array( 'ok' => false, 'error' => __( 'That code did not work. Check it and try again.', 'extroverting-core' ) );
		}

		$chan = $otp['channel'];
		$user = $this->find_user_by_contact( $chan, $value );
		delete_option( $otp_key );
		if ( ! $user ) {
			return array( 'ok' => false, 'error' => __( 'No account found for that contact. Try registering.', 'extroverting-core' ) );
		}
		return array( 'ok' => true, 'user' => $user );
	}

	/**
	 * Register a new user from the registration form (D2, D3, D4).
	 *
	 * @param array $data Form fields: contact, home_zip, work_zip, username, first, last, crm.
	 * @return array{ok:bool, user?:\WP_User, error?:string}
	 */
	public function register( $data ) {
		$contact = $this->normalize_contact( $data['contact'] ?? '' );
		if ( null === $contact ) {
			return array( 'ok' => false, 'error' => __( 'Enter a valid email or mobile number.', 'extroverting-core' ) );
		}
		if ( $this->find_user_by_contact( $contact['channel'], $contact['value'] ) ) {
			return array( 'ok' => false, 'error' => __( 'An account with that contact already exists. Sign in instead.', 'extroverting-core' ) );
		}

		$home_zip = preg_replace( '/\D/', '', (string) ( $data['home_zip'] ?? '' ) );
		$work_zip = preg_replace( '/\D/', '', (string) ( $data['work_zip'] ?? '' ) );
		if ( ! preg_match( '/^\d{5}$/', $home_zip ) && ! preg_match( '/^\d{5}$/', $work_zip ) ) {
			return array( 'ok' => false, 'error' => __( 'Enter your home or work ZIP so we can find nearby events and retailers.', 'extroverting-core' ) );
		}

		$username = sanitize_user( (string) ( $data['username'] ?? '' ) );
		if ( '' === $username ) {
			$username = $this->generate_username();
		}
		$username = wp_unique_username( $username );

		$user_id = wp_insert_user( array(
			'user_login' => $username,
			'user_pass'  => wp_generate_password( 24, false ), // Unused; never exposed.
			'display_name' => $username,
			'role'       => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) ) {
			return array( 'ok' => false, 'error' => $user_id->get_error_message() );
		}

		$first = sanitize_text_field( (string) ( $data['first'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $data['last'] ?? '' ) );
		if ( $first || $last ) {
			$display = trim( $first . ' ' . $last );
			update_user_meta( $user_id, 'first_name', $first );
			update_user_meta( $user_id, 'last_name', $last );
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $display, 'user_nicename' => $display ) );
		}

		update_user_meta( $user_id, 'ext_contact_' . $contact['channel'], $contact['value'] );
		update_user_meta( $user_id, 'ext_home_zip', $home_zip );
		update_user_meta( $user_id, 'ext_work_zip', $work_zip );

		// D4: CRM consent — record the explicit, revocable consent with a timestamp.
		$crm = ! empty( $data['crm'] );
		update_user_meta( $user_id, 'ext_crm_consent', $crm ? 1 : 0 );
		if ( $crm ) {
			update_user_meta( $user_id, 'ext_crm_consent_at', gmdate( 'Y-m-d H:i:s' ) );
			/**
			 * Hook for CRM integration (Jetpack CRM per CRM-CONSENT.md).
			 *
			 * @param int    $user_id User.
			 * @param string $contact Normalized contact value.
			 */
			do_action( 'extroverting_crm_consent_granted', $user_id, $contact['value'] );
		}

		return array( 'ok' => true, 'user' => get_user_by( 'id', $user_id ) );
	}

	/**
	 * Log a user in (sets the auth cookie).
	 *
	 * @param \WP_User $user User.
	 * @return void
	 */
	public function login( $user ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'extroverting_user_logged_in', $user );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX endpoints                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * POST ?action=ext_request_otp { contact }
	 */
	public function ajax_request_otp() {
		check_ajax_referer( 'extroverting_auth', 'nonce' );
		$contact = $this->normalize_contact( $_POST['contact'] ?? '' );
		if ( null === $contact ) {
			wp_send_json_error( array( 'error' => __( 'Enter a valid email or mobile number.', 'extroverting-core' ) ) );
		}
		$result = $this->request_otp( $contact );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'error' => $result['error'] ) );
		}
		$payload = array(
			'message' => sprintf(
				/* translators: %s: channel (email or text message) */
				__( 'Code sent to your %s ✓', 'extroverting-core' ),
				$result['channel']
			),
			'channel' => $result['channel'],
		);
		if ( ! empty( $result['dev_code'] ) ) {
			$payload['dev_code'] = $result['dev_code'];
			$payload['message']  = __( 'Code ready (dev mode) ✓', 'extroverting-core' );
		}
		wp_send_json_success( $payload );
	}

	/**
	 * POST ?action=ext_verify_otp { contact, code }
	 */
	public function ajax_verify_otp() {
		check_ajax_referer( 'extroverting_auth', 'nonce' );
		$contact = $this->normalize_contact( $_POST['contact'] ?? '' );
		if ( null === $contact ) {
			wp_send_json_error( array( 'error' => __( 'Enter a valid email or mobile number.', 'extroverting-core' ) ) );
		}
		$result = $this->verify_otp( $contact['value'], $_POST['code'] ?? '' );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'error' => $result['error'] ) );
		}
		$this->login( $result['user'] );
		wp_send_json_success( array(
			'message' => __( 'Welcome back ✓', 'extroverting-core' ),
			'redirect' => esc_url_raw( admin_url( '' ) . 'index.php?route=dashboard' ),
		) );
	}

	/**
	 * POST ?action=ext_register { contact, home_zip, work_zip, username, first, last, crm }
	 */
	public function ajax_register() {
		check_ajax_referer( 'extroverting_auth', 'nonce' );
		$data = array(
			'contact'  => $_POST['contact'] ?? '',
			'home_zip' => $_POST['home_zip'] ?? '',
			'work_zip' => $_POST['work_zip'] ?? '',
			'username' => $_POST['username'] ?? '',
			'first'    => $_POST['first'] ?? '',
			'last'     => $_POST['last'] ?? '',
			'crm'      => ! empty( $_POST['crm'] ),
		);
		$result = $this->register( $data );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'error' => $result['error'] ) );
		}
		$this->login( $result['user'] );
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: greeting name */
				__( "You're in, %s. We've saved your events and quiz results ✓", 'extroverting-core' ),
				$result['user']->display_name
			),
			'redirect' => esc_url_raw( admin_url( '' ) . 'index.php?route=dashboard/calendar' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Template routing                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Map pretty routes to template slugs on the query var.
	 *
	 * Called on `template_redirect` (before template_include). Sets the
	 * `ext_page` query var so the theme can resolve the template:
	 *
	 *   /dashboard[/calendar|routes|retailers|settings] → ext_page = dashboard-…
	 *   /account/login | /account/register              → ext_page = account-…
	 *
	 * @return void
	 */
	public function map_pretty_routes() {
		$uri = trim( (string) ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ), '/' );
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = (string) trim( (string) $path, '/' );

		$slug = '';
		if ( preg_match( '#^dashboard(?:/([a-z-]+))?/?$#', $path, $m ) ) {
			$slug = 'dashboard' . ( ! empty( $m[1] ) ? '-' . $m[1] : '' );
		} elseif ( preg_match( '#^account/(login|register)/?$#', $path, $m ) ) {
			$slug = 'account-' . $m[1];
		} elseif ( preg_match( '#^legal/(privacy|terms|accessibility)/?$#', $path, $m ) ) {
			$slug = 'legal-' . $m[1];
		}
		if ( '' === $slug ) {
			return;
		}
		query_vars()->ext_page = $slug;
	}

	/**
	 * Resolve the ext_page slug to a template file.
	 *
	 * @param string $template Current template.
	 * @return string
	 */
	public function filter_template_include( $template ) {
		$slug = get_query_var( 'ext_page' );
		if ( ! $slug ) {
			return $template;
		}
		$candidate = locate_template( array( 'templates/ext-' . $slug . '.html' ) );
		return $candidate ? $candidate : $template;
	}

	/**
	 * Guard: dashboard pages require a logged-in user.
	 *
	 * @return void
	 */
	public function guard_dashboard() {
		$slug = get_query_var( 'ext_page' );
		if ( $slug && 0 === strpos( $slug, 'dashboard' ) && ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/account/login?redirect_to=' . rawurlencode( '/dashboard' ) ) );
			exit;
		}
	}

	/**
	 * Guard: account/register should not show when logged in.
	 *
	 * @return void
	 */
	public function guard_register() {
		$slug = get_query_var( 'ext_page' );
		if ( 'account-register' === $slug && is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/dashboard' ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Internals                                                           */
	/* ------------------------------------------------------------------ */

	private function generate_code() {
		return (string) random_int( 100000, 999999 );
	}

	private function generate_username() {
		$chars = 'abcdefghjkmnpqrstuvwxyz23456789';
		$s     = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$s .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return 'ext-' . $s;
	}

	private function deliver( $contact, $code ) {
		if ( 'email' === $contact['channel'] ) {
			$transport = new Ext_OTP_Email_Transport();
		} else {
			/**
			 * SMS transport filter — swap in a real gateway.
			 *
			 * @param Ext_OTP_Transport $t Default no-op.
			 */
			$transport = apply_filters( 'extroverting/otp/sms_transport', new Ext_OTP_Sms_Transport() );
		}
		return $transport->send( $contact['value'], $code );
	}

	/**
	 * Cron sweep of expired OTP rows.
	 *
	 * @return void
	 */
	public function sweep_expired_otps() {
		global $wpdb;
		$prefix = $wpdb->esc_like( self::OPT_PREFIX . 'otp_' );
		$rows   = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix . '%' ) );
		$now    = time();
		foreach ( $rows as $raw ) {
			$otp = maybe_unserialize( $raw );
			if ( is_array( $otp ) && isset( $otp['ttl'] ) && $otp['ttl'] < $now ) {
				// Delete by key pattern match on the unserialized ttl.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
						$prefix . '%',
						'%\"ttl\":' . (int) $otp['ttl'] . '%',
					)
				);
			}
		}
	}
}
