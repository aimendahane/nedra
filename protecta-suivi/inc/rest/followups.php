<?php
/**
 * API du سجل المتابعة et des fichiers d'un projet.
 *
 *   GET  /projects/{id}/followups     liste
 *   POST /projects/{id}/followups     ajouter { date, text, nextDate }   — personne qui intervient sur le projet
 *   PUT  /followups/{id}              modifier (auteur 24 h, ou الإدارة)
 *   GET  /projects/{id}/files         liste
 *   POST /projects/{id}/files         envoyer (multipart, champ « file »)  — personne qui intervient
 *   GET  /files/{id}                  télécharger (toute personne connectée à l'application)
 *   DELETE /files/{id}                retirer (envoyeur 24 h, ou الإدارة)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_followup_routes' );
function ps_register_followup_routes(): void {
	$pid = '/projects/(?P<id>\d+)';
	register_rest_route(
		PS_REST_NS,
		$pid . '/followups',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_followups_list',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => ps_rest_permission( 'ps_add_followup' ),
				'callback'            => 'ps_rest_followup_add',
			),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/followups/(?P<id>\d+)',
		array(
			'methods'             => 'PUT',
			'permission_callback' => ps_rest_permission( 'ps_add_followup' ),
			'callback'            => 'ps_rest_followup_update',
		)
	);
	register_rest_route(
		PS_REST_NS,
		$pid . '/files',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => static function ( WP_REST_Request $r ) {
					if ( ! ps_project_row( (int) $r['id'] ) ) {
						return ps_rest_not_found();
					}
					$uid = get_current_user_id();
					return rest_ensure_response( array_map( fn( $f ) => ps_file_to_api( $f, $uid ), ps_files_for_project( (int) $r['id'] ) ) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => ps_rest_permission( 'ps_add_followup' ),
				'callback'            => 'ps_rest_file_upload',
			),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/files/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => ps_rest_permission(),
				'callback'            => 'ps_rest_file_download',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => ps_rest_permission( 'ps_add_followup' ),
				'callback'            => 'ps_rest_file_delete',
			),
		)
	);
}

/**
 * Projet + vérification « l'utilisateur intervient sur ce projet ».
 *
 * @return array|WP_Error Projet chargé.
 */
function ps_rest_project_for_contribution( int $project_id ) {
	$p = ps_project_load( $project_id );
	if ( ! $p || $p['fields']['deleted_at'] ) {
		return ps_rest_not_found();
	}
	if ( ! ps_user_involved_in_project( $p['fields'], array_column( $p['items'], 'employee_id' ), get_current_user_id() ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'يمكن الإضافة فقط للمشاريع التي تشارك فيها.' ), array( 'status' => 403 ) );
	}
	return $p;
}

/**
 * Valide les champs d'une entrée de suivi.
 *
 * @return array|WP_Error [ date, text, nextDate ]
 */
function ps_rest_followup_input( WP_REST_Request $r ) {
	$date = ps_clean_text( $r->get_param( 'date' ) ) ?? ps_today();
	$text = ps_clean_text( $r->get_param( 'text' ), true );
	$next = ps_clean_text( $r->get_param( 'nextDate' ) );
	$errors = array();
	if ( ! ps_is_valid_date( $date ) ) {
		$errors['date'] = ps__( 'تاريخ غير صحيح' );
	}
	if ( null === $text ) {
		$errors['text'] = ps__( 'اكتب نص المتابعة' );
	}
	if ( null !== $next && ! ps_is_valid_date( $next ) ) {
		$errors['nextDate'] = ps__( 'تاريخ غير صحيح' );
	} elseif ( null !== $next && ! isset( $errors['date'] ) && $next < $date ) {
		$errors['nextDate'] = ps__( 'تاريخ المتابعة القادمة لازم يكون بعد تاريخ المتابعة' );
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات المعلّمة' ), array( 'status' => 422, 'errors' => $errors ) );
	}
	return array( $date, $text, $next );
}

