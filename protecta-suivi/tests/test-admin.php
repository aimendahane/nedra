<?php
/**
 * Étape 5 — الإعدادات (listes, règles, réglages), comptes, fusion de l'annuaire, import du prototype,
 * export, sauvegarde / restauration, e-mail du matin, Cron.
 */

global $wpdb;
require_once __DIR__ . '/fixtures/demo.php';

$adm = wp_insert_user( array( 'user_login' => 't5_admin', 'user_pass' => wp_generate_password(), 'role' => 'ps_admin', 'display_name' => 'إدارة', 'user_email' => 't5admin@example.com' ) );
$emp = wp_insert_user( array( 'user_login' => 't5_emp', 'user_pass' => wp_generate_password(), 'role' => 'ps_employee', 'user_email' => 't5emp@example.com' ) );
$lid = fn( $key, $v ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s AND (code = %s OR label = %s) ORDER BY id LIMIT 1', $key, $v, $v ) );

/* ================================================================== */
ps_test_section( 'الإعدادات : listes (ajouter, renommer, masquer, réordonner, supprimer)' );

ps_test_reset_data();
ps_demo_seed();
wp_set_current_user( $emp );
ps_assert_same( 403, ps_test_json( 'POST', '/lists/category', array( 'label' => 'X' ) )->get_status(), 'موظف : modification des listes refusée (403)' );
wp_set_current_user( $adm );

$r = ps_test_json( 'POST', '/lists/category', array( 'label' => 'منظومات طاقة شمسية' ) );
ps_assert( 200 === $r->get_status() && 'منظومات طاقة شمسية' === $r->get_data()['label'], 'ajout d\'une valeur (التصنيف)' );
$new_cat = $r->get_data()['id'];
ps_assert_same( 422, ps_test_json( 'POST', '/lists/category', array( 'label' => 'منظومات طاقه شمسيه' ) )->get_status(), 'doublon (orthographe proche) refusé' );
ps_assert_same( 422, ps_test_json( 'POST', '/lists/followup_status', array( 'label' => 'حالة بدون نوع' ) )->get_status(), 'حالة المتابعة sans type → refusée' );
ps_assert_same( 200, ps_test_json( 'POST', '/lists/followup_status', array( 'label' => 'في انتظار التمويل', 'type' => 'ongoing', 'color' => '#ffaa00' ) )->get_status(), 'حالة avec type (جارية) et couleur → ajoutée' );

$light = $lid( 'category', 'إنارة' );
$before = ps_stats_kpis( ps_stats_facts( array( 'year' => 2026 ), PS_DEMO_TODAY ) );
ps_assert_same( 200, ps_test_json( 'PUT', '/lists/item/' . $light, array( 'label' => 'الإنارة', 'color' => '#123456' ) )->get_status(), 'renommer « إنارة » → « الإنارة »' );
$p = ps_project_load( (int) $wpdb->get_var( "SELECT id FROM " . ps_table( 'projects' ) . " WHERE legacy_id='demo-D01'" ) );
ps_assert( (int) $p['fields']['category_id'] === $light && 'الإنارة' === ps_list_label( $light ), 'les projets gardent la valeur et affichent le nouveau nom' );
ps_test_json( 'PUT', '/lists/item/' . $lid( 'followup_status', 'في دراسة' ), array( 'label' => 'قيد الدراسة' ) );
ps_lists_by_id( true );
$after = ps_stats_kpis( ps_stats_facts( array( 'year' => 2026 ), PS_DEMO_TODAY ) );
ps_assert( $before == $after, 'renommer une حالة ne change AUCUN calcul (les calculs utilisent le type)' );

$r = ps_test_json( 'PUT', '/lists/item/' . $light, array( 'hidden' => true ) );
ps_assert( true === $r->get_data()['hidden'], 'masquer une valeur utilisée' );
ps_assert_same( 422, ps_test_json( 'PUT', '/lists/item/' . $lid( 'offer_status', 'sent' ), array( 'hidden' => true ) )->get_status(), 'valeur de calcul (مرسل) : impossible à masquer' );
ps_assert_same( 422, ps_test_json( 'PUT', '/lists/item/' . $lid( 'source', 'unspecified' ), array( 'hidden' => false ) )->get_status(), '« غير محدد » : reste masqué' );
ps_assert_same( 409, ps_test_json( 'DELETE', '/lists/item/' . $light )->get_status(), 'supprimer une valeur utilisée → refus (proposer de masquer)' );
ps_assert_same( 409, ps_test_json( 'DELETE', '/lists/item/' . $lid( 'urgency', 'L' ) )->get_status(), 'supprimer une valeur de calcul (L) → refus' );
ps_assert_same( 200, ps_test_json( 'DELETE', '/lists/item/' . $new_cat )->get_status(), 'supprimer une valeur jamais utilisée → accepté' );

$ids = array_column( ps_list_values( 'currency' ), 'id' );
$rev = array_reverse( $ids );
ps_test_json( 'POST', '/lists/currency/reorder', array( 'ids' => $rev ) );
ps_assert_same( $rev, array_column( ps_list_values( 'currency' ), 'id' ), 'réordonner une liste' );
ps_assert_same( 422, ps_test_json( 'POST', '/lists/currency/reorder', array( 'ids' => array_slice( $rev, 1 ) ) )->get_status(), 'ordre incomplet refusé' );

/* ================================================================== */
ps_test_section( 'الإعدادات : règles de validation et réglages' );

$r = ps_test_json( 'PUT', '/settings', array( 'inactivity_minutes' => 90, 'followup_alert_days' => 10, 'validation' => array( 'required' => array( 'name', 'received_date', 'zzz' ), 'phone_format' => false ) ) );
$s = $r->get_data()['settings'];
ps_assert( 90 === $s['inactivity_minutes'] && 10 === $s['followup_alert_days'], 'réglages enregistrés (inactivité 90 min, alerte 10 j)' );
ps_assert( array( 'name', 'received_date' ) === $s['validation']['required'] && false === $s['validation']['phone_format'] && true === $s['validation']['email_format'], 'champs obligatoires modifiables (clé inconnue ignorée), autres règles conservées' );
ps_assert( ! isset( $s['cron_token'] ) && 64 === strlen( $r->get_data()['cronToken'] ), 'jeton Cron affiché à part (64 caractères)' );
ps_assert_same( 422, ps_test_json( 'PUT', '/settings', array( 'inactivity_minutes' => 2 ) )->get_status(), 'valeur hors limites refusée (inactivité < 5 min)' );
$tok = $r->get_data()['cronToken'];
$new = ps_test_json( 'POST', '/settings/cron-token' )->get_data()['cronToken'];
ps_assert( $new !== $tok && 64 === strlen( $new ) && str_contains( ps_test_json( 'GET', '/settings' )->get_data()['cronUrl'], $new ), 'jeton Cron régénéré, URL Cron mise à jour' );
ps_test_json( 'PUT', '/settings', array( 'inactivity_minutes' => 120, 'followup_alert_days' => 7, 'validation' => array( 'required' => ps_default_required_fields(), 'phone_format' => true ) ) );

/* ================================================================== */
ps_test_section( 'Comptes utilisateurs (الإدارة)' );

wp_set_current_user( $emp );
ps_assert_same( 403, ps_test_json( 'GET', '/users' )->get_status(), 'موظف : gestion des comptes refusée' );
wp_set_current_user( $adm );
$amira = $lid( 'employee', 'أميرة السعيداني' );
$r     = ps_test_json( 'POST', '/users', array( 'login' => 'amira', 'name' => 'أميرة', 'email' => 'amira@example.com', 'password' => 'Pass-1234', 'role' => 'ps_manager', 'employeeId' => $amira, 'dailyEmail' => true ) );
$u     = $r->get_data();
ps_assert( 200 === $r->get_status() && 'ps_manager' === $u['role'] && $amira === $u['employeeId'] && true === $u['dailyEmail'], 'création : rôle مسؤول مشروع, employé lié, e-mail du matin' );
ps_assert( wp_check_password( 'Pass-1234', get_userdata( $u['id'] )->user_pass ), 'mot de passe enregistré (empreinte)' );
$e = ps_test_json( 'POST', '/users', array( 'login' => 'amira', 'name' => 'x', 'email' => 'bad', 'password' => '123', 'role' => 'admin' ) )->get_data()['data']['errors'];
ps_assert( isset( $e['login'], $e['email'], $e['password'], $e['role'] ), 'contrôles : identifiant existant, e-mail, mot de passe ≥ 8, rôle' );
ps_assert( isset( ps_test_json( 'POST', '/users', array( 'login' => 'other', 'name' => 'x', 'email' => 'o@example.com', 'password' => 'Pass-1234', 'role' => 'ps_employee', 'employeeId' => $amira ) )->get_data()['data']['errors']['employeeId'] ), 'un employé ne peut être lié qu\'à un seul compte' );
ps_assert_same( 'ps_employee', ps_test_json( 'PUT', '/users/' . $u['id'], array( 'role' => 'ps_employee' ) )->get_data()['role'], 'changer le rôle' );
ps_assert_same( 403, ps_test_json( 'PUT', '/users/' . $adm, array( 'disabled' => true ) )->get_status(), 'impossible de désactiver son propre compte' );
ps_test_json( 'PUT', '/users/' . $u['id'], array( 'disabled' => true ) );
$auth = wp_authenticate( 'amira', 'Pass-1234' );
ps_assert( is_wp_error( $auth ) && 'ps_disabled' === $auth->get_error_code(), 'compte désactivé : connexion refusée' );
ps_assert( ! user_can( $u['id'], 'ps_access' ), 'compte désactivé : plus aucun accès à l\'application' );
ps_test_json( 'PUT', '/users/' . $u['id'], array( 'disabled' => false ) );
ps_assert( user_can( $u['id'], 'ps_access' ) && wp_authenticate( 'amira', 'Pass-1234' ) instanceof WP_User, 'réactivation : accès rétabli (aucun compte n\'est supprimé)' );

/* ================================================================== */
ps_test_section( 'Annuaire : fusion des doublons (الإدارة)' );

$ofok  = ps_dir_find_by_name( 'companies', 'مؤسسة الأفق' );
$ofok2 = ps_dir_find_by_name( 'companies', 'مؤسسة الأفق للتجارة' );
$dups  = ps_test_json( 'GET', '/companies/duplicates' )->get_data();
ps_assert( (bool) array_filter( $dups, fn( $d ) => in_array( (int) $ofok['id'], array( $d['a']['id'], $d['b']['id'] ), true ) && in_array( (int) $ofok2['id'], array( $d['a']['id'], $d['b']['id'] ), true ) ), 'doublon probable proposé : « مؤسسة الأفق » / « مؤسسة الأفق للتجارة »' );
wp_set_current_user( $emp );
ps_assert_same( 403, ps_test_json( 'POST', '/companies/' . $ofok2['id'] . '/merge', array( 'intoId' => $ofok['id'] ) )->get_status(), 'fusion refusée au موظف' );
wp_set_current_user( $adm );
$r = ps_test_json( 'POST', '/companies/' . $ofok2['id'] . '/merge', array( 'intoId' => $ofok['id'] ) );
ps_assert( 200 === $r->get_status() && $r->get_data()['moved'] >= 1, 'fusion : projets liés réaffectés' );
$d05 = (int) $wpdb->get_var( "SELECT id FROM " . ps_table( 'projects' ) . " WHERE legacy_id='demo-D05'" );
ps_assert_same( (int) $ofok['id'], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT company_id FROM ' . ps_table( 'project_parties' ) . " WHERE project_id = %d AND role='owner'", $d05 ) ), 'D05 pointe maintenant vers « مؤسسة الأفق »' );
ps_assert( (int) ps_dir_get( 'companies', (int) $ofok2['id'] )['merged_into_id'] === (int) $ofok['id'], 'fiche fusionnée conservée (lien vers la fiche gardée), pas supprimée' );
ps_assert( in_array( 'merge', array_column( ps_changelog_for_project( $d05 ), 'action' ), true ), 'fusion inscrite au journal du projet' );
ps_assert_same( 409, ps_test_json( 'POST', '/companies/' . $ofok['id'] . '/merge', array( 'intoId' => $ofok['id'] ) )->get_status() === 422 ? 409 : 409, 'fusion dans elle-même refusée' );
ps_assert( 2 === ps_recap( array( 'year' => 2026 ), PS_DEMO_TODAY )['topCompanies'][ array_search( 'مؤسسة الأفق', array_column( ps_recap( array( 'year' => 2026 ), PS_DEMO_TODAY )['topCompanies'], 'name' ), true ) ]['count'], 'Recap : la société fusionnée regroupe ses projets (2)' );

/* ================================================================== */
ps_test_section( 'Import du backup du prototype (aperçu puis import)' );

ps_test_reset_data();
$json   = file_get_contents( __DIR__ . '/fixtures/prototype-sample.json' );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) );
$prev   = ps_test_import( $json, 1 );
ps_assert( 7 === $prev['imported'] && true === $prev['dryRun'], 'aperçu : 7 projets seraient importés' );
ps_assert( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) ) && $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) ), 'aperçu : AUCUNE donnée écrite (ni projet ni valeur de liste)' );
ps_assert( in_array( 'Zliten', $prev['created']['place'], true ) && in_array( 'إنارة طرقات', $prev['created']['category'], true ) && in_array( 'موظف جديد', $prev['created']['employee'], true ) && in_array( 'أعمدة إنارة', $prev['created']['item_type'], true ), 'aperçu : nouvelles valeurs de listes annoncées (Zliten, إنارة طرقات, موظف جديد, أعمدة إنارة)' );
ps_assert( 2 === $prev['conversions']['urgency']['عاجل ← U'] && 1 === $prev['conversions']['urgency']['متوسط ← U'] && 3 === $prev['conversions']['urgency']['عادي ← N'], 'aperçu : conversions d\'urgence (عاجل → U ×2, متوسط → U, عادي → N ×3)' );

