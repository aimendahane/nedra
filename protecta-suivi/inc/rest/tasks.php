<?php
/**
 * API des tâches libres « مهمة جديدة » (1.0.1) — règles dans inc/repo/tasks.php.
 *
 *   GET  /free-tasks          ?status=open|done|cancelled|all &employeeId= &projectId= &sent=1 (envoyées par moi)
 *   POST /free-tasks          { employeeId, title, dueDate?, note?, projectId? }       (ps_assign_tasks)
 *   PUT  /free-tasks/{id}     { action: done|reopen|cancel|edit, doneNote?, …champs } (droits vérifiés ici)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_task_routes' );
function ps_register_task_routes(): void {
	register_rest_route(
		PS_REST_NS,
		'/free-tasks',
		array(
			array( 'methods' => 'GET', 'permission_callback' => ps_rest_permission(), 'callback' => 'ps_rest_free_tasks_list' ),
			array( 'methods' => 'POST', 'permission_callback' => ps_rest_permission( 'ps_assign_tasks' ), 'callback' => 'ps_rest_free_task_create' ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/free-tasks/(?P<id>\d+)',
		array( 'methods' => 'PUT', 'permission_callback' => ps_rest_permission(), 'callback' => 'ps_rest_free_task_update' )
	);
}

function ps_rest_free_tasks_list( WP_REST_Request $r ) {
	$uid  = get_current_user_id();
	$args = array(
		'status'     => in_array( $r['status'], array( 'open', 'done', 'cancelled', 'all' ), true ) ? $r['status'] : 'open',
		'employeeId' => ps_clean_id( $r['employeeId'] ),
		'projectId'  => ps_clean_id( $r['projectId'] ),
	);
	if ( $r['sent'] ) {
		$args['createdBy'] = $uid;
	}
	if ( ! user_can( $uid, 'ps_edit_all_projects' ) ) {
		$args['visibleTo'] = $uid;
	}
	return rest_ensure_response( array( 'tasks' => ps_task_list( $args ) ) );
}

function ps_rest_free_task_create( WP_REST_Request $r ) {
	$id = ps_task_create( (array) $r->get_json_params(), get_current_user_id() );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return rest_ensure_response( ps_rest_free_task_one( $id ) );
}

function ps_rest_free_task_update( WP_REST_Request $r ) {
	$uid  = get_current_user_id();
	$task = ps_task_row( (int) $r['id'] );
	if ( ! $task || ! ps_task_can_view( $task, $uid ) ) {
		return new WP_Error( 'ps_not_found', 'المهمة غير موجودة', array( 'status' => 404 ) );
	}
	$b      = (array) $r->get_json_params();
	$action = (string) ( $b['action'] ?? '' );
	$ok     = in_array( $action, array( 'done', 'reopen' ), true ) ? ps_task_can_complete( $task, $uid ) : ps_task_can_edit( $task, $uid );
	if ( ! $ok ) {
		return new WP_Error( 'ps_forbidden', 'يمكن تعديل المهمة أو إلغاؤها من طرف من أرسلها أو الإدارة فقط.', array( 'status' => 403 ) );
	}
	$res = ps_task_update( $task, $action, $b, $uid );
	return is_wp_error( $res ) ? $res : rest_ensure_response( ps_rest_free_task_one( (int) $task['id'] ) );
}

/** Une tâche au format API (avec le projet lié). */
function ps_rest_free_task_one( int $id ): ?array {
	return ps_task_list( array( 'status' => 'all', 'id' => $id ) )[0] ?? null;
}
