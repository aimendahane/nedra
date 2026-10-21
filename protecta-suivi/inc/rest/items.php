<?php
/**
 * API Suivi Offres / Suivi Livraison / مهامي / compteurs.
 *
 *   GET /items?view=offers|delivery&…   lignes (voir ps_items_list)
 *   PUT /items/{id}                      modifier l'offre ou la livraison d'un صنف
 *   GET /counters                        compteurs du menu (« لازم قرار »…) — appel « passif »
 *   GET /tasks?employeeId=…              page مهامي
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_item_routes' );
function ps_register_item_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/items',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static fn( WP_REST_Request $r ) => rest_ensure_response( ps_items_list( $r->get_params(), get_current_user_id() ) ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/items/(?P<id>\d+)',
		array(
			'methods'             => 'PUT',
			'permission_callback' => ps_rest_permission(),
			'callback'            => 'ps_rest_item_update',
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/counters',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => static fn() => rest_ensure_response( ps_counters( get_current_user_id() ) ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/tasks',
		array(
			'methods'             => 'GET',
			'permission_callback' => ps_rest_permission(),
			'callback'            => 'ps_rest_tasks',
		)
	);
}

function ps_rest_item_update( WP_REST_Request $r ) {
	$item = ps_item_row( (int) $r['id'] );
	$proj = $item ? ps_project_row( (int) $item['project_id'] ) : null;
	if ( ! $item || ! $proj || $proj['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'الصنف غير موجود' ), array( 'status' => 404 ) );
	}
	$uid = get_current_user_id();
	if ( ! ps_item_can_edit( $item, $proj, $uid ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'يمكن تعديل هذا الصنف من الموظف المسؤول عنه، المسؤول عن المشروع أو الإدارة.' ), array( 'status' => 403 ) );
	}
	$res = ps_item_update( $item, $proj, (array) $r->get_json_params(), $uid );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$list = ps_items_list( array( 'view' => 'offers', 'projectId' => (int) $proj['id'] ), $uid );
	foreach ( $list['rows'] as $row ) {
		if ( $row['id'] === (int) $item['id'] ) {
			return rest_ensure_response( $row );
		}
	}
	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Compteurs du menu.
 *   offres : أصناف « لازم قرار » (les miens pour un موظف ; toute l'équipe pour الإدارة / مسؤول) ;
 *   livraison : livraisons en retard (même règle) ;
 *   taches : total des tâches de la page مهامي de l'utilisateur.
 */
function ps_counters( int $user_id ): array {
	$facts = ps_stats_facts();
	$me    = ps_user_employee_id( $user_id );
	$team  = user_can( $user_id, 'ps_edit_all_projects' ) || ! $me;
	$late  = 0;
	$mine  = 0;
	$dl    = 0;
	foreach ( $facts as $f ) {
		foreach ( $f['items'] as $i ) {
			if ( $i['late'] ) {
				++$late;
				$mine += ( $me && $i['employee'] === $me ) ? 1 : 0;
			}
			if ( $i['delivery_late'] && ( $team || $i['employee'] === $me ) ) {
				++$dl;
			}
		}
	}
	$tasks = ps_tasks( $facts, $team && user_can( $user_id, 'ps_edit_all_projects' ) ? null : $me );
	return array(
		'needDecision'     => $late,
		'needDecisionMine' => $mine,
		'offres'           => $team ? $late : $mine,
		'livraison'        => $dl,
		'taches'           => $me || user_can( $user_id, 'ps_edit_all_projects' ) ? count( $tasks['lateOffers'] ) + count( $tasks['followupsDue'] ) + count( $tasks['deliveries'] ) + count( $tasks['assigned'] ) : 0,
	);
}

/**
 * Contenu de la page مهامي (brief §4.5) pour un employé ($employee_id) ou toute l'équipe (null).
 *   lateOffers   : أصناف en retard = « لازم قرار » (قيد الإعداد, أجل dépassé, hors L) — dont il est responsable ;
 *   followupsDue : projets dont la prochaine date de suivi est aujourd'hui ou dépassée (المتابع ou المسؤول) ;
 *   noFollowup   : projets sans suivi depuis plus de 7 jours (alerte de suivi) ;
 *   deliveries   : livraisons de la semaine (non livrées, أجل التسليم ≤ aujourd'hui + 7 j, retards compris) ;
 *   assigned     : tâches libres ouvertes confiées à l'employé (« مهمة جديدة », 1.0.1).
 */