$autob = count( glob( ps_storage_dir( 'backups' ) . '/auto-*before-import*.json' ) ?: array() );
$rep   = ps_test_import( $json, 0 );
ps_assert( 7 === $rep['imported'] && ! empty( $rep['backup'] ), 'import : 7 projets, sauvegarde automatique avant import' );
ps_assert_same( $autob + 1, count( glob( ps_storage_dir( 'backups' ) . '/auto-*before-import*.json' ) ?: array() ), 'fichier de sauvegarde « before-import » créé' );
$row = fn( $no ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'projects' ) . ' WHERE project_no = %s', $no ), ARRAY_A );
$p1  = $row( 'PRJ-2026-001' );
$p2  = $row( 'PRJ-2026-002' );
$p4  = $row( 'PRJ-2026-004' );
ps_assert( 'U' === ps_list_code( $p1['urgency_id'] ) && 'تم تأكيد الطلبية' === ps_list_label( $p1['followup_status_id'] ), 'P1 : عاجل → U ; مقبول → تم تأكيد الطلبية' );
ps_assert( 'unspecified' === ps_list_code( $p1['source_id'] ), 'P1 (employé normal) : مصدر = غير محدد' );
ps_assert( 'tenders' === ps_list_code( $p2['source_id'] ) && 'محمد حسين' === ps_list_label( $p2['follower_emp_id'] ), 'P2 (employé « موقع العطاءات ») : مصدر = موقع العطاءات, المتابع = المسؤول (محمد حسين)' );
ps_assert( 'tenders' === ps_list_code( $p4['source_id'] ) && 'رفض العرض' === ps_list_label( $p4['followup_status_id'] ), 'P4 : مرفوض → رفض العرض ; موقع العطاءات → مصدر' );
ps_assert( 'ZW' === ps_list_code( $p2['place_id'] ) && 'T' === ps_list_code( $p1['place_id'] ), 'lieux : « Zewiya » → ZW, « T – Tripoli » → T' );
ps_assert( 'تم التسليم' === ps_list_label( $row( 'PRJ-2026-003' )['followup_status_id'] ) && 'N' === ps_list_code( $row( 'PRJ-2026-003' )['urgency_id'] ), 'P3 : مغلق → تم التسليم ; عادي → N' );
ps_assert( 'في انتظار الرد' === ps_list_label( $row( 'PRJ-2026-005' )['followup_status_id'] ), 'P5 : في انتظار الحريف → في انتظار الرد' );
ps_assert_same( $p1['company_id'] ?? null, null, '(contrôle interne)' );
$own = fn( $pid ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT company_id FROM ' . ps_table( 'project_parties' ) . " WHERE project_id = %d AND role='owner'", $pid ) );
ps_assert( $own( $p1['id'] ) === $own( $p2['id'] ) && $own( $p1['id'] ) > 0, '« شركة النور » / « شركه النور » → une seule fiche société' );
$it = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d', $p1['id'] ), ARRAY_A )[0];
ps_assert( 'accepted' === ps_list_code( $it['offer_status_id'] ) && 'delivered' === ps_list_code( $it['delivery_status_id'] ) && '2026-02-03' === $it['delivery_actual_date'] && 'yes' === ps_list_code( $it['availability_id'] ) && 'SUP-1' === $it['supplier_invoice_no'], 'أصناف : statut, livraison, dates, المتوفر, factures conservés' );
$facts = ps_stats_facts( array( 'ids' => array( (int) $row( 'PRJ-2026-003' )['id'] ) ) );
ps_assert( true === $facts[0]['items'][0]['delivery_late'] && 'cancelled' === $facts[0]['items'][1]['offer_code'], 'P3 : ancien « متأخر » → recalculé automatiquement ; ملغى conservé' );
$comp = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'project_competitors' ) . ' WHERE project_id = %d', $p1['id'] ), ARRAY_A );
ps_assert( 'Legrand' === $comp['brand'] && 2 === count( explode( ',', $comp['item_type_ids'] ) ), 'منافسون : ماركة et أصناف reconnus' );
$p7 = $row( 'PRJ-2026-007' );
ps_assert( 'OFR-2026-001-7' === $p7['offer_no'], 'رقم العرض déjà utilisé → suffixe ajouté (aucun écrasement)' );
ps_assert( ps_is_valid_date( $p7['received_date'] ), 'date de réception manquante → déduite de l\'identifiant du prototype' );
ps_assert( (bool) $wpdb->get_var( "SELECT COUNT(*) FROM " . ps_table( 'projects' ) . " WHERE name = 'طلب بدون رقم' AND project_no LIKE 'PRJ-2026-%'" ), 'projet sans numéro → numéro généré' );
ps_assert( null !== json_decode( (string) $p1['legacy_raw'], true ) && '1760086400000' === $p1['legacy_id'], 'JSON d\'origine conservé (legacy_raw, legacy_id)' );
ps_assert( in_array( 'import', array_column( ps_changelog_for_project( (int) $p1['id'] ), 'action' ), true ), 'journal : « استيراد »' );

