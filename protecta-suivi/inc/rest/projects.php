<?php
/**
 * API des projets.
 *
 *   GET    /projects                 Base Projets : liste filtrée / triée / paginée
 *   POST   /projects                 créer (Fiche Projet)          — ps_create_project
 *   GET    /projects/{id}            projet complet + droits
 *   PUT    /projects/{id}            modifier                       — règle de propriété (inc/permissions.php)
 *   DELETE /projects/{id}            corbeille                      — ps_delete (الإدارة)
 *   POST   /projects/{id}/restore    restaurer depuis la corbeille  — ps_delete
 *   GET    /projects/{id}/changelog  journal des modifications
 *   POST   /projects/bulk-source     attribuer un مصدر à plusieurs projets — ps_edit_all_projects
 *   GET    /projects/suggest-offer-no?date=AAAA-MM-JJ  suggestion de رقم العرض
 *
 * Enregistrement : validation (422 + erreurs par champ), puis détection de doublons
 * (409 + liste ; renvoyer avec confirmDuplicates=true pour enregistrer quand même).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_project_routes' );
function ps_register_project_routes(): void {
	$id = '/projects/(?P<id>\d+)';

	register_rest_route(
		PS_REST_NS,
		'/projects',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_projects_list',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => ps_rest_permission( 'ps_create_project' ),
				'callback'            => 'ps_rest_project_create',
			),
		)
	);
	register_rest_route(
		PS_REST_NS,
		$id,
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_project_get',
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_project_update',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => ps_rest_permission( 'ps_delete' ),
				'callback'            => 'ps_rest_project_delete',
			),
		)
	);
	register_rest_route(
		PS_REST_NS,
		$id . '/restore',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission( 'ps_delete' ),
			'callback'            => 'ps_rest_project_restore',
		)
	);
	register_rest_route(
		PS_REST_NS,
		$id . '/changelog',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function ( WP_REST_Request $r ) {
				return ps_project_row( (int) $r['id'] ) ? rest_ensure_response( ps_changelog_for_project( (int) $r['id'] ) ) : ps_rest_not_found();
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/projects/bulk-source',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission( 'ps_edit_all_projects' ),
			'callback'            => 'ps_rest_projects_bulk_source',
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/projects/suggest-offer-no',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission( 'ps_create_project' ),
			'callback'            => 'ps_rest_suggest_offer_no',
		)
	);
}

function ps_rest_not_found(): WP_Error {
	return new WP_Error( 'ps_not_found', 'المشروع غير موجود', array( 'status' => 404 ) );
}

function ps_rest_projects_list( WP_REST_Request $r ) {
	$args = $r->get_params();
	if ( ! empty( $args['trash'] ) && ! current_user_can( 'ps_delete' ) ) {
		return new WP_Error( 'ps_forbidden', 'ليس لديك صلاحية لهذه العملية.', array( 'status' => 403 ) );
	}
	return rest_ensure_response( ps_projects_list( $args ) );
}

function ps_rest_project_get( WP_REST_Request $r ) {
	$p = ps_project_load( (int) $r['id'] );
	if ( ! $p || ( $p['fields']['deleted_at'] && ! current_user_can( 'ps_delete' ) ) ) {
		return ps_rest_not_found();
	}
	return rest_ensure_response( ps_project_to_api( $p, get_current_user_id() ) );
}

/**
 * Validation + doublons communs à la création et à la modification.
 *
 * @return WP_Error|null
 */
function ps_rest_project_check( array $in, ?array $existing, bool $confirm_duplicates ) {
	$errors = ps_validate_project( $in, $existing );
	if ( $errors ) {
		return new WP_Error( 'ps_validation', 'يرجى تصحيح الخانات المعلّمة', array( 'status' => 422, 'errors' => $errors ) );
	}
	// En modification, l'avertissement ne revient que si le nom, la société ou la date changent.
	$relevant = ! $existing
		|| ps_normalize_text( $existing['fields']['name'] ) !== ps_normalize_text( (string) $in['fields']['name'] )
		|| (string) $existing['fields']['received_date'] !== (string) $in['fields']['received_date']
		|| (int) ( $existing['parties']['owner']['company_id'] ?? 0 ) !== (int) ( $in['parties']['owner']['company_id'] ?? 0 )
		|| ( empty( $in['parties']['owner']['company_id'] ) && ! empty( $in['parties']['owner']['company_name'] ) );
	if ( ! $confirm_duplicates && $relevant ) {
		$dups = ps_find_duplicate_projects( $in, $existing ? (int) $existing['fields']['id'] : null );
		if ( $dups ) {
			return new WP_Error( 'ps_duplicates', 'يوجد مشروع مشابه لنفس الشركة', array( 'status' => 409, 'duplicates' => $dups ) );
		}
	}
	return null;
}

