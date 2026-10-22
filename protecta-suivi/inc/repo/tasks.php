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
		'open'      => ps__( 'مفتوحة' ),
		'done'      => ps__( 'تمت' ),
		'cancelled' => ps__( 'ملغاة' ),
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
		'hasVoice'      => ! empty( $r['voice_path'] ),
		'voiceSeconds'  => isset( $r['voice_seconds'] ) ? (int) $r['voice_seconds'] : null,
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
			$errors['employeeId'] = ps__( 'اختر الموظف' );
		} else {
			$set['employee_id'] = $emp;
		}
	}
	$title = $get( 'title' );
	if ( false !== $title ) {
		$title = ps_clean_text( $title );
		if ( null === $title ) {
			$errors['title'] = ps__( 'اكتب المهمة' );
		} elseif ( mb_strlen( $title ) > 255 ) {
			$errors['title'] = ps__( 'المهمة طويلة جدًا (255 حرفًا كحد أقصى) — ضع التفاصيل في الملاحظة' );
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
			$errors['dueDate'] = ps__( 'تاريخ غير صالح' );
		} else {
			$set['due_date'] = $due;
		}
	}
	$pid = $get( 'projectId' );
	if ( false !== $pid ) {
		$pid = ps_clean_id( $pid );
		if ( $pid && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d AND deleted_at IS NULL', $pid ) ) ) {
			$errors['projectId'] = ps__( 'المشروع غير موجود' );
		} else {
			$set['project_id'] = $pid;
		}
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات المعلّمة' ), array( 'status' => 422, 'errors' => $errors ) );
	}
	return $set;
}

/**
 * Crée une tâche, l'inscrit au journal et prévient l'employé par e-mail (si possible).
 *
 * @return int|WP_Error Identifiant de la tâche.
 */
