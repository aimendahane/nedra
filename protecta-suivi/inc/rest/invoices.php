<?php
/**
 * API des factures (1.6.0) — règles dans inc/repo/invoices.php.
 *
 *   GET    /invoices?status=pending|late|answered|yes|no|all&q=&employeeId=&projectId=&itemId=   liste
 *   GET    /invoices/{id}             une facture (+ ses fichiers)
 *   POST   /projects/{id}/invoices    nouvelle facture pour l'OFFRE ENTIÈRE du projet (1.11.0)
 *   POST   /items/{id}/invoices       idem, à partir d'un صنف de l'offre (la facture suit l'offre entière)
 *   PUT    /invoices/{id}             modifier (seules les clés envoyées)
 *   DELETE /invoices/{id}             retirer (corbeille) — مسؤول المشروع / الإدارة
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_invoice_routes' );
function ps_register_invoice_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/invoices',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static fn( WP_REST_Request $r ) => rest_ensure_response( ps_invoices_list( $r->get_params(), get_current_user_id() ) ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/invoices/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_invoice_get',
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_invoice_update',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_invoice_delete',
			),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/items/(?P<id>\d+)/invoices',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static function ( WP_REST_Request $r ) {
				$item = ps_item_row( (int) $r['id'] );
				return ps_rest_invoice_create_for( $item ? (int) $item['project_id'] : 0, (array) $r->get_json_params() );
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/projects/(?P<id>\d+)/invoices',
		array(
			'methods'             => 'POST',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static fn( WP_REST_Request $r ) => ps_rest_invoice_create_for( (int) $r['id'], (array) $r->get_json_params() ),
		)
	);
}

/** Facture au format API, avec ses fichiers ; null si absente / retirée. */
function ps_rest_invoice_one( int $id ): ?array {
	$uid = get_current_user_id();
	$row = ps_invoices_list( array( 'id' => $id ), $uid )['rows'][0] ?? null;
	if ( ! $row ) {
		return null;
	}
	global $wpdb;
	$files        = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'files' ) . ' WHERE invoice_id = %d AND deleted_at IS NULL ORDER BY id DESC', $id ), ARRAY_A );
	$row['files'] = array_map( fn( $f ) => ps_file_to_api( $f, $uid ), $files );
	return $row;
}

function ps_rest_invoice_get( WP_REST_Request $r ) {
	$one = ps_rest_invoice_one( (int) $r['id'] );
	return $one ? rest_ensure_response( $one ) : new WP_Error( 'ps_not_found', ps__( 'الفاتورة غير موجودة' ), array( 'status' => 404 ) );
}

/**
 * Contexte d'une facture : [ صنف, projet ]. Facture de l'offre entière (1.11.0, item_id = 0) → صنف vide.
 *
 * @return array|WP_Error
 */
function ps_rest_invoice_context( array $inv ) {
	$proj = ps_project_row( (int) $inv['project_id'] );
	$item = (int) $inv['item_id'] ? ps_item_row( (int) $inv['item_id'] ) : array( 'id' => 0, 'project_id' => (int) $inv['project_id'] );
	if ( ! $item || ! $proj || $proj['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'الفاتورة غير موجودة' ), array( 'status' => 404 ) );
	}
	return array( $item, $proj );
}

/** Nouvelle facture pour l'offre entière d'un projet (« مشروع = نعم » seulement). */
function ps_rest_invoice_create_for( int $project_id, array $body ) {
	$proj = $project_id ? ps_project_row( $project_id ) : null;
	if ( ! $proj || $proj['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'المشروع غير موجود' ), array( 'status' => 404 ) );
	}
	$uid = get_current_user_id();
	if ( ! ps_invoice_can_edit_offer( $proj, $uid ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'إضافة فاتورة: للموظف المسؤول عن الصنف، المتابع، المسؤول عن المشروع أو الإدارة.' ), array( 'status' => 403 ) );
	}
	if ( PS_PROJECT_YES !== (int) $proj['is_project'] ) { // « لا » ou « لم يُحدد بعد »
		return new WP_Error( 'ps_validation', ps__( 'هذا الطلب مسجّل «مشروع مُلغى»: لا عروض ولا فواتير.' ), array( 'status' => 422 ) );
	}
	$id = ps_invoice_create( $proj, $body, $uid );
	return is_wp_error( $id ) ? $id : rest_ensure_response( ps_rest_invoice_one( $id ) );
}

function ps_rest_invoice_update( WP_REST_Request $r ) {
	$inv = ps_invoice_row( (int) $r['id'] );
	$ctx = $inv && ! $inv['deleted_at'] ? ps_rest_invoice_context( $inv ) : new WP_Error( 'ps_not_found', ps__( 'الفاتورة غير موجودة' ), array( 'status' => 404 ) );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	$uid = get_current_user_id();
	if ( ! ps_invoice_can_edit( $ctx[0], $ctx[1], $uid ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'تعديل الفاتورة: للموظف المسؤول عن الصنف، المتابع، المسؤول عن المشروع أو الإدارة.' ), array( 'status' => 403 ) );
	}
	$res = ps_invoice_update( $inv, (array) $r->get_json_params(), $uid );
	return is_wp_error( $res ) ? $res : rest_ensure_response( ps_rest_invoice_one( (int) $inv['id'] ) );
}

function ps_rest_invoice_delete( WP_REST_Request $r ) {
	$inv = ps_invoice_row( (int) $r['id'] );
	$ctx = $inv && ! $inv['deleted_at'] ? ps_rest_invoice_context( $inv ) : new WP_Error( 'ps_not_found', ps__( 'الفاتورة غير موجودة' ), array( 'status' => 404 ) );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	if ( ! ps_invoice_can_delete( $ctx[1], get_current_user_id() ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'حذف الفاتورة: للمسؤول عن المشروع والإدارة فقط.' ), array( 'status' => 403 ) );
	}
	ps_invoice_soft_delete( $inv, get_current_user_id() );
	return rest_ensure_response( array( 'ok' => true ) );
}
