<?php
/**
 * Fichiers joints aux projets (cahier §10 : PDF, Excel, image… + Word).
 *
 * Stockage : uploads/protecta-suivi/files/AAAA/MM/<nom aléatoire>.<ext>
 *   - le dossier est interdit d'accès direct (.htaccess, voir inc/storage.php) ;
 *   - le nom réel n'apparaît jamais dans l'URL ; le fichier est servi par l'API après
 *     vérification de la connexion (GET /ps/v1/files/<id>).
 * Retrait d'un fichier = « corbeille » (deleted_at) : le fichier reste sur le disque.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extensions autorisées → types MIME acceptés.
 */
function ps_allowed_file_types(): array {
	return array(
		'pdf'  => array( 'application/pdf' ),
		'xls'  => array( 'application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2', 'application/x-ole-storage' ),
		'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream' ),
		'csv'  => array( 'text/csv', 'text/plain', 'application/csv' ),
		'doc'  => array( 'application/msword', 'application/octet-stream', 'application/CDFV2', 'application/x-ole-storage' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream' ),
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
		'gif'  => array( 'image/gif' ),
		'webp' => array( 'image/webp' ),
	);
}

function ps_file_get( int $id ): ?array {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'files' ) . ' WHERE id = %d', $id ), ARRAY_A );
	return $r ?: null;
}

function ps_files_for_project( int $project_id ): array {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare( 'SELECT * FROM ' . ps_table( 'files' ) . ' WHERE project_id = %d AND deleted_at IS NULL ORDER BY id DESC', $project_id ),
		ARRAY_A
	);
}

/**
 * Enregistre un fichier envoyé (élément de $_FILES).
 *
 * @return int|WP_Error Id du fichier.
 */
function ps_file_store( int $project_id, array $upload, int $user_id ) {
	global $wpdb;
	if ( ! empty( $upload['error'] ) || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) && ! defined( 'PS_TESTING' ) ) {
		return new WP_Error( 'ps_upload', 'فشل رفع الملف', array( 'status' => 400 ) );
	}
	$max = wp_max_upload_size();
	if ( (int) $upload['size'] > $max ) {
		return new WP_Error( 'ps_upload_size', 'الملف كبير جدًا (الحد الأقصى ' . size_format( $max ) . ')', array( 'status' => 400 ) );
	}
	$name    = sanitize_file_name( wp_basename( (string) $upload['name'] ) );
	$ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$allowed = ps_allowed_file_types();
	if ( ! isset( $allowed[ $ext ] ) ) {
		return new WP_Error( 'ps_upload_type', 'نوع الملف غير مسموح (PDF، Excel، Word، صور)', array( 'status' => 400 ) );
	}
	$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $upload['tmp_name'] ) : $allowed[ $ext ][0];
	if ( ! in_array( $mime, $allowed[ $ext ], true ) ) {
		return new WP_Error( 'ps_upload_type', 'محتوى الملف لا يطابق نوعه', array( 'status' => 400 ) );
	}

	if ( ! ps_ensure_storage() ) {
		return new WP_Error( 'ps_storage', 'تعذّر إنشاء مجلد الملفات', array( 'status' => 500 ) );
	}
	$sub = current_time( 'Y/m' );
	$dir = ps_storage_dir( 'files/' . $sub );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'ps_storage', 'تعذّر إنشاء مجلد الملفات', array( 'status' => 500 ) );
	}
	$stored = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
	$dest   = ps_storage_dir( 'files/' . $stored );
	$moved  = defined( 'PS_TESTING' ) ? copy( $upload['tmp_name'], $dest ) : move_uploaded_file( $upload['tmp_name'], $dest );
	if ( ! $moved ) {
		return new WP_Error( 'ps_storage', 'تعذّر حفظ الملف', array( 'status' => 500 ) );
	}

	$original = ps_clean_text( wp_basename( (string) $upload['name'] ) ) ?? $name;
	$wpdb->insert(
		ps_table( 'files' ),
		array(
			'project_id'    => $project_id,
			'original_name' => $original,
			'stored_name'   => $stored,
			'mime'          => $mime,
			'size'          => (int) $upload['size'],
			'uploaded_by'   => $user_id,
			'created_at'    => ps_now(),
		)
	);
	$id = (int) $wpdb->insert_id;
	ps_log( $project_id, 'create', 'file', $id, null, null, $original, $user_id );
	return $id;
}

function ps_file_soft_delete( array $file, int $user_id ): void {
	global $wpdb;
	$wpdb->update( ps_table( 'files' ), array( 'deleted_at' => ps_now() ), array( 'id' => (int) $file['id'] ) );
	ps_log( (int) $file['project_id'], 'delete', 'file', (int) $file['id'], null, $file['original_name'], null, $user_id );
}

/**
 * Retirer un fichier : الإدارة, ou la personne qui l'a envoyé pendant 24 h.
 */
function ps_file_can_delete( array $file, int $user_id ): bool {
	return user_can( $user_id, 'ps_edit_all_projects' )
		|| ( (int) $file['uploaded_by'] === $user_id && ps_hours_since( $file['created_at'] ) < (float) ps_setting( 'creator_edit_hours' ) );
}

function ps_file_to_api( array $f, int $user_id ): array {
	$u = $f['uploaded_by'] ? get_userdata( (int) $f['uploaded_by'] ) : null;
	return array(
		'id'        => (int) $f['id'],
		'name'      => $f['original_name'],
		'mime'      => $f['mime'],
		'size'      => (int) $f['size'],
		'author'    => $u ? $u->display_name : '—',
		'createdAt' => $f['created_at'],
		'url'       => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( PS_REST_NS . '/files/' . (int) $f['id'] ) ),
		'canDelete' => ps_file_can_delete( $f, $user_id ),
	);
}
