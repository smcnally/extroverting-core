<?php
/**
 * AWS SES v2 SMS transport (End User Messaging).
 *
 * Implements the OTP SMS seam using the AWS SES v2 `SendSmsMessage` API
 * with SigV4 signing done by hand — no AWS SDK / Composer dependency
 * required. Phone numbers are E.164 (ext_normalize_phone() already
 * produces that).
 *
 * AWS-side prerequisites (see the AWS End User Messaging user guide):
 *  - AWS account with SES SMS enabled in the target region.
 *  - A sender ID (phone number, in E.164, or an alphanumeric sender ID)
 *    verified/registered for the region. 10DLC registration for US
 *    numbers is the owner's responsibility (CRM-CONSENT.md L2).
 *  - An IAM user/role with `ses:SendSmsMessage` permission.
 *  - Optionally: the AWS account out of SES sandbox (else destination
 *    numbers must be verified — see AWS docs).
 *
 * Credentials come from plugin options (admin settings page) or from the
 * standard AWS_* environment variables when no option is set.
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * AWS SES v2 SMS transport.
 */
final class Ext_Aws_Sms_Transport extends Ext_OTP_Transport {

	/**
	 * Send an OTP via AWS SES v2 SendSmsMessage.
	 *
	 * @param string $to   E.164 phone number.
	 * @param string $code 6-digit code.
	 * @return bool
	 */
	public function send( $to, $code ) {
		$cfg = $this->config();
		if ( empty( $cfg['access_key'] ) || empty( $cfg['secret_key'] ) ) {
			error_log( '[extroverting-core] AWS SMS: missing credentials.' );
			return false;
		}
		if ( empty( $cfg['from'] ) ) {
			error_log( '[extroverting-core] AWS SMS: missing sender (from) number.' );
			return false;
		}

		$message = sprintf(
			/* translators: 1: 6-digit code, 2: minutes */
			__( 'Extroverting: your one-time code is %1$s. It expires in %2$d minutes. If you didn\'t request this, ignore it.', 'extroverting-core' ),
			$code,
			EXT_OTP_TTL_MINUTES
		);

		$params = array(
			'phoneNumber' => $to,
			'message'     => $message,
		);
		// Alphanumeric sender IDs (brand names) are not valid in SMS
		// Medium/Standard; use FromPhoneNumberID for those.
		if ( preg_match( '/^\+?1?\d{10,15}$/', $cfg['from'] ) ) {
			$params['from'] = $cfg['from'];
		} else {
			$params['fromPhoneNumberId'] = $cfg['from'];
		}

		$body = wp_json_encode( $params );
		$res  = $this->sign_and_send( $cfg, $body );

		if ( is_wp_error( $res ) ) {
			error_log( '[extroverting-core] AWS SMS error: ' . $res->get_error_message() );
			return false;
		}
		list( $status, $resp_body ) = $res;
		if ( 200 !== $status ) {
			error_log( sprintf( '[extroverting-core] AWS SMS HTTP %d: %s', $status, $resp_body ) );
			return false;
		}
		return true;
	}

	public function label() {
		return __( 'text message', 'extroverting-core' );
	}

	/**
	 * Manual HMAC-SHA256, returning raw 32 bytes.
	 *
	 * Implemented by hand (not via hash_hmac()) to avoid PHP's hex-key
	 * auto-decode quirk in the SigV4 derivation chain. SHA-256 block size is
	 * 64 bytes; keys longer than that are hashed first, then padded.
	 *
	 * @param string $key Raw key bytes.
	 * @param string $msg Message bytes.
	 * @return string Raw 32-byte digest.
	 */
	private static function hmac_sha256( string $key, string $msg ): string {
		$bs = 64;
		if ( strlen( $key ) > $bs ) {
			$key = hash( 'sha256', $key, true );
		}
		$key = str_pad( $key, $bs, "\0" );
		$opad = '';
		$ipad = '';
		for ( $i = 0; $i < $bs; $i++ ) {
			$opad .= chr( ord( $key[ $i ] ) ^ 0x5c );
			$ipad .= chr( ord( $key[ $i ] ) ^ 0x36 );
		}
		return hash( 'sha256', $opad . hash( 'sha256', $ipad . $msg, true ), true );
	}

