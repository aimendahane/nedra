<?php
/**
 * Prépare une installation WordPress de TEST toute neuve (appelé par run-tests.php).
 *
 * Utilise le préfixe de tables « wptest_ » (wp-config.php local : PS_TEST=1)
 * pour ne jamais toucher aux données de développement.
 * Supprime les tables wptest_* puis installe WordPress et active le thème.
 */

if ( ! getenv( 'PS_TEST' ) ) {
	fwrite( STDERR, "Refus : PS_TEST=1 obligatoire (protection des données).\n" );
	exit( 1 );
}

define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';

require rtrim( getenv( 'PS_WP_PATH' ) ?: '/srv/wp', '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;
if ( 'wptest_' !== $wpdb->prefix ) {
	fwrite( STDERR, "Refus : le préfixe de test doit être wptest_.\n" );
	exit( 1 );
}

foreach ( $wpdb->get_col( "SHOW TABLES LIKE 'wptest\\_%'" ) as $table ) {
	$wpdb->query( "DROP TABLE `$table`" ); // phpcs:ignore
}
wp_cache_flush();

wp_install( 'Protecta Test', 'admin', 'admin@example.com', false, '', wp_generate_password( 20 ) );
update_option( 'siteurl', 'http://localhost:8080' );
update_option( 'home', 'http://localhost:8080' );
update_option( 'permalink_structure', '/%postname%/' );
switch_theme( 'protecta-suivi' );

echo "Site de test installé (thème : " . get_option( 'stylesheet' ) . ")\n";
