<?php
/**
 * Sauvegarde JSON complète des données de l'application.
 *
 * Étape 1 : sauvegarde automatique avant chaque migration (mise à jour du thème).
 * Étape 5 : téléchargement manuel + restauration depuis الإعدادات (même format).
 *
 * Format :
 * {
 *   "format": "protecta-suivi-backup", "format_version": 1,
 *   "theme_version": "0.1.0", "db_version": 1, "created_at": "...", "reason": "...",
 *   "tables":  { "lists": [ {...}, ... ], "projects": [...], ... },
 *   "options": { "ps_settings": {...}, "ps_seeded_lists": [...], ... },
 *   "users":   [ { "ID":..., "user_login":..., "user_pass": "<hash>", "roles": [...], "meta": {...} } ]
 * }
 *
 * Les sauvegardes sont écrites dans uploads/protecta-suivi/backups/ (accès web interdit,
 * voir inc/storage.php). Elles contiennent les empreintes (hash) des mots de passe,
 * jamais les mots de passe eux-mêmes.
 */

defined( 'ABSPATH' ) || exit;

/** Nombre de sauvegardes automatiques conservées (les plus anciennes sont supprimées). */
const PS_AUTO_BACKUPS_KEEP = 30;

/**
 * Options WordPress du thème incluses dans la sauvegarde.
 */
function ps_backup_option_names(): array {
	return array( 'ps_settings', 'ps_seeded_lists', 'ps_db_version' );
}

/**
 * Construit le contenu complet de la sauvegarde (tableau PHP).
 */
