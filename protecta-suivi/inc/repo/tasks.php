<?php
/**
 * Tâches libres « مهمة جديدة » (version 1.0.1).
 *
 * Une tâche = une demande précise envoyée par الإدارة ou un مسؤول مشروع à un employé
 * (ex. « اتصل بالحريف »، « حضّر الوثائق ») : موظف, عنوان, أجل (facultatif), ملاحظة,
 * projet lié (facultatif). Elle apparaît dans la page مهامي de l'employé et dans son
 * e-mail du matin ; il la marque « تمت ».
 *
 * Règles (vérifiées dans l'API, jamais par l'interface seule) :
 *   - créer : capacité ps_assign_tasks (مسؤول مشروع, الإدارة) ;
 *   - voir : الإدارة voit tout ; les autres voient les tâches qui leur sont confiées
 *     et celles qu'ils ont envoyées ;
 *   - marquer « تمت » / rouvrir : l'employé concerné, l'auteur ou الإدارة ;
 *   - modifier / annuler : l'auteur ou الإدارة, tant que la tâche est ouverte.
 *   - rien n'est supprimé : une tâche annulée garde le statut « cancelled ».
 */

defined( 'ABSPATH' ) || exit;

/** Statuts possibles. */
function ps_task_statuses(): array {
	return array(
		'open'      => 'مفتوحة',
		'done'      => 'تمت',
		'cancelled' => 'ملغاة',
	);
}

function ps_task_row( int $id ): ?array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'tasks' ) . ' WHERE id = %d', $id ), ARRAY_A ) ?: null;
}

/**
 * Format renvoyé par l'API.
 */
function ps_task_to_api( array $r, ?string $today = null ): array {
	$today   = $today ?? ps_today();
	$creator = $r['created_by'] ? get_userdata( (int) $r['created_by'] ) : null;
	$doer    = $r['done_by'] ? get_userdata( (int) $r['done_by'] ) : null;
	return array(
		'id'            => (int) $r['id'],
		'employeeId'    => (int) $r['employee_id'],
		'projectId'     => $r['project_id'] ? (int) $r['project_id'] : null,
		'projectNo'     => $r['project_no'] ?? null,
		'projectName'   => $r['project_name'] ?? null,
		'title'         => $r['title'],
		'note'          => $r['note'],
		'dueDate'       => $r['due_date'],
		'status'        => $r['status'],
		'late'          => ps_calc_task_late( $r['status'], $r['due_date'], $today ),
		'createdBy'     => $r['created_by'] ? (int) $r['created_by'] : null,
		'createdByName' => $creator ? $creator->display_name : null,
		'createdAt'     => $r['created_at'],
		'doneAt'        => $r['done_at'],
		'doneByName'    => $doer ? $doer->display_name : null,
		'doneNote'      => $r['done_note'],
	);
}

/**
 * Liste des tâches.
 *
 * @param array $a id, employeeId (int|null = tous), projectId, status (open|done|cancelled|all), createdBy (int),
 *                 visibleTo (int : utilisateur non-الإدارة → seulement ses tâches et celles qu'il a envoyées),
 *                 doneSince (Y-m-d : tâches « تمت » depuis cette date, en plus des ouvertes).
 */
function ps_task_list( array $a = array() ): array {
	global $wpdb;
	$t     = ps_table( 'tasks' );
	$where = array( '1=1' );
	$args  = array();
	if ( ! empty( $a['id'] ) ) {
		$where[] = 't.id = %d';
		$args[]  = (int) $a['id'];
	}
	if ( ! empty( $a['employeeId'] ) ) {
		$where[] = 't.employee_id = %d';
		$args[]  = (int) $a['employeeId'];
	}
	if ( ! empty( $a['createdBy'] ) ) {
		$where[] = 't.created_by = %d';
		$args[]  = (int) $a['createdBy'];
	}
	if ( ! empty( $a['projectId'] ) ) {
		$where[] = 't.project_id = %d';
		$args[]  = (int) $a['projectId'];
	}
	$status = $a['status'] ?? 'open';
	if ( 'all' !== $status ) {
		if ( ! empty( $a['doneSince'] ) && 'open' === $status ) {
			$where[] = "(t.status = 'open' OR (t.status = 'done' AND t.done_at >= %s))";
			$args[]  = $a['doneSince'] . ' 00:00:00';
		} else {
			$where[] = 't.status = %s';
			$args[]  = $status;
		}
	}
	if ( ! empty( $a['visibleTo'] ) ) {
		$emp     = ps_user_employee_id( (int) $a['visibleTo'] );
		$where[] = '(t.created_by = %d OR t.employee_id = %d)';
		$args[]  = (int) $a['visibleTo'];
		$args[]  = $emp ?: -1;
	}
	$sql  = "SELECT t.*, p.project_no, p.name AS project_name FROM $t t LEFT JOIN " . ps_table( 'projects' ) . ' p ON p.id = t.project_id WHERE ' . implode( ' AND ', $where )
		. " ORDER BY t.status = 'open' DESC, t.due_date IS NULL, t.due_date, t.id";
	$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	$today = ps_today();
	return array_map( fn( $r ) => ps_task_to_api( $r, $today ), $rows ?: array() );
}

