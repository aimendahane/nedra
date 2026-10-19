<?php
/**
 * Annuaires : sociétés (ps_companies) et منافسون (ps_competitors).
 *
 * Chaque société / concurrent est enregistré UNE SEULE FOIS (brief §4.1-4.2) :
 *   - recherche par nom normalisé (inc/services/arabic.php) ;
 *   - à la saisie d'un nom sans choisir dans la liste, on réutilise la fiche existante
 *     au nom identique (après normalisation) au lieu d'en créer une deuxième ;
 *   - une fiche fusionnée (merged_into_id) n'est jamais supprimée : elle renvoie vers
 *     la fiche conservée (la fusion par la الإدارة arrive à l'étape 5).
 */

defined( 'ABSPATH' ) || exit;

/** Colonnes de coordonnées d'une société. */
const PS_COMPANY_FIELDS = array( 'person', 'email', 'phone', 'address', 'position', 'rank_title', 'website', 'notes' );

/**
 * Table d'un annuaire : 'companies' ou 'competitors'.
 */
function ps_dir_table( string $kind ): string {
	return ps_table( 'competitors' === $kind ? 'competitors' : 'companies' );
}

/**
 * Suit les fusions : renvoie l'id de la fiche conservée.
 */
function ps_dir_resolve( string $kind, int $id ): int {
	global $wpdb;
	$t = ps_dir_table( $kind );
	for ( $i = 0; $i < 10 && $id; $i++ ) {
		$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT merged_into_id FROM $t WHERE id = %d", $id ) );
		if ( ! $next ) {
			break;
		}
		$id = $next;
	}
	return $id;
}

function ps_dir_get( string $kind, int $id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_dir_table( $kind ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $row ?: null;
}

/**
 * Recherche (autocomplétion) : fiches non fusionnées dont le nom normalisé contient la saisie.
 * Les noms qui COMMENCENT par la saisie sont proposés en premier.
 */
function ps_dir_search( string $kind, string $q, int $limit = 10 ): array {
	global $wpdb;
	$t    = ps_dir_table( $kind );
	$norm = ps_normalize_text( $q );
	if ( '' === $norm ) {
		return array();
	}
	$like = '%' . $wpdb->esc_like( $norm ) . '%';
	$pre  = $wpdb->esc_like( $norm ) . '%';
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $t WHERE merged_into_id IS NULL AND name_norm LIKE %s ORDER BY (name_norm LIKE %s) DESC, CHAR_LENGTH(name) ASC LIMIT %d",
			$like,
			$pre,
			$limit
		),
		ARRAY_A
	);
}

/**
 * Fiche au nom identique (après normalisation), ou null.
 */
function ps_dir_find_by_name( string $kind, string $name ): ?array {
	global $wpdb;
	$norm = ps_normalize_text( $name );
	if ( '' === $norm ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_dir_table( $kind ) . ' WHERE merged_into_id IS NULL AND name_norm = %s ORDER BY id LIMIT 1', $norm ), ARRAY_A );
	return $row ?: null;
}

/**
 * Crée une fiche. $data : name + (sociétés) coordonnées.
 */
function ps_dir_create( string $kind, array $data, ?int $user_id = null ): int {
	global $wpdb;
	$now = ps_now();
	$row = array(
		'name'       => trim( (string) $data['name'] ),
		'name_norm'  => ps_normalize_text( $data['name'] ),
		'notes'      => $data['notes'] ?? null,
		'created_by' => $user_id ?? get_current_user_id(),
		'created_at' => $now,
		'updated_at' => $now,
	);
	if ( 'companies' === $kind ) {
		foreach ( PS_COMPANY_FIELDS as $f ) {
			$v = isset( $data[ $f ] ) ? trim( (string) $data[ $f ] ) : '';
			$row[ $f ] = '' === $v ? null : $v;
		}
	}
	$wpdb->insert( ps_dir_table( $kind ), $row );
	return (int) $wpdb->insert_id;
}

/**
 * Renvoie l'id de la fiche pour ce nom : existante (même nom normalisé) ou créée.
 * Pour une société existante, les coordonnées VIDES de la fiche sont complétées avec
 * celles saisies (jamais d'écrasement d'une valeur déjà présente).
 */