function ps_backup_build( string $reason = 'manual' ): array {
	global $wpdb;

	$tables = array();
	foreach ( ps_table_names() as $name ) {
		$table = ps_table( $name );
		// La table peut ne pas encore exister (sauvegarde avant la toute première migration).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			continue;
		}
		$tables[ $name ] = $wpdb->get_results( "SELECT * FROM $table ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	$options = array();
	foreach ( ps_backup_option_names() as $opt ) {
		$options[ $opt ] = get_option( $opt, null );
	}

	$users = array();
	foreach ( get_users( array( 'role__in' => array_merge( array_keys( ps_role_definitions() ), array( 'administrator' ) ) ) ) as $u ) {
		$meta = array();
		foreach ( get_user_meta( $u->ID ) as $k => $vals ) {
			if ( str_starts_with( $k, 'ps_' ) ) {
				$meta[ $k ] = maybe_unserialize( $vals[0] );
			}
		}
		$users[] = array(
			'ID'           => (int) $u->ID,
			'user_login'   => $u->user_login,
			'user_email'   => $u->user_email,
			'display_name' => $u->display_name,
			'user_pass'    => $u->user_pass,
			'roles'        => array_values( $u->roles ),
			'meta'         => $meta,
		);
	}

	return array(
		'format'         => 'protecta-suivi-backup',
		'format_version' => 1,
		'theme_version'  => PS_VERSION,
		'db_version'     => (int) get_option( 'ps_db_version', 0 ),
		'created_at'     => ps_now(),
		'reason'         => $reason,
		'tables'         => $tables,
		'options'        => $options,
		'users'          => $users,
	);
}

/**
 * Écrit une sauvegarde automatique dans le dossier protégé.
 *
 * @return string|false Chemin du fichier créé, ou false en cas d'échec.
 */
function ps_backup_write_auto( string $reason ) {
	if ( ! ps_ensure_storage() ) {
		return false;
	}
	$data = ps_backup_build( $reason );
	$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	if ( false === $json ) {
		return false;
	}

	// Nom de fichier avec suffixe aléatoire : impossible à deviner.
	$slug = sanitize_file_name( $reason );
	$file = ps_storage_dir( 'backups' ) . '/auto-' . current_time( 'Ymd-His' ) . '-' . $slug . '-' . bin2hex( random_bytes( 6 ) ) . '.json';
	if ( false === file_put_contents( $file, $json ) ) {
		return false;
	}

	ps_backup_rotate();
	return $file;
}

/**
 * Supprime les sauvegardes AUTOMATIQUES au-delà des PS_AUTO_BACKUPS_KEEP plus récentes.
 * Les sauvegardes manuelles (autres noms) ne sont jamais supprimées.
 */
function ps_backup_rotate(): void {
	$files = glob( ps_storage_dir( 'backups' ) . '/auto-*.json' );
	if ( ! $files || count( $files ) <= PS_AUTO_BACKUPS_KEEP ) {
		return;
	}
	sort( $files ); // Le nom commence par la date : tri chronologique.
	foreach ( array_slice( $files, 0, count( $files ) - PS_AUTO_BACKUPS_KEEP ) as $old ) {
		wp_delete_file( $old );
	}
}

/**
 * Restaure une sauvegarde complète (الإعدادات › النسخ الاحتياطي).
 *
 * Sécurité des données :
 *   - une sauvegarde automatique de l'état ACTUEL est écrite avant toute modification ;
 *   - une sauvegarde plus récente que le thème installé est refusée ;
 *   - les comptes ne sont jamais supprimés : ceux de la sauvegarde qui manquent sont recréés
 *     (même identifiant, même mot de passe), les identifiants sont remis en correspondance ;
 *   - après restauration, les migrations manquantes sont rejouées (structure à jour).
 * Les fichiers joints eux-mêmes ne sont pas dans le JSON (sauvegarde cPanel du dossier uploads).
 *
 * @return array|WP_Error Rapport : [ tables => [nom => lignes], users_created => n, backup => fichier ]
 */
function ps_backup_restore( array $data, int $user_id ) {
	global $wpdb;
	if ( 'protecta-suivi-backup' !== ( $data['format'] ?? '' ) || ! isset( $data['tables'] ) || ! is_array( $data['tables'] ) ) {
		return new WP_Error( 'ps_restore_format', 'الملف ليس نسخة احتياطية لهذا التطبيق', array( 'status' => 422 ) );
	}
	if ( (int) ( $data['db_version'] ?? 0 ) > ps_target_db_version() ) {
		return new WP_Error( 'ps_restore_version', 'النسخة الاحتياطية من إصدار أحدث للتطبيق: حدّث القالب أولًا', array( 'status' => 422 ) );
	}
	$backup = ps_backup_write_auto( 'before-restore' );
	if ( false === $backup ) {
		return new WP_Error( 'ps_restore_backup', 'تعذّرت النسخة الاحتياطية التلقائية قبل الاسترجاع: لم يتم أي تغيير', array( 'status' => 500 ) );
	}

	// Comptes : correspondance identifiant de la sauvegarde → identifiant local (par nom d'utilisateur).
	$map     = array();
	$created = 0;
	foreach ( (array) ( $data['users'] ?? array() ) as $u ) {
		if ( empty( $u['user_login'] ) ) {
			continue;
		}
		$local = get_user_by( 'login', $u['user_login'] );
		if ( ! $local ) {
			$id = wp_insert_user( array( 'user_login' => $u['user_login'], 'user_pass' => wp_generate_password( 24 ), 'user_email' => $u['user_email'] ?? '', 'display_name' => $u['display_name'] ?? $u['user_login'], 'role' => '' ) );
			if ( is_wp_error( $id ) ) {
				continue;
			}
			$wpdb->update( $wpdb->users, array( 'user_pass' => $u['user_pass'] ), array( 'ID' => $id ) ); // même mot de passe (empreinte)
			clean_user_cache( $id );
			$local = get_userdata( $id );
			foreach ( (array) ( $u['roles'] ?? array() ) as $role ) {
				$local->add_role( $role );
			}
			++$created;
		}
		foreach ( (array) ( $u['meta'] ?? array() ) as $k => $v ) {
			if ( str_starts_with( $k, 'ps_' ) && '' === (string) get_user_meta( $local->ID, $k, true ) ) {
				update_user_meta( $local->ID, $k, $v );
			}
		}
		$map[ (int) $u['ID'] ] = (int) $local->ID;
	}
	$user_cols = array( 'created_by', 'updated_by', 'deleted_by', 'author_id', 'uploaded_by', 'user_id', 'done_by', 'actor_id' );

	$report = array( 'tables' => array(), 'users_created' => $created, 'backup' => basename( $backup ) );
	$wpdb->query( 'START TRANSACTION' );
	foreach ( ps_table_names() as $name ) {
		if ( ! isset( $data['tables'][ $name ] ) ) {
			continue;
		}
		$t    = ps_table( $name );
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM $t" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM $t" ); // phpcs:ignore -- remplacement volontaire, après sauvegarde automatique
		$n = 0;
		foreach ( (array) $data['tables'][ $name ] as $row ) {
			$row = array_intersect_key( (array) $row, array_flip( $cols ) );
			foreach ( $user_cols as $uc ) {
				if ( 'lists' !== $name && ! empty( $row[ $uc ] ) && isset( $map[ (int) $row[ $uc ] ] ) ) {
					$row[ $uc ] = $map[ (int) $row[ $uc ] ];
				}
			}
			if ( $wpdb->insert( $t, $row ) ) {
				++$n;
			}
		}
		$report['tables'][ $name ] = $n;
	}
	if ( $wpdb->last_error ) {
		$err = $wpdb->last_error;
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'ps_restore_db', 'فشل الاسترجاع، لم يتغير شيء: ' . $err, array( 'status' => 500 ) );
	}
	$wpdb->query( 'COMMIT' );

	foreach ( array( 'ps_settings', 'ps_seeded_lists' ) as $opt ) {
		if ( isset( $data['options'][ $opt ] ) && is_array( $data['options'][ $opt ] ) ) {
			update_option( $opt, $data['options'][ $opt ], false );
		}
	}
	// Structure : rejouer les migrations postérieures à la sauvegarde (additives, sans perte).
	update_option( 'ps_db_version', (int) ( $data['db_version'] ?? 1 ) );
	ps_maybe_upgrade();
	update_option( 'ps_db_version', ps_target_db_version() );
	ps_lists_by_id( true );
	ps_log( null, 'restore', 'backup', null, null, null, $report['backup'], $user_id );
	return $report;
}

/**
 * Sauvegardes présentes dans le dossier protégé (les plus récentes d'abord).
 */
function ps_backup_list(): array {
	$out = array();
	foreach ( glob( ps_storage_dir( 'backups' ) . '/*.json' ) ?: array() as $f ) {
		$out[] = array( 'name' => basename( $f ), 'size' => filesize( $f ), 'date' => wp_date( 'Y-m-d H:i', filemtime( $f ) ) );
	}
	usort( $out, fn( $a, $b ) => strcmp( $b['name'], $a['name'] ) );
	return $out;
}
