<?php
/**
 * Étape 1 — API REST : les permissions sont vérifiées côté serveur.
 */

ps_test_section( 'API REST : permissions' );

function ps_test_rest( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
	$req = new WP_REST_Request( $method, '/' . PS_REST_NS . $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	foreach ( $headers as $k => $v ) {
		$req->set_header( $k, $v );
	}
	return rest_get_server()->dispatch( $req );
}

wp_set_current_user( 0 );
$r = ps_test_rest( 'GET', '/lists' );
ps_assert_same( 401, $r->get_status(), 'visiteur non connecté : /lists refusé (401)' );
ps_assert_same( 401, ps_test_rest( 'GET', '/me' )->get_status(), 'visiteur non connecté : /me refusé (401)' );
ps_assert_same( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users' ) )->get_status(), 'liste des utilisateurs WordPress non publique' );

$emp = get_user_by( 'login', 'test_emp' );
wp_set_current_user( $emp->ID );
$r = ps_test_rest( 'GET', '/lists' );
ps_assert_same( 200, $r->get_status(), 'موظف connecté : /lists autorisé' );
ps_assert_same( 15, count( $r->get_data()['lists'] ), '/lists renvoie les 15 listes (1.6.0 : سبب عدم اعتباره مشروعا)' );

$me = ps_test_rest( 'GET', '/me' )->get_data();
ps_assert( 'موظف' === $me['role'] && false === $me['caps']['ps_view_recap'] && true === $me['caps']['ps_create_project'], '/me : rôle et capacités du موظف' );

$r = ps_test_rest( 'POST', '/me/prefs', array( 'theme' => 'dark', 'dailyEmail' => true ) );
ps_assert( 200 === $r->get_status() && 'dark' === get_user_meta( $emp->ID, 'ps_theme', true ) && '1' === get_user_meta( $emp->ID, 'ps_daily_email', true ), 'préférences (mode nuit, e-mail du matin) enregistrées' );
ps_assert_same( 400, ps_test_rest( 'POST', '/me/prefs', array( 'theme' => 'rose' ) )->get_status(), 'valeur invalide refusée (400)' );

ps_test_section( 'API REST : activité et expiration de session' );

update_user_meta( $emp->ID, 'ps_last_activity', time() - 600 );
ps_test_rest( 'GET', '/lists', array(), array( 'X-PS-Passive' => '1' ) );
ps_assert( time() - (int) get_user_meta( $emp->ID, 'ps_last_activity', true ) >= 600, 'rafraîchissement automatique (X-PS-Passive) : ne prolonge pas la session' );
ps_test_rest( 'POST', '/session/ping' );
ps_assert( time() - (int) get_user_meta( $emp->ID, 'ps_last_activity', true ) < 5, 'activité réelle (ping) : session prolongée' );

$GLOBALS['ps_session_expired'] = true;
$r = ps_test_rest( 'GET', '/lists' );
ps_assert( 401 === $r->get_status() && 'ps_session_expired' === $r->get_data()['code'], 'session expirée : 401 « ps_session_expired »' );
unset( $GLOBALS['ps_session_expired'] );

wp_set_current_user( 0 );