function ps_tasks( array $facts, ?int $employee_id, ?string $today = null ): array {
	$today = $today ?? ps_today();
	$week  = gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) + 7 * DAY_IN_SECONDS );
	$out   = array( 'lateOffers' => array(), 'followupsDue' => array(), 'noFollowup' => array(), 'deliveries' => array(), 'assigned' => array() );
	$mine  = fn( $emp ) => null === $employee_id || (int) $emp === $employee_id;
	foreach ( $facts as $f ) {
		$base = array( 'projectId' => $f['id'], 'projectNo' => $f['project_no'], 'projectName' => $f['name'], 'companyName' => $f['company_name'], 'urgencyId' => $f['urgency'] );
		foreach ( $f['items'] as $i ) {
			if ( $i['late'] && $mine( $i['employee'] ) ) {
				$out['lateOffers'][] = $base + array( 'itemId' => $i['id'], 'itemTypeId' => $i['type'], 'employeeId' => $i['employee'], 'dueDate' => $i['due'], 'daysLate' => ps_calc_days_between( $i['due'], $today ) );
			}
			if ( 'accepted' === $i['offer_code'] && 'delivered' !== $i['delivery_code'] && $i['delivery_due'] && $i['delivery_due'] <= $week && $mine( $i['employee'] ) ) {
				$out['deliveries'][] = $base + array( 'itemId' => $i['id'], 'itemTypeId' => $i['type'], 'employeeId' => $i['employee'], 'deliveryDueDate' => $i['delivery_due'], 'late' => $i['delivery_late'], 'delayDays' => $i['delay'] );
			}
		}
		$owner = null === $employee_id || $f['follower'] === $employee_id || $f['responsible'] === $employee_id;
		if ( $owner && ! $f['is_closed'] && $f['next_followup'] && $f['next_followup'] <= $today ) {
			$out['followupsDue'][] = $base + array( 'employeeId' => $f['follower'], 'nextDate' => $f['next_followup'], 'lastDate' => $f['last_followup'] );
		} elseif ( $owner && $f['needs_followup'] ) {
			$out['noFollowup'][] = $base + array( 'employeeId' => $f['follower'], 'lastDate' => $f['last_followup'] );
		}
	}
	usort( $out['lateOffers'], fn( $a, $b ) => strcmp( $a['dueDate'], $b['dueDate'] ) );
	usort( $out['followupsDue'], fn( $a, $b ) => strcmp( $a['nextDate'], $b['nextDate'] ) );
	usort( $out['deliveries'], fn( $a, $b ) => strcmp( $a['deliveryDueDate'], $b['deliveryDueDate'] ) );
	if ( null === $employee_id || $employee_id > 0 ) {
		$out['assigned'] = ps_task_list( array( 'status' => 'open', 'employeeId' => $employee_id ) );
	}
	return $out;
}

function ps_rest_tasks( WP_REST_Request $r ) {
	$uid   = get_current_user_id();
	$me    = ps_user_employee_id( $uid );
	$admin = user_can( $uid, 'ps_edit_all_projects' );
	$emp   = $me;
	if ( $admin ) {
		// الإدارة : toute l'équipe par défaut, filtrable par employé.
		$p   = $r->get_param( 'employeeId' );
		$emp = ( null === $p || '' === $p || 'all' === $p ) ? null : (int) $p;
	}
	// Tâches libres envoyées par moi (ouvertes + « تمت » depuis 14 jours) : pour suivre ce que j'ai demandé.
	$sent = user_can( $uid, 'ps_assign_tasks' ) ? ps_task_list( array( 'status' => 'open', 'createdBy' => $uid, 'doneSince' => gmdate( 'Y-m-d', strtotime( ps_today() . ' 00:00:00 UTC' ) - 14 * DAY_IN_SECONDS ) ) ) : array();
	if ( ! $admin && ! $me ) {
		return rest_ensure_response( array( 'linked' => false, 'employeeId' => null, 'today' => ps_today(), 'sent' => $sent ) + ps_tasks( array(), -1 ) );
	}
	return rest_ensure_response( array( 'linked' => true, 'employeeId' => $emp, 'today' => ps_today(), 'sent' => $sent ) + ps_tasks( ps_stats_facts(), $emp ) );
}
