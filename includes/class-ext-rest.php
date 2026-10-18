<?php
/**
 * REST endpoints for Extroverting core.
 *
 * Namespace: /wp-json/extroverting/v1
 *
 * Prefer REST over admin-ajax for all new integrations. Each endpoint
 * declares its schema, sanitize/validate callbacks, and permission check so
 * it is discoverable in the Site Editor, usable from blocks, and
 * automatically documented at /wp-json.
 *
 * Endpoints:
 *   GET  /events?city={id}&cat={slug}&sort={soon|az|added}
 *   GET  /events/{id}
 *   POST /events/{id}/save            (auth) toggle save
 *   GET  /cities                      list cities (public)
 *   GET  /cities/{id}                single city
 *   POST /auth/request-otp           (public) passwordless code request — REST-native alternative to admin-ajax ext_request_otp
 *   POST /auth/verify-otp            (public) verify code + log in
 *   POST /auth/register              (public) create account + log in
 *   GET  /account                     (auth) profile + stats
 *   POST /account                    (auth) update profile/ZIP/consent
 *   GET  /account/saved?filter={upcoming|past}  (auth)
 *   GET  /account/routes?origin={home|work}     (auth)
 *   POST /account/routes              (auth) save a route
 *   DELETE /account/routes            (auth) remove a route
 *   GET  /retailers?ctx={home|work|venue}       (auth)
 *   GET  /consent                     full consent text
 *   POST /import                     (admin) trigger cfe-events import (WP-CLI remains the primary path)
 *
 * @package Extroverting\Core
 */

namespace Extroverting\Core;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class Ext_REST {

	const NS = 'extroverting/v1';

