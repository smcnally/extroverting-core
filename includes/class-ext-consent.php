<?php
/**
 * CRM marketing consent (D4).
 *
 * The recordable consent text comes from CRM-CONSENT.md. It is stored as a
 * plugin option so it can be updated by legal without a code release, and
 * rendered by the theme on the register form and in Settings → Updates.
 *
 * Entities: Extroverting (product) · Mean Business (legal parent).
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Consent service.
 */
final class Ext_Consent {

	const OPT = 'extroverting_crm_consent_text';

	/**
	 * Init: seed the consent text if absent.
	 *
	 * @return void
	 */
	public function init() {
		if ( false === get_option( self::OPT, false ) ) {
			add_action( 'admin_init', array( $this, 'seed_consent_text' ) );
		}
	}

	/**
	 * Seed the consent text on first admin load.
	 *
	 * @return void
	 */
	public function seed_consent_text() {
		update_option( self::OPT, $this->default_consent_text() );
	}

	/**
	 * The checkbox label shown at register + settings.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Send me updates about events and results from Extroverting. You can unsubscribe anytime.', 'extroverting-core' );
	}

	/**
	 * Full recordable consent HTML.
	 *
	 * @return string
	 */
	public function full_text() {
		$text = get_option( self::OPT, '' );
		if ( '' === $text ) {
			$text = $this->default_consent_text();
		}
		return wp_kses_post( $text );
	}

	/**
	 * Default consent text (from CRM-CONSENT.md, placeholders kept for legal).
	 *
	 * @return string
	 */
	private function default_consent_text() {
		return '
<p>By checking this box you consent to receive marketing and update communications from <strong>Extroverting</strong>, operated by <strong>Mean Business</strong> ("we," "us," or "our").</p>
<p><strong>What we\'ll send.</strong> Emails and/or text (SMS) messages about events in your area, new quizzes, and updates related to saved events and quiz results.</p>
<p><strong>How we use it.</strong> Your contact information is stored in our CRM ([Jetpack CRM]) solely to send these communications and manage your preferences. We do not sell or share it with third parties for their marketing.</p>
<p><strong>Messages &amp; data.</strong> Message and data rates may apply. Text messages are sent from a number associated with Mean Business: [short code / 10DLC number]. Reply <strong>STOP</strong> to opt out of text messages at any time; reply <strong>HELP</strong> for help. You can also unsubscribe from emails by clicking the link in any message.</p>
<p><strong>Optional &amp; revocable.</strong> This consent is not required to use Extroverting, save events, or keep quiz results. You may change this choice at any time in Settings → Updates, by replying STOP, or via the unsubscribe link in any email. See our Privacy Policy ([URL]).</p>';
	}
}