function ps_rest_project_create( WP_REST_Request $r ) {
	$body = (array) $r->get_json_params();
	$in   = ps_project_input( $body );
	$err  = ps_rest_project_check( $in, null, ! empty( $body['confirmDuplicates'] ) );
	if ( $err ) {
		return $err;
	}
	$id = ps_project_save( $in, null, get_current_user_id() );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$res = rest_ensure_response( ps_project_to_api( ps_project_load( $id ), get_current_user_id() ) );
	$res->set_status( 201 );
	return $res;
}

function ps_rest_project_update( WP_REST_Request $r ) {
	$existing = ps_project_load( (int) $r['id'] );
	if ( ! $existing || $existing['fields']['deleted_at'] ) {
		return ps_rest_not_found();
	}
	$uid = get_current_user_id();
	if ( ! ps_project_edit_reason( $existing['fields'], $uid ) ) {
		return new WP_Error( 'ps_forbidden', 'لا يمكنك تعديل هذا المشروع (فقط المسؤول عنه، الإدارة، أو من أنشأه خلال 24 ساعة).', array( 'status' => 403 ) );
	}
	$body = (array) $r->get_json_params();
	$in   = ps_project_input( $body );
	$err  = ps_rest_project_check( $in, $existing, ! empty( $body['confirmDuplicates'] ) );
	if ( $err ) {
		return $err;
	}
	$id = ps_project_save( $in, $existing, $uid );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return rest_ensure_response( ps_project_to_api( ps_project_load( $id ), $uid ) );
}

function ps_rest_project_delete( WP_REST_Request $r ) {
	$row = ps_project_row( (int) $r['id'] );
	if ( ! $row ) {
		return ps_rest_not_found();
	}
	if ( ! $row['deleted_at'] ) {
		ps_project_soft_delete( (int) $row['id'], get_current_user_id() );
	}
	return rest_ensure_response( array( 'ok' => true ) );
}

function ps_rest_project_restore( WP_REST_Request $r ) {
	$row = ps_project_row( (int) $r['id'] );
	if ( ! $row ) {
		return ps_rest_not_found();
	}
	if ( $row['deleted_at'] ) {
		ps_project_restore( (int) $row['id'], get_current_user_id() );
	}
	return rest_ensure_response( array( 'ok' => true ) );
}

function ps_rest_projects_bulk_source( WP_REST_Request $r ) {
	$source = (int) $r->get_param( 'sourceId' );
	$row    = ps_lists_by_id()[ $source ] ?? null;
	if ( ! $row || 'source' !== $row['list_key'] || 'unspecified' === $row['code'] ) {
		return new WP_Error( 'ps_validation', 'اختر مصدرًا صحيحًا', array( 'status' => 422 ) );
	}
	$ids = array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) );
	if ( ! $ids ) {
		return new WP_Error( 'ps_validation', 'لم يتم اختيار أي مشروع', array( 'status' => 422 ) );
	}
	return rest_ensure_response( array( 'updated' => ps_projects_bulk_source( $ids, $source, get_current_user_id() ) ) );
}

/**
 * Suggestion de رقم العرض : OFR-<année>-<prochain numéro libre> (modifiable par l'utilisateur).
 */
function ps_rest_suggest_offer_no( WP_REST_Request $r ) {
	global $wpdb;
	$date = (string) $r->get_param( 'date' );
	$year = ps_is_valid_date( $date ) ? (int) substr( $date, 0, 4 ) : (int) current_time( 'Y' );
	$t    = ps_table( 'projects' );
	$n    = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(seq) FROM $t WHERE year = %d", $year ) );
	do {
		$no     = sprintf( 'OFR-%d-%03d', $year, $n++ );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $t WHERE offer_no = %s", $no ) );
	} while ( $exists );
	return rest_ensure_response( array( 'offerNo' => $no ) );
}