/**
 * Peut voir la tâche : الإدارة, l'auteur, l'employé concerné.
 */
function ps_task_can_view( array $task, int $user_id ): bool {
	return user_can( $user_id, 'ps_edit_all_projects' )
		|| (int) $task['created_by'] === $user_id
		|| ps_user_employee_id( $user_id ) === (int) $task['employee_id'];
}

/** Marquer « تمت » / rouvrir : même règle que voir. */
function ps_task_can_complete( array $task, int $user_id ): bool {
	return ps_task_can_view( $task, $user_id );
}

/** Modifier / annuler : l'auteur ou الإدارة. */
function ps_task_can_edit( array $task, int $user_id ): bool {
	return user_can( $user_id, 'ps_edit_all_projects' ) || ( (int) $task['created_by'] === $user_id && user_can( $user_id, 'ps_assign_tasks' ) );
}

/**
 * Vérifie et nettoie les champs d'une tâche. @return array|WP_Error
 */
function ps_task_input( array $b, ?array $existing = null ) {
	global $wpdb;
	$errors = array();
	$set    = array();
	$get    = fn( $k ) => array_key_exists( $k, $b ) ? $b[ $k ] : ( null === $existing ? null : false );

	$emp = $get( 'employeeId' );
	if ( false !== $emp ) {
		$emp = ps_clean_id( $emp );
		$row = $emp ? ps_list_value_raw( $emp ) : null;
		if ( ! $row || 'employee' !== $row['list_key'] ) {
			$errors['employeeId'] = 'اختر الموظف';
		} else {
			$set['employee_id'] = $emp;
		}
	}
	$title = $get( 'title' );
	if ( false !== $title ) {
		$title = ps_clean_text( $title );
		if ( null === $title ) {
			$errors['title'] = 'اكتب المهمة';
		} elseif ( mb_strlen( $title ) > 255 ) {
			$errors['title'] = 'المهمة طويلة جدًا (255 حرفًا كحد أقصى) — ضع التفاصيل في الملاحظة';
		} else {
			$set['title'] = $title;
		}
	}
	$note = $get( 'note' );
	if ( false !== $note ) {
		$set['note'] = ps_clean_text( $note, true );
	}
	$due = $get( 'dueDate' );
	if ( false !== $due ) {
		$due = ps_clean_text( $due );
		if ( null !== $due && ! ps_is_valid_date( $due ) ) {
			$errors['dueDate'] = 'تاريخ غير صالح';
		} else {
			$set['due_date'] = $due;
		}
	}
	$pid = $get( 'projectId' );
	if ( false !== $pid ) {
		$pid = ps_clean_id( $pid );
		if ( $pid && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d AND deleted_at IS NULL', $pid ) ) ) {
			$errors['projectId'] = 'المشروع غير موجود';
		} else {
			$set['project_id'] = $pid;
		}
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', 'يرجى تصحيح الخانات المعلّمة', array( 'status' => 422, 'errors' => $errors ) );
	}
	return $set;
}

/**
 * Crée une tâche, l'inscrit au journal et prévient l'employé par e-mail (si possible).
 *
 * @return int|WP_Error Identifiant de la tâche.
 */
function ps_task_create( array $b, int $user_id ) {
	global $wpdb;
	$set = ps_task_input( $b );
	if ( is_wp_error( $set ) ) {
		return $set;
	}
	$now = ps_now();
	$wpdb->insert( ps_table( 'tasks' ), $set + array( 'status' => 'open', 'created_by' => $user_id, 'created_at' => $now, 'updated_at' => $now ) );
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		return new WP_Error( 'ps_db', 'تعذّر الحفظ', array( 'status' => 500 ) );
	}
	ps_log( $set['project_id'] ?? null, 'create', 'task', $id, null, null, $set['title'] . ' → ' . ps_list_label( $set['employee_id'] ), $user_id );
	ps_task_notify( ps_task_row( $id ) );
	return $id;
}

/**
 * Change le statut ou les champs d'une tâche.
 *
 * @param string $action done | reopen | cancel | edit
 * @return true|WP_Error
 */
