<?php
/**
 * Version 1.4.0 — import du fichier Excel de l'équipe (tests/fixtures/excel-equipe.xlsx, données fictives
 * reproduisant les cas du vrai fichier : lignes du même « رقم », date 2005 au lieu de 2025, date vide,
 * ligne incomplète, « موقع العطاءات » en الوسيط, téléphone en nombre, valeurs de الأهمية 3X / M / L).
 */

global $wpdb;

ps_test_section( 'Import Excel : lecture du fichier' );

$rows = ps_xl_read( __DIR__ . '/fixtures/excel-equipe.xlsx' );
ps_assert( is_array( $rows ) && 7 === count( $rows ), '.xlsx lu sans extension particulière (ZipArchive) : titre + 6 lignes', is_wp_error( $rows ) ? $rows->get_error_message() : count( $rows ) );
$conv = ps_xl_to_projects( $rows );
ps_assert( 4 === count( $conv['projects'] ) && 6 === $conv['rows'], '6 lignes → 4 projets (2 lignes « P902 » = 1 projet, ligne incomplète ignorée)' );
$csv = ps_xl_to_projects( ps_xl_read( __DIR__ . '/fixtures/excel-equipe.csv' ) );
ps_assert( 4 === count( $csv['projects'] ) && $csv['projects'][0]['recv'] === $conv['projects'][0]['recv'], 'même résultat avec le fichier enregistré en CSV (« ; », dates JJ/MM/AAAA)' );
$by = array_column( $conv['projects'], null, 'no' );
ps_assert( '218917122719' === $by['P901']['dir_ph'], 'téléphone saisi comme nombre → gardé en entier (pas 2.18E+11)' );
ps_assert( '2025-10-04' === $by['P903']['recv'] && (bool) array_filter( $conv['warnings'], fn( $w ) => str_contains( $w, 'P903' ) && str_contains( $w, '2005-10-04' ) ), 'date 2005-10-04 avec السنة = 2025 et الشهر = 10 → corrigée en 2025-10-04 (signalé)' );
ps_assert( '2025-09-01' === $by['P904']['recv'], 'date de réception vide → 1er du mois (السنة / الشهر), signalé' );
ps_assert( 5 === ( $conv['missing']['التصنيف'] ?? 0 ) && 1 === ( $conv['missing']['اسم المشروع'] ?? 0 ), '« ce qui manque » : nombre de cases vides par colonne (التصنيف ×5, اسم المشروع ×1)' );
ps_assert( (bool) array_filter( $conv['warnings'], fn( $w ) => str_contains( $w, 'elsewedy' ) ), 'ligne incomplète (seulement un nom de société) → ignorée et signalée' );
$bad = wp_tempnam( 'xl' );
file_put_contents( $bad, "a;b;c\n1;2;3\n" );
ps_assert( is_wp_error( ps_xl_to_projects( ps_xl_read( $bad ) ) ), 'fichier sans les colonnes du tableau → refusé avec un message clair' );

ps_test_section( 'Import Excel : aperçu puis import' );

ps_test_reset_data();
$adm = wp_insert_user( array( 'user_login' => 'xl_adm', 'user_pass' => wp_generate_password(), 'role' => 'ps_admin' ) );
$emp = wp_insert_user( array( 'user_login' => 'xl_emp', 'user_pass' => wp_generate_password(), 'role' => 'ps_employee' ) );
$xl  = function ( string $file, int $dry ) {
	$tmp = wp_tempnam( 'xl' );
	copy( __DIR__ . '/fixtures/' . $file, $tmp );
	$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/import/excel' );
	$req->set_param( 'dryRun', (string) $dry );
	$req->set_file_params( array( 'file' => array( 'name' => $file, 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ) ) );
	return rest_get_server()->dispatch( $req );
};
wp_set_current_user( $emp );
ps_assert_same( 403, $xl( 'excel-equipe.xlsx', 1 )->get_status(), 'import refusé au موظف' );
wp_set_current_user( $adm );
$prev = $xl( 'excel-equipe.xlsx', 1 )->get_data();
ps_assert( 4 === $prev['imported'] && true === $prev['dryRun'] && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) ), 'aperçu : 4 projets annoncés, RIEN écrit', wp_json_encode( $prev, JSON_UNESCAPED_UNICODE ) );
ps_assert( in_array( 'KL', array_map( fn( $s ) => explode( ' ', $s )[0], $prev['created']['place'] ), true ) && in_array( 'جهاز جديد غير موجود', $prev['created']['item_type'], true ), 'aperçu : nouvelles valeurs annoncées (lieu KL, صنف inconnu)' );
ps_assert( array_values( array_intersect( array( 'غير محدد', 'M', 'L' ), $prev['created']['importance'] ) ) === array( 'غير محدد', 'M', 'L' ) && ! in_array( '3X', $prev['created']['importance'], true ), 'الأهمية : « 3X » → « غير محدد » (1.6.0) ; « M / L » ajoutées à la liste (à renommer par la الإدارة)', wp_json_encode( $prev['created']['importance'], JSON_UNESCAPED_UNICODE ) );