	/**
	 * Core instance for service access.
	 *
	 * @var Extroverting_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Extroverting_Core $core Core.
	 */
	public function __construct( Extroverting_Core $core ) {
		$this->core = $core;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( self::NS, '/events', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_events' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'city' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'cat'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_title' ),
				'sort' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'soon',
				),
			),
		) );

		register_rest_route( self::NS, '/events/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_event' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/events/(?P<id>\d+)/save', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'toggle_save' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/account', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_account' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/account', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_account' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		register_rest_route( self::NS, '/account/saved', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_saved' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'filter' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'upcoming',
				),
			),
		) );

		register_rest_route( self::NS, '/account/routes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_routes' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'origin' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'home',
				),
			),
		) );

		register_rest_route( self::NS, '/account/routes', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_route' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'origin'   => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function ( $v ) {
						return in_array( $v, array( 'home', 'work' ), true );
					},
					'default'           => 'home',
				),
				'event_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $v ) {
						return $v > 0;
					},
				),
				'dist'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'eta'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( self::NS, '/account/routes', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'remove_route' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'origin'   => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'home',
				),
				'event_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::NS, '/retailers', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_retailers' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'ctx' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
					'default'           => 'home',
				),
			),
		) );

		register_rest_route( self::NS, '/consent', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_consent' ),
			'permission_callback' => '__return_true',
		) );

		// --- Auth via REST (modern alternative to admin-ajax) ---
		register_rest_route( self::NS, '/cities', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_cities' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/cities/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_city' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/auth/request-otp', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_request_otp' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'contact' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NS, '/auth/verify-otp', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_verify_otp' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'contact' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'code'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		register_rest_route( self::NS, '/auth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_register' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'contact'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'home_zip' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'work_zip' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'username' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_user' ),
				'first'    => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'last'     => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'crm'      => array( 'required' => false, 'type' => 'boolean' ),
			),
		) );

		// Admin-only import trigger (convenience for ops; primary path remains WP-CLI).
		register_rest_route( self::NS, '/import', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_import' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Event shape for API consumers.
	 *
	 * @param \WP_Post $p Event post.
	 * @return array
	 */
	private function shape_event( \WP_Post $p ) {
		$meta = array(
			'date'    => get_post_meta( $p->ID, 'ext_date', true ),
			'time'    => get_post_meta( $p->ID, 'ext_time', true ),
			'venue'   => get_post_meta( $p->ID, 'ext_venue', true ),
			'hood'    => get_post_meta( $p->ID, 'ext_hood', true ),
			'city_id' => (int) get_post_meta( $p->ID, 'ext_city', true ),
			'quiz'    => (bool) get_post_meta( $p->ID, 'ext_has_quiz', true ),
			'provider' => get_post_meta( $p->ID, 'ext_quiz_provider', true ),
		);
		$terms = get_the_terms( $p->ID, 'ext_event_cat' );
		return array(
			'id'      => $p->ID,
			'title'   => $p->post_title,
			'slug'    => $p->post_name,
			'link'    => get_permalink( $p ),
			'cat'     => $terms ? $terms[0]->name : '',
			'meta'    => $meta,
			'saved'   => $this->core->get( 'saves' )->is_saved( $p->ID ),
			'ended'   => isset( $meta['date'] ) && $meta['date'] < gmdate( 'Y-m-d' ),
		);
	}

	/**
	 * GET /events
	 */
	public function get_events( \WP_REST_Request $req ) {
		$city = (int) $req['city'];
		if ( ! $city ) {
			return new \WP_Error( 'ext_missing_city', __( 'A city is required.', 'extroverting-core' ), array( 'status' => 400 ) );
		}
		$q = Ext_Content_Types::event_query( $city, (string) $req['cat'], (string) $req['sort'], true );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = $this->shape_event( $p );
		}
		return rest_ensure_response( array( 'events' => $out, 'count' => count( $out ) ) );
	}

	/**
	 * GET /events/{id}
	 */
	public function get_event( \WP_REST_Request $req ) {
		$p = get_post( (int) $req['id'] );
		if ( ! $p || 'ext_event' !== $p->post_type ) {
			return new \WP_Error( 'ext_not_found', __( 'Event not found.', 'extroverting-core' ), array( 'status' => 404 ) );
		}
		$shape = $this->shape_event( $p );
		$shape['content'] = wp_kses_post( $p->post_content );
		$shape['excerpt'] = $p->post_excerpt;
		return rest_ensure_response( $shape );
	}

	/**
	 * POST /events/{id}/save
	 */
	public function toggle_save( \WP_REST_Request $req ) {
		$now_saved = $this->core->get( 'saves' )->toggle_event( (int) $req['id'] );
		return rest_ensure_response( array( 'saved' => $now_saved ) );
	}

	/**
	 * GET /account
	 */
	public function get_account( \WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$auth  = $this->core->get( 'auth' );
		$saves = $this->core->get( 'saves' );
		// Determine primary contact channel.
		$contact = '';
		foreach ( array( 'ext_contact_email', 'ext_contact_phone' ) as $key ) {
			$v = get_user_meta( $uid, $key, true );
			if ( $v ) {
				$contact = $v;
				break;
			}
		}
		return rest_ensure_response( array(
			'user_id'    => $uid,
			'username'   => wp_get_current_user()->user_login,
			'display'    => wp_get_current_user()->display_name,
			'first'      => get_user_meta( $uid, 'first_name', true ),
			'last'       => get_user_meta( $uid, 'last_name', true ),
			'contact'    => $contact,
			'home_zip'   => get_user_meta( $uid, 'ext_home_zip', true ),
			'work_zip'   => get_user_meta( $uid, 'ext_work_zip', true ),
			'crm'        => (bool) get_user_meta( $uid, 'ext_crm_consent', true ),
			'saved_count' => count( $saves->get_saved_events( $uid ) ),
			'route_count' => count( $saves->get_routes( $uid ) ),
		) );
	}

	/**
	 * POST /account — update profile, ZIPs, and CRM consent.
	 *
	 * Accepted params: username, first, last, home_zip, work_zip, crm.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_account( \WP_REST_Request $req ) {
		$uid = get_current_user_id();
		$user = get_user_by( 'id', $uid );
		if ( ! $user ) {
			return new \WP_Error( 'ext_no_user', __( 'User not found.', 'extroverting-core' ), array( 'status' => 404 ) );
		}

		$updated = array();

		// Username.
		$username = $req->get_param( 'username' );
		if ( null !== $username ) {
			$username = sanitize_user( (string) $username );
			if ( '' === $username ) {
				return new \WP_Error( 'ext_bad_username', __( 'Username cannot be empty.', 'extroverting-core' ), array( 'status' => 400 ) );
			}
			$unique = ext_unique_username( $username, (int) $uid );
			$res    = wp_update_user( array( 'ID' => $uid, 'user_login' => $unique ) );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$updated[] = 'username';
		}

		// Names.
		$first = $req->get_param( 'first' );
		$last  = $req->get_param( 'last' );
		if ( null !== $first || null !== $last ) {
			$first = null !== $first ? sanitize_text_field( (string) $first ) : get_user_meta( $uid, 'first_name', true );
			$last  = null !== $last ? sanitize_text_field( (string) $last ) : get_user_meta( $uid, 'last_name', true );
			update_user_meta( $uid, 'first_name', $first );
			update_user_meta( $uid, 'last_name', $last );
			$display = trim( $first . ' ' . $last );
			if ( $display ) {
				wp_update_user( array( 'ID' => $uid, 'display_name' => $display, 'user_nicename' => $display ) );
			}
			$updated[] = 'name';
		}

		// ZIPs (D3: at least one required).
		$home_zip = $req->get_param( 'home_zip' );
		$work_zip = $req->get_param( 'work_zip' );
		if ( null !== $home_zip || null !== $work_zip ) {
			$home_zip = null !== $home_zip ? preg_replace( '/\D/', '', (string) $home_zip ) : get_user_meta( $uid, 'ext_home_zip', true );
			$work_zip = null !== $work_zip ? preg_replace( '/\D/', '', (string) $work_zip ) : get_user_meta( $uid, 'ext_work_zip', true );
			if ( '' !== (string) $home_zip && ! preg_match( '/^\d{5}$/', $home_zip ) ) {
				return new \WP_Error( 'ext_bad_zip', __( 'Home ZIP must be 5 digits.', 'extroverting-core' ), array( 'status' => 400 ) );
			}
			if ( '' !== (string) $work_zip && ! preg_match( '/^\d{5}$/', $work_zip ) ) {
				return new \WP_Error( 'ext_bad_zip', __( 'Work ZIP must be 5 digits.', 'extroverting-core' ), array( 'status' => 400 ) );
			}
			if ( '' === (string) $home_zip && '' === (string) $work_zip ) {
				return new \WP_Error( 'ext_no_zip', __( 'Enter your home or work ZIP so we can find nearby events and retailers.', 'extroverting-core' ), array( 'status' => 400 ) );
			}
			update_user_meta( $uid, 'ext_home_zip', (string) $home_zip );
			update_user_meta( $uid, 'ext_work_zip', (string) $work_zip );
			$updated[] = 'zips';
		}

		// CRM consent (D4).
		$crm = $req->get_param( 'crm' );
		if ( null !== $crm ) {
			$crm = ! empty( $crm );
			update_user_meta( $uid, 'ext_crm_consent', $crm ? 1 : 0 );
			if ( $crm ) {
				$existing = get_user_meta( $uid, 'ext_crm_consent_at', true );
				if ( ! $existing ) {
					update_user_meta( $uid, 'ext_crm_consent_at', gmdate( 'Y-m-d H:i:s' ) );
				}
				$contact = get_user_meta( $uid, 'ext_contact_email', true ) ?: get_user_meta( $uid, 'ext_contact_phone', true );
				do_action( 'extroverting_crm_consent_granted', $uid, (string) $contact );
			}
			$updated[] = 'crm';
		}

		return rest_ensure_response( array( 'ok' => true, 'updated' => $updated ) );
	}

	/**
	 * GET /account/saved
	 */
	public function get_saved( \WP_REST_Request $req ) {
		$uid      = get_current_user_id();
		$upcoming = 'past' !== $req['filter'];
		$posts    = $this->core->get( 'saves' )->saved_event_posts( $uid, $upcoming );
		$out      = array();
		foreach ( $posts as $p ) {
			$out[] = $this->shape_event( $p );
		}
		return rest_ensure_response( array( 'events' => $out ) );
	}

	/**
	 * GET /account/routes
	 */
	public function get_routes( \WP_REST_Request $req ) {
		$uid    = get_current_user_id();
		$origin = $req['origin'];
		$routes = $this->core->get( 'saves' )->get_routes( $uid );
		$out    = array();
		foreach ( $routes as $route ) {
			if ( $route['origin'] !== $origin ) {
				continue;
			}
			$event = get_post( $route['event_id'] );
			if ( ! $event ) {
				continue;
			}
			$out[] = array(
				'event_id' => $route['event_id'],
				'to'       => get_the_title( $event ) . ' · ' . get_post_meta( $event->ID, 'ext_hood', true ),
				'dist'     => $route['dist'],
				'eta'      => $route['eta'],
			);
		}
		return rest_ensure_response( array( 'routes' => $out ) );
	}

	/**
	 * POST /account/routes
	 */
	public function save_route( \WP_REST_Request $req ) {
		$uid    = get_current_user_id();
		$origin = $req->get_param( 'origin' ) ?: 'home';
		$event  = (int) $req->get_param( 'event_id' );
		if ( ! $event || ! get_post( $event ) ) {
			return new \WP_Error( 'ext_bad_event', __( 'Event not found.', 'extroverting-core' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $origin, array( 'home', 'work' ), true ) ) {
			$origin = 'home';
		}
		$this->core->get( 'saves' )->save_route( $uid, $origin, $event, array(
			'dist' => sanitize_text_field( (string) $req->get_param( 'dist' ) ),
			'eta'  => sanitize_text_field( (string) $req->get_param( 'eta' ) ),
		) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * DELETE /account/routes
	 */
	public function remove_route( \WP_REST_Request $req ) {
		$uid    = get_current_user_id();
		$origin = $req->get_param( 'origin' ) ?: 'home';
		$event  = (int) $req->get_param( 'event_id' );
		if ( ! $event ) {
			return new \WP_Error( 'ext_missing_event', __( 'Missing event.', 'extroverting-core' ), array( 'status' => 400 ) );
		}
		$this->core->get( 'saves' )->remove_route( $uid, $origin, $event );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * GET /retailers
	 */
	public function get_retailers( \WP_REST_Request $req ) {
		$uid   = get_current_user_id();
		$ctx   = $req['ctx'];
		$zip   = 'work' === $ctx ? get_user_meta( $uid, 'ext_work_zip', true ) : get_user_meta( $uid, 'ext_home_zip', true );

		$args = array(
			'post_type'      => 'ext_retailer',
			'posts_per_page' => 12,
		);
		if ( $zip ) {
			$args['meta_query'] = array(
				array(
					'key'   => 'ext_zip',
					'value' => $zip,
				),
			);
		}
		$q     = new \WP_Query( $args );
		$out   = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'id'   => $p->ID,
				'name' => $p->post_title,
				'cat'  => get_post_meta( $p->ID, 'ext_category', true ),
				'saved' => in_array( $p->ID, array_map( 'intval', (array) get_user_meta( $uid, 'ext_favorite_retailers', true ) ), true ),
			);
		}
		return rest_ensure_response( array( 'retailers' => $out ) );
	}

	/**
	 * GET /consent
	 */
	public function get_consent( \WP_REST_Request $req ) {
		return rest_ensure_response( array( 'label' => $this->core->get( 'consent' )->label(), 'full' => $this->core->get( 'consent' )->full_text() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Cities (public, for block bindings + headless consumers)            */
	/* ------------------------------------------------------------------ */

	/**
	 * GET /cities
	 */
	public function get_cities( \WP_REST_Request $req ) {
		$q   = new \WP_Query( array(
			'post_type'      => 'ext_city',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = $this->shape_city( $p );
		}
		wp_reset_postdata();
		return rest_ensure_response( array( 'cities' => $out, 'count' => count( $out ) ) );
	}

	/**
	 * GET /cities/{id}
	 */
	public function get_city( \WP_REST_Request $req ) {
		$p = get_post( (int) $req['id'] );
		if ( ! $p || 'ext_city' !== $p->post_type ) {
			return new \WP_Error( 'ext_not_found', __( 'City not found.', 'extroverting-core' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->shape_city( $p ) );
	}

	private function shape_city( \WP_Post $p ) {
		return array(
			'id'       => $p->ID,
			'slug'     => $p->post_name,
			'title'    => $p->post_title,
			'link'     => get_permalink( $p ),
			'excerpt'  => $p->post_excerpt,
			'state'    => get_post_meta( $p->ID, 'ext_state', true ),
			'launched' => (bool) get_post_meta( $p->ID, 'ext_launched', true ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Auth via REST (passwordless OTP)                                    */
	/* ------------------------------------------------------------------ */

	public function rest_request_otp( \WP_REST_Request $req ) {
		$auth    = $this->core->get( 'auth' );
		$contact = $auth->normalize_contact( $req->get_param( 'contact' ) );
		if ( null === $contact ) {
			return new \WP_Error( 'ext_bad_contact', __( 'Enter a valid email or mobile number.', 'extroverting-core' ), array( 'status' => 400 ) );
		}
		$result = $auth->request_otp( $contact );
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'ext_otp_failed', $result['error'], array( 'status' => 429 ) );
		}
		$payload = array(
			'message' => sprintf( __( 'Code sent to your %s ✓', 'extroverting-core' ), $result['channel'] ),
			'channel' => $result['channel'],
		);
		if ( ! empty( $result['dev_code'] ) ) {
			$payload['dev_code'] = $result['dev_code'];
			$payload['message']  = __( 'Code ready (dev mode) ✓', 'extroverting-core' );
		}
		return rest_ensure_response( $payload );
	}

	public function rest_verify_otp( \WP_REST_Request $req ) {
		$auth    = $this->core->get( 'auth' );
		$contact = $auth->normalize_contact( $req->get_param( 'contact' ) );
		if ( null === $contact ) {
			return new \WP_Error( 'ext_bad_contact', __( 'Enter a valid email or mobile number.', 'extroverting-core' ), array( 'status' => 400 ) );
		}
		$result = $auth->verify_otp( $contact['value'], (string) $req->get_param( 'code' ) );
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'ext_bad_code', $result['error'], array( 'status' => 400 ) );
		}
		$auth->login( $result['user'] );
		return rest_ensure_response( array(
			'message'  => __( 'Welcome back ✓', 'extroverting-core' ),
			'redirect' => esc_url_raw( home_url( '/dashboard' ) ),
			'user_id'  => $result['user']->ID,
		) );
	}

	public function rest_register( \WP_REST_Request $req ) {
		$auth   = $this->core->get( 'auth' );
		$data   = array(
			'contact'  => $req->get_param( 'contact' ) ?? '',
			'home_zip' => $req->get_param( 'home_zip' ) ?? '',
			'work_zip' => $req->get_param( 'work_zip' ) ?? '',
			'username' => $req->get_param( 'username' ) ?? '',
			'first'    => $req->get_param( 'first' ) ?? '',
			'last'     => $req->get_param( 'last' ) ?? '',
			'crm'      => (bool) $req->get_param( 'crm' ),
		);
		$result = $auth->register( $data );
		if ( ! $result['ok'] ) {
			return new \WP_Error( 'ext_register_failed', $result['error'], array( 'status' => 400 ) );
		}
		$auth->login( $result['user'] );
		return rest_ensure_response( array(
			'message'  => sprintf( __( "You're in, %s. We've saved your events and quiz results ✓", 'extroverting-core' ), $result['user']->display_name ),
			'redirect' => esc_url_raw( home_url( '/dashboard/calendar' ) ),
			'user_id'  => $result['user']->ID,
		) );
	}

	/**
	 * POST /import — admin-only convenience.
	 */
	public function rest_import( \WP_REST_Request $req ) {
		$summary = \Extroverting\Core\extroverting_core_import_events( null );
		if ( isset( $summary['error'] ) ) {
			return new \WP_Error( 'ext_import_error', $summary['error'], array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'summary' => $summary ) );
	}
}