function ps_task_create( array $b, int $user_id, ?array $voice = null ) {
	global $wpdb;
	// Message vocal seul : un titre par défaut suffit.
	if ( $voice && '' === trim( (string) ( $b['title'] ?? '' ) ) ) {
		$b['title'] = ps__( '🎙 رسالة صوتية' );
	}
	$set = ps_task_input( $b );
	if ( is_wp_error( $set ) ) {
		return $set;
	}
	if ( $voice ) {
		$stored = ps_task_store_voice( $voice, (int) ( $b['voiceSeconds'] ?? 0 ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$set += $stored;
	}
	$now = ps_now();
	$wpdb->insert( ps_table( 'tasks' ), $set + array( 'status' => 'open', 'created_by' => $user_id, 'created_at' => $now, 'updated_at' => $now ) );
	$id = (int) $wpdb->insert_id;
	if ( ! $id ) {
		return new WP_Error( 'ps_db', ps__( 'تعذّر الحفظ' ), array( 'status' => 500 ) );
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
				return new WP_Error( 'ps_state', ps__( 'المهمة ليست مفتوحة' ), array( 'status' => 409 ) );
			}
			$set = array( 'status' => 'done', 'done_by' => $user_id, 'done_at' => $now, 'done_note' => ps_clean_text( $b['doneNote'] ?? null, true ) );
			break;
		case 'reopen':
			if ( 'done' !== $task['status'] ) {
				return new WP_Error( 'ps_state', ps__( 'المهمة غير منجزة' ), array( 'status' => 409 ) );
			}
			$set = array( 'status' => 'open', 'done_by' => null, 'done_at' => null );
			break;
		case 'cancel':
			if ( 'open' !== $task['status'] ) {
				return new WP_Error( 'ps_state', ps__( 'المهمة ليست مفتوحة' ), array( 'status' => 409 ) );
			}
			$set = array( 'status' => 'cancelled' );
			break;
		case 'edit':
			if ( 'open' !== $task['status'] ) {
				return new WP_Error( 'ps_state', ps__( 'لا يمكن تعديل مهمة منجزة أو ملغاة' ), array( 'status' => 409 ) );
			}
			$set = ps_task_input( $b, $task );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			break;
		default:
			return new WP_Error( 'ps_validation', ps__( 'إجراء غير معروف' ), array( 'status' => 422 ) );
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
 * 1.5.3 (demande du client : « l'admin doit pouvoir effacer les tâches, elles prennent de la place ») :
 * suppression DÉFINITIVE d'une tâche libre, réservée à la الإدارة. Le titre reste dans le journal ; le message vocal
 * éventuel reste sur le disque (dossier protégé, jamais servi sans la tâche).
 */
function ps_task_can_delete( int $user_id ): bool {
	return user_can( $user_id, 'ps_edit_all_projects' );
}

function ps_task_delete( array $task, int $user_id ): void {
	global $wpdb;
	$wpdb->delete( ps_table( 'tasks' ), array( 'id' => (int) $task['id'] ) );
	ps_log( $task['project_id'] ? (int) $task['project_id'] : null, 'delete', 'task', (int) $task['id'], null, $task['title'] . ' → ' . ps_list_label( (int) $task['employee_id'] ), null, $user_id );
}

/** Nombre de tâches par groupe pour « 🧹 تنظيف المهام » : closed = منجزة + ملغاة, all = toutes. */
function ps_task_counts(): array {
	global $wpdb;
	$t = ps_table( 'tasks' );
	return array(
		'closed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status IN ('done','cancelled')" ), // phpcs:ignore
		'all'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore
	);
}

/**
 * Efface en une fois les tâches terminées (« closed ») ou toutes (« all »), APRÈS une sauvegarde automatique
 * (pas de sauvegarde = rien n'est effacé ; retour possible par « استرجاع »).
 *
 * @return array|WP_Error { deleted, backup }
 */
function ps_tasks_purge( string $scope, int $user_id ) {
	global $wpdb;
	$t     = ps_table( 'tasks' );
	$where = 'all' === $scope ? '1=1' : "status IN ('done','cancelled')";
	$n     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ); // phpcs:ignore
	if ( ! $n ) {
		return array( 'deleted' => 0, 'backup' => null );
	}
	$backup = ps_backup_write_auto( 'before-task-delete' );
	if ( false === $backup ) {
		return new WP_Error( 'ps_backup', ps__( 'تعذّرت النسخة الاحتياطية التلقائية: لم يُحذف شيء.' ), array( 'status' => 500 ) );
	}
	$wpdb->query( "DELETE FROM $t WHERE $where" ); // phpcs:ignore
	ps_log( null, 'delete', 'task', null, null, (string) $n, 'all' === $scope ? 'حذف كل المهام' : 'حذف المهام المنجزة والملغاة', $user_id );
	return array( 'deleted' => $n, 'backup' => basename( $backup ) );
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
 * Aussi sur Telegram (1.2.0) si le compte est lié : texte + message vocal éventuel.
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
		if ( $creator && $u->ID === $creator->ID ) {
			continue;
		}
		// Dans la langue du destinataire (1.3.0).
		$sent += (int) ps_with_lang( $u, static fn() => ps_task_notify_user( $u, $task, $creator, $app ) );
	}
	return $sent;
}

/**
 * Prévient UN employé d'une nouvelle tâche : Telegram (s'il est lié) puis e-mail. @return bool e-mail parti.
 */
function ps_task_notify_user( WP_User $u, array $task, ?WP_User $creator, string $app ): bool {
	ps_task_telegram( $u, $task, $creator );
	if ( ! is_email( $u->user_email ) ) {
		return false;
	}
	$h  = '<div dir="' . ps_dir() . '" style="font-family:Tahoma,Arial,sans-serif;color:#1F2733;max-width:640px;margin:auto;border-top:4px solid #E8571F;padding:16px">';
	$h .= '<h2 style="color:#385B9A;margin:0 0 8px">' . ps__( 'مهمة جديدة' ) . '</h2>';
	$h .= '<p style="font-size:16px;font-weight:bold;margin:0 0 8px">' . esc_html( $task['title'] ) . '</p>';
	$h .= $task['due_date'] ? '<p style="margin:0 0 4px">' . ps__( 'الأجل:' ) . ' <strong>' . esc_html( wp_date( 'd/m/Y', strtotime( $task['due_date'] . ' 12:00:00' ) ) ) . '</strong></p>' : '';
	$h .= $creator ? '<p style="margin:0 0 4px">' . ps__( 'من:' ) . ' ' . esc_html( $creator->display_name ) . '</p>' : '';
	$h .= $task['note'] ? '<p style="white-space:pre-line;background:#F4F6FA;padding:10px;border-radius:6px">' . esc_html( $task['note'] ) . '</p>' : '';
	$h .= '<p style="margin-top:20px"><a href="' . $app . '" style="background:#385B9A;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">' . ps__( 'فتح مهامي' ) . '</a></p></div>';
	return true === ps_mail_send( $u->user_email, ps__( 'مهمة جديدة: ' ) . $task['title'], $h );
}

/**
 * Tâche envoyée sur Telegram : texte, puis le message vocal s'il y en a un (1.2.0).
 */
function ps_task_telegram( WP_User $u, array $task, ?WP_User $creator ): void {
	if ( '' === ps_tg_chat_id( $u->ID ) || ! ps_tg_enabled() ) {
		return;
	}
	$h  = '📌 <b>' . ps__( 'مهمة جديدة' ) . '</b>' . "\n" . '<b>' . esc_html( $task['title'] ) . '</b>' . "\n";
	$h .= $task['due_date'] ? ps__( '📅 الأجل: ' ) . esc_html( wp_date( 'd/m/Y', strtotime( $task['due_date'] . ' 12:00:00' ) ) ) . "\n" : '';
	$h .= $creator ? ps__( '👤 من: ' ) . esc_html( $creator->display_name ) . "\n" : '';
	$h .= $task['note'] ? "\n" . esc_html( $task['note'] ) : '';
	ps_tg_send_user( $u, $h, home_url( '/#/taches' ), ps__( 'فتح مهامي' ) );
	if ( ! empty( $task['voice_path'] ) ) {
		ps_tg_send_voice( $u, ps_storage_dir( 'files/' . $task['voice_path'] ), (string) $task['voice_mime'], '🎙 ' . esc_html( $task['title'] ) );
	}
}

/* ---------------------------------------------------------------------- */
/* Message vocal (1.2.0)                                                   */
/* ---------------------------------------------------------------------- */

/** Formats audio acceptés : extension => types détectés sur le contenu (navigateurs : webm/ogg ; iPhone : mp4). */
function ps_voice_types(): array {
	return array(
		'webm' => array( 'audio/webm', 'video/webm' ),
		'ogg'  => array( 'audio/ogg', 'application/ogg', 'video/ogg' ),
		'm4a'  => array( 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/aac' ),
		'mp3'  => array( 'audio/mpeg' ),
		'wav'  => array( 'audio/wav', 'audio/x-wav' ),
	);
}

/** Format audio d'un fichier, détecté sur son CONTENU (extension de ps_voice_types()), ou null. */
function ps_voice_detect_ext( string $file ): ?string {
	$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file ) : '';
	foreach ( ps_voice_types() as $e => $mimes ) {
		if ( in_array( $mime, $mimes, true ) ) {
			return $e;
		}
	}
	return null;
}

/** Copie Ogg d'un message vocal WebM (même nom, extension .ogg). */
function ps_voice_ogg_path( string $path ): string {
	return preg_replace( '/\.webm$/', '.ogg', $path );
}

/** Durée maximale d'un message (secondes) et taille maximale (octets). */
const PS_VOICE_MAX_SECONDS = 300;
const PS_VOICE_MAX_BYTES   = 12 * MB_IN_BYTES;

/**
 * Vérifie et range un message vocal envoyé avec une tâche (dossier protégé files/voice/AAAA/MM).
 *
 * @param array $upload Entrée de $_FILES.
 * @return array|WP_Error Colonnes voice_* à enregistrer.
 */
function ps_task_store_voice( array $upload, int $seconds ) {
	if ( ! empty( $upload['error'] ) || empty( $upload['tmp_name'] ) || ( ! is_uploaded_file( $upload['tmp_name'] ) && ! defined( 'PS_TESTING' ) ) ) {
		return new WP_Error( 'ps_voice', ps__( 'فشل رفع الرسالة الصوتية' ), array( 'status' => 400 ) );
	}
	if ( (int) $upload['size'] <= 0 || (int) $upload['size'] > PS_VOICE_MAX_BYTES ) {
		return new WP_Error( 'ps_voice_size', ps__( 'الرسالة الصوتية كبيرة جدًا (5 دقائق كحد أقصى)' ), array( 'status' => 400 ) );
	}
	$ext = ps_voice_detect_ext( $upload['tmp_name'] );
	if ( ! $ext ) {
		return new WP_Error( 'ps_voice_type', ps__( 'صيغة الصوت غير مدعومة' ), array( 'status' => 400 ) );
	}
	// 1.3.2 : enregistrement muet (micro coupé, mauvais micro, autorisation partielle) → refusé, rien n'est envoyé.
	if ( true === ps_voice_is_silent( $upload['tmp_name'] ) ) {
		return new WP_Error( 'ps_voice_silent', ps__( 'التسجيل بدون صوت: الميكروفون لم يلتقط شيئًا. تأكّد من السماح بالميكروفون وأنه غير مكتوم، ثم أعد التسجيل.' ), array( 'status' => 422 ) );
	}
	if ( ! ps_ensure_storage() ) {
		return new WP_Error( 'ps_storage', ps__( 'تعذّر إنشاء مجلد الملفات' ), array( 'status' => 500 ) );
	}
	$sub = 'voice/' . current_time( 'Y/m' );
	if ( ! wp_mkdir_p( ps_storage_dir( 'files/' . $sub ) ) ) {
		return new WP_Error( 'ps_storage', ps__( 'تعذّر إنشاء مجلد الملفات' ), array( 'status' => 500 ) );
	}
	$stored = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
	$dest   = ps_storage_dir( 'files/' . $stored );
	$moved  = defined( 'PS_TESTING' ) ? copy( $upload['tmp_name'], $dest ) : move_uploaded_file( $upload['tmp_name'], $dest );
	if ( ! $moved ) {
		return new WP_Error( 'ps_storage', ps__( 'تعذّر حفظ الرسالة الصوتية' ), array( 'status' => 500 ) );
	}
	// 1.3.1 : copie Ogg/Opus d'un enregistrement WebM (Chrome, Android) pour Telegram, qui ne lit pas le WebM.
	if ( 'webm' === $ext ) {
		ps_voice_webm_to_ogg( $dest, ps_voice_ogg_path( $dest ) );
	}
	return array(
		'voice_path'    => $stored,
		// Type « audio/… » envoyé au navigateur (un webm/mp4 audio peut être détecté « video/… »).
		'voice_mime'    => array( 'webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav' )[ $ext ],
		'voice_size'    => (int) $upload['size'],
		'voice_seconds' => max( 0, min( PS_VOICE_MAX_SECONDS, $seconds ) ) ?: null,
	);
}
