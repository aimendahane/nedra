<?php
/**
 * API des listes déroulantes.
 *
 *   GET  /ps/v1/lists → { labels: {...}, followupTypes: {...}, lists: { urgency: [...], ... } }
 *   POST /ps/v1/lists/{key}/quick-add { label, code } → ajout rapide pendant la saisie
 *        (cahier §8.4-8.5 : « + إضافة مكان جديد » et nouveau صنف), seulement pour
 *        les listes place et item_type. Si la valeur existe déjà (même nom normalisé),
 *        elle est renvoyée au lieu d'être dupliquée.
 *
 * Toutes les valeurs sont renvoyées, masquées comprises (« hidden: true ») : l'interface
 * ne propose à la saisie que les valeurs visibles, mais affiche les anciennes valeurs
 * des projets existants.
 */

defined( 'ABSPATH' ) || exit;

/** Listes de الإعدادات (réponse de GET /lists ; 1.11.0 : aussi incluse dans la page pour éviter un aller-retour). */
function ps_lists_payload(): array {
	return array(
		'labels'        => ps_list_labels(),
		'followupTypes' => ps_followup_types(),
		'lists'         => ps_lists_all(),
	);
}

add_action( 'rest_api_init', 'ps_register_lists_routes' );
function ps_register_lists_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/lists',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static fn() => rest_ensure_response( ps_lists_payload() ),
		)
	);

	register_rest_route(
		PS_REST_NS,
		'/lists/(?P<key>place|item_type)/quick-add',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission( 'ps_create_project' ),
			'callback'            => 'ps_rest_list_quick_add',
		)
	);
}

function ps_rest_list_quick_add( WP_REST_Request $r ) {
	global $wpdb;
	$key   = (string) $r['key'];
	$label = ps_clean_text( $r->get_param( 'label' ) );
	$code  = ps_clean_text( $r->get_param( 'code' ) );
	if ( null === $label ) {
		return new WP_Error( 'ps_validation', ps__( 'اكتب الاسم' ), array( 'status' => 422 ) );
	}
	$code = null === $code ? null : strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
	foreach ( ps_list_values( $key ) as $v ) {
		$same_label = ps_normalize_text( $v['label'] ) === ps_normalize_text( $label );
		$same_code  = $code && $v['code'] && strtoupper( $v['code'] ) === $code;
		if ( $same_label || $same_code ) {
			if ( $v['hidden'] ) {
				return new WP_Error( 'ps_exists_hidden', ps__( 'هذه القيمة موجودة لكنها مخفية؛ اطلب من الإدارة إظهارها' ), array( 'status' => 409 ) );
			}
			return rest_ensure_response( array( 'value' => $v, 'existed' => true ) );
		}
	}
	$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(sort_order),0) FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s', $key ) );
	$id  = ps_insert_list_value( $key, array( 'label' => $label, 'code' => $code ), $max + 10 );
	ps_log( null, 'create', 'list', $id, $key, null, $label );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'lists' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return rest_ensure_response( array( 'value' => ps_list_row( $row ), 'existed' => false ) );
}
