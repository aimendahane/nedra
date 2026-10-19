<?php
/**
 * Étape 1 — Activation : tables, listes par défaut, rôles, réglages.
 */

global $wpdb;

ps_test_section( 'Activation : base de données' );

ps_assert_same( PS_DB_VERSION, (int) get_option( 'ps_db_version' ), 'ps_db_version = dernière migration' );

foreach ( ps_table_names() as $name ) {
	$t = ps_table( $name );
	ps_assert( $wpdb->get_var( "SHOW TABLES LIKE '$t'" ) === $t, "table $t créée" );
}

$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . ps_table( 'projects' ) );
foreach ( array( 'project_no', 'offer_no', 'received_date', 'sent_date', 'source_id', 'offer_currency_id', 'purchase_currency_id', 'created_by', 'deleted_at', 'legacy_raw' ) as $c ) {
	ps_assert( in_array( $c, $cols, true ), "colonne projects.$c" );
}
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . ps_table( 'items' ) );
foreach ( array( 'offer_status_id', 'not_sent_reason_id', 'not_sent_note', 'delivery_due_date', 'delivery_actual_date', 'delivery_status_id' ) as $c ) {
	ps_assert( in_array( $c, $cols, true ), "colonne items.$c" );
}

$unique = $wpdb->get_col( 'SHOW INDEX FROM ' . ps_table( 'projects' ) . ' WHERE Non_unique = 0', 2 );
foreach ( array( 'project_no', 'offer_no', 'year_seq' ) as $k ) {
	ps_assert( in_array( $k, $unique, true ), "index unique projects.$k (numéros jamais répétés)" );
}

ps_test_section( 'Activation : listes par défaut' );

$expected_counts = array(
	'urgency' => 4, 'employee' => 11, 'followup_status' => 10, 'item_type' => 26, 'place' => 9,
	'category' => 6, 'importance' => 3, 'source' => 8, 'offer_status' => 6, 'not_sent_reason' => 7,
	'loss_reason' => 5, 'delivery_status' => 4, 'availability' => 3, 'currency' => 4,
);
$lists = ps_lists_all();
foreach ( $expected_counts as $key => $n ) {
	ps_assert_same( $n, count( $lists[ $key ] ), "liste $key : $n valeurs" );
}

$labels = fn( $k ) => array_column( $lists[ $k ], 'label' );
ps_assert( ! in_array( 'موقع العطاءات', $labels( 'employee' ), true ), '« موقع العطاءات » retiré des employés' );
ps_assert( in_array( 'موقع العطاءات', $labels( 'source' ), true ), '« موقع العطاءات » présent dans مصدر الطلب' );

$unspec = array_values( array_filter( $lists['source'], fn( $v ) => 'unspecified' === $v['code'] ) )[0] ?? null;
ps_assert( $unspec && $unspec['hidden'] && ! empty( $unspec['meta']['import_only'] ), '« غير محدد » : masqué à la saisie, réservé à l\'import' );

ps_assert_same(
	array( 'preparing', 'sent', 'not_sent', 'accepted', 'rejected', 'cancelled' ),
	array_column( $lists['offer_status'], 'code' ),
	'statuts d\'offre : 6 codes (dont لم يُرسل)'
);

$types = array_count_values( array_column( $lists['followup_status'], 'type' ) );
ps_assert_same( array( 'ongoing' => 3, 'rejected' => 1, 'confirmed' => 5, 'delivered' => 1 ), $types, 'types des حالات المتابعة (3 جارية، 1 مرفوض، 5 مؤكد، 1 مسلّم)' );

ps_assert_same( array( 'VU' => 4, 'U' => 3, 'N' => 2, 'L' => 1 ), array_combine( array_column( $lists['urgency'], 'code' ), array_column( $lists['urgency'], 'weight' ) ), 'poids des urgences VU=4 U=3 N=2 L=1' );
ps_assert_same( array( 3, 2, 1 ), array_column( $lists['importance'], 'weight' ), 'poids de الأهمية عالية=3 متوسطة=2 ضعيفة=1' );
ps_assert_same( array( 'T', 'M', 'BG', 'ZW', 'S', 'J', 'D', 'LY', 'K' ), array_column( $lists['place'], 'code' ), 'codes des lieux (Zewiya = ZW)' );
ps_assert_same( array( 'LYD', 'USD', 'EUR', 'TND' ), array_column( $lists['currency'], 'code' ), 'devises LYD, USD, EUR, TND' );

ps_test_section( 'Activation : réglages' );

$s = ps_settings();
ps_assert_same( 120, (int) $s['inactivity_minutes'], 'déconnexion après inactivité : 120 min par défaut' );
ps_assert_same( 24, (int) $s['creator_edit_hours'], 'fenêtre de modification du créateur : 24 h' );
ps_assert( (bool) preg_match( '/^[0-9a-f]{64}$/', $s['cron_token'] ), 'jeton Cron : 64 caractères hexadécimaux aléatoires' );
ps_assert_same( 'Africa/Tripoli', get_option( 'timezone_string' ), 'fuseau horaire Africa/Tripoli' );

$old = $s['cron_token'];
$new = ps_regenerate_cron_token();
ps_assert( $new !== $old && preg_match( '/^[0-9a-f]{64}$/', $new ) && ps_setting( 'cron_token' ) === $new, 'jeton Cron régénérable (nouveau jeton enregistré)' );
ps_assert_same( 120, (int) ps_setting( 'inactivity_minutes' ), 'régénérer le jeton ne touche pas aux autres réglages' );

ps_test_section( 'Activation : rôles' );

foreach ( array( 'ps_employee' => 'موظف', 'ps_manager' => 'مسؤول مشروع', 'ps_admin' => 'الإدارة' ) as $slug => $label ) {
	$r = get_role( $slug );
	ps_assert( $r && wp_roles()->roles[ $slug ]['name'] === $label, "rôle $label ($slug)" );
}
$emp = get_role( 'ps_employee' );
$mgr = get_role( 'ps_manager' );
$adm = get_role( 'ps_admin' );
ps_assert( $emp->has_cap( 'ps_create_project' ) && $emp->has_cap( 'ps_edit_own_items' ), 'موظف : peut créer un projet et mettre à jour ses أصناف' );
ps_assert( ! $emp->has_cap( 'ps_edit_own_projects' ) && ! $emp->has_cap( 'ps_view_recap' ) && ! $emp->has_cap( 'ps_manage_settings' ) && ! $emp->has_cap( 'ps_delete' ), 'موظف : ni modification des projets, ni Recap, ni الإعدادات, ni suppression' );
ps_assert( $mgr->has_cap( 'ps_edit_own_projects' ) && ! $mgr->has_cap( 'ps_view_recap' ), 'مسؤول مشروع : modifie ses projets, pas de Recap' );
ps_assert( ! array_diff( ps_all_caps(), array_keys( array_filter( $adm->capabilities ) ) ), 'الإدارة : toutes les capacités' );
ps_assert( ! array_diff( ps_all_caps(), array_keys( array_filter( get_role( 'administrator' )->capabilities ) ) ), 'administrateur WordPress : toutes les capacités ps_*' );

ps_test_section( 'Réactivation (idempotence)' );

$before = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) );
ps_on_activate();
ps_on_activate();
ps_migration_001_initial();
ps_assert_same( $before, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) ), 'réactiver le thème ne duplique pas les listes' );