function ps_rest_followups_list( WP_REST_Request $r ) {
	if ( ! ps_project_row( (int) $r['id'] ) ) {
		return ps_rest_not_found();
	}
	$uid = get_current_user_id();
	return rest_ensure_response( array_map( fn( $f ) => ps_followup_to_api( $f, $uid ), ps_followups_for_project( (int) $r['id'] ) ) );
}

function ps_rest_followup_add( WP_REST_Request $r ) {
	$p = ps_rest_project_for_contribution( (int) $r['id'] );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$in = ps_rest_followup_input( $r );
	if ( is_wp_error( $in ) ) {
		return $in;
	}
	$id = ps_followup_add( (int) $r['id'], $in[0], $in[1], $in[2], get_current_user_id() );
	return rest_ensure_response( ps_followup_to_api( ps_followup_get( $id ), get_current_user_id() ) );
}

function ps_rest_followup_update( WP_REST_Request $r ) {
	$f   = ps_followup_get( (int) $r['id'] );
	$uid = get_current_user_id();
	if ( ! $f ) {
		return new WP_Error( 'ps_not_found', ps__( 'غير موجود' ), array( 'status' => 404 ) );
	}
	if ( ! ps_followup_can_edit( $f, $uid ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'يمكن تعديل المتابعة من كاتبها خلال 24 ساعة، أو من الإدارة.' ), array( 'status' => 403 ) );
	}
	$in = ps_rest_followup_input( $r );
	if ( is_wp_error( $in ) ) {
		return $in;
	}
	ps_followup_update( $f, $in[0], $in[1], $in[2], $uid );
	return rest_ensure_response( ps_followup_to_api( ps_followup_get( (int) $f['id'] ), $uid ) );
}

function ps_rest_file_upload( WP_REST_Request $r ) {
	$p = ps_rest_project_for_contribution( (int) $r['id'] );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$files = $r->get_file_params();
	if ( empty( $files['file'] ) ) {
		return new WP_Error( 'ps_upload', ps__( 'لم يتم اختيار ملف' ), array( 'status' => 400 ) );
	}
	$id = ps_file_store( (int) $r['id'], $files['file'], get_current_user_id() );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	return rest_ensure_response( ps_file_to_api( ps_file_get( $id ), get_current_user_id() ) );
}

function ps_rest_file_delete( WP_REST_Request $r ) {
	$f = ps_file_get( (int) $r['id'] );
	if ( ! $f || $f['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'الملف غير موجود' ), array( 'status' => 404 ) );
	}
	if ( ! ps_file_can_delete( $f, get_current_user_id() ) ) {
		return new WP_Error( 'ps_forbidden', ps__( 'يمكن حذف الملف من رافعه خلال 24 ساعة، أو من الإدارة.' ), array( 'status' => 403 ) );
	}
	ps_file_soft_delete( $f, get_current_user_id() );
	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Envoie le fichier au navigateur (images et PDF affichés, autres téléchargés).
 */
function ps_rest_file_download( WP_REST_Request $r ) {
	$f = ps_file_get( (int) $r['id'] );
	if ( ! $f || $f['deleted_at'] ) {
		return new WP_Error( 'ps_not_found', ps__( 'الملف غير موجود' ), array( 'status' => 404 ) );
	}
	$path = ps_storage_dir( 'files/' . $f['stored_name'] );
	$real = realpath( $path );
	if ( ! $real || ! str_starts_with( $real, realpath( ps_storage_dir( 'files' ) ) ) || ! is_readable( $real ) ) {
		return new WP_Error( 'ps_not_found', ps__( 'الملف غير موجود على الخادم' ), array( 'status' => 404 ) );
	}
	$inline = preg_match( '#^(image/|application/pdf)#', (string) $f['mime'] );
	nocache_headers();
	header( 'Content-Type: ' . $f['mime'] );
	header( 'Content-Length: ' . filesize( $real ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( "Content-Disposition: " . ( $inline ? 'inline' : 'attachment' ) . "; filename*=UTF-8''" . rawurlencode( $f['original_name'] ) );
	readfile( $real );
	exit;
}