$rep = $xl( 'excel-equipe.xlsx', 0 )->get_data();
ps_assert( 4 === $rep['imported'] && ! empty( $rep['backup'] ), 'import : 4 projets, sauvegarde automatique avant' );
$row = fn( $no ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'projects' ) . ' WHERE project_no = %s', $no ), ARRAY_A );
$its = fn( $pid ) => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d ORDER BY sort_order', $pid ), ARRAY_A );
$p1  = $row( 'P901' );
$p2  = $row( 'P902' );
$p3  = $row( 'P903' );
$p4  = $row( 'P904' );
ps_assert( $p1 && $p2 && $p3 && $p4 && 901 === (int) $p1['seq'], 'numéros du fichier conservés tels quels : P901, P902… (problème 09)' );
ps_assert( 'مستشفى الاختبار' === $p1['name'] && '2025-08-15' === $p1['received_date'] && '2025-08-30' === $p1['sent_date'] && 'BG' === ps_list_code( $p1['place_id'] ) && 'N' === ps_list_code( $p1['urgency_id'] ), 'P901 : nom, dates, المكان, الاستعجال' );
ps_assert( 'غفران دريدي' === ps_list_label( $p1['follower_emp_id'] ) && 'مجدي ميلادي' === ps_list_label( $p1['intermediary_emp_id'] ) && 'unspecified' === ps_list_code( $p1['source_id'] ), 'P901 : المتابع, الوسيط ; مصدر = غير محدد (décision n°3)' );
$i1 = $its( $p1['id'] );
ps_assert( 1 === count( $i1 ) && 'UPS' === ps_list_label( $i1[0]['item_type_id'] ) && 'sent' === ps_list_code( $i1[0]['offer_status_id'] ) && 'في انتظار الرد' === ps_list_label( $p1['followup_status_id'] ), 'P901 : « تم التسليم » + « في انتظار الرد » → صنف مرسل, حالة المتابعة في انتظار الرد' );
$i2 = $its( $p2['id'] );
ps_assert( 2 === count( $i2 ) && 'preparing' === ps_list_code( $i2[0]['offer_status_id'] ) && 'accepted' === ps_list_code( $i2[1]['offer_status_id'] ), 'P902 : 2 lignes → 2 أصناف (« في انتظار معلومات » → قيد الإعداد ; « تم تأكيد الطلبية » → مقبول)' );
ps_assert( 'أميرة السعيداني' === ps_list_label( $i2[1]['employee_id'] ) && 'تم تأكيد الطلبية' === ps_list_label( $p2['followup_status_id'] ) && '2025-08-01' === $p2['received_date'], 'P902 : employé par صنف ; حالة المتابعة = la plus avancée ; date = la plus ancienne' );
ps_assert( 'tenders' === ps_list_code( $p3['source_id'] ) && 'rejected' === ps_list_code( $its( $p3['id'] )[0]['offer_status_id'] ) && 'رفض العرض' === ps_list_label( $p3['followup_status_id'] ), 'P903 : الوسيط « موقع العطاءات » → مصدر موقع العطاءات ; « تم الرفض » → مرفوض / رفض العرض' );
ps_assert( '2025-10-04' === $p3['received_date'] && 2025 === (int) $p3['year'], 'P903 : date corrigée → compté en 2025' );
ps_assert( 'شركة الاختبار الثالثة' === $p3['name'] && 0 === count( $its( $p4['id'] ) ), 'P903 sans nom → nom de la société (1.5.1, « le nom d\'abord ») ; P904 sans صنف → importé sans صنف (signalé)' );
$own = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'project_parties' ) . " WHERE project_id = %d AND role='owner'", $p1['id'] ), ARRAY_A );
$dir = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'project_parties' ) . " WHERE project_id = %d AND role='direct'", $p1['id'] ), ARRAY_A );
ps_assert( $own && (int) $own['company_id'] > 0 && 'خليل' === $dir['person'] && '218917122719' === $dir['phone'], 'société → annuaire ; شخص المباشر + téléphone gardés' );
ps_assert( null !== json_decode( (string) $p2['legacy_raw'], true ) && 2 === count( json_decode( (string) $p2['legacy_raw'], true )['xl'] ), 'lignes Excel d\'origine conservées (legacy_raw)' );
ps_assert( 'ملاحظة أولى' === $p1['note'] && 'ملاحظة أولى' === $i1[0]['offer_notes'], 'NOTE → ملاحظة du projet et du صنف' );

$again = $xl( 'excel-equipe.xlsx', 0 )->get_data();
ps_assert( 0 === $again['imported'] && 4 === count( $again['skipped'] ) && 4 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) ), 'réimport du même fichier : 4 ignorés, aucun doublon, rien écrasé' );
$csvr = $xl( 'excel-equipe.csv', 1 )->get_data();
ps_assert( 4 === count( $csvr['skipped'] ), 'même fichier en CSV : reconnu comme déjà importé' );

