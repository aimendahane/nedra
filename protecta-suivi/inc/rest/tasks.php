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
		array(
			array( 'methods' => 'PUT', 'permission_callback' => ps_rest_permission(), 'callback' => 'ps_rest_free_task_update' ),
			// 1.5.3 : suppression définitive, الإدارة seulement.
			array( 'methods' => 'DELETE', 'permission_callback' => ps_rest_permission( 'ps_edit_all_projects' ), 'callback' => 'ps_rest_free_task_delete' ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/free-tasks/purge',
		array(
			array( 'methods' => 'GET', 'permission_callback' => ps_rest_permission( 'ps_edit_all_projects' ), 'callback' => static fn() => rest_ensure_response( ps_task_counts() ) ),
			array( 'methods' => 'POST', 'permission_callback' => ps_rest_permission( 'ps_edit_all_projects' ), 'callback' => 'ps_rest_free_tasks_purge' ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/voice-test',
		array( 'methods' => 'POST', 'permission_callback' => ps_rest_permission( 'ps_assign_tasks' ), 'callback' => 'ps_rest_voice_test' )
	);
	register_rest_route(
		PS_REST_NS,
		'/free-tasks/(?P<id>\d+)/voice',
		array( 'methods' => 'GET', 'permission_callback' => ps_rest_permission(), 'callback' => 'ps_rest_free_task_voice' )
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
	// JSON, ou formulaire multipart quand un message vocal (« voice ») accompagne la tâche.
	$body  = $r->get_json_params() ?: $r->get_body_params();
	$voice = $r->get_file_params()['voice'] ?? null;
	$id    = ps_task_create( (array) $body, get_current_user_id(), $voice ?: null );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return rest_ensure_response( ps_rest_free_task_one( $id ) );
}

function ps_rest_free_task_update( WP_REST_Request $r ) {
	$uid  = get_current_user_id();
	$task = ps_task_row( (int) $r['id'] );
	if ( ! $task || ! ps_task_can_view( $task, $uid ) ) {
		return new WP_Error( 'ps_not_found', ps__( 'المهمة غير موجودة' ), array( 'status' => 404 ) );
	}
	$b      = (array) $r->get_json_params();
	$action = (string) ( $b['action'] ?? '' );
	$ok     = in_array( $action, array( 'done', 'reopen' ), true ) ? ps_task_can_complete( $task, $uid ) : ps_task_can_edit( $task, $uid );
	if ( ! $ok ) {
		return new WP_Error( 'ps_forbidden', ps__( 'يمكن تعديل المهمة أو إلغاؤها من طرف من أرسلها أو الإدارة فقط.' ), array( 'status' => 403 ) );
	}
	$res = ps_task_update( $task, $action, $b, $uid );
	return is_wp_error( $res ) ? $res : rest_ensure_response( ps_rest_free_task_one( (int) $task['id'] ) );
}

function ps_rest_free_task_delete( WP_REST_Request $r ) {
	$task = ps_task_row( (int) $r['id'] );
	if ( ! $task ) {
		return new WP_Error( 'ps_not_found', ps__( 'المهمة غير موجودة' ), array( 'status' => 404 ) );
	}
	ps_task_delete( $task, get_current_user_id() );
	return rest_ensure_response( array( 'deleted' => (int) $task['id'] ) );
}

/** « 🧹 تنظيف المهام » : scope closed (منجزة + ملغاة) ou all (toutes, mot « مسح الكل » obligatoire). */
function ps_rest_free_tasks_purge( WP_REST_Request $r ) {
	$scope = (string) $r['scope'];
	if ( ! in_array( $scope, array( 'closed', 'all' ), true ) ) {
		return new WP_Error( 'ps_validation', ps__( 'إجراء غير معروف' ), array( 'status' => 422 ) );
	}
	if ( 'all' === $scope && 'مسح الكل' !== trim( (string) $r['confirm'] ) ) {
		return new WP_Error( 'ps_confirm', ps__( 'اكتب «مسح الكل» للتأكيد' ), array( 'status' => 422 ) );
	}
	$res = ps_tasks_purge( $scope, get_current_user_id() );
	return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
}

/** Une tâche au format API (avec le projet lié). */
function ps_rest_free_task_one( int $id ): ?array {
	return ps_task_list( array( 'status' => 'all', 'id' => $id ) )[0] ?? null;
}

/**
 * Lecture du message vocal (lecteur audio de مهامي) : seulement pour qui peut voir la tâche.
 * Les navigateurs demandent des morceaux du fichier (« Range ») pour avancer dans l'audio : pris en charge.
 */
function ps_rest_free_task_voice( WP_REST_Request $r ) {
	$task = ps_task_row( (int) $r['id'] );
	if ( ! $task || ! ps_task_can_view( $task, get_current_user_id() ) || empty( $task['voice_path'] ) ) {
		return new WP_Error( 'ps_not_found', ps__( 'الرسالة الصوتية غير موجودة' ), array( 'status' => 404 ) );
	}
	$real = realpath( ps_storage_dir( 'files/' . $task['voice_path'] ) );
	if ( ! $real || ! str_starts_with( $real, realpath( ps_storage_dir( 'files' ) ) ) || ! is_readable( $real ) ) {
		return new WP_Error( 'ps_not_found', ps__( 'الرسالة الصوتية غير موجودة على الخادم' ), array( 'status' => 404 ) );
	}
	// Rien ne doit précéder le son (tampons, compression gzip de l'hébergeur) : sinon fichier illisible / muet.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	if ( function_exists( 'ini_set' ) ) {
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore
	}
	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore
	}
	$size  = filesize( $real );
	$start = 0;
	$end   = $size - 1;
	if ( preg_match( '/bytes=(\d*)-(\d*)/', (string) $r->get_header( 'range' ), $m ) ) {
		$start = '' === $m[1] ? max( 0, $size - (int) $m[2] ) : (int) $m[1];
		$end   = ( '' === $m[1] || '' === $m[2] ) ? $end : min( $end, (int) $m[2] );
		status_header( 206 );
		header( "Content-Range: bytes $start-$end/$size" );
	}
	header( 'Content-Type: ' . $task['voice_mime'] );
	header( 'Accept-Ranges: bytes' );
	header( 'Content-Length: ' . ( $end - $start + 1 ) );
	header( 'Cache-Control: private, max-age=3600' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Encoding: identity' );
	if ( 'HEAD' === $r->get_method() ) {
		exit;
	}
	$fh = fopen( $real, 'rb' ); // phpcs:ignore
	fseek( $fh, $start );
	echo fread( $fh, $end - $start + 1 ); // phpcs:ignore
	fclose( $fh ); // phpcs:ignore
	exit;
}

/**
 * « 🎙 اختبار الصوت » (1.3.3) : analyse un enregistrement d'essai SANS rien enregistrer (fichier temporaire supprimé).
 * Réponse : format détecté, taille, durée (Opus), muet ou non, copie Ogg pour Telegram, envoi d'essai sur Telegram.
 */
function ps_rest_voice_test( WP_REST_Request $r ) {
	$up = $r->get_file_params()['voice'] ?? null;
	if ( ! $up || ! empty( $up['error'] ) || empty( $up['tmp_name'] ) || ( ! is_uploaded_file( $up['tmp_name'] ) && ! defined( 'PS_TESTING' ) ) ) {
		return new WP_Error( 'ps_voice', ps__( 'فشل رفع الرسالة الصوتية' ), array( 'status' => 400 ) );
	}
	$ext = ps_voice_detect_ext( $up['tmp_name'] );
	$out = array(
		'size'     => (int) filesize( $up['tmp_name'] ),
		'format'   => $ext,
		'silent'   => $ext ? ps_voice_is_silent( $up['tmp_name'] ) : null,
		'seconds'  => null,
		'oggOk'    => null,
		'telegram' => null,
	);
	$d = (string) file_get_contents( $up['tmp_name'] );
	if ( 'webm' === $ext || 'ogg' === $ext ) {
		$packets = 'webm' === $ext ? ( ps_voice_webm_packets( $d )['packets'] ?? array() ) : ps_voice_ogg_packets( $d );
		$out['seconds'] = round( array_sum( array_map( 'ps_voice_opus_samples', $packets ) ) / 48000, 1 );
	}
	$tmp = trailingslashit( get_temp_dir() ) . 'ps-voice-test-' . bin2hex( random_bytes( 8 ) ) . '.' . ( $ext ?: 'bin' );
	copy( $up['tmp_name'], $tmp );
	if ( 'webm' === $ext ) {
		$out['oggOk'] = ps_voice_webm_to_ogg( $tmp, ps_voice_ogg_path( $tmp ) );
	}
	if ( $ext && $r['telegram'] ) {
		$u = wp_get_current_user();
		if ( '' === ps_tg_chat_id( $u->ID ) || ! ps_tg_enabled() ) {
			$out['telegram'] = ps__( 'حسابك غير مربوط بـ Telegram (🔔 ← ربط Telegram)' );
		} else {
			$GLOBALS['ps_tg_voice_result'] = '';
			$ok              = ps_tg_send_voice( $u, $tmp, array( 'webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav' )[ $ext ], '🎙 ' . ps__( 'اختبار الصوت' ) );
			$out['telegram'] = $ok ? 'ok:' . $GLOBALS['ps_tg_voice_result'] : (string) $GLOBALS['ps_tg_voice_result'];
		}
	}
	foreach ( array( $tmp, ps_voice_ogg_path( $tmp ) ) as $f ) {
		if ( is_file( $f ) ) {
			unlink( $f ); // fichiers temporaires de l'essai uniquement
		}
	}
	return rest_ensure_response( $out );
}
