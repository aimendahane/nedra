<?php
/**
 * Étape 1 — Sécurité : dossiers protégés, tentatives de connexion, inactivité, wp-admin.
 */

ps_test_section( 'Dossiers protégés (fichiers des projets + sauvegardes)' );

ps_ensure_storage();
foreach ( array( '', 'files', 'backups' ) as $sub ) {
	$dir = ps_storage_dir( $sub );
	$ht  = @file_get_contents( $dir . '/.htaccess' );
	ps_assert( $ht && str_contains( $ht, 'Require all denied' ) && str_contains( $ht, 'Deny from all' ), 'accès web interdit : ' . ( $sub ?: 'racine' ) . '/.htaccess (Apache 2.2 et 2.4)' );
	ps_assert( file_exists( $dir . '/index.php' ), 'listage impossible : ' . ( $sub ?: 'racine' ) . '/index.php' );
}
// Un .htaccess modifié ou supprimé est réparé automatiquement.
file_put_contents( ps_storage_dir( 'backups' ) . '/.htaccess', 'Allow from all' );
ps_ensure_storage();
ps_assert( str_contains( file_get_contents( ps_storage_dir( 'backups' ) . '/.htaccess' ), 'Require all denied' ), '.htaccess altéré → réparé' );

ps_test_section( 'Limitation des tentatives de connexion' );

$uid = wp_insert_user( array( 'user_login' => 'test_emp', 'user_pass' => 'Bon-mot-2026', 'role' => 'ps_employee', 'display_name' => 'موظف اختبار' ) );
for ( $i = 1; $i <= 5; $i++ ) {
	$r = wp_authenticate( 'test_emp', 'faux' . $i );
}
ps_assert( is_wp_error( $r ) && 'ps_locked' !== $r->get_error_code(), '5 échecs : refus normal (mot de passe incorrect)' );
$r = wp_authenticate( 'test_emp', 'Bon-mot-2026' );
ps_assert( is_wp_error( $r ) && 'ps_locked' === $r->get_error_code(), '6e essai avec le BON mot de passe : bloqué' );
ps_assert( str_contains( $r->get_error_message(), '15' ), 'message indique la durée du blocage (15 min)' );

ps_update_settings( array( 'login_max_attempts' => 10 ) );
$r = wp_authenticate( 'test_emp', 'Bon-mot-2026' );
ps_assert( $r instanceof WP_User, 'limite réglable (10) : la connexion repasse' );
ps_update_settings( array( 'login_max_attempts' => 5 ) );
do_action( 'wp_login', 'test_emp', $r ); // Ce que fait wp_signon() (formulaire) après une connexion réussie.
$keys = ps_login_keys( 'test_emp' );
ps_assert( false === get_transient( $keys['pair'] ), 'connexion réussie → compteur remis à zéro' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
ps_assert( wp_authenticate( 'test_emp', 'Bon-mot-2026' ) instanceof WP_User, 'autre adresse IP : non concernée par le blocage' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

ps_test_section( 'Déconnexion après inactivité (contrôle serveur)' );

$now = time();
update_user_meta( $uid, 'ps_last_activity', $now - 119 * 60 );
ps_assert( ! ps_session_is_expired( $uid, $now ), '119 min sans activité : session active (défaut 2 h)' );
update_user_meta( $uid, 'ps_last_activity', $now - 121 * 60 );
ps_assert( ps_session_is_expired( $uid, $now ), '121 min sans activité : session expirée' );
ps_update_settings( array( 'inactivity_minutes' => 30 ) );
update_user_meta( $uid, 'ps_last_activity', $now - 31 * 60 );
ps_assert( ps_session_is_expired( $uid, $now ), 'durée réglable : 30 min → expirée après 31 min' );
ps_update_settings( array( 'inactivity_minutes' => 120 ) );

ps_test_section( 'Accès à wp-admin' );

$mgr = wp_insert_user( array( 'user_login' => 'test_mgr', 'user_pass' => wp_generate_password(), 'role' => 'ps_manager' ) );
$adm = wp_insert_user( array( 'user_login' => 'test_adm', 'user_pass' => wp_generate_password(), 'role' => 'ps_admin' ) );
ps_assert( ! ps_user_can_access_wp_admin( get_user_by( 'id', $uid ) ), 'موظف : wp-admin interdit' );
ps_assert( ! ps_user_can_access_wp_admin( get_user_by( 'id', $mgr ) ), 'مسؤول مشروع : wp-admin interdit' );
ps_assert( ! ps_user_can_access_wp_admin( get_user_by( 'id', $adm ) ), 'الإدارة : wp-admin interdit (tout se fait dans l\'application)' );
ps_assert( ps_user_can_access_wp_admin( get_user_by( 'login', 'admin' ) ), 'administrateur WordPress (technique) : wp-admin permis' );
ps_assert( false === apply_filters( 'show_admin_bar', true ), 'barre admin désactivée' );
ps_assert( false === apply_filters( 'xmlrpc_enabled', true ), 'XML-RPC désactivé' );
