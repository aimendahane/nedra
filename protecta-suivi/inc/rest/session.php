<?php
/**
 * API session et préférences de l'utilisateur connecté.
 *
 *   GET  /ps/v1/me               → utilisateur, rôle, capacités, préférences
 *   POST /ps/v1/me/prefs         → { theme: auto|light|dark, dailyEmail: bool, instantEmail: bool (1.0.2) }
 *   POST /ps/v1/session/ping     → signale une activité réelle (prolonge la session)
 *   POST /ps/v1/session/logout   → déconnexion
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_session_routes' );
function ps_register_session_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/me',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => fn() => rest_ensure_response( ps_current_user_payload() ),
		)
	);

	register_rest_route(
		PS_REST_NS,
		'/me/prefs',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => 'ps_rest_save_prefs',
			'args'                => array(
				'theme'      => array( 'type' => 'string', 'enum' => array( 'auto', 'light', 'dark' ) ),
				'dailyEmail' => array( 'type' => 'boolean' ),
			),
		)
	);

	register_rest_route(
		PS_REST_NS,
		'/session/ping',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => fn() => rest_ensure_response( array( 'ok' => true ) ),
		)
	);

	register_rest_route(
		PS_REST_NS,
		'/session/logout',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // Se déconnecter est toujours permis.
			'callback'            => static function () {
				wp_logout();
				return rest_ensure_response( array( 'ok' => true ) );
			},
		)
	);
}

function ps_rest_save_prefs( WP_REST_Request $req ) {
	$uid = get_current_user_id();
	if ( null !== $req->get_param( 'theme' ) ) {
		update_user_meta( $uid, 'ps_theme', $req->get_param( 'theme' ) );
	}
	if ( null !== $req->get_param( 'dailyEmail' ) ) {
		update_user_meta( $uid, 'ps_daily_email', $req->get_param( 'dailyEmail' ) ? 1 : 0 );
	}
	if ( null !== $req->get_param( 'instantEmail' ) ) {
		update_user_meta( $uid, 'ps_instant_email', $req->get_param( 'instantEmail' ) ? 1 : 0 );
	}
	return rest_ensure_response( ps_current_user_payload() );
}
