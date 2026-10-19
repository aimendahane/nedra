<?php
/**
 * Étape 3 — Vérification de CHAQUE calcul du brief §6 :
 *   A. règles unitaires (cas limites) ;
 *   B. jeu de démonstration de 30 projets comparé aux résultats calculés à la main
 *      (tests/fixtures/demo-expected.md), « aujourd'hui » = 2026-10-19.
 */

require_once __DIR__ . '/fixtures/demo.php';

$T = PS_DEMO_TODAY;

/* ================================================================== */
ps_test_section( 'A. Règles unitaires (§6)' );

ps_assert( ps_calc_is_confirmed( 'confirmed' ) && ps_calc_is_confirmed( 'delivered' ) && ! ps_calc_is_confirmed( 'ongoing' ) && ! ps_calc_is_confirmed( 'rejected' ), 'مؤكد = type مؤكد ou مسلّم (مسلّم compte comme مؤكد)' );
ps_assert( ps_calc_is_closed( 'rejected' ) && ps_calc_is_closed( 'delivered' ) && ! ps_calc_is_closed( 'confirmed' ) && ! ps_calc_is_closed( 'ongoing' ), 'مغلق = مرفوض ou مسلّم' );

ps_assert( ps_calc_is_offer_sent( 'sent', 'ongoing' ) && ps_calc_is_offer_sent( 'accepted', 'ongoing' ) && ps_calc_is_offer_sent( 'rejected', 'ongoing' ), 'المرسلة : حالة العرض مرسل / مقبول / مرفوض' );
ps_assert( ps_calc_is_offer_sent( 'preparing', 'confirmed' ) && ps_calc_is_offer_sent( 'preparing', 'rejected' ) && ps_calc_is_offer_sent( 'preparing', 'delivered' ), 'المرسلة : OU suivi de type مؤكد / مرفوض / مسلّم' );
ps_assert( ! ps_calc_is_offer_sent( 'not_sent', 'ongoing' ) && ! ps_calc_is_offer_sent( 'preparing', 'ongoing' ), 'لم يُرسل et قيد الإعداد (suivi جارية) : pas envoyées' );
ps_assert( ps_calc_is_offer_not_sent( 'not_sent', 'ongoing' ) && ! ps_calc_is_offer_not_sent( 'not_sent', 'confirmed' ), 'لم تُرسل : statut لم يُرسل et pas compté comme envoyé' );

ps_assert_same( 63.6, ps_calc_success_rate( 7, 11 ), 'نسبة النجاح 7 ÷ 11 = 63.6 (1 décimale)' );
ps_assert_same( 33.3, ps_calc_success_rate( 1, 3 ), 'نسبة النجاح 1 ÷ 3 = 33.3' );
ps_assert_same( null, ps_calc_success_rate( 0, 0 ), 'نسبة النجاح sans offre envoyée → « — » (null)' );
ps_assert_same( 84.6, ps_calc_send_rate( 11, 2 ), 'نسبة الإرسال 11 ÷ 13 = 84.6' );
ps_assert_same( 100.0, ps_calc_send_rate( 5, 0 ), 'نسبة الإرسال 5 ÷ 5 = 100.0' );
ps_assert_same( null, ps_calc_send_rate( 0, 0 ), 'نسبة الإرسال sans donnée → null' );
ps_assert_same( array( 1 => 1500.5, 2 => 300.0 ), ps_calc_amounts_by_currency( array( array( 1000, 1 ), array( '500.5', 1 ), array( 300, 2 ), array( null, 1 ), array( 999, null ) ) ), 'montants séparés par devise, jamais additionnés entre devises' );

