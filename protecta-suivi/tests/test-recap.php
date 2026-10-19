<?php
/**
 * Étape 4 — Recap : indicateurs, comparaison N-1, chaque graphique / tableau, filtres, droits.
 * Jeu de démonstration rechargé (« aujourd'hui » = 2026-10-19). Valeurs attendues calculées à la main.
 */

global $wpdb;
require_once __DIR__ . '/fixtures/demo.php';
ps_test_reset_data();
ps_demo_seed();

$T   = PS_DEMO_TODAY;
$r   = ps_recap( array( 'year' => 2026 ), $T );
$lid = fn( $key, $v ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s AND (code = %s OR label = %s) LIMIT 1', $key, $v, $v ) );

ps_test_section( 'Recap 2026 : indicateurs et comparaison avec 2025' );

ps_assert( 23 === $r['kpis']['projects'] && 1 === $r['kpis']['cancelled'] && 7 === $r['kpis']['confirmed'] && 63.6 === $r['kpis']['successRate'], 'indicateurs identiques au calcul à la main (23 projets, 1 ملغى, 7 مؤكدة, 63.6 %)' );
ps_assert_same( array( 'prev' => 6, 'diff' => 17.0, 'pct' => 283.3, 'dir' => 'up' ), $r['compare']['projects'], 'عدد المشاريع : 23 vs 6 en 2025 → ↑ 283.3 %' );
ps_assert_same( array( 'prev' => 50.0, 'diff' => 13.6, 'pct' => null, 'dir' => 'up' ), $r['compare']['successRate'], 'نسبة النجاح : 63.6 vs 50.0 → ↑ 13.6 points' );
ps_assert_same( array( 'prev' => 80.0, 'diff' => 4.6, 'pct' => null, 'dir' => 'up' ), $r['compare']['sendRate'], 'نسبة الإرسال : 84.6 vs 80.0 → ↑ 4.6 points' );
ps_assert_same( array( 'prev' => 1, 'diff' => 1.0, 'pct' => 100.0, 'dir' => 'up' ), $r['compare']['rejected'], 'المرفوضة : 2 vs 1 → ↑ 100.0 %' );
$lyd = $lid( 'currency', 'LYD' );
$tnd = $lid( 'currency', 'TND' );
ps_assert_same( array( 'prev' => 9000.0, 'diff' => 66000.0, 'pct' => 733.3, 'dir' => 'up' ), $r['compare']['amounts'][ $lyd ], 'مبالغ LYD : 75 000 vs 9 000 → ↑ 733.3 %' );
ps_assert_same( null, $r['compare']['amounts'][ $tnd ]['pct'], 'TND absent en 2025 → pas de pourcentage (« جديد »)' );

ps_test_section( 'Recap 2026 : graphiques et tableaux' );

$months = array_map( fn( $m ) => array( $m['ongoing'], $m['confirmed'], $m['rejected'], $m['total'] ), $r['byMonth'] );
ps_assert_same(
	array( array( 0, 1, 1, 2 ), array( 2, 0, 0, 2 ), array( 0, 2, 0, 2 ), array( 2, 0, 0, 2 ), array( 1, 1, 0, 2 ), array( 1, 0, 0, 1 ), array( 1, 1, 1, 3 ), array( 1, 1, 0, 2 ), array( 3, 0, 0, 3 ), array( 3, 1, 0, 4 ), array( 0, 0, 0, 0 ), array( 0, 0, 0, 0 ) ),
	$months,
	'حسب الشهر : جارية / مؤكدة / مرفوضة / total pour جانفي…ديسمبر (D11 ملغى exclu)'
);
$cats = array_column( $r['byCategory'], 'count', 'label' );
ps_assert( 6 === $cats['إنارة'] && 6 === $cats['كهرباء'] && 4 === $cats['شبكات'] && 4 === $cats['أنظمة أمان وإنذار'] && 3 === $cats['تجهيزات'], 'حسب التصنيف : إنارة 6, كهرباء 6, شبكات 4, أمان 4, تجهيزات 3', $cats );
ps_assert_same( 26.1, $r['byCategory'][0]['pct'], 'pourcentage : 6 ÷ 23 = 26.1 %' );
$urg = array_column( $r['byUrgency'], 'count', 'code' );
ps_assert_same( array( 'VU' => 4, 'U' => 10, 'N' => 7, 'L' => 2 ), $urg, 'حسب درجة الاستعجال : VU 4, U 10, N 7, L 2' );
$fs = array_column( $r['byFollowupStatus'], 'count', 'label' );
ps_assert( 2 === $fs['رفض العرض'] && 1 === $fs['تم التسليم'] && 3 === $fs['تم تأكيد الطلبية'] && 10 === $fs['في دراسة'], 'حسب حالة المتابعة : رفض 2, تسليم 1, تأكيد 3, في دراسة 10', $fs );
ps_assert( ! array_filter( $r['byFollowupStatus'], fn( $s ) => empty( $s['color'] ) ), 'chaque حالة a sa couleur (reprise des réglages)' );

$emps = array();
foreach ( $r['byEmployee'] as $e ) {
	$emps[ $e['label'] ] = $e;
}
ps_assert( 3 === $emps['محمد حسين']['count'] && 1 === $emps['محمد حسين']['confirmed'] && 100.0 === $emps['محمد حسين']['successRate'], 'حسب الموظف : محمد حسين 3 projets, 1 مؤكدة, 100.0 %' );
$a = $emps['أميرة السعيداني'];
ps_assert( 3 === $a['count'] && 1 === $a['confirmed'] && 1 === $a['rejected'] && 1 === $a['delivered'] && 1 === $a['notSent'] && 50.0 === $a['successRate'], 'حسب الموظف : أميرة 3 / 1 مؤكدة / 1 مرفوضة / 1 مسلّمة / 1 لم تُرسل / 50.0 %' );

$types = array_column( $r['byItemType'], 'count', 'label' );
ps_assert( 4 === $types['CABLE'] && 3 === $types['RELLI'] && 3 === $types['حساسات'], 'حسب الصنف : CABLE 4, RELLI 3 (D19 compte une seule fois), حساسات 3', $types );
ps_assert( $r['byItemType'][0]['count'] >= end( $r['byItemType'] )['count'], 'حسب الصنف : barres triées' );
ps_assert( 'T' === $r['byPlace'][0]['code'] && 10 === $r['byPlace'][0]['count'] && 'Tripoli' === $r['byPlace'][0]['label'], 'حسب المكان : T (Tripoli) en tête avec 10 projets' );

$src = array_column( $r['bySource'], null, 'label' );
ps_assert( 4 === $src['موقع العطاءات']['count'] && 0 === $src['موقع العطاءات']['confirmed'] && 0.0 === $src['موقع العطاءات']['successRate'], 'حسب المصدر : موقع العطاءات 4 projets, 0 مؤكدة, 0.0 %' );
ps_assert( 8 === $src['مباشر']['count'] && 100.0 === $src['مباشر']['successRate'], 'حسب المصدر : مباشر 8 projets, 100.0 %' );

ps_assert( 'شركة الأمان' === $r['topCompanies'][0]['name'] && 4 === $r['topCompanies'][0]['count'] && 3 === $r['topCompanies'][0]['confirmed'], 'أهم الشركات : 1. شركة الأمان (4 projets, 3 مؤكدة)' );
ps_assert_same( array( $lyd => 8000.0, $lid( 'currency', 'EUR' ) => 4000.0 ), $r['topCompanies'][0]['confirmedAmounts'], 'شركة الأمان : montants confirmés par devise (8 000 LYD · 4 000 EUR)' );
ps_assert( 'شركة النور' === $r['topCompanies'][1]['name'] && 4 === $r['topCompanies'][1]['count'] && array( $lyd => 60000.0 ) === $r['topCompanies'][1]['confirmedAmounts'], '2. شركة النور (« شركه النور » fusionnée) : 4 projets, 60 000 LYD' );
ps_assert( 'المنافس الأول' === $r['competitors'][0]['name'] && 3 === $r['competitors'][0]['count'] && array( 'Philips', 'Nexans' ) === $r['competitors'][0]['brands'], 'المنافسون : المنافس الأول (3 projets, Philips, Nexans)' );

ps_assert_same( 4, $r['notSentItems'], 'لم يُرسل : 4 أصناف' );
$reasons = array_column( $r['notSentReasons'], 'pct', 'label' );
ps_assert_same( array( 'السعر غير منافس' => 50.0, 'المنتج غير متوفر' => 25.0, 'الأجل قصير' => 25.0 ), $reasons, 'أسباب عدم الإرسال : السعر 50 %, المنتج 25 %, الأجل 25 %' );
ps_assert_same( 4, count( $r['notSentByType'] ), 'لم تُرسل حسب الصنف : 4 أصناف' );

ps_test_section( 'Recap : filtres' );

$oct = ps_recap( array( 'year' => 2026, 'month' => 10 ), $T );
ps_assert( 4 === $oct['kpis']['projects'] && 1 === $oct['kpis']['confirmed'], 'أكتوبر 2026 : 4 projets, 1 مؤكدة' );
ps_assert( 0 === $oct['compare']['projects']['prev'] && null === $oct['compare']['projects']['pct'], 'comparaison avec أكتوبر 2025 (0 projet) → pas de %' );
$m = ps_recap( array( 'year' => 2026, 'followerEmpId' => $lid( 'employee', 'محمد حسين' ) ), $T );
ps_assert_same( 3, $m['kpis']['projects'], 'filtre الموظف المتابع : محمد حسين → 3 projets' );
$p = ps_recap( array( 'year' => 2026, 'placeId' => $lid( 'place', 'BG' ) ), $T );
ps_assert_same( 4, $p['kpis']['projects'], 'filtre المكان : BG → 4 projets (D02, D05, D15, D22)' );
$s = ps_recap( array( 'year' => 2026, 'sourceId' => $lid( 'source', 'tenders' ) ), $T );
ps_assert_same( 4, $s['kpis']['projects'], 'filtre مصدر : موقع العطاءات → 4 projets' );
ps_assert_same( 0, ps_recap( array( 'year' => 2030 ), $T )['kpis']['projects'], 'année sans projet → 0 (état vide)' );

ps_test_section( 'Recap : « غير محدد » à part et hors نسبة النجاح ; anneau limité à 8 parts' );

$unspec = $lid( 'source', 'unspecified' );
$wpdb->query( $wpdb->prepare( 'UPDATE ' . ps_table( 'projects' ) . " SET source_id = %d WHERE legacy_id IN ('demo-D01','demo-D10')", $unspec ) );
$u   = ps_recap( array( 'year' => 2026 ), $T );
$last = end( $u['bySource'] );
ps_assert( 'غير محدد' === $last['label'] && true === $last['unspecified'] && 2 === $last['count'] && null === $last['successRate'], '« غير محدد » affiché en dernier, 2 projets, sans نسبة النجاح' );

for ( $i = 1; $i <= 9; $i++ ) {
	$cid = ps_insert_list_value( 'category', array( 'label' => 'تصنيف ' . $i ), 1000 + $i );
	$wpdb->update( ps_table( 'projects' ), array( 'category_id' => $cid ), array( 'legacy_id' => sprintf( 'demo-D%02d', $i ) ) );
}
ps_lists_by_id( true );
$c = ps_recap( array( 'year' => 2026 ), $T )['byCategory'];
ps_assert( 8 === count( $c ) && 'أخرى' === end( $c )['label'] && 23 === array_sum( array_column( $c, 'count' ) ), '> 8 parts : 7 + « أخرى » (total conservé = 23)' );

ps_test_section( 'Recap : réservé à la الإدارة' );

$emp_u = wp_insert_user( array( 'user_login' => 't4_emp', 'user_pass' => wp_generate_password(), 'role' => 'ps_employee' ) );
$mgr_u = wp_insert_user( array( 'user_login' => 't4_mgr', 'user_pass' => wp_generate_password(), 'role' => 'ps_manager' ) );
$adm_u = wp_insert_user( array( 'user_login' => 't4_adm', 'user_pass' => wp_generate_password(), 'role' => 'ps_admin' ) );
foreach ( array( array( $emp_u, 403, 'موظف' ), array( $mgr_u, 403, 'مسؤول مشروع' ), array( $adm_u, 200, 'الإدارة' ) ) as list( $u, $code, $label ) ) {
	wp_set_current_user( $u );
	ps_assert_same( $code, ps_test_json( 'GET', '/recap?year=2026' )->get_status(), "Recap : $label → $code" );
}
wp_set_current_user( 0 );
