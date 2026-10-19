<?php
/**
 * Étape 1 — Mise à jour sans perte de données (brief §8).
 *
 * Scénario : on remplit la base comme le ferait l'équipe (projet, société, صنف, suivi,
 * listes modifiées par la الإدارة, réglages personnalisés) sur une base remise à l'état de
 * la version 1.0.0 (base v1 : sans la table ps_tasks), puis on installe la version actuelle
 * (vraie migration n°2 : tâches libres) + une migration simulée n°PS_DB_VERSION+1 (ajout d'une
 * colonne). Tout doit être conservé à l'identique, et une sauvegarde doit avoir été faite avant.
 */

global $wpdb;

ps_test_section( 'Mise à jour : préparation de données réelles' );

// Base remise à l'état 1.0.0 (base de TEST uniquement) : sans les tables ajoutées depuis (ps_tasks 1.0.1,
// ps_notifications 1.0.2) ni la capacité ps_assign_tasks.
foreach ( array( 'tasks', 'notifications' ) as $added_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . ps_table( $added_table ) );
}
foreach ( array( 'ps_manager', 'ps_admin', 'administrator' ) as $role_slug ) {
	get_role( $role_slug )->remove_cap( 'ps_assign_tasks' );
}
update_option( 'ps_db_version', 1 );
$sim = PS_DB_VERSION + 1; // numéro de la migration simulée

$now   = ps_now();
$lists = ps_lists_all();
$id_of = fn( $key, $code ) => ps_list_id_by_code( $key, $code );

$wpdb->insert( ps_table( 'companies' ), array( 'name' => 'شركة الاختبار', 'name_norm' => 'شركه الاختبار', 'phone' => '0912345678', 'created_at' => $now, 'updated_at' => $now ) );
$company_id = (int) $wpdb->insert_id;

