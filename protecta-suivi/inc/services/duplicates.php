<?php
/**
 * Détection de doublons (brief §4.7) : à l'enregistrement, AVERTIR (sans bloquer) si un
 * projet de la même société (المالك) avec un nom proche existe dans les 60 derniers jours.
 *
 *   - même société : même fiche de l'annuaire (après fusions) ;
 *   - nom proche   : similarité ≥ PS_DUPLICATE_SIMILARITY (voir ps_text_similarity) ;
 *   - période      : تاريخ استلام الطلب à ± N jours (réglage duplicate_window_days, 60 par défaut).
 *
 * L'utilisateur voit la liste et peut choisir « حفظ رغم ذلك ».
 */

defined( 'ABSPATH' ) || exit;

/** Seuil de similarité des noms (0 à 1). */
const PS_DUPLICATE_SIMILARITY = 0.75;

/**
 * Projets ressemblant au projet en cours d'enregistrement.
 *
 * @param array    $in         Format interne (ps_project_input).
 * @param int|null $exclude_id Projet modifié (exclu de la recherche).
 * @return array Liste [ id, projectNo, name, receivedDate, companyName, similarity ].
 */
function ps_find_duplicate_projects( array $in, ?int $exclude_id = null ): array {
	global $wpdb;
	$owner = $in['parties']['owner'] ?? array();
	$name  = (string) ( $in['fields']['name'] ?? '' );
	$date  = (string) ( $in['fields']['received_date'] ?? '' );
	if ( '' === trim( $name ) || ! ps_is_valid_date( $date ) ) {
		return array();
	}

	// Société : fiche choisie, ou fiche existante au même nom.
	$company_id = null;
	if ( ! empty( $owner['company_id'] ) ) {
		$company_id = ps_dir_resolve( 'companies', (int) $owner['company_id'] );
	} elseif ( ! empty( $owner['company_name'] ) ) {
		$found      = ps_dir_find_by_name( 'companies', $owner['company_name'] );
		$company_id = $found ? (int) $found['id'] : null;
	}
	if ( ! $company_id ) {
		return array(); // Société nouvelle : aucun projet antérieur possible.
	}

	$days = max( 1, (int) ps_setting( 'duplicate_window_days' ) );
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT p.id, p.project_no, p.name, p.received_date, c.name AS company_name FROM ' . ps_table( 'projects' ) . ' p
			 JOIN ' . ps_table( 'project_parties' ) . " pp ON pp.project_id = p.id AND pp.role = 'owner'
			 JOIN " . ps_table( 'companies' ) . ' c ON c.id = pp.company_id
			 WHERE p.deleted_at IS NULL AND pp.company_id = %d AND p.id <> %d
			   AND p.received_date BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)',
			$company_id,
			(int) $exclude_id,
			$date,
			$days,
			$date,
			$days
		),
		ARRAY_A
	);

	$out = array();
	foreach ( $rows as $r ) {
		$sim = ps_text_similarity( $name, $r['name'] );
		if ( $sim >= PS_DUPLICATE_SIMILARITY ) {
			$out[] = array(
				'id'           => (int) $r['id'],
				'projectNo'    => $r['project_no'],
				'name'         => $r['name'],
				'receivedDate' => $r['received_date'],
				'companyName'  => $r['company_name'],
				'similarity'   => round( $sim, 2 ),
			);
		}
	}
	usort( $out, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
	return $out;
}