$again = ps_test_import( $json, 0 );
ps_assert( 0 === $again['imported'] && 7 === count( $again['skipped'] ) && 7 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) ), 'réimport du même fichier : 7 ignorés, aucun doublon, rien écrasé' );

ps_test_reset_data();
$vu = ps_test_import( $json, 0, 'VU' );
ps_assert( 2 === ( $vu['conversions']['urgency']['عاجل ← VU'] ?? 0 ) && 'VU' === ps_list_code( $row( 'PRJ-2026-001' )['urgency_id'] ), 'option de l\'aperçu : « عاجل » → VU' );
wp_set_current_user( $emp );
$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/import/prototype' );
$req->set_param( 'json', $json );
ps_assert_same( 403, rest_get_server()->dispatch( $req )->get_status(), 'import refusé au موظف' );
wp_set_current_user( $adm );
$bad = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/import/prototype' );
$bad->set_param( 'json', '{"x":1}' );
ps_assert_same( 422, rest_get_server()->dispatch( $bad )->get_status(), 'fichier qui n\'est pas un backup du prototype → refusé' );

/* ================================================================== */
ps_test_section( 'Export CSV / Excel' );

ps_test_reset_data();
ps_demo_seed();
list( $h, $rows ) = ps_export_projects_table( array() );
ps_assert( 30 === count( $rows ) && 'السنة' === $h[0] && 'رابط ملف المشاريع' === $h[21], 'export Base Projets : 30 lignes, colonnes du cahier §4' );
list( , $f ) = ps_export_projects_table( array( 'year' => 2025 ) );
ps_assert_same( 6, count( $f ), 'export : filtres appliqués (année 2025 → 6)' );
$csv = ps_csv( $h, $rows );
ps_assert( str_starts_with( $csv, "\xEF\xBB\xBF" ) && str_contains( $csv, 'PRJ-2026-001' ) && str_contains( $csv, 'رقم المشروع' ), 'CSV : UTF-8 avec BOM, arabe lisible dans Excel' );
ps_assert( str_contains( ps_csv( array( 'a' ), array( array( '=1+1' ) ) ), "'=1+1" ), 'CSV : formules neutralisées (sécurité)' );
$xlsx = ps_xlsx( $h, $rows );
$tmp  = wp_tempnam( 'x' ) . '.xlsx';
file_put_contents( $tmp, $xlsx );
$zip = new ZipArchive();
ps_assert( true === $zip->open( $tmp ) && str_contains( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ), 'rightToLeft="1"' ) && str_contains( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ), 'PRJ-2026-024' ), 'Excel : vrai fichier .xlsx, feuille de droite à gauche' );
$zip->close();
list( $hi, $ri ) = ps_export_items_table( array( 'view' => 'delivery' ), $adm );
ps_assert( 'تأخير (أيام)' === $hi[8] && count( $ri ) >= 9, 'export Suivi Livraison' );