function ps_dir_find_or_create( string $kind, string $name, array $data = array(), ?int $user_id = null ): int {
	$existing = ps_dir_find_by_name( $kind, $name );
	if ( ! $existing ) {
		return ps_dir_create( $kind, array_merge( $data, array( 'name' => $name ) ), $user_id );
	}
	if ( 'companies' === $kind ) {
		ps_company_fill_empty( (int) $existing['id'], $data );
	}
	return (int) $existing['id'];
}

/**
 * Complète les coordonnées vides d'une société (sans rien écraser).
 */
function ps_company_fill_empty( int $id, array $data ): void {
	global $wpdb;
	$row = ps_dir_get( 'companies', $id );
	if ( ! $row ) {
		return;
	}
	$set = array();
	foreach ( PS_COMPANY_FIELDS as $f ) {
		$v = isset( $data[ $f ] ) ? trim( (string) $data[ $f ] ) : '';
		if ( '' !== $v && ( null === $row[ $f ] || '' === trim( (string) $row[ $f ] ) ) ) {
			$set[ $f ] = $v;
		}
	}
	if ( $set ) {
		$set['updated_at'] = ps_now();
		$wpdb->update( ps_table( 'companies' ), $set, array( 'id' => $id ) );
	}
}

/**
 * Modification d'une fiche (الإدارة). Renvoie false si le nouveau nom existe déjà.
 */
function ps_dir_update( string $kind, int $id, array $data ): bool {
	global $wpdb;
	$set = array( 'updated_at' => ps_now() );
	if ( isset( $data['name'] ) && '' !== trim( $data['name'] ) ) {
		$other = ps_dir_find_by_name( $kind, $data['name'] );
		if ( $other && (int) $other['id'] !== $id ) {
			return false;
		}
		$set['name']      = trim( $data['name'] );
		$set['name_norm'] = ps_normalize_text( $data['name'] );
	}
	$fields = 'companies' === $kind ? PS_COMPANY_FIELDS : array( 'notes' );
	foreach ( $fields as $f ) {
		if ( array_key_exists( $f, $data ) ) {
			$v         = trim( (string) $data[ $f ] );
			$set[ $f ] = '' === $v ? null : $v;
		}
	}
	$wpdb->update( ps_dir_table( $kind ), $set, array( 'id' => $id ) );
	return true;
}

/**
 * Liste de l'annuaire avec le nombre de projets liés (page الشركات والمنافسون).
 */
function ps_dir_list( string $kind, string $q = '', int $page = 1, int $per_page = 50 ): array {
	global $wpdb;
	$t     = ps_dir_table( $kind );
	$where = 'd.merged_into_id IS NULL';
	$args  = array();
	if ( '' !== ps_normalize_text( $q ) ) {
		$where .= ' AND d.name_norm LIKE %s';
		$args[] = '%' . $wpdb->esc_like( ps_normalize_text( $q ) ) . '%';
	}
	$projects = ps_table( 'projects' );
	if ( 'companies' === $kind ) {
		$link  = ps_table( 'project_parties' );
		$count = "SELECT COUNT(DISTINCT l.project_id) FROM $link l JOIN $projects p ON p.id = l.project_id AND p.deleted_at IS NULL WHERE l.company_id = d.id";
	} else {
		$link  = ps_table( 'project_competitors' );
		$count = "SELECT COUNT(DISTINCT l.project_id) FROM $link l JOIN $projects p ON p.id = l.project_id AND p.deleted_at IS NULL WHERE l.competitor_id = d.id";
	}
	$total_sql = "SELECT COUNT(*) FROM $t d WHERE $where";
	$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $total_sql, $args ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore
	$offset    = max( 0, ( $page - 1 ) * $per_page );
	$sql       = "SELECT d.*, ($count) AS project_count FROM $t d WHERE $where ORDER BY d.name LIMIT %d OFFSET %d";
	$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore
	return array( 'total' => $total, 'rows' => $rows );
}

/**
 * Format API d'une fiche.
 */
