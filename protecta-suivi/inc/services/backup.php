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