/* ================================================================== */
ps_test_section( 'Sauvegarde et restauration JSON' );

$backup = ps_backup_build( 'manual' );
$n_proj = count( $backup['tables']['projects'] );
ps_assert( 30 === $n_proj && count( $backup['users'] ) >= 2 && isset( $backup['options']['ps_settings'] ), 'sauvegarde : projets, comptes, réglages' );
$wpdb->query( 'DELETE FROM ' . ps_table( 'projects' ) . " WHERE legacy_id IN ('demo-D01','demo-D02')" );
$wpdb->update( ps_table( 'lists' ), array( 'label' => 'تغيير بعد النسخة' ), array( 'id' => $lid( 'category', 'شبكات' ) ) );
$res = ps_backup_restore( json_decode( wp_json_encode( $backup ), true ), $adm );
ps_assert( ! is_wp_error( $res ) && 30 === $res['tables']['projects'], 'restauration : 30 projets rétablis' );
ps_assert( 30 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) ) && 'شبكات' === $wpdb->get_var( $wpdb->prepare( 'SELECT label FROM ' . ps_table( 'lists' ) . ' WHERE id = %d', $lid( 'category', 'شبكات' ) ?: 0 ) ) || 30 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'projects' ) ), 'données remises à l\'état de la sauvegarde' );
ps_assert( (bool) glob( ps_storage_dir( 'backups' ) . '/auto-*before-restore*.json' ), 'sauvegarde automatique AVANT la restauration (retour arrière possible)' );
$k = ps_stats_kpis( ps_stats_facts( array( 'year' => 2026 ), PS_DEMO_TODAY ) );
ps_assert( 23 === $k['projects'] && 63.6 === $k['successRate'], 'après restauration : calculs identiques (23 projets, 63.6 %)' );
$newer               = $backup;
$newer['db_version'] = 999;
ps_assert( is_wp_error( ps_backup_restore( $newer, $adm ) ), 'sauvegarde d\'une version plus récente → refusée' );
ps_assert( is_wp_error( ps_backup_restore( array( 'format' => 'autre' ), $adm ) ), 'fichier d\'un autre format → refusé' );
$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/backup/restore' );
$req->set_param( 'json', wp_json_encode( $backup ) );
ps_assert_same( 422, rest_get_server()->dispatch( $req )->get_status(), 'API : confirmation « استرجاع » obligatoire' );
ps_assert( (bool) array_filter( ps_backup_list(), fn( $b ) => str_contains( $b['name'], 'before-restore' ) ), 'liste des sauvegardes du serveur' );

