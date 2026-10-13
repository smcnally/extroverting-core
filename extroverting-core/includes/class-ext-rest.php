<?php
/**
 * REST endpoints for Extroverting core.
 *
 * Namespace: /wp-json/extroverting/v1
 *
 * Endpoints:
 *   GET  /events?city={id}&cat={slug}&sort={soon|az|added}
 *   GET  /events/{id}
 *   POST /events/{id}/save            (auth) toggle save
 *   GET  /account                     (auth) profile + stats
 *   GET  /account/saved?filter={upcoming|past}  (auth)
 *   GET  /account/routes?origin={home|work}     (auth)
 *   POST /account/routes              (auth) save a route
 *   DELETE /account/routes            (auth) remove a route
 *   GET  /retailers?ctx={home|work|venue}       (auth)
 *   GET  /consent                     full consent text
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
		) );

		register_rest_route( self::NS, '/account/routes', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'remove_route' ),
			'permission_callback' => 'is_user_logged_in',
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
		$this->core->get( 'saves' )->save_route( $uid, $origin, $event, array(
			'dist' => $req->get_param( 'dist' ),
			'eta'  => $req->get_param( 'eta' ),
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
}