ps_assert( ps_calc_is_offer_late( 'preparing', '2026-10-18', 'N', $T ), 'عرض متأخر : قيد الإعداد, أجل hier, N' );
ps_assert( ! ps_calc_is_offer_late( 'preparing', '2026-10-19', 'VU', $T ), 'pas en retard le jour même du أجل' );
ps_assert( ! ps_calc_is_offer_late( 'preparing', '2026-01-01', 'L', $T ), 'urgence L : jamais en retard' );
ps_assert( ! ps_calc_is_offer_late( 'sent', '2026-01-01', 'U', $T ) && ! ps_calc_is_offer_late( 'not_sent', '2026-01-01', 'U', $T ), 'مرسل / لم يُرسل : pas en retard' );
ps_assert( ! ps_calc_is_offer_late( 'preparing', null, 'U', $T ), 'sans أجل : pas en retard' );

ps_assert( ps_calc_is_delivery_late( 'accepted', 'waiting', '2026-10-18', null, $T ), 'تسليم متأخر : sans date réelle, demandée < aujourd\'hui' );
ps_assert( ps_calc_is_delivery_late( 'accepted', 'ready', '2026-09-01', '2026-09-11', $T ), 'تسليم متأخر : date réelle > demandée (pas encore « تم التسليم »)' );
ps_assert( ! ps_calc_is_delivery_late( 'accepted', 'delivered', '2026-09-01', '2026-09-11', $T ), '« تم التسليم » : jamais en retard' );
ps_assert( ! ps_calc_is_delivery_late( 'accepted', 'waiting', '2026-10-19', null, $T ), 'demandée = aujourd\'hui : pas en retard' );
ps_assert( ! ps_calc_is_delivery_late( 'sent', 'waiting', '2026-01-01', null, $T ), 'صنف non مقبول : hors livraison' );

ps_assert_same( 10, ps_calc_delivery_delay_days( '2026-09-01', '2026-09-11', $T ), 'تأخير = réelle − demandée = 10' );
ps_assert_same( 0, ps_calc_delivery_delay_days( '2026-02-15', '2026-02-10', $T ), 'تأخير jamais négatif (livré en avance → 0)' );
ps_assert_same( 19, ps_calc_delivery_delay_days( '2026-09-30', null, $T ), 'تأخير sans date réelle = aujourd\'hui − demandée = 19' );
ps_assert_same( 0, ps_calc_delivery_delay_days( '2026-10-25', null, $T ), 'تأخير : date future → 0' );
ps_assert_same( 29, ps_calc_delivery_delay_days( '2026-02-01', '2026-03-02', $T ), 'تأخير à cheval sur février (28 j) = 29' );
ps_assert_same( 'late', ps_calc_effective_delivery_status( 'waiting', true ), 'حالة التسليم passe automatiquement à « متأخر »' );
ps_assert_same( 'ready', ps_calc_effective_delivery_status( 'ready', false ), 'sinon : statut choisi conservé' );

ps_assert_same( 'late', ps_calc_offer_row_color( 'preparing', '2026-10-01', 'U', $T ), 'ligne ROUGE : offre en retard' );
ps_assert_same( null, ps_calc_offer_row_color( 'preparing', '2026-10-01', 'L', $T ), 'pas de rouge pour L' );
ps_assert_same( 'done', ps_calc_offer_row_color( 'accepted', '2026-10-01', 'U', $T ), 'ligne VERTE : مقبول' );

ps_assert( ps_calc_needs_followup( 'ongoing', 'U', '2026-10-11', '2026-10-01', null, $T ), 'alerte suivi : dernier suivi il y a 8 jours (> 7)' );
ps_assert( ! ps_calc_needs_followup( 'ongoing', 'U', '2026-10-12', '2026-10-01', null, $T ), 'pas d\'alerte à 7 jours exactement' );
ps_assert( ps_calc_needs_followup( 'ongoing', 'U', '2026-10-18', '2026-10-18', '2026-10-18', $T ), 'alerte : prochaine date de suivi dépassée' );
ps_assert( ! ps_calc_needs_followup( 'ongoing', 'U', '2026-10-15', '2026-10-15', '2026-10-19', $T ), 'prochaine date = aujourd\'hui : pas encore dépassée' );
ps_assert( ps_calc_needs_followup( 'confirmed', 'N', null, '2026-10-01 10:00:00', null, $T ), 'sans سجل : dernière modification du projet (18 j)' );
ps_assert( ! ps_calc_needs_followup( 'rejected', 'U', null, '2026-01-01', null, $T ) && ! ps_calc_needs_followup( 'delivered', 'U', null, '2026-01-01', null, $T ), 'projet مغلق : pas d\'alerte' );
ps_assert( ! ps_calc_needs_followup( 'ongoing', 'L', null, '2026-01-01', null, $T ), 'urgence L : pas d\'alerte' );
ps_update_settings( array( 'followup_alert_days' => 3 ) );
ps_assert( ps_calc_needs_followup( 'ongoing', 'U', '2026-10-15', '2026-10-15', null, $T, (int) ps_setting( 'followup_alert_days' ) ), 'seuil réglable (3 jours)' );
ps_update_settings( array( 'followup_alert_days' => 7 ) );

