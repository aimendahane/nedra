<?php
/**
 * Rôles et capacités (cahier des charges §1).
 *
 *   ps_employee : موظف        — saisit des projets, met à jour SES أصناف, سجل المتابعة, fichiers.
 *   ps_manager  : مسؤول مشروع — modifie toutes les données de SES projets ; envoie des tâches libres.
 *   ps_admin    : الإدارة     — tous les droits (Recap, الإعدادات, export, suppression…).
 *
 * L'administrateur WordPress reçoit aussi toutes les capacités ps_*.
 *
 * Les capacités disent « quel type d'action » ; la règle « c'est MON projet / MON صنف »
 * (y compris la fenêtre de 24 h du créateur) est vérifiée dans l'API (étape 2).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capacités par rôle. Modifier ici puis augmenter PS_DB_VERSION avec une migration
 * qui appelle ps_register_roles() pour appliquer le changement.
 */
function ps_role_definitions(): array {
	$employee = array(
		'read'                => true,
		'ps_access'           => true, // Accès à l'application.
		'ps_create_project'   => true, // Créer un projet (Fiche Projet).
		'ps_edit_own_items'   => true, // Statut d'offre / livraison de ses أصناف.
		'ps_add_followup'     => true, // Ajouter au سجل المتابعة, joindre des fichiers.
		'ps_add_directory'    => true, // Ajouter une société / un concurrent à l'annuaire.
	);

	$manager = array_merge(
		$employee,
		array(
			'ps_edit_own_projects' => true, // Toutes les données des projets dont il est المسؤول.
			'ps_assign_tasks'      => true, // Envoyer une tâche libre (« مهمة جديدة ») à un employé (1.0.1).
		)
	);

	$admin = array_merge(
		$manager,
		array(
			'ps_edit_all_projects' => true,
			'ps_view_recap'        => true,
			'ps_manage_settings'   => true, // الإعدادات : listes, règles.
			'ps_manage_users'      => true, // Gestion des utilisateurs.
			'ps_manage_directory'  => true, // Modifier / fusionner sociétés et concurrents.
			'ps_export'            => true, // CSV / Excel, sauvegarde, restauration, import.
			'ps_delete'            => true, // Suppression (corbeille).
		)
	);

	return array(
		'ps_employee' => array( 'label' => 'موظف', 'caps' => $employee ),
		'ps_manager'  => array( 'label' => 'مسؤول مشروع', 'caps' => $manager ),
		'ps_admin'    => array( 'label' => 'الإدارة', 'caps' => $admin ),
	);
}

/**
 * Toutes les capacités ps_* connues.
 */
function ps_all_caps(): array {
	$defs = ps_role_definitions();
	return array_values( array_filter( array_keys( $defs['ps_admin']['caps'] ), fn( $c ) => str_starts_with( $c, 'ps_' ) ) );
}

/**
 * Crée ou complète les rôles. Idempotent ; n'enlève jamais un utilisateur d'un rôle.
 */
function ps_register_roles(): void {
	foreach ( ps_role_definitions() as $slug => $def ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			add_role( $slug, $def['label'], $def['caps'] );
			continue;
		}
		foreach ( $def['caps'] as $cap => $grant ) {
			if ( empty( $role->capabilities[ $cap ] ) && $grant ) {
				$role->add_cap( $cap );
			}
		}
	}

	$wp_admin = get_role( 'administrator' );
	if ( $wp_admin ) {
		foreach ( ps_all_caps() as $cap ) {
			$wp_admin->add_cap( $cap );
		}
	}
}

/**
 * Libellé arabe du rôle principal d'un utilisateur.
 */
function ps_user_role_label( WP_User $user ): string {
	if ( user_can( $user, 'ps_manage_settings' ) ) {
		return 'الإدارة';
	}
	$defs = ps_role_definitions();
	foreach ( $user->roles as $r ) {
		if ( isset( $defs[ $r ] ) ) {
			return $defs[ $r ]['label'];
		}
	}
	return '';
}
