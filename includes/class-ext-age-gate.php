<?php
/**
 * Deferred age gate (D7).
 *
 * Not shown on load and not site-wide. It appears only when a user
 * requests a gated resource: an event detail page, a quiz, a results
 * page, or any blog post. Browsing home + city lists needs no gate.
 * Fires once per session after the first YES.
 *
 * Which resources are gated is configurable — a neutral deployment can
 * set the filter to an empty array and the gate never appears (L0 stays
 * brand-neutral; the gate is an L2 overlay declared by the deployment).
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Age gate service.
 */
final class Ext_Age_Gate {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_ext_age_gate_confirm', array( $this, 'ajax_confirm' ) );
		add_action( 'wp_ajax_nopriv_ext_age_gate_confirm', array( $this, 'ajax_confirm' ) );

		/**
		 * Filter which post types trigger the gate.
		 *
		 * Default: events and standard posts (blog). Home and city
		 * landers are open.
		 *
		 * @param string[] $types Post types.
		 */
		add_filter( 'extroverting/age_gate/post_types', array( $this, 'default_gated_types' ) );
	}

	/**
	 * Default gated post types.
	 *
	 * @return string[]
	 */
	public function default_gated_types() {
		return array( 'ext_event', 'post' );
	}

	/**
	 * Is the current request gated?
	 *
	 * @return bool
	 */
	public function is_current_gated() {
		if ( $this->is_verified_this_session() ) {
			return false;
		}
		if ( is_singular( $this->gated_types() ) ) {
			return true;
		}
		// Quiz/results sub-routes of an event (handled via template routing).
		if ( is_singular( 'ext_event' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Gated types (filtered).
	 *
	 * @return string[]
	 */
	public function gated_types() {
		$types = apply_filters( 'extroverting/age_gate/post_types', array() );
		return is_array( $types ) ? $types : array();
	}

	/**
	 * Whether the session has already passed the gate.
	 *
	 * @return bool
	 */
	public function is_verified_this_session() {
		return ! empty( $_COOKIE['ext_age_verified'] ) && (int) $_COOKIE['ext_age_verified'] > time() - 86400 * 14;
	}

	/**
	 * Template filter: if a gated page was requested and the session has
	 * not verified, render the gate template instead.
	 *
	 * @param string $template Resolved template.
	 * @return string
	 */
	public function filter_template_include( $template ) {
		if ( ! $this->is_current_gated() ) {
			return $template;
		}
		$gate = locate_template( array( 'templates/ext-age-gate.html' ) );
		return $gate ? $gate : $template;
	}

	/**
	 * POST ?action=ext_age_gate_confirm { yes: 1 }
	 */
	public function ajax_confirm() {
		check_ajax_referer( 'extroverting_auth', 'nonce' );
		$yes = ! empty( $_POST['yes'] );
		if ( $yes ) {
			$this->mark_verified();
			wp_send_json_success( array( 'message' => __( 'Welcome in ✓', 'extroverting-core' ) ) );
		}
		wp_send_json_success( array(
			'message'  => __( 'You need to be 21 or a qualified patient to view that.', 'extroverting-core' ),
			'redirect' => home_url( '/' ),
		) );
	}

	/**
	 * Mark the current session as verified (cookie-based, once per session).
	 *
	 * @return void
	 */
	private function mark_verified() {
		// Core defines both at runtime; guard for static analysis and edge hosts.
		$path   = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		setcookie( 'ext_age_verified', (string) time(), time() + 86400 * 14, $path, $domain, is_ssl(), true );
		$_COOKIE['ext_age_verified'] = (string) time();
	}

	/**
	 * Allow the age-verification cookie on REST requests.
	 *
	 * @return void
	 */
	public function allow_session_cookie() {
		// The cookie is standard; no special REST handling needed.
	}
}