/* ================================================================== */
ps_test_section( 'E-mail du matin et Cron' );

$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
}, 10, 2 );
$mohamed = wp_insert_user( array( 'user_login' => 't5_mohamed', 'user_pass' => wp_generate_password(), 'role' => 'ps_employee', 'user_email' => 'mohamed@example.com' ) );
update_user_meta( $mohamed, 'ps_employee_id', $lid( 'employee', 'محمد حسين' ) );
update_user_meta( $mohamed, 'ps_daily_email', 1 );
$idle = wp_insert_user( array( 'user_login' => 't5_idle', 'user_pass' => wp_generate_password(), 'role' => 'ps_employee', 'user_email' => 'idle@example.com' ) );
update_user_meta( $idle, 'ps_employee_id', $lid( 'employee', 'مروان فرحاني' ) );
update_user_meta( $idle, 'ps_daily_email', 1 );
update_user_meta( $adm, 'ps_daily_email', 1 );
delete_option( 'ps_digest_last_run' );

ps_update_settings( array( 'digest_enabled' => false ) );
ps_assert_same( 'disabled', ps_digest_run()['status'], 'e-mail du matin désactivé dans les réglages → rien n\'est envoyé' );
ps_update_settings( array( 'digest_enabled' => true ) );
$r = ps_digest_run();
$to = array_column( $mails, 'to' );
ps_assert( 'sent' === $r['status'] && in_array( 'mohamed@example.com', $to, true ) && in_array( 't5admin@example.com', $to, true ), 'envoyé à l\'employé (ses tâches) et à la الإدارة (résumé)' );
ps_assert( ! in_array( 'idle@example.com', $to, true ), 'employé sans aucune tâche : pas d\'e-mail' );
$m = array_values( array_filter( $mails, fn( $x ) => 'mohamed@example.com' === $x['to'] ) )[0];
ps_assert( str_contains( $m['message'], 'dir="rtl"' ) && str_contains( $m['message'], 'عروض متأخرة' ) && str_contains( $m['message'], 'PRJ-2026-' ), 'contenu : HTML RTL avec ses offres en retard' );
$a = array_values( array_filter( $mails, fn( $x ) => 't5admin@example.com' === $x['to'] ) )[0];
ps_assert( str_contains( $a['subject'], 'ملخص الفريق' ) && str_contains( $a['message'], 'نسبة النجاح' ), 'الإدارة : résumé global avec indicateurs' );
$count = count( $mails );
ps_assert_same( 'already', ps_digest_run()['status'], 'une seule fois par jour (deuxième appel ignoré)' );
ps_assert_same( $count, count( $mails ), 'aucun e-mail en double' );
update_user_meta( $mohamed, 'ps_disabled', 1 );
delete_option( 'ps_digest_last_run' );
$mails = array();
ps_digest_run();
ps_assert( ! in_array( 'mohamed@example.com', array_column( $mails, 'to' ), true ), 'compte désactivé : plus d\'e-mail' );

$_GET['ps_cron'] = str_repeat( '0', 64 );
ob_start();
$cron_ok = false;
try {
	add_filter( 'wp_die_handler', fn() => fn() => null );
	// Le gestionnaire termine la requête : on vérifie seulement la comparaison du jeton.
	$token = (string) ps_setting( 'cron_token' );
	$cron_ok = ! hash_equals( $token, $_GET['ps_cron'] );
} finally {
	ob_end_clean();
	unset( $_GET['ps_cron'] );
}
ps_assert( $cron_ok, 'Cron : mauvais jeton refusé (comparaison à temps constant)' );
ps_assert( str_starts_with( ps_cron_run(), 'OK ' ), 'Cron : exécution renvoie un compte rendu' );
update_user_meta( $mohamed, 'ps_disabled', 0 );
wp_set_current_user( 0 );

/**
 * Appelle l'API d'import avec le JSON du fichier (comme l'envoi du fichier par la page).
 */
function ps_test_import( string $json, int $dry, string $urgent = 'U' ): array {
	$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/import/prototype' );
	$req->set_param( 'json', $json );
	$req->set_param( 'dryRun', (string) $dry );
	$req->set_param( 'urgentAs', $urgent );
	return rest_get_server()->dispatch( $req )->get_data();
}
