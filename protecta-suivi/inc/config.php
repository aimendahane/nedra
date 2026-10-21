<?php
/**
 * Constantes globales du thème.
 *
 * PS_VERSION    : version affichée (doit être identique à « Version: » dans style.css).
 * PS_DB_VERSION : numéro de la dernière migration de base de données
 *                 (voir inc/install/migrations.php). À augmenter UNIQUEMENT quand
 *                 on ajoute une migration.
 */

defined( 'ABSPATH' ) || exit;

define( 'PS_VERSION', '1.3.0' );
define( 'PS_DB_VERSION', 4 );

define( 'PS_DIR', get_template_directory() );
define( 'PS_URL', get_template_directory_uri() );

/** Espace de noms de l'API REST : /wp-json/ps/v1/... */
define( 'PS_REST_NS', 'ps/v1' );

/**
 * Nom complet d'une table du thème : ps_table( 'projects' ) → wp_ps_projects.
 */
function ps_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'ps_' . $name;
}

/**
 * Liste des tables du thème (sans préfixe). Utilisée par la sauvegarde JSON
 * et les tests. Toute nouvelle table doit être ajoutée ici.
 */
function ps_table_names(): array {
	return array(
		'lists',
		'projects',
		'project_parties',
		'companies',
		'competitors',
		'project_competitors',
		'items',
		'followups',
		'files',
		'changelog',
		'tasks',
		'notifications',
	);
}

/**
 * Date et heure « maintenant » dans le fuseau WordPress (MySQL DATETIME).
 */
function ps_now(): string {
	return current_time( 'mysql' );
}

/**
 * Date du jour (Y-m-d) dans le fuseau WordPress.
 */
function ps_today(): string {
	// Site de TEST uniquement (variable d'environnement PS_TEST, jamais présente chez l'hébergeur) :
	// date figée par tests/e2e-setup.php pour que les contrôles du jeu de démonstration ne dépendent pas du calendrier.
	if ( ( getenv( 'PS_TEST' ) || ! empty( $_SERVER['PS_TEST'] ) ) && ( $frozen = get_option( 'ps_test_today' ) ) ) {
		return (string) $frozen;
	}
	return current_time( 'Y-m-d' );
}