$wpdb->insert(
	ps_table( 'projects' ),
	array(
		'project_no' => 'PRJ-2026-001', 'year' => 2026, 'seq' => 1, 'month' => 10, 'offer_no' => 'OFR-2026-001',
		'name' => 'طالب بريزة علبة أرضية', 'received_date' => '2026-10-01', 'urgency_id' => $id_of( 'urgency', 'U' ),
		'source_id' => $id_of( 'source', 'direct' ), 'offer_amount' => '12500.500', 'offer_currency_id' => $id_of( 'currency', 'LYD' ),
		'note' => 'نص الطلب', 'created_at' => $now, 'updated_at' => $now,
	)
);
$project_id = (int) $wpdb->insert_id;
$wpdb->insert( ps_table( 'project_parties' ), array( 'project_id' => $project_id, 'role' => 'owner', 'company_id' => $company_id, 'person' => 'أحمد', 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->insert( ps_table( 'items' ), array( 'project_id' => $project_id, 'item_type_id' => $lists['item_type'][0]['id'], 'due_date' => '2026-10-10', 'offer_status_id' => $id_of( 'offer_status', 'preparing' ), 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->insert( ps_table( 'followups' ), array( 'project_id' => $project_id, 'entry_date' => '2026-10-02', 'text' => 'اتصال بالحريف', 'next_date' => '2026-10-09', 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->insert( ps_table( 'changelog' ), array( 'project_id' => $project_id, 'action' => 'create', 'entity' => 'project', 'entity_id' => $project_id, 'created_at' => $now ) );

// Modifications de la الإدارة dans الإعدادات.
$lt      = ps_table( 'lists' );
$safa    = (int) $wpdb->get_var( "SELECT id FROM $lt WHERE list_key='employee' AND label='صفاء'" );
$other   = (int) $wpdb->get_var( "SELECT id FROM $lt WHERE list_key='category' AND label='أخرى'" );
$loss    = (int) $wpdb->get_var( "SELECT id FROM $lt WHERE list_key='loss_reason' AND label='أخرى'" );
$cancel  = (int) $id_of( 'offer_status', 'cancelled' );
$wpdb->update( $lt, array( 'label' => 'صفاء بن علي' ), array( 'id' => $safa ) );          // renommée
$wpdb->update( $lt, array( 'is_hidden' => 1 ), array( 'id' => $other ) );                 // masquée
$wpdb->delete( $lt, array( 'id' => $loss ) );                                             // supprimée
$wpdb->update( $lt, array( 'label' => 'ألغاه الحريف', 'color' => '#000000' ), array( 'id' => $cancel ) ); // système renommée
ps_update_settings( array( 'inactivity_minutes' => 90, 'cron_token' => str_repeat( 'ab', 32 ) ) );

/**
 * Photographie de toutes les données (tables du thème + réglages) pour comparaison.
 * Les colonnes ajoutées par une migration sont ignorées (elles n'existaient pas avant).
 */
function ps_test_snapshot( array $ignore_cols = array() ): array {
	global $wpdb;
	$snap = array();
	foreach ( ps_table_names() as $name ) {
		$rows = $wpdb->get_results( 'SELECT * FROM ' . ps_table( $name ) . ' ORDER BY id', ARRAY_A ) ?: array(); // table absente en v1 → vide
		foreach ( $rows as &$r ) {
			foreach ( $ignore_cols as $c ) {
				unset( $r[ $c ] );
			}
		}
		$snap[ $name ] = $rows;
	}
	$snap['ps_settings']     = get_option( 'ps_settings' );
	$snap['ps_seeded_lists'] = get_option( 'ps_seeded_lists' );
	return $snap;
}

$wpdb->suppress_errors( true );
$before = ps_test_snapshot();
$wpdb->suppress_errors( false );
ps_assert( count( $before['projects'] ) === 1 && count( $before['items'] ) === 1 && count( $before['followups'] ) === 1, 'données de test en place' );

ps_test_section( 'Mise à jour : version actuelle (migration 2) + migration simulée' );

$migration_sim = static function (): void {
	global $wpdb;
	$t = ps_table( 'projects' );
	if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $t LIKE 'test_new_col'" ) ) {
		$wpdb->query( "ALTER TABLE $t ADD COLUMN test_new_col varchar(20) DEFAULT NULL" );
	}
	ps_apply_schema();
	ps_seed_lists();
	ps_register_roles();
	ps_init_settings();
};
add_filter( 'ps_migrations', fn( $m ) => $m + array( $sim => $migration_sim ) );

$backups_before = glob( ps_storage_dir( 'backups' ) . '/auto-*.json' ) ?: array();
ps_check_version(); // ce que fait WordPress au premier chargement après le remplacement du zip
$report = ps_maybe_upgrade(); // déjà à jour : ne doit rien refaire

ps_assert_same( $sim, (int) get_option( 'ps_db_version' ), "ps_db_version passe à $sim" );
ps_assert_same( array(), $report['ran'], 'deuxième passage : aucune migration rejouée' );
ps_assert( false === get_option( 'ps_migration_lock' ), 'verrou de migration libéré' );

$backups_after = glob( ps_storage_dir( 'backups' ) . '/auto-*.json' ) ?: array();
$new_backups   = array_values( array_diff( $backups_after, $backups_before ) );
ps_assert_same( 1, count( $new_backups ), 'une sauvegarde automatique créée AVANT la migration' );
if ( $new_backups ) {
	$b = json_decode( file_get_contents( $new_backups[0] ), true );
	ps_assert( 'protecta-suivi-backup' === ( $b['format'] ?? '' ) && 1 === (int) $b['db_version'], 'sauvegarde au bon format, version 1 (avant migration)' );
	ps_assert( 'PRJ-2026-001' === ( $b['tables']['projects'][0]['project_no'] ?? '' ) && 1 === count( $b['tables']['items'] ), 'la sauvegarde contient les projets et les أصناف' );
	ps_assert( ! isset( $b['tables']['projects'][0]['test_new_col'] ), 'la sauvegarde reflète l\'état avant migration' );
	ps_assert( (bool) preg_match( '/auto-\d{8}-\d{6}-before-migration-1-to-' . $sim . '-[0-9a-f]{12}\.json$/', $new_backups[0] ), 'nom de sauvegarde non devinable (suffixe aléatoire)', basename( $new_backups[0] ) );
}

$after = ps_test_snapshot( array( 'test_new_col' ) );
foreach ( array_keys( $before ) as $k ) {
	ps_assert( $before[ $k ] == $after[ $k ], "« $k » identique après la mise à jour" );
}
ps_assert( (bool) $wpdb->get_var( 'SHOW COLUMNS FROM ' . ps_table( 'projects' ) . " LIKE 'test_new_col'" ), 'la nouvelle colonne est ajoutée' );

ps_test_section( 'Migration 2 (1.0.1) : tâches libres' );
ps_assert( ps_table( 'tasks' ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ps_table( 'tasks' ) ) ), 'table ps_tasks créée' );
ps_assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'tasks' ) ), 'table ps_tasks vide (rien inventé)' );
ps_assert( get_role( 'ps_manager' )->has_cap( 'ps_assign_tasks' ) && get_role( 'ps_admin' )->has_cap( 'ps_assign_tasks' ), 'capacité ps_assign_tasks ajoutée à مسؤول مشروع et الإدارة' );
ps_assert( ! get_role( 'ps_employee' )->has_cap( 'ps_assign_tasks' ), 'موظف : pas de capacité ps_assign_tasks' );
ps_assert( in_array( 'tasks', array_keys( ps_backup_build()['tables'] ), true ), 'les tâches sont incluses dans la sauvegarde JSON' );

ps_test_section( 'Migration 3 (1.0.2) : notifications' );
ps_assert( ps_table( 'notifications' ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ps_table( 'notifications' ) ) ), 'table ps_notifications créée' );
ps_assert_same( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'notifications' ) ), 'table ps_notifications vide (aucune notification inventée pour les anciennes données)' );

