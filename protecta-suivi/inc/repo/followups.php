<?php
/**
 * سجل المتابعة (brief §4.4) : chronologie des suivis d'un projet.
 * Chaque entrée = date, auteur, texte, prochaine date de suivi (optionnelle).
 * Le champ NOTE du projet reste réservé au texte de la demande.
 *
 * Modification d'une entrée : son auteur pendant 24 h (réglage creator_edit_hours) ou la الإدارة.
 * Les entrées ne sont jamais supprimées (historique).
 */

defined( 'ABSPATH' ) || exit;

function ps_followup_get( int $id ): ?array {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'followups' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $r ?: null;
}

/**
 * Entrées d'un projet, de la plus récente à la plus ancienne.
 */
function ps_followups_for_project( int $project_id ): array {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare( 'SELECT * FROM ' . ps_table( 'followups' ) . ' WHERE project_id = %d ORDER BY entry_date DESC, id DESC', $project_id ),
		ARRAY_A
	);
}

function ps_followup_add( int $project_id, string $entry_date, string $text, ?string $next_date, int $user_id ): int {
	global $wpdb;
	$now = ps_now();
	$wpdb->insert(
		ps_table( 'followups' ),
		array(
			'project_id' => $project_id,
			'entry_date' => $entry_date,
			'author_id'  => $user_id,
			'text'       => $text,
			'next_date'  => $next_date,
			'created_at' => $now,
			'updated_at' => $now,
		)
	);
	return (int) $wpdb->insert_id;
}

function ps_followup_update( array $old, string $entry_date, string $text, ?string $next_date, int $user_id ): void {
	global $wpdb;
	$new = array( 'entry_date' => $entry_date, 'text' => $text, 'next_date' => $next_date );
	foreach ( $new as $col => $v ) {
		if ( (string) $old[ $col ] !== (string) $v ) {
			ps_log( (int) $old['project_id'], 'update', 'followup', (int) $old['id'], $col, $old[ $col ], $v, $user_id );
		}
	}
	$wpdb->update( ps_table( 'followups' ), array_merge( $new, array( 'updated_at' => ps_now() ) ), array( 'id' => (int) $old['id'] ) );
}

/**
 * L'utilisateur peut-il modifier cette entrée ?
 */
function ps_followup_can_edit( array $f, int $user_id ): bool {
	if ( user_can( $user_id, 'ps_edit_all_projects' ) ) {
		return true;
	}
	return (int) $f['author_id'] === $user_id && ps_hours_since( $f['created_at'] ) < (float) ps_setting( 'creator_edit_hours' );
}

function ps_followup_to_api( array $f, int $user_id ): array {
	$u = $f['author_id'] ? get_userdata( (int) $f['author_id'] ) : null;
	return array(
		'id'        => (int) $f['id'],
		'projectId' => (int) $f['project_id'],
		'date'      => $f['entry_date'],
		'text'      => $f['text'],
		'nextDate'  => $f['next_date'],
		'author'    => $u ? $u->display_name : '—',
		'authorId'  => $f['author_id'] ? (int) $f['author_id'] : null,
		'createdAt' => $f['created_at'],
		'canEdit'   => ps_followup_can_edit( $f, $user_id ),
	);
}