/* ================================================================== */
ps_test_section( 'B. Jeu de démonstration (30 projets) vs résultats calculés à la main' );

ps_test_reset_data();
$ids   = ps_demo_seed();
$facts = ps_stats_facts( array(), $T );
$by    = array();
foreach ( $facts as $f ) {
	$by[ str_replace( 'demo-', '', $wpdb->get_var( $wpdb->prepare( 'SELECT legacy_id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $f['id'] ) ) ) ] = $f;
}
ps_assert_same( 30, count( $facts ), '30 projets chargés' );

// حالة العرض, envoi, priorité : tableau §1.
$expected = array(
	'D01' => array( 'accepted', true, 'P1' ), 'D02' => array( 'rejected', true, 'P2' ), 'D03' => array( 'preparing', false, 'P2' ),
	'D04' => array( 'preparing', false, 'P3' ), 'D05' => array( 'accepted', true, 'P1' ), 'D06' => array( 'accepted', true, 'P2' ),
	'D07' => array( 'sent', true, 'P2' ), 'D08' => array( 'not_sent', false, 'P3' ), 'D09' => array( 'not_sent', false, 'P2' ),
	'D10' => array( 'accepted', true, 'P1' ), 'D11' => array( 'cancelled', false, 'P3' ), 'D12' => array( 'preparing', false, 'P2' ),
	'D13' => array( 'sent', true, 'P2' ), 'D14' => array( 'preparing', true, 'P2' ), 'D15' => array( 'preparing', false, 'P2' ),
	'D16' => array( 'accepted', true, 'P1' ), 'D17' => array( 'preparing', false, 'P2' ), 'D18' => array( 'preparing', false, 'P2' ),
	'D19' => array( 'accepted', true, 'P2' ), 'D20' => array( 'preparing', false, 'P3' ), 'D21' => array( 'preparing', false, 'P1' ),
	'D22' => array( 'preparing', false, 'P2' ), 'D23' => array( 'preparing', false, 'P3' ), 'D24' => array( 'accepted', true, 'P2' ),
);
$bad = array();
foreach ( $expected as $code => list( $offer, $sent, $prio ) ) {
	$f = $by[ $code ];
	if ( $f['offer_status'] !== $offer || $f['is_sent'] !== $sent || $f['priority'] !== $prio ) {
		$bad[] = "$code: {$f['offer_status']}/" . var_export( $f['is_sent'], true ) . "/{$f['priority']}";
	}
}
ps_assert( ! $bad, '24 projets 2026 : حالة العرض, « مرسل ? » et الأولوية conformes au tableau §1', $bad );
ps_assert_same( 'sent', $by['D07']['offer_status'], 'D07 : مرسل + لم يُرسل → مرسل' );
ps_assert_same( 'preparing', $by['D17']['offer_status'], 'D17 : قيد الإعداد + ملغى → ملغى ignoré → قيد الإعداد' );
ps_assert( $by['D14']['is_sent'] && 'preparing' === $by['D14']['offer_status'], 'D14 : offre قيد الإعداد mais suivi مؤكد → compte comme envoyée' );

// Indicateurs 2026 : tableau §2.
$k26 = ps_stats_kpis( array_values( array_filter( $facts, fn( $f ) => 2026 === $f['year'] ) ) );
$cur = fn( $code ) => ps_list_id_by_code( 'currency', $code );
ps_assert_same( 23, $k26['projects'], '2026 عدد المشاريع (hors ملغى) = 23' );
ps_assert_same( 1, $k26['cancelled'], '2026 ملغى (à part) = 1' );
ps_assert_same( 7, $k26['confirmed'], '2026 المؤكدة = 7' );
ps_assert_same( 2, $k26['rejected'], '2026 المرفوضة = 2' );
ps_assert_same( 14, $k26['ongoing'], '2026 قيد الدراسة = 14' );
ps_assert_same( 11, $k26['sent'], '2026 العروض المرسلة = 11' );
ps_assert_same( 2, $k26['notSent'], '2026 لم تُرسل = 2' );
ps_assert_same( 63.6, $k26['successRate'], '2026 نسبة النجاح = 7 ÷ 11 = 63.6 %' );
ps_assert_same( 84.6, $k26['sendRate'], '2026 نسبة الإرسال = 11 ÷ 13 = 84.6 %' );
ps_assert_same( array( $cur( 'LYD' ) => 75000.0, $cur( 'USD' ) => 17000.0, $cur( 'EUR' ) => 24000.0, $cur( 'TND' ) => 3000.0 ), $k26['amounts'], '2026 مبالغ العروض : LYD 75 000, USD 17 000, EUR 24 000, TND 3 000' );
ps_assert_same( array( $cur( 'LYD' ) => 68000.0, $cur( 'USD' ) => 12000.0, $cur( 'EUR' ) => 24000.0 ), $k26['confirmedAmounts'], '2026 مبالغ المؤكدة : LYD 68 000, USD 12 000, EUR 24 000' );
ps_assert_same( 6, $k26['lateOffers'], '2026 العروض المتأخرة = 6' );
ps_assert_same( 3, $k26['lateDeliveries'], '2026 التسليمات المتأخرة = 3' );
ps_assert_same( 14, $k26['followupAlerts'], '2026 alertes de suivi = 14' );

$late_codes = array();
foreach ( $by as $code => $f ) {
	foreach ( $f['items'] as $i ) {
		if ( $i['late'] ) {
			$late_codes[] = $code;
		}
	}
}
sort( $late_codes );
ps_assert_same( array( 'D03', 'D14', 'D17', 'D20', 'D21', 'D23', 'E06' ), $late_codes, 'أصناف en retard (لازم قرار) : D03, D14, D17, D20, D21, D23 (+ E06 en 2025)' );

// Marges : tableau §3.
ps_assert_same( array( 'amount' => 2500.0, 'percent' => 25.0 ), $by['D01']['margin'], 'D01 هامش 2 500 (25.0 %)' );
ps_assert_same( array( 'amount' => 5000.0, 'percent' => 25.0 ), $by['D05']['margin'], 'D05 هامش 5 000 EUR (25.0 %)' );
ps_assert_same( array( 'amount' => -500.0, 'percent' => -6.3 ), $by['D06']['margin'], 'D06 هامش −500 (−6.3 %)' );
ps_assert_same( array( 'amount' => 9000.0, 'percent' => 18.0 ), $by['D10']['margin'], 'D10 هامش 9 000 (18.0 %)' );
ps_assert_same( array( 'amount' => 3000.0, 'percent' => 25.0 ), $by['D16']['margin'], 'D16 هامش 3 000 USD (25.0 %)' );
ps_assert_same( null, $by['D02']['margin'], 'D02 : USD vs LYD → pas de marge' );
ps_assert_same( null, $by['D24']['margin'], 'D24 : pas de مبلغ الشراء → pas de marge' );

// Livraisons : tableau §4.
$dl = array(
	'D01' => array( false, 0, 'delivered' ), 'D05' => array( false, 5, 'delivered' ), 'D06' => array( true, 19, 'late' ),
	'D10' => array( false, 0, 'ready' ), 'D16' => array( true, 10, 'late' ), 'D24' => array( true, 1, 'late' ),
);
foreach ( $dl as $code => list( $late, $delay, $display ) ) {
	$i = $by[ $code ]['items'][0];
	ps_assert( $i['delivery_late'] === $late && $i['delay'] === $delay && $i['delivery_display'] === $display, "$code : retard " . ( $late ? 'oui' : 'non' ) . ", تأخير $delay, حالة « $display »", array( $i['delivery_late'], $i['delay'], $i['delivery_display'] ) );
}
$d19 = array_values( array_filter( $by['D19']['items'], fn( $i ) => 'accepted' === $i['offer_code'] ) )[0];
ps_assert( false === $d19['delivery_late'] && 0 === $d19['delay'], 'D19 : livraison future → ni retard ni تأخير' );

// Alertes de suivi : tableau §5.
$alerts = array_keys( array_filter( $by, fn( $f ) => $f['needs_followup'] && 2026 === $f['year'] ) );
sort( $alerts );
ps_assert_same( array( 'D03', 'D06', 'D07', 'D08', 'D09', 'D10', 'D11', 'D14', 'D15', 'D16', 'D17', 'D19', 'D20', 'D22' ), $alerts, 'alertes de suivi 2026 : 14 projets du tableau §5' );

// Indicateurs 2025 : tableau §6.
$k25 = ps_stats_kpis( array_values( array_filter( $facts, fn( $f ) => 2025 === $f['year'] ) ) );
ps_assert(
	6 === $k25['projects'] && 0 === $k25['cancelled'] && 2 === $k25['confirmed'] && 1 === $k25['rejected'] && 3 === $k25['ongoing']
	&& 4 === $k25['sent'] && 1 === $k25['notSent'] && 50.0 === $k25['successRate'] && 80.0 === $k25['sendRate']
	&& 1 === $k25['lateOffers'] && 0 === $k25['lateDeliveries'],
	'2025 : 6 projets, 2 مؤكدة, 1 مرفوضة, 3 قيد الدراسة, 4 مرسلة, 1 لم تُرسل, 50.0 %, 80.0 %, 1 retard d\'offre, 0 livraison',
	$k25
);
ps_assert_same( array( $cur( 'LYD' ) => 9000.0, $cur( 'USD' ) => 15000.0 ), $k25['amounts'], '2025 montants : LYD 9 000, USD 15 000' );

// مهامي : tableau §7.
$emp   = fn( $name ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . " WHERE list_key='employee' AND label=%s", $name ) );
$count = function ( $name ) use ( $facts, $emp, $T ) {
	$t = ps_tasks( $facts, $emp( $name ), $T );
	return array( count( $t['lateOffers'] ), count( $t['followupsDue'] ), count( $t['deliveries'] ) );
};
ps_assert_same( array( 3, 2, 2 ), $count( 'محمد حسين' ), 'مهامي محمد حسين : 3 عروض متأخرة, 2 متابعات, 2 تسليمات' );
ps_assert_same( array( 2, 1, 1 ), $count( 'وفاء حامدي' ), 'مهامي وفاء حامدي : 2, 1, 1' );
ps_assert_same( array( 1, 0, 1 ), $count( 'غفران دريدي' ), 'مهامي غفران دريدي : 1, 0, 1' );
$all = ps_tasks( $facts, null, $T );
ps_assert_same( 7, count( $all['lateOffers'] ), 'مهامي الإدارة (toute l\'équipe) : 7 offres en retard (6 en 2026 + E06)' );

// Annuaire : doublons de sociétés.
ps_assert_same( $by['D01']['company_id'], $by['D21']['company_id'], '« شركة النور » et « شركه النور » : même fiche' );
ps_assert( $by['D02']['company_id'] !== $by['D05']['company_id'], '« مؤسسة الأفق » et « مؤسسة الأفق للتجارة » : deux fiches (à fusionner par la الإدارة)' );
