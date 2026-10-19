<?php
/**
 * Règles de propriété : « est-ce MON projet ? » (décision client n°1, voir CLAUDE.md).
 *
 * Les capacités (inc/roles.php) disent quel TYPE d'action un rôle peut faire ;
 * ces fonctions disent si l'utilisateur peut la faire SUR CE projet. Elles sont appelées
 * par l'API avant chaque modification (jamais seulement dans l'interface).
 *
 *   Modifier les infos générales d'un projet (+ أطراف، منافسون، structure des أصناف) :
 *     - الإدارة (ps_edit_all_projects) : tous les projets ;
 *     - مسؤول مشروع (ps_edit_own_projects) : les projets dont il est « المسؤول عن المشروع » ;
 *     - le CRÉATEUR du projet : pendant 24 h après l'enregistrement (réglage creator_edit_hours).
 *   Ajouter au سجل المتابعة / joindre un fichier : الإدارة, ou toute personne qui intervient
 *     sur le projet (المسؤول، المتابع، الوسيط، موظف d'un صنف) ou qui l'a créé.
 *
 * Lien utilisateur ↔ employé : méta « ps_employee_id » du compte (id dans la liste « employee »).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Employé (liste « employee ») associé à un compte, ou null.
 */
function ps_user_employee_id( int $user_id ): ?int {
	$id = (int) get_user_meta( $user_id, 'ps_employee_id', true );
	return $id ?: null;
}

/**
 * Nombre d'heures écoulées depuis une date MySQL enregistrée en heure locale WordPress.
 */
function ps_hours_since( ?string $mysql_datetime, ?int $now_ts = null ): float {
	if ( ! $mysql_datetime ) {
		return INF;
	}
	$then = date_create_immutable( $mysql_datetime, wp_timezone() );
	if ( ! $then ) {
		return INF;
	}
	return ( ( $now_ts ?? time() ) - $then->getTimestamp() ) / HOUR_IN_SECONDS;
}

/**
 * Pourquoi l'utilisateur peut modifier ce projet : 'admin' | 'responsible' | 'creator' | null (ne peut pas).
 *
 * @param array $project Ligne brute de ps_projects (au moins responsible_emp_id, created_by, created_at, deleted_at).
 */
function ps_project_edit_reason( array $project, int $user_id, ?int $now_ts = null ): ?string {
	if ( ! $user_id || ! empty( $project['deleted_at'] ) ) {
		return null;
	}
	if ( user_can( $user_id, 'ps_edit_all_projects' ) ) {
		return 'admin';
	}
	$emp = ps_user_employee_id( $user_id );
	if ( $emp && user_can( $user_id, 'ps_edit_own_projects' ) && (int) $project['responsible_emp_id'] === $emp ) {
		return 'responsible';
	}
	if ( (int) $project['created_by'] === $user_id
		&& ps_hours_since( $project['created_at'], $now_ts ) < (float) ps_setting( 'creator_edit_hours' ) ) {
		return 'creator';
	}
	return null;
}

/**
 * Fin de la fenêtre de modification du créateur (« Y-m-d H:i:s », heure locale), ou null.
 */
function ps_creator_edit_until( array $project ): ?string {
	$then = date_create_immutable( (string) $project['created_at'], wp_timezone() );
	if ( ! $then ) {
		return null;
	}
	return $then->modify( '+' . (int) ps_setting( 'creator_edit_hours' ) . ' hours' )->format( 'Y-m-d H:i:s' );
}

/**
 * L'utilisateur intervient-il sur ce projet (pour سجل المتابعة et fichiers) ?
 *
 * @param array $item_employee_ids Employés des أصناف du projet.
 */
function ps_user_involved_in_project( array $project, array $item_employee_ids, int $user_id ): bool {
	if ( ! $user_id || ! empty( $project['deleted_at'] ) ) {
		return false;
	}
	if ( user_can( $user_id, 'ps_edit_all_projects' ) || (int) $project['created_by'] === $user_id ) {
		return true;
	}
	$emp = ps_user_employee_id( $user_id );
	if ( ! $emp ) {
		return false;
	}
	$people = array( (int) $project['responsible_emp_id'], (int) $project['follower_emp_id'], (int) $project['intermediary_emp_id'] );
	return in_array( $emp, $people, true ) || in_array( $emp, array_map( 'intval', $item_employee_ids ), true );
}
