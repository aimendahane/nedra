<?php
/**
 * Fichiers joints aux projets.
 *
 * 1.5.0 (demande du client) :
 *   - tout type de fichier SAUF les fichiers exécutables / scripts (liste ps_file_blocked_exts(), vérifiée sur
 *     chaque partie du nom : « x.php.jpg » est refusé) ; le contenu est aussi vérifié (code PHP, programme
 *     Windows/Linux, script « #! ») ;
 *   - taille maximale : réglage « file_max_mb » (20 Mo par défaut), plafonnée par la limite PHP de l'hébergeur ;
 *   - nom d'affichage (saisi ou nom du fichier), date d'ajout et auteur ;
 *   - le nom sur le disque est un identifiant aléatoire sans extension exécutable (« <hex>.bin ») ;
 *   - ajout : toute personne qui intervient sur le projet (موظف compris) ; retrait : مسؤول المشروع, الإدارة,
 *     et l'auteur pendant 24 h (réglage creator_edit_hours) ;
 *   - téléchargement uniquement par l'API (GET /ps/v1/files/<id>) après vérification de la connexion et des droits.
 *
 * Stockage : d'abord sur le serveur (uploads/protecta-suivi/files/AAAA/MM/, accès direct interdit), puis envoyé
 * dans Google Drive quand il est relié (inc/services/gdrive.php) ; la copie du serveur n'est effacée qu'après
 * vérification de l'empreinte MD5 renvoyée par Google. Retrait = « corbeille » (deleted_at) : rien n'est effacé.
 */

defined( 'ABSPATH' ) || exit;

/** Extensions refusées : programmes, scripts, pages web exécutables dans le navigateur, fichiers serveur. */
function ps_file_blocked_exts(): array {
	return array(
		// Code serveur.
		'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'pgif', 'inc',
		'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'jsp', 'jspx', 'cfm', 'cgi', 'pl', 'pm', 'py', 'pyc', 'rb', 'lua',
		'htaccess', 'htpasswd', 'ini', 'user',
		// Programmes et installeurs.
		'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'pif', 'cpl', 'dll', 'sys', 'jar', 'war', 'apk', 'app', 'deb', 'rpm', 'dmg', 'run', 'bin', 'elf', 'so',
		// Scripts.
		'sh', 'bash', 'zsh', 'csh', 'ksh', 'js', 'mjs', 'cjs', 'jsx', 'ts', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'psd1', 'reg', 'lnk', 'scf',
		// Pages web / images actives (exécutées par le navigateur).
		'html', 'htm', 'xhtml', 'xht', 'shtml', 'shtm', 'svg', 'svgz', 'hta', 'swf', 'xsl', 'xslt',
	);
}

/** Taille maximale d'un fichier (octets) : réglage, sans dépasser la limite de l'hébergeur. */
function ps_file_max_bytes(): int {
	$setting = max( 1, (int) ps_setting( 'file_max_mb' ) ) * MB_IN_BYTES;
	$server  = (int) wp_max_upload_size();
	return $server > 0 ? min( $setting, $server ) : $setting;
}

/**
 * Vérifie un fichier envoyé (nom + contenu). @return WP_Error|null
 */
