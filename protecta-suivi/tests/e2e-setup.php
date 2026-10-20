<?php
/**
 * Prépare le site de TEST pour les tests navigateur (PS_TEST=1 obligatoire) :
 * réinstallation neuve (tables wptest_*), activation du thème, comptes de test.
 *
 *   PS_TEST=1 php tests/e2e-setup.php
 *
 * Option : DEMO=1 → charge le jeu de démonstration.
 *
 * Comptes (mot de passe Test-pass-2026) :
 *   idara (الإدارة), masoul (مسؤول مشروع → وليد جدير), mowadhaf (موظف → وفاء حامدي), mowadhaf2 (موظف → محمد حسين)
 */
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/install-test-site.php' ), $code );
if ( $code ) {
	exit( $code );
}
$_SERVER['HTTP_HOST']   = 'localhost:8081';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( getenv( 'PS_WP_PATH' ) ?: '/srv/wp', '/' ) . '/wp-load.php';
update_option( 'siteurl', 'http://localhost:8081' );
update_option( 'home', 'http://localhost:8081' );
$emp = static function ( string $label ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . " WHERE list_key='employee' AND label=%s", $label ) );
};
foreach ( array( array( 'idara', 'ps_admin', 'سارة الإدارة', null ), array( 'masoul', 'ps_manager', 'وليد جدير', 'وليد جدير' ), array( 'mowadhaf', 'ps_employee', 'وفاء حامدي', 'وفاء حامدي' ), array( 'mowadhaf2', 'ps_employee', 'محمد حسين', 'محمد حسين' ) ) as list( $login, $role, $name, $e ) ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'Test-pass-2026', 'role' => $role, 'display_name' => $name, 'user_email' => $login . '@example.com' ) );
	if ( $e ) {
		update_user_meta( $id, 'ps_employee_id', $emp( $e ) );
	}
}
// Date figée du site de test (voir ps_today()) : les résultats attendus du jeu de démonstration sont calculés au 19/10/2026.
update_option( 'ps_test_today', '2026-10-19' );
// DEMO=1 : charge aussi le jeu de démonstration (30 projets, voir fixtures/demo-expected.md).
if ( getenv( 'DEMO' ) ) {
	require __DIR__ . '/fixtures/demo.php';
	$n = count( ps_demo_seed( (int) get_user_by( 'login', 'idara' )->ID ) );
	echo "Jeu de démonstration chargé : $n projets\n";
}
echo 'Site de test prêt : ' . home_url( '/' ) . ' (thème ' . PS_VERSION . ", base v" . get_option( 'ps_db_version' ) . ")\n";