function ps_task_update( array $task, string $action, array $b, int $user_id ) {
	global $wpdb;
	$now = ps_now();
	$set = array();
	switch ( $action ) {
		case 'done':
			if ( 'open' !== $task['status'] ) {
				return new WP_Error( 'ps_state', 'المهمة ليست مفتوحة', array( 'status' => 409 ) );
			}
			$set = array( 'status' => 'done', 'done_by' => $user_id, 'done_at' => $now, 'done_note' => ps_clean_text( $b['doneNote'] ?? null, true ) );
			break;
		case 'reopen':
			if ( 'done' !== $task['status'] ) {
				return new WP_Error( 'ps_state', 'المهمة غير منجزة', array( 'status' => 409 ) );
			}
			$set = array( 'status' => 'open', 'done_by' => null, 'done_at' => null );
			break;
		case 'cancel':
			if ( 'open' !== $task['status'] ) {
				return new WP_Error( 'ps_state', 'المهمة ليست مفتوحة', array( 'status' => 409 ) );
			}
			$set = array( 'status' => 'cancelled' );
			break;
		case 'edit':
			if ( 'open' !== $task['status'] ) {
				return new WP_Error( 'ps_state', 'لا يمكن تعديل مهمة منجزة أو ملغاة', array( 'status' => 409 ) );
			}
			$set = ps_task_input( $b, $task );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			break;
		default:
			return new WP_Error( 'ps_validation', 'إجراء غير معروف', array( 'status' => 422 ) );
	}
	foreach ( $set as $col => $v ) {
		if ( (string) $task[ $col ] !== (string) $v && 'done_at' !== $col ) {
			ps_log( $task['project_id'] ? (int) $task['project_id'] : null, 'update', 'task', (int) $task['id'], $col, $task[ $col ], $v, $user_id );
		}
	}
	$wpdb->update( ps_table( 'tasks' ), $set + array( 'updated_at' => $now ), array( 'id' => (int) $task['id'] ) );
	// Nouvel employé → il est prévenu à son tour.
	if ( 'edit' === $action && isset( $set['employee_id'] ) && (int) $set['employee_id'] !== (int) $task['employee_id'] ) {
		ps_task_notify( ps_task_row( (int) $task['id'] ) );
	}
	return true;
}

/**
 * Comptes liés à un employé de la liste (actifs).
 *
 * @return WP_User[]
 */
function ps_users_for_employee( int $employee_id ): array {
	$users = get_users( array( 'meta_key' => 'ps_employee_id', 'meta_value' => (string) $employee_id ) );
	return array_values( array_filter( $users, fn( $u ) => ! get_user_meta( $u->ID, 'ps_disabled', true ) && user_can( $u, 'ps_access' ) ) );
}

/**
 * E-mail immédiat à l'employé quand une tâche lui est confiée. Sans effet bloquant :
 * un échec d'envoi n'empêche pas l'enregistrement (la tâche est de toute façon dans مهامي).
 *
 * @return int Nombre d'e-mails partis.
 */
function ps_task_notify( ?array $task ): int {
	if ( ! $task ) {
		return 0;
	}
	$sent    = 0;
	$creator = $task['created_by'] ? get_userdata( (int) $task['created_by'] ) : null;
	$app     = esc_url( home_url( '/#/taches' ) );
	foreach ( ps_users_for_employee( (int) $task['employee_id'] ) as $u ) {
		if ( ! is_email( $u->user_email ) || ( $creator && $u->ID === $creator->ID ) ) {
			continue;
		}
		$h  = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;color:#1F2733;max-width:640px;margin:auto;border-top:4px solid #E8571F;padding:16px">';
		$h .= '<h2 style="color:#385B9A;margin:0 0 8px">مهمة جديدة</h2>';
		$h .= '<p style="font-size:16px;font-weight:bold;margin:0 0 8px">' . esc_html( $task['title'] ) . '</p>';
		$h .= $task['due_date'] ? '<p style="margin:0 0 4px">الأجل: <strong>' . esc_html( wp_date( 'd/m/Y', strtotime( $task['due_date'] . ' 12:00:00' ) ) ) . '</strong></p>' : '';
		$h .= $creator ? '<p style="margin:0 0 4px">من: ' . esc_html( $creator->display_name ) . '</p>' : '';
		$h .= $task['note'] ? '<p style="white-space:pre-line;background:#F4F6FA;padding:10px;border-radius:6px">' . esc_html( $task['note'] ) . '</p>' : '';
		$h .= '<p style="margin-top:20px"><a href="' . $app . '" style="background:#385B9A;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">فتح مهامي</a></p></div>';
		if ( true === ps_mail_send( $u->user_email, 'مهمة جديدة: ' . $task['title'], $h ) ) {
			++$sent;
		}
	}
	return $sent;
}
