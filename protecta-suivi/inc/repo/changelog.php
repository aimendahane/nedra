<?php
/**
 * Journal des modifications (qui, quoi, quand) — table ps_changelog.
 *
 * Les anciennes / nouvelles valeurs sont enregistrées en TEXTE LISIBLE au moment de la
 * modification (ex. « في دراسة » plutôt qu'un identifiant) : le journal montre ce que
 * l'utilisateur voyait à ce moment-là, même si une liste est renommée plus tard.
 *
 * Actions : create, update, delete, restore, merge, import.
 * Entités : project, party, item, competitor, followup, file.
 */

defined( 'ABSPATH' ) || exit;

function ps_log( ?int $project_id, string $action, string $entity, ?int $entity_id = null, ?string $field = null, $old = null, $new = null, ?int $user_id = null ): void {
	global $wpdb;
	$wpdb->insert(
		ps_table( 'changelog' ),
		array(
			'project_id' => $project_id,
			'user_id'    => $user_id ?? get_current_user_id(),
			'action'     => $action,
			'entity'     => $entity,
			'entity_id'  => $entity_id,
			'field'      => $field,
			'old_value'  => null === $old ? null : (string) $old,
			'new_value'  => null === $new ? null : (string) $new,
			'created_at' => ps_now(),
		)
	);
}

/**
 * Journal d'un projet, du plus récent au plus ancien, avec le nom de l'auteur.
 */
function ps_changelog_for_project( int $project_id, int $limit = 300 ): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare( 'SELECT * FROM ' . ps_table( 'changelog' ) . ' WHERE project_id = %d ORDER BY id DESC LIMIT %d', $project_id, $limit ),
		ARRAY_A
	);
	return array_map(
		static function ( $r ) {
			$u = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
			return array(
				'id'       => (int) $r['id'],
				'at'       => $r['created_at'],
				'user'     => $u ? $u->display_name : '—',
				'action'   => $r['action'],
				'entity'   => $r['entity'],
				'entityId' => $r['entity_id'] ? (int) $r['entity_id'] : null,
				'field'    => $r['field'],
				'old'      => $r['old_value'],
				'new'      => $r['new_value'],
			);
		},
		$rows
	);
}
