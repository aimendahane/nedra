<?php
/**
 * API des factures (1.6.0) — règles dans inc/repo/invoices.php.
 *
 *   GET    /invoices?status=pending|late|answered|yes|no|all&q=&employeeId=&projectId=&itemId=   liste
 *   GET    /invoices/{id}             une facture (+ ses fichiers)
 *   POST   /items/{id}/invoices       nouvelle facture pour une offre
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
			'callback'            => 'ps_rest_invoice_create',
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

/** Offre + projet (non supprimé, et projet « مشروع = نعم »). @return array|WP_Error [ item, project ] */
function ps_rest_invoice_context( int $item_id ) {
	$item = ps_item_row( $item_id );
	$proj = $item ? ps_project_row( (int) $item['project_id'] ) : null;
	if ( ! $item || ! $proj || $proj['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'الصنف غير موجود' ), array( 'status' => 404 ) );
	}
	return array( $item, $proj );
}

function ps_rest_invoice_create( WP_REST_Request $r ) {
	$ctx = ps_rest_invoice_context( (int) $r['id'] );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	list( $item, $proj ) = $ctx;
	$uid = get_current_user_id();
	if ( ! ps_invoice_can_edit( $item, $proj, $uid ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'إضافة فاتورة: للموظف المسؤول عن الصنف، المتابع، المسؤول عن المشروع أو الإدارة.' ), array( 'status' => 403 ) );
	}
	if ( PS_PROJECT_YES !== (int) $proj['is_project'] ) { // « لا » ou « لم يُحدد بعد » (1.9.0)
		return new WP_Error( 'ps_validation', ps__( 'هذا الطلب مسجّل «غير مشروع»: لا عروض ولا فواتير.' ), array( 'status' => 422 ) );
	}
	$id = ps_invoice_create( $item, (array) $r->get_json_params(), $uid );
	return is_wp_error( $id ) ? $id : rest_ensure_response( ps_rest_invoice_one( $id ) );
}

function ps_rest_invoice_update( WP_REST_Request $r ) {
	$inv = ps_invoice_row( (int) $r['id'] );
	$ctx = $inv && ! $inv['deleted_at'] ? ps_rest_invoice_context( (int) $inv['item_id'] ) : new WP_Error( 'ps_not_found', ps__( 'الفاتورة غير موجودة' ), array( 'status' => 404 ) );
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
	$ctx = $inv && ! $inv['deleted_at'] ? ps_rest_invoice_context( (int) $inv['item_id'] ) : new WP_Error( 'ps_not_found', ps__( 'الفاتورة غير موجودة' ), array( 'status' => 404 ) );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	if ( ! ps_invoice_can_delete( $ctx[1], get_current_user_id() ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'حذف الفاتورة: للمسؤول عن المشروع والإدارة فقط.' ), array( 'status' => 403 ) );
	}
	ps_invoice_soft_delete( $inv, get_current_user_id() );
	return rest_ensure_response( array( 'ok' => true ) );
}
