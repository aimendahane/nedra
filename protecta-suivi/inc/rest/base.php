<?php
/**
 * Outils communs de l'API REST (/wp-json/ps/v1/...).
 *
 * Toute route du thème doit utiliser ps_rest_permission() comme permission_callback :
 * c'est ici que sont vérifiés, CÔTÉ SERVEUR, la connexion, l'expiration de la session
 * et la capacité demandée. Les règles « mon projet / mon صنف » sont vérifiées en plus
 * dans chaque contrôleur.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique un permission_callback exigeant une capacité.
 *
 * @param string $cap Capacité ps_* requise (ps_access = simple accès à l'application).
 */
function ps_rest_permission( string $cap = 'ps_access' ): callable {
	return static function ( WP_REST_Request $request ) use ( $cap ) {
		if ( ! empty( $GLOBALS['ps_session_expired'] ) ) {
			return new WP_Error( 'ps_session_expired', ps__( 'انتهت الجلسة بسبب عدم النشاط. الرجاء تسجيل الدخول من جديد.' ), array( 'status' => 401 ) );
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new WP_Error( 'ps_not_logged_in', ps__( 'الرجاء تسجيل الدخول.' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'ps_forbidden', ps__( 'ليس لديك صلاحية لهذه العملية.' ), array( 'status' => 403 ) );
		}
		// Les rafraîchissements automatiques (X-PS-Passive) ne prolongent pas la session.
		if ( ! $request->get_header( 'x_ps_passive' ) ) {
			ps_touch_activity( $uid );
		}
		return true;
	};
}