// Un nouveau projet saisi ensuite en 2025 prend le numéro suivant (aucune collision avec P901…).
// 1.5.1 : « tout effacer puis importer » (demande du client), avec sauvegarde et confirmation.
$xl2 = function ( array $params ) {
	$tmp = wp_tempnam( 'xl' );
	copy( __DIR__ . '/fixtures/excel-equipe.xlsx', $tmp );
	$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/import/excel' );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$req->set_file_params( array( 'file' => array( 'name' => 'x.xlsx', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ) ) );
	return rest_get_server()->dispatch( $req );
};
$wpdb->insert( ps_table( 'projects' ), array( 'project_no' => 'PRJ-2026-001', 'year' => 2026, 'seq' => 1, 'month' => 10, 'name' => 'مشروع قديم للحذف', 'name_norm' => 'x', 'received_date' => '2026-10-01', 'created_at' => ps_now(), 'updated_at' => ps_now() ) );
// 1.5.2 : les tâches et les notifications sont aussi effacées (« effacer tous les projets ET toutes les tâches »).
foreach ( array( 'مهمة قديمة 1', 'مهمة قديمة 2' ) as $tt ) {
	$wpdb->insert( ps_table( 'tasks' ), array( 'employee_id' => 1, 'title' => $tt, 'status' => 'open', 'created_by' => $adm, 'created_at' => ps_now(), 'updated_at' => ps_now() ) );
}
$wpdb->insert( ps_table( 'notifications' ), array( 'user_id' => $adm, 'title' => 'إشعار قديم', 'created_at' => ps_now() ) );
$tcnt    = fn() => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'tasks' ) ) . '/' . (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'notifications' ) );
$tasks_n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'tasks' ) );
$cnt     = fn() => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) );
$lists_n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) );
$comp_n  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'companies' ) );
$prev    = $xl2( array( 'dryRun' => '1', 'replaceAll' => '1' ) )->get_data();
ps_assert( 5 === $prev['wiped'] && 4 === $prev['imported'] && 5 === $cnt(), 'aperçu « مسح الكل » : 5 projets seraient effacés, 4 importés — RIEN n\'est touché', wp_json_encode( $prev ) );
ps_assert( $tasks_n === $prev['wipedTasks'] && $tasks_n >= 2 && $tasks_n . '/' . ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'notifications' ) ) ) === $tcnt(), 'aperçu : nombre de tâches qui seraient effacées annoncé, tâches gardées tant que non confirmé', $tcnt() );
ps_assert_same( 422, $xl2( array( 'dryRun' => '0', 'replaceAll' => '1' ) )->get_status(), 'sans le mot « مسح الكل » → refusé' );
ps_assert( 5 === $cnt(), '… et rien n\'est effacé' );
$done = $xl2( array( 'dryRun' => '0', 'replaceAll' => '1', 'confirm' => 'مسح الكل' ) )->get_data();
ps_assert( 5 === $done['wiped'] && 4 === $done['imported'] && 4 === $cnt() && ! $wpdb->get_var( "SELECT id FROM " . ps_table( 'projects' ) . " WHERE name = 'مشروع قديم للحذف'" ), 'confirmé : anciens projets effacés, les 4 du fichier importés (sans « déjà présent »)' );
ps_assert( '0/0' === $tcnt() && $tasks_n === $done['wipedTasks'], '1.5.2 : toutes les tâches et notifications effacées aussi', $tcnt() );
ps_assert( ! empty( $done['backup'] ) && file_exists( ps_storage_dir( 'backups/' . $done['backup'] ) ), 'sauvegarde complète faite AVANT l\'effacement (retour possible par « استرجاع »)' );
$bk = json_decode( (string) file_get_contents( ps_storage_dir( 'backups/' . $done['backup'] ) ), true );
ps_assert( in_array( 'مشروع قديم للحذف', array_column( $bk['tables']['projects'], 'name' ), true ), 'la sauvegarde contient bien les projets effacés' );
ps_assert( in_array( 'مهمة قديمة 1', array_column( $bk['tables']['tasks'] ?? array(), 'title' ), true ), '… et les tâches effacées' );
ps_assert( $lists_n <= (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) ) && $comp_n <= (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'companies' ) ), 'listes et sociétés gardées' );
$wpdb->query( 'DELETE FROM ' . ps_table( 'projects' ) . " WHERE project_no = 'PRJ-2025-905'" );

$f    = ps_project_insert( array( 'received_date' => '2025-11-02', 'name' => 'جديد بعد الاستيراد', 'name_norm' => 'x', 'month' => 11 ), $adm, ps_now() );
$next = $wpdb->get_var( $wpdb->prepare( 'SELECT project_no FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $f ) );
ps_assert_same( 'PRJ-2025-905', $next, 'nouveau projet 2025 après l\'import : PRJ-2025-905 (suite des numéros)' );
wp_set_current_user( 0 );
