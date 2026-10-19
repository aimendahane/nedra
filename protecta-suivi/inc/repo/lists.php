<?php
/**
 * Accès aux listes déroulantes (table ps_lists).
 * L'édition (ajout, renommage, masquage, ordre) arrive à l'étape 5 (الإعدادات).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transforme une ligne brute en tableau prêt pour l'API.
 */
function ps_list_row( array $r ): array {
	return array(
		'id'       => (int) $r['id'],
		'listKey'  => $r['list_key'],
		'code'     => $r['code'],
		'label'    => $r['label'],
		'type'     => $r['type'],
		'weight'   => null === $r['weight'] ? null : (int) $r['weight'],
		'color'    => $r['color'],
		'userId'   => null === $r['user_id'] ? null : (int) $r['user_id'],
		'sort'     => (int) $r['sort_order'],
		'hidden'   => (bool) $r['is_hidden'],
		'system'   => (bool) $r['is_system'],
		'aliases'  => $r['aliases'] ? (array) json_decode( $r['aliases'], true ) : array(),
		'meta'     => $r['meta'] ? (array) json_decode( $r['meta'], true ) : (object) array(),
	);
}

/**
 * Toutes les valeurs de toutes les listes (masquées comprises), groupées par liste.
 * Les valeurs masquées restent nécessaires pour afficher les anciens projets.
 */
function ps_lists_all(): array {
	global $wpdb;
	$table = ps_table( 'lists' );
	$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY list_key, sort_order, id", ARRAY_A ); // phpcs:ignore

	$out = array_fill_keys( array_keys( ps_list_labels() ), array() );
	foreach ( $rows as $r ) {
		$out[ $r['list_key'] ][] = ps_list_row( $r );
	}
	return $out;
}

/**
 * Valeurs d'une liste.
 */
function ps_list_values( string $list_key, bool $include_hidden = true ): array {
	global $wpdb;
	$table = ps_table( 'lists' );
	$sql   = "SELECT * FROM $table WHERE list_key = %s" . ( $include_hidden ? '' : ' AND is_hidden = 0' ) . ' ORDER BY sort_order, id';
	return array_map( 'ps_list_row', $wpdb->get_results( $wpdb->prepare( $sql, $list_key ), ARRAY_A ) ); // phpcs:ignore
}

/**
 * Identifiant d'une valeur par son code (ex. ps_list_id_by_code( 'offer_status', 'sent' )).
 */
function ps_list_id_by_code( string $list_key, string $code ): ?int {
	global $wpdb;
	$table = ps_table( 'lists' );
	$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE list_key = %s AND code = %s ORDER BY id LIMIT 1", $list_key, $code ) );
	return $id ? (int) $id : null;
}
