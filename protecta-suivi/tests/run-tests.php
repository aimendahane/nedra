<?php
/**
 * Lance tous les tests automatiques sur un WordPress local.
 *
 *   PS_TEST=1 php tests/run-tests.php
 *
 * Variables : PS_WP_PATH (défaut /srv/wp). Le wp-config.php local doit utiliser le
 * préfixe « wptest_ » quand PS_TEST=1 (voir tests/README.md). Base de test effacée
 * et réinstallée à chaque lancement ; les données de développement ne sont pas touchées.
 */

if ( ! getenv( 'PS_TEST' ) ) {
	fwrite( STDERR, "Lancer avec PS_TEST=1 (base de test séparée).\n" );
	exit( 1 );
}

// 1. Site de test neuf (processus séparé : WordPress s'installe puis le thème est activé).
passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/install-test-site.php' ), $code );
if ( 0 !== $code ) {
	exit( $code );
}

// 2. Dossier d'uploads temporaire pour les tests (filtre posé AVANT le chargement de WordPress).
$GLOBALS['ps_test_uploads'] = sys_get_temp_dir() . '/ps-test-uploads-' . getmypid();
$GLOBALS['wp_filter']['upload_dir'][10][] = array(
	'accepted_args' => 1,
	'function'      => static function ( $u ) {
		$u['basedir'] = $GLOBALS['ps_test_uploads'];
		$u['path']    = $GLOBALS['ps_test_uploads'];
		return $u;
	},
);

// 3. Chargement normal : le premier « init » déclenche l'activation du thème (migrations).
$_SERVER['HTTP_HOST']   = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
define( 'PS_TESTING', true ); // fichiers de test : copie au lieu de move_uploaded_file
require rtrim( getenv( 'PS_WP_PATH' ) ?: '/srv/wp', '/' ) . '/wp-load.php';

require __DIR__ . '/lib.php';

echo 'Thème v' . PS_VERSION . ' — WordPress ' . get_bloginfo( 'version' ) . ' — PHP ' . PHP_VERSION . "\n";

foreach ( array( 'test-install.php', 'test-migrations.php', 'test-security.php', 'test-rest.php', 'test-projects.php', 'test-calculs.php', 'test-items.php', 'test-recap.php', 'test-admin.php', 'test-tasks.php' ) as $file ) {
	require __DIR__ . '/' . $file;
}

exit( ps_test_summary() );
