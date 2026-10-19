<?php
/**
 * Activation du thème et contrôle de version.
 *
 * - À l'activation (Apparence > Thèmes) : migrations → tables, rôles, listes, réglages.
 * - À chaque chargement : si un nouveau zip a été installé (PS_DB_VERSION plus grand),
 *   les migrations manquantes s'exécutent automatiquement (avec sauvegarde préalable).
 * - À la désactivation : RIEN n'est supprimé (données, tables, rôles conservés).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_switch_theme', 'ps_on_activate' );
function ps_on_activate(): void {
	ps_maybe_upgrade();
	// Les rôles sont réappliqués à chaque activation (ajout de capacités seulement).
	ps_register_roles();
}

// Contrôle de version léger à chaque requête (une simple lecture d'option en cache).
add_action( 'init', 'ps_check_version', 1 );
function ps_check_version(): void {
	if ( (int) get_option( 'ps_db_version', 0 ) < ps_target_db_version() ) {
		ps_maybe_upgrade();
	}
}
