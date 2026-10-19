<?php
/**
 * Tâche planifiée en ligne de commande (alternative à l'URL secrète) :
 *
 *   php /home/UTILISATEUR/public_html/wp-content/themes/protecta-suivi/cron.php
 *
 * Refuse toute exécution depuis le web.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'forbidden' );
}

// Remonte les dossiers jusqu'à trouver WordPress (wp-load.php).
$ps_dir = __DIR__;
for ( $i = 0; $i < 8 && ! file_exists( $ps_dir . '/wp-load.php' ); $i++ ) {
	$ps_dir = dirname( $ps_dir );
}
if ( ! file_exists( $ps_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "wp-load.php introuvable\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $ps_dir . '/wp-load.php';

if ( ! function_exists( 'ps_cron_run' ) ) {
	fwrite( STDERR, "Le thème Protecta Suivi n'est pas actif.\n" );
	exit( 1 );
}
echo ps_cron_run();