	/**
	 * Gather config from options, falling back to AWS_* env vars.
	 *
	 * @return array{region:string, access_key:string, secret_key:string, session:string, from:string}
	 */
	private function config() {
		$env = function ( $k ) {
			$v = getenv( $k );
			if ( false === $v ) {
				$v = isset( $_SERVER[ $k ] ) ? $_SERVER[ $k ] : '';
			}
			return (string) $v;
		};
		return array(
			'region'     => (string) ( get_option( 'ext_aws_region', '' ) ?: $env( 'AWS_REGION' ) ),
			'access_key' => (string) ( get_option( 'ext_aws_access_key', '' ) ?: $env( 'AWS_ACCESS_KEY_ID' ) ),
			'secret_key' => (string) ( get_option( 'ext_aws_secret_key', '' ) ?: $env( 'AWS_SECRET_ACCESS_KEY' ) ),
			'session'    => (string) ( get_option( 'ext_aws_session_token', '' ) ?: $env( 'AWS_SESSION_TOKEN' ) ),
			'from'       => ext_normalize_phone( (string) ( get_option( 'ext_aws_from_number', '' ) ?: $env( 'AWS_SMS_FROM' ) ) ),
		);
	}

	/**
	 * SigV4-sign and send the SES v2 request.
	 *
	 * @param array $cfg  Config from config().
	 * @param string $body JSON body.
	 * @return array{0:int,1:string}|\WP_Error [status, body].
	 */
	private function sign_and_send( $cfg, $body ) {
		$region = $cfg['region'] ? $cfg['region'] : 'us-east-1';
		$host   = 'email.' . $region . '.amazonaws.com';
		$uri    = '/v2/email/sms';
		$method = 'POST';
		$amz    = 'sesv2';

		$now         = gmdate( 'Ymd\THis\Z' );
		$datestamp   = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', $body );

		// Canonical headers (must be sorted, lowercase).
		$headers = array(
			'host'                  => $host,
			'amz-content-sha256'    => $payload_hash,
			'amz-date'              => $now,
			'content-type'          => 'application/x-amz-json-1.1',
		);
		if ( $cfg['session'] ) {
			$headers['x-amz-security-token'] = $cfg['session'];
		}
		ksort( $headers );
		$canonical_headers = '';
		$signed_header_keys = array();
		foreach ( $headers as $k => $v ) {
			$canonical_headers .= $k . ':' . trim( $v ) . "\n";
			$signed_header_keys[] = $k;
		}
		$signed_headers = implode( ';', $signed_header_keys );

		$canonical_request = $method . "\n" . $uri . "\n" . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;

		$scope       = $datestamp . '/' . $region . '/' . $amz . '/aws4_request';
		$string_to_sign = 'AWS4-HMAC-SHA256' . "\n" . $now . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		// SigV4 key derivation using a MANUAL HMAC-SHA256.
		//
		// Why not hash_hmac()? PHP's hash_hmac() silently AUTO-DECODES a key
		// that is a valid 64-hex-char string into raw bytes (documented in
		// the PHP manual). SigV4's intermediate keys ARE 64-hex-char digests,
		// and each must be used as the raw 32 bytes of the prior round — but
		// the auto-decode makes the behavior depend on incidental formatting
		// and has bitten many implementations. A hand-rolled HMAC (below) is
		// unambiguous and was verified byte-for-byte against Python's
		// hmac.new()/hashlib reference for this exact request.
		$k_date    = self::hmac_sha256( 'AWS4' . $cfg['secret_key'], $datestamp );
		$k_region  = self::hmac_sha256( $k_date, $region );
		$k_service = self::hmac_sha256( $k_region, $amz );
		$k_signing = self::hmac_sha256( $k_service, 'aws4_request' );
		$signature = bin2hex( self::hmac_sha256( $k_signing, $string_to_sign ) );

		$authorization = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key'] . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

		$all_headers = $headers;
		$all_headers['Authorization'] = $authorization;

		$curl_headers = array();
		foreach ( $all_headers as $k => $v ) {
			$curl_headers[] = $k . ': ' . $v;
		}

		$ch = curl_init( 'https://' . $host . $uri );
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => $curl_headers,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_SSL_VERIFYPEER => true,
		) );
		$response = curl_exec( $ch );
		$status   = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$err      = curl_error( $ch );
		curl_close( $ch );

		if ( false === $response ) {
			return new \WP_Error( 'ext_aws_curl', $err ? $err : 'CURL request failed.' );
		}
		return array( $status, (string) $response );
	}
}
