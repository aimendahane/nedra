<?php
/**
 * Contrôles de livraison sur des WordPress VIERGES (développement uniquement).
 *
 *   php tests/release-check.php install  <dossier WP> <port> <zip>   WP neuf + « Téléverser un thème » + activation
 *   php tests/release-check.php seed     <dossier WP> <port>         données réalistes (sur une ancienne version)
 *   php tests/release-check.php snapshot <dossier WP> <port> <json>  photographie de toutes les données
 *   php tests/release-check.php upgrade  <dossier WP> <port> <zip>   nouveau zip en mode « remplacer » (mise à jour)
 *   php tests/release-check.php compare  <dossier WP> <port> <json>  compare avec la photographie (aucune perte)
 *
 * Utilisé par tests/release-check.sh.
 */

list( , $phase, $root, $port ) = $argv + array( null, null, null, null );
$arg = $argv[4] ?? null;
$_SERVER['HTTP_HOST']   = 'localhost:' . $port;
$_SERVER['REQUEST_URI'] = '/';
if ( 'install' === $phase ) {
	define( 'WP_INSTALLING', true );
}
require rtrim( $root, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
global $wpdb;

$upload = static function ( string $zip, bool $overwrite ): string {
	$up = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
	$r  = $up->install( $zip, array( 'overwrite_package' => $overwrite ) );
	return true === $r ? 'OK' : ( is_wp_error( $r ) ? $r->get_error_message() : implode( ' | ', $up->skin->get_upgrade_messages() ) );
};

switch ( $phase ) {
	case 'install':
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $t ) {
			$wpdb->query( "DROP TABLE `$t`" );
		}
		wp_cache_flush();
		$dir = WP_CONTENT_DIR . '/themes/protecta-suivi';
		if ( is_dir( $dir ) ) {
			exec( 'rm -rf ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( WP_CONTENT_DIR . '/uploads/protecta-suivi' ) );
		}
		wp_install( 'Protecta', 'admin', 'admin@example.com', false, '', 'Admin-pass-2026' );
		update_option( 'siteurl', 'http://localhost:' . $port );
		update_option( 'home', 'http://localhost:' . $port );
		update_option( 'permalink_structure', '/%postname%/' );
		echo 'Téléverser le thème : ' . $upload( $arg, false ) . "\n";
		switch_theme( 'protecta-suivi' );
		echo 'Thème actif : ' . get_option( 'stylesheet' ) . ' ' . wp_get_theme()->get( 'Version' ) . "\n";
		break;

	case 'seed':
		// Données « d'avant la mise à jour » écrites directement dans les tables (schéma v1).
		$now = current_time( 'mysql' );
		$lid = fn( $k, $v ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . "ps_lists WHERE list_key = %s AND (code = %s OR label = %s) LIMIT 1", $k, $v, $v ) );
		$emp = wp_insert_user( array( 'user_login' => 'wafa', 'user_pass' => 'Wafa-pass-2026', 'role' => 'ps_employee', 'display_name' => 'وفاء حامدي', 'user_email' => 'wafa@example.com' ) );
		update_user_meta( $emp, 'ps_employee_id', $lid( 'employee', 'وفاء حامدي' ) );
		update_user_meta( $emp, 'ps_daily_email', 1 );
		$adm = wp_insert_user( array( 'user_login' => 'idara', 'user_pass' => 'Idara-pass-2026', 'role' => 'ps_admin', 'display_name' => 'الإدارة', 'user_email' => 'idara@example.com' ) );
		$wpdb->insert( $wpdb->prefix . 'ps_companies', array( 'name' => 'شركة النور', 'name_norm' => 'شركه النور', 'phone' => '0912345678', 'created_at' => $now, 'updated_at' => $now ) );
		$company = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'ps_projects', array( 'project_no' => 'PRJ-2026-001', 'year' => 2026, 'seq' => 1, 'month' => 10, 'offer_no' => 'OFR-2026-001', 'name' => 'إنارة مدرسة الأمل', 'name_norm' => 'انارة مدرسه الامل', 'received_date' => '2026-10-01', 'sent_date' => '2026-10-03', 'responsible_emp_id' => $lid( 'employee', 'وفاء حامدي' ), 'follower_emp_id' => $lid( 'employee', 'وفاء حامدي' ), 'place_id' => $lid( 'place', 'T' ), 'category_id' => $lid( 'category', 'إنارة' ), 'urgency_id' => $lid( 'urgency', 'U' ), 'followup_status_id' => $lid( 'followup_status', 'تم تأكيد الطلبية' ), 'source_id' => $lid( 'source', 'direct' ), 'offer_amount' => 12500, 'offer_currency_id' => $lid( 'currency', 'LYD' ), 'note' => 'نص الطلب الأصلي', 'created_by' => $emp, 'updated_by' => $emp, 'created_at' => $now, 'updated_at' => $now ) );
		$pid = $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'ps_project_parties', array( 'project_id' => $pid, 'role' => 'owner', 'company_id' => $company, 'person' => 'سالم', 'phone' => '0912345678', 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $wpdb->prefix . 'ps_project_parties', array( 'project_id' => $pid, 'role' => 'direct', 'person' => 'خالد', 'phone' => '0921112233', 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $wpdb->prefix . 'ps_items', array( 'project_id' => $pid, 'item_type_id' => $lid( 'item_type', 'إنارة' ), 'quantity' => 12, 'employee_id' => $lid( 'employee', 'وفاء حامدي' ), 'due_date' => '2026-10-05', 'offer_status_id' => $lid( 'offer_status', 'accepted' ), 'offer_date' => '2026-10-03', 'offer_invoice_no' => 'FA-001', 'delivery_due_date' => '2026-10-15', 'delivery_status_id' => $lid( 'delivery_status', 'waiting' ), 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $wpdb->prefix . 'ps_followups', array( 'project_id' => $pid, 'entry_date' => '2026-10-04', 'author_id' => $emp, 'text' => 'تأكيد الطلبية هاتفيًا', 'next_date' => '2026-10-20', 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( $wpdb->prefix . 'ps_changelog', array( 'project_id' => $pid, 'user_id' => $emp, 'action' => 'create', 'entity' => 'project', 'entity_id' => $pid, 'created_at' => $now ) );
		// Fichier joint réel.
		ps_ensure_storage();
		wp_mkdir_p( ps_storage_dir( 'files/2026/10' ) );
		file_put_contents( ps_storage_dir( 'files/2026/10/abc123.pdf' ), "%PDF-1.4\n%%EOF" );
		$wpdb->insert( $wpdb->prefix . 'ps_files', array( 'project_id' => $pid, 'original_name' => 'عرض السعر.pdf', 'stored_name' => '2026/10/abc123.pdf', 'mime' => 'application/pdf', 'size' => 14, 'uploaded_by' => $emp, 'created_at' => $now ) );
		// Personnalisations de la الإدارة.
		$wpdb->update( $wpdb->prefix . 'ps_lists', array( 'label' => 'كهرباء عامة' ), array( 'id' => $lid( 'category', 'كهرباء' ) ) );
		$wpdb->update( $wpdb->prefix . 'ps_lists', array( 'is_hidden' => 1 ), array( 'id' => $lid( 'category', 'أخرى' ) ) );
		$wpdb->delete( $wpdb->prefix . 'ps_lists', array( 'id' => $lid( 'loss_reason', 'أخرى' ) ) );
		$wpdb->insert( $wpdb->prefix . 'ps_lists', array( 'list_key' => 'place', 'code' => 'ZL', 'label' => 'Zliten', 'sort_order' => 100, 'created_at' => $now, 'updated_at' => $now ) );
		$s                       = get_option( 'ps_settings' );
		$s['inactivity_minutes'] = 90;
		update_option( 'ps_settings', $s );
		echo "Données de test insérées (projet PRJ-2026-001, comptes wafa et idara, fichier, listes personnalisées)\n";
		break;

	case 'snapshot':
	case 'compare':
		$snap = array();
		foreach ( array( 'lists', 'projects', 'project_parties', 'companies', 'competitors', 'project_competitors', 'items', 'followups', 'files', 'changelog' ) as $t ) {
			$snap[ $t ] = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'ps_' . $t . ' ORDER BY id', ARRAY_A );
		}
		$snap['ps_settings'] = get_option( 'ps_settings' );
		$snap['users']       = array_map( fn( $u ) => array( $u->user_login, $u->user_pass, $u->roles, get_user_meta( $u->ID, 'ps_employee_id', true ) ), get_users( array( 'orderby' => 'ID' ) ) );
		$snap['file']        = md5_file( WP_CONTENT_DIR . '/uploads/protecta-suivi/files/2026/10/abc123.pdf' );
		if ( 'snapshot' === $phase ) {
			file_put_contents( $arg, serialize( $snap ) );
			echo 'Photographie : ' . count( $snap['projects'] ) . ' projet(s), ' . count( $snap['lists'] ) . " valeurs de listes\n";
			break;
		}
		$before = unserialize( file_get_contents( $arg ) );
		$bad    = array();
		foreach ( $before as $k => $v ) {
			$now = $snap[ $k ];
			// Colonnes ajoutées par une migration (ex. margin en 1.4.0) : absentes avant, elles doivent être vides
			// ou avoir leur valeur par défaut (ex. storage = 'local', drive_tries = 0 en 1.5.0).
			if ( is_array( $v ) && isset( $v[0] ) && is_array( $v[0] ) && is_array( $now ) ) {
				$defaults = array();
				foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'ps_' . $k, ARRAY_A ) as $colinfo ) {
					$defaults[ $colinfo['Field'] ] = $colinfo['Default'];
				}
				foreach ( $now as $i => $row ) {
					foreach ( array_diff_key( $row, $v[ $i ] ?? $v[0] ) as $col => $val ) {
						if ( null !== $val && (string) $val !== (string) ( $defaults[ $col ] ?? '' ) && (string) $val !== trim( (string) ( $defaults[ $col ] ?? '' ), "'" ) ) {
							$bad[] = "$k.$col";
						}
						unset( $now[ $i ][ $col ] );
					}
				}
			}
			if ( $v != $now ) {
				$bad[] = $k;
			}
		}
		echo 'Thème après mise à jour : ' . wp_get_theme()->get( 'Version' ) . ' · base v' . get_option( 'ps_db_version' ) . "\n";
		echo $bad ? 'DONNÉES MODIFIÉES : ' . implode( ', ', $bad ) . "\n" : "AUCUNE PERTE : projets, أصناف, intervenants, sociétés, suivis, fichiers, journal, listes, réglages et comptes identiques\n";
		exit( $bad ? 1 : 0 );

	case 'upgrade':
		echo 'Mise à jour (remplacer le thème) : ' . $upload( $arg, true ) . "\n";
		break;
}