function ps_dir_to_api( string $kind, array $r ): array {
	$out = array(
		'id'           => (int) $r['id'],
		'name'         => $r['name'],
		'notes'        => $r['notes'] ?? null,
		'projectCount' => isset( $r['project_count'] ) ? (int) $r['project_count'] : null,
	);
	if ( 'companies' === $kind ) {
		foreach ( array( 'person', 'email', 'phone', 'address', 'position', 'website' ) as $f ) {
			$out[ $f ] = $r[ $f ];
		}
		$out['rank'] = $r['rank_title'];
	}
	return $out;
}

/**
 * Fusionne la fiche $from dans la fiche $into (الإدارة) :
 *   - les projets liés à $from sont réaffectés à $into (journal de chaque projet) ;
 *   - les coordonnées vides de $into sont complétées par celles de $from ;
 *   - $from n'est PAS supprimée : elle garde un lien vers $into (merged_into_id).
 *
 * @return int|WP_Error Nombre de liens de projets réaffectés.
 */
function ps_dir_merge( string $kind, int $from, int $into, int $user_id ) {
	global $wpdb;
	$src = ps_dir_get( $kind, $from );
	$dst = ps_dir_get( $kind, ps_dir_resolve( $kind, $into ) );
	if ( ! $src || ! $dst || $src['merged_into_id'] ) {
		return new WP_Error( 'ps_not_found', 'الفيشة غير موجودة أو مدموجة مسبقًا', array( 'status' => 404 ) );
	}
	if ( (int) $src['id'] === (int) $dst['id'] ) {
		return new WP_Error( 'ps_validation', 'لا يمكن دمج الفيشة في نفسها', array( 'status' => 422 ) );
	}
	$link = 'companies' === $kind ? ps_table( 'project_parties' ) : ps_table( 'project_competitors' );
	$col  = 'companies' === $kind ? 'company_id' : 'competitor_id';
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, project_id FROM $link WHERE $col = %d", (int) $src['id'] ), ARRAY_A ); // phpcs:ignore

	$wpdb->query( 'START TRANSACTION' );
	foreach ( $rows as $r ) {
		$wpdb->update( $link, array( $col => (int) $dst['id'], 'updated_at' => ps_now() ), array( 'id' => (int) $r['id'] ) );
		ps_log( (int) $r['project_id'], 'merge', 'companies' === $kind ? 'party' : 'competitor', (int) $r['id'], $col, $src['name'], $dst['name'], $user_id );
	}
	if ( 'companies' === $kind ) {
		ps_company_fill_empty( (int) $dst['id'], array_intersect_key( $src, array_flip( PS_COMPANY_FIELDS ) ) );
	}
	$wpdb->update( ps_dir_table( $kind ), array( 'merged_into_id' => (int) $dst['id'], 'updated_at' => ps_now() ), array( 'id' => (int) $src['id'] ) );
	ps_log( null, 'merge', $kind, (int) $src['id'], null, $src['name'], $dst['name'], $user_id );
	if ( $wpdb->last_error ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ps_db', 'تعذّر الدمج', array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' );
	return count( $rows );
}

/**
 * Doublons probables (noms proches) pour aider la الإدارة à fusionner.
 * Similarité ≥ 0.75 (inc/services/arabic.php). Au plus 50 paires.
 */
function ps_dir_duplicate_suggestions( string $kind ): array {
	global $wpdb;
	$rows  = $wpdb->get_results( 'SELECT id, name FROM ' . ps_dir_table( $kind ) . ' WHERE merged_into_id IS NULL ORDER BY id LIMIT 2000', ARRAY_A ); // phpcs:ignore
	$pairs = array();
	$n     = count( $rows );
	for ( $i = 0; $i < $n; $i++ ) {
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$s = ps_text_similarity( $rows[ $i ]['name'], $rows[ $j ]['name'] );
			if ( $s >= 0.75 ) {
				$pairs[] = array( 'a' => array( 'id' => (int) $rows[ $i ]['id'], 'name' => $rows[ $i ]['name'] ), 'b' => array( 'id' => (int) $rows[ $j ]['id'], 'name' => $rows[ $j ]['name'] ), 'similarity' => round( $s, 2 ) );
			}
		}
	}
	usort( $pairs, fn( $x, $y ) => $y['similarity'] <=> $x['similarity'] );
	return array_slice( $pairs, 0, 50 );
}
