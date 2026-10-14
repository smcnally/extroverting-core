<?php
/**
 * OTP delivery transports.
 *
 * D2: the one-time code goes to whichever channel the account has —
 * email or mobile. Each transport is a small class with `send()`.
 * Swap in a real SMS gateway (Twilio / 10DLC / Plivo) by adding a class
 * and registering it on the `extroverting/otp/sms_transport` filter.
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Transport base.
 */
abstract class Ext_OTP_Transport {

	/**
	 * Deliver a code.
	 *
	 * @param string $to    Destination (email or phone).
	 * @param string $code  6-digit code.
	 * @return bool true on success.
	 */
	abstract public function send( $to, $code );

	/**
	 * Human description of the channel, for UI copy.
	 *
	 * @return string
	 */
	abstract public function label();
}

/**
 * Email transport — uses WP core wp_mail().
 */
final class Ext_OTP_Email_Transport extends Ext_OTP_Transport {

	public function send( $to, $code ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subj = sprintf( /* translators: %s: site name */ __( 'Your %s sign-in code', 'extroverting-core' ), $site );
		$body = sprintf(
			/* translators: 1: 6-digit code, 2: minutes until expiry */
			__( "Your one-time sign-in code is %1\$s. It expires in %2\$d minutes.\n\nIf you didn't request this, you can ignore this message.", 'extroverting-core' ),
			$code,
			EXT_OTP_TTL_MINUTES
		);
		return (bool) wp_mail( $to, $subj, $body );
	}

	public function label() {
		return __( 'email', 'extroverting-core' );
	}
}

/**
 * SMS transport — NO-OP by default (D5: external service agnostic).
 *
 * To wire a real gateway, create a subclass of Ext_OTP_Transport and return
 * an instance from:
 *
 *   add_filter( 'extroverting/otp/sms_transport', function () {
 *       return new My_Twilio_Transport( 'ACCOUNT_SID', 'AUTH_TOKEN', 'FROM' );
 *   } );
 *
 * The no-op returns false so the caller can tell the user delivery failed,
 * rather than silently "succeeding."
 */
final class Ext_OTP_Sms_Transport extends Ext_OTP_Transport {

	public function send( $to, $code ) {
		/**
		 * Allow a registered gateway to actually send.
		 *
		 * @param bool   $sent  Initially false (no-op).
		 * @param string $to    Phone number.
		 * @param string $code  Code.
		 */
		$sent = apply_filters( 'extroverting/otp/sms_send', false, $to, $code );
		if ( false === $sent ) {
			// Dev aid: log instead of sending.
			error_log( sprintf( '[extroverting-core] OTP (no-op SMS) to %s: %s', $to, $code ) );
		}
		return (bool) $sent;
	}

	public function label() {
		return __( 'text message', 'extroverting-core' );
	}
}

/**
 * Detect the channel from a contact string.
 *
 * @param string $value Email or phone.
 * @return string 'email' | 'phone' | 'unknown'.
 */
function ext_detect_channel( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 'unknown';
	}
	if ( is_email( $value ) ) {
		return 'email';
	}
	// 10-digit US phone with optional separators.
	if ( preg_match( '/^\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}$/', $value ) ) {
		return 'phone';
	}
	return 'unknown';
}

/**
 * Normalize a phone number to E.164-ish (+1XXXXXXXXXX) for transport.
 *
 * @param string $value Phone.
 * @return string
 */
function ext_normalize_phone( $value ) {
	$digits = preg_replace( '/\D/', '', (string) $value );
	if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
		return '+' . $digits;
	}
	if ( 10 === strlen( $digits ) ) {
		return '+1' . $digits;
	}
	return $digits;
}