ps_test_section( 'Mise à jour : listes et réglages de la الإدارة conservés' );

ps_assert_same( 'صفاء بن علي', $wpdb->get_var( "SELECT label FROM $lt WHERE id=$safa" ), 'valeur renommée : nom conservé' );
ps_assert_same( '1', $wpdb->get_var( "SELECT is_hidden FROM $lt WHERE id=$other" ), 'valeur masquée : reste masquée' );
ps_assert_same( '0', $wpdb->get_var( "SELECT COUNT(*) FROM $lt WHERE list_key='loss_reason' AND label='أخرى'" ), 'valeur supprimée : pas recréée' );
ps_assert_same( 'ألغاه الحريف', $wpdb->get_var( "SELECT label FROM $lt WHERE id=$cancel" ), 'valeur système renommée : nom conservé' );
ps_assert_same( 90, (int) ps_setting( 'inactivity_minutes' ), 'réglage inactivité personnalisé conservé' );
ps_assert_same( str_repeat( 'ab', 32 ), ps_setting( 'cron_token' ), 'jeton Cron conservé' );

ps_test_section( 'Mise à jour : valeur système disparue → recréée, sans toucher au reste' );

$wpdb->delete( $lt, array( 'id' => (int) $id_of( 'offer_status', 'not_sent' ) ) );
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $lt" );
ps_seed_lists();
ps_assert( (bool) $id_of( 'offer_status', 'not_sent' ), 'statut « لم يُرسل » (logique de calcul) recréé' );
ps_assert_same( $count + 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $lt" ), 'une seule valeur ajoutée, rien d\'autre' );

ps_test_section( 'Mise à jour : sécurité de la procédure' );

update_option( 'ps_db_version', 1 );
add_option( 'ps_migration_lock', time() );
$r = ps_maybe_upgrade();
ps_assert_same( 'locked', $r['error'], 'migration déjà en cours ailleurs → pas d\'exécution simultanée' );
update_option( 'ps_migration_lock', time() - 3600 );
$r = ps_maybe_upgrade();
ps_assert( null === $r['error'] && array_merge( range( 2, PS_DB_VERSION ), array( $sim ) ) === $r['ran'], 'verrou périmé (> 10 min) → migrations reprises' );

// Retour à l'état normal pour la suite des tests.
remove_all_filters( 'ps_migrations' );
update_option( 'ps_db_version', PS_DB_VERSION );
ps_update_settings( array( 'inactivity_minutes' => 120 ) );