function ps_file_check_upload( string $name, string $tmp ): ?WP_Error {
	$parts = array_slice( explode( '.', strtolower( $name ) ), 1 ); // toutes les extensions : « a.php.jpg » → php, jpg
	if ( str_starts_with( $name, '.' ) ) {
		$parts = explode( '.', strtolower( ltrim( $name, '.' ) ) );
	}
	$bad = array_values( array_intersect( array_map( 'trim', $parts ), ps_file_blocked_exts() ) );
	if ( $bad ) {
		return new WP_Error( 'ps_upload_type', sprintf( ps__( 'نوع الملف غير مسموح (.%s): الملفات التنفيذية والبرمجية ممنوعة لحماية الموقع.' ), $bad[0] ), array( 'status' => 400 ) );
	}
	$head = (string) file_get_contents( $tmp, false, null, 0, 4096 );
	$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $tmp ) : '';
	if ( str_starts_with( $head, 'MZ' ) || str_starts_with( $head, "\x7FELF" ) || str_starts_with( $head, '#!' )
		|| false !== stripos( $head, '<?php' ) || ( str_starts_with( $mime, 'text/' ) && false !== strpos( $head, '<?=' ) )
		|| in_array( $mime, array( 'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'text/x-php', 'application/x-httpd-php', 'text/html', 'image/svg+xml' ), true ) ) {
		return new WP_Error( 'ps_upload_type', ps__( 'محتوى الملف برنامج أو سكربت: غير مسموح لحماية الموقع.' ), array( 'status' => 400 ) );
	}
	return null;
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
 * Enregistre un fichier envoyé (élément de $_FILES) sur le serveur, puis tente l'envoi dans Google Drive.
 *
 * @param string|null $display_name Nom d'affichage saisi (sinon le nom du fichier).
 * @return int|WP_Error Id du fichier.
 */
function ps_file_store( int $project_id, array $upload, int $user_id, ?string $display_name = null ) {
	global $wpdb;
	$max = ps_file_max_bytes();
	if ( in_array( (int) ( $upload['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
		return new WP_Error( 'ps_upload_size', sprintf( ps__( 'الملف كبير جدًا: الحد الأقصى %s' ), size_format( $max ) ), array( 'status' => 400 ) );
	}
	if ( ! empty( $upload['error'] ) || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) && ! defined( 'PS_TESTING' ) ) {
		return new WP_Error( 'ps_upload', ps__( 'فشل رفع الملف' ), array( 'status' => 400 ) );
	}
	if ( (int) $upload['size'] > $max ) {
		return new WP_Error( 'ps_upload_size', sprintf( ps__( 'الملف كبير جدًا: الحد الأقصى %s' ), size_format( $max ) ), array( 'status' => 400 ) );
	}
	$original = ps_clean_text( wp_basename( (string) $upload['name'] ) ) ?? 'file';
	$err      = ps_file_check_upload( $original, $upload['tmp_name'] );
	if ( $err ) {
		return $err;
	}
	$mime = function_exists( 'finfo_open' ) ? (string) finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $upload['tmp_name'] ) : 'application/octet-stream';

	if ( ! ps_ensure_storage() ) {
		return new WP_Error( 'ps_storage', ps__( 'تعذّر إنشاء مجلد الملفات' ), array( 'status' => 500 ) );
	}
	$sub = current_time( 'Y/m' );
	$dir = ps_storage_dir( 'files/' . $sub );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'ps_storage', ps__( 'تعذّر إنشاء مجلد الملفات' ), array( 'status' => 500 ) );
	}
	$stored = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.bin'; // identifiant aléatoire, jamais le nom saisi
	$dest   = ps_storage_dir( 'files/' . $stored );
	$moved  = defined( 'PS_TESTING' ) ? copy( $upload['tmp_name'], $dest ) : move_uploaded_file( $upload['tmp_name'], $dest );
	if ( ! $moved ) {
		return new WP_Error( 'ps_storage', ps__( 'تعذّر حفظ الملف' ), array( 'status' => 500 ) );
	}

	$display = ps_clean_text( $display_name ) ?? $original;
	$wpdb->insert(
		ps_table( 'files' ),
		array(
			'project_id'    => $project_id,
			'original_name' => $original,
			'display_name'  => mb_substr( $display, 0, 255 ),
			'stored_name'   => $stored,
			'mime'          => $mime,
			'size'          => (int) $upload['size'],
			'md5'           => md5_file( $dest ),
			'storage'       => 'local',
			'uploaded_by'   => $user_id,
			'created_at'    => ps_now(),
		)
	);
	$id = (int) $wpdb->insert_id;
	ps_log( $project_id, 'create', 'file', $id, null, null, $display, $user_id );
	if ( function_exists( 'ps_gdrive_push' ) && ps_gdrive_connected() ) {
		ps_gdrive_push( ps_file_get( $id ) ); // échec non bloquant : le fichier reste sur le serveur et repartira plus tard
	}
	return $id;
}

function ps_file_soft_delete( array $file, int $user_id ): void {
	global $wpdb;
	$wpdb->update( ps_table( 'files' ), array( 'deleted_at' => ps_now() ), array( 'id' => (int) $file['id'] ) );
	ps_log( (int) $file['project_id'], 'delete', 'file', (int) $file['id'], null, ps_file_label( $file ), null, $user_id );
}

/** Nom affiché d'un fichier. */
function ps_file_label( array $f ): string {
	return (string) ( $f['display_name'] ?: $f['original_name'] );
}

/**
 * Retirer un fichier (1.5.0) : الإدارة, مسؤول المشروع, ou la personne qui l'a ajouté pendant 24 h.
 */
function ps_file_can_delete( array $file, int $user_id ): bool {
	$project = ps_project_row( (int) $file['project_id'] );
	if ( $project && in_array( ps_project_edit_reason( $project, $user_id ), array( 'admin', 'responsible' ), true ) ) {
		return true;
	}
	return (int) $file['uploaded_by'] === $user_id && ps_hours_since( $file['created_at'] ) < (float) ps_setting( 'creator_edit_hours' );
}

/**
 * Peut télécharger : compte actif de l'application (tous voient tous les projets, décision n°1) ;
 * projet à la corbeille → الإدارة seulement.
 */
function ps_file_can_download( array $file, int $user_id ): bool {
	if ( ! $user_id || ! user_can( $user_id, 'ps_access' ) ) {
		return false;
	}
	$project = ps_project_row( (int) $file['project_id'] );
	if ( ! $project ) {
		return false;
	}
	return empty( $project['deleted_at'] ) || user_can( $user_id, 'ps_edit_all_projects' );
}

function ps_file_to_api( array $f, int $user_id ): array {
	$u = $f['uploaded_by'] ? get_userdata( (int) $f['uploaded_by'] ) : null;
	return array(
		'id'           => (int) $f['id'],
		'name'         => ps_file_label( $f ),
		'originalName' => $f['original_name'],
		'mime'         => $f['mime'],
		'size'         => (int) $f['size'],
		'author'       => $u ? $u->display_name : '—',
		'createdAt'    => $f['created_at'],
		'storage'      => $f['storage'] ?? 'local',
		'url'          => add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( PS_REST_NS . '/files/' . (int) $f['id'] ) ),
		'canDelete'    => ps_file_can_delete( $f, $user_id ),
	);
}
