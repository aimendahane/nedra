<?php
/**
 * Point d'entrée de la tâche planifiée (Cron Job de cPanel), une fois par jour le matin.
 *
 * Deux façons de l'appeler (voir README.md, section Cron) :
 *   1. URL secrète : https://votre-site/?ps_cron=<jeton de 64 caractères>   (wget / curl)
 *   2. Ligne de commande : php …/wp-content/themes/protecta-suivi/cron.php
 *
 * Le jeton est affiché (et régénérable) dans الإعدادات. Mauvais jeton → 403.
 * Actions : e-mail du matin (une fois par jour).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'ps_handle_cron_request', 20 );
function ps_handle_cron_request(): void {
	if ( ! isset( $_GET['ps_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	$given = (string) wp_unslash( $_GET['ps_cron'] ); // phpcs:ignore
	$token = (string) ps_setting( 'cron_token' );
	if ( '' === $token || strlen( $given ) !== strlen( $token ) || ! hash_equals( $token, $given ) ) {
		status_header( 403 );
		echo 'forbidden';
		exit;
	}
	echo ps_cron_run();
	exit;
}

/**
 * Exécute les tâches du jour et renvoie un compte rendu en une ligne.
 */
function ps_cron_run(): string {
	$r = ps_digest_run();
	return sprintf( "OK %s digest=%s sent=%d skipped=%d\n", ps_now(), $r['status'], $r['sent'], $r['skipped'] );
}
