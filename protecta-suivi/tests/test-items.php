<?php
/**
 * Étape 3 — Suivi Offres / Suivi Livraison / مهامي / compteurs (API et droits).
 * S'appuie sur le jeu de démonstration chargé par test-calculs.php.
 */

global $wpdb;

ps_test_section( 'Suivi Offres : modification d\'un صنف (droits et règles)' );

$lid  = fn( $key, $code ) => ps_list_id_by_code( $key, $code );
$emp  = fn( $name ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . " WHERE list_key='employee' AND label=%s", $name ) );
$mk   = function ( $login, $role, $employee = null ) use ( $emp ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => $login ) );
	if ( $employee ) {
		update_user_meta( $id, 'ps_employee_id', $emp( $employee ) );
	}
	return $id;
};
$u_m   = $mk( 't3_mohamed', 'ps_employee', 'محمد حسين' );
$u_w   = $mk( 't3_wafa', 'ps_employee', 'وفاء حامدي' );
$u_adm = $mk( 't3_admin', 'ps_admin' );

$pid_of  = fn( $code ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'projects' ) . ' WHERE legacy_id = %s', 'demo-' . $code ) );
$item_of = fn( $code, $k = 0 ) => (int) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d ORDER BY sort_order', $pid_of( $code ) ) )[ $k ];
$put     = fn( $item_id, $body ) => ps_test_json( 'PUT', '/items/' . $item_id, $body );

// D03 : صنف UPS de محمد حسين, قيد الإعداد, en retard.
$d03 = $item_of( 'D03' );
wp_set_current_user( $u_w );
ps_assert_same( 403, $put( $d03, array( 'offerNotes' => 'x' ) )->get_status(), 'موظف : le صنف d\'un autre employé → refusé (403)' );
wp_set_current_user( $u_m );
ps_assert_same( 200, $put( $d03, array( 'offerNotes' => 'بانتظار سعر المورد', 'offerInvoiceNo' => 'FA-77' ) )->get_status(), 'موظف responsable du صنف : modification acceptée' );

$r = $put( $d03, array( 'offerStatusId' => $lid( 'offer_status', 'not_sent' ) ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['notSentReasonId'] ), '« لم يُرسل » sans سبب → refusé' );
$r = $put( $d03, array( 'offerStatusId' => $lid( 'offer_status', 'not_sent' ), 'notSentReasonId' => $lid( 'offer_status', 'sent' ) ) );
ps_assert_same( 422, $r->get_status(), 'سبب pris dans une autre liste → refusé' );
$reason = (int) $wpdb->get_var( 'SELECT id FROM ' . ps_table( 'lists' ) . " WHERE list_key='not_sent_reason' AND label='خارج اختصاصنا'" );
$r      = $put( $d03, array( 'offerStatusId' => $lid( 'offer_status', 'not_sent' ), 'notSentReasonId' => $reason, 'notSentNote' => 'ليس من مجالنا' ) );
ps_assert( 200 === $r->get_status() && 'not_sent' === $r->get_data()['offerStatusCode'] && false === $r->get_data()['computed']['late'], '« لم يُرسل » + سبب + note → accepté, n\'est plus « لازم قرار »' );

// D20 : projet sans تاريخ إرسال العرض ; صنف de غفران → on passe par الإدارة.
$d20 = $item_of( 'D20' );
wp_set_current_user( $u_adm );
$r = $put( $d20, array( 'offerStatusId' => $lid( 'offer_status', 'sent' ) ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['projectSentDate'] ), 'مرسل sur un projet sans تاريخ الإرسال → date demandée' );
$r = $put( $d20, array( 'offerStatusId' => $lid( 'offer_status', 'sent' ), 'projectSentDate' => '2026-09-01' ) );
ps_assert_same( 422, $r->get_status(), 'تاريخ الإرسال avant تاريخ الاستلام → refusé' );
$r = $put( $d20, array( 'offerStatusId' => $lid( 'offer_status', 'sent' ), 'projectSentDate' => ps_today() ) );
ps_assert( 200 === $r->get_status() && ps_today() === ps_project_row( $pid_of( 'D20' ) )['sent_date'], 'date du jour acceptée → enregistrée comme تاريخ إرسال العرض du projet' );
ps_assert_same( ps_today(), $r->get_data()['offerDate'], 'تاريخ تقديم العرض rempli automatiquement (aujourd\'hui)' );
ps_assert( in_array( 'offer_status_id', array_column( ps_changelog_for_project( $pid_of( 'D20' ) ), 'field' ), true ) && in_array( 'sent_date', array_column( ps_changelog_for_project( $pid_of( 'D20' ) ), 'field' ), true ), 'journal : statut et تاريخ الإرسال enregistrés' );

ps_test_section( 'Suivi Livraison : règles' );

$d06 = $item_of( 'D06' );
ps_assert_same( 422, $put( $d06, array( 'deliveryStatusId' => $lid( 'delivery_status', 'late' ) ) )->get_status(), '« متأخر » ne peut pas être choisi à la main' );
ps_assert_same( 422, $put( $d06, array( 'deliveryActualDate' => '2026-02-30' ) )->get_status(), 'date impossible refusée' );
$r = $put( $d06, array( 'deliveryActualDate' => '2026-10-05', 'deliveryStatusId' => $lid( 'delivery_status', 'delivered' ), 'supplierInvoiceNo' => 'SUP-9' ) );
$wpdb->query( 'SELECT 1' );
$row = ps_items_list( array( 'view' => 'delivery', 'projectId' => $pid_of( 'D06' ) ), $u_adm, PS_DEMO_TODAY )['rows'][0];
ps_assert( false === $row['computed']['deliveryLate'] && 5 === $row['computed']['delayDays'] && 'delivered' === $row['computed']['deliveryDisplay'], 'livré le 05/10 (demandé 30/09) : plus en retard, تأخير 5 jours, « تم التسليم »' );

ps_test_section( 'Listes Suivi Offres / Livraison et compteurs' );

$offers = ps_items_list( array( 'view' => 'offers' ), $u_m, PS_DEMO_TODAY );
ps_assert_same( 5, $offers['needDecision'], 'Suivi Offres : 5 « لازم قرار » (7 − D03 لم يُرسل − D20 مرسل)' );
$late_rows = ps_items_list( array( 'view' => 'offers', 'late' => 1 ), $u_m, PS_DEMO_TODAY )['rows'];
ps_assert( 5 === count( $late_rows ) && 'late' === $late_rows[0]['computed']['rowColor'], 'filtre « لازم قرار فقط » : lignes rouges' );
$mine = ps_items_list( array( 'view' => 'offers', 'employeeId' => 'me', 'late' => 1 ), $u_m, PS_DEMO_TODAY )['rows'];
ps_assert_same( 2, count( $mine ), 'mes أصناف en retard (محمد حسين) : D17, D21' );
$green = array_filter( $offers['rows'], fn( $r ) => 'done' === $r['computed']['rowColor'] );
ps_assert( count( $green ) >= 7 && ! array_filter( $green, fn( $r ) => 'accepted' !== $r['offerStatusCode'] ), 'lignes VERTES = أصناف مقبولة' );
ps_assert( ! array_filter( $offers['rows'], fn( $r ) => $r['canEdit'] && ! $r['mine'] ), 'موظف : seuls ses أصناف sont modifiables dans la liste' );
$deliv = ps_items_list( array( 'view' => 'delivery' ), $u_m, PS_DEMO_TODAY );
ps_assert( ! array_filter( $deliv['rows'], fn( $r ) => 'accepted' !== $r['offerStatusCode'] ), 'Suivi Livraison : uniquement les أصناف مقبولة' );
ps_assert_same( 2, count( array_filter( $deliv['rows'], fn( $r ) => $r['computed']['deliveryLate'] ) ), 'livraisons en retard après correction de D06 : D16, D24' );
$byReason = ps_items_list( array( 'view' => 'offers', 'notSentReasonId' => $reason ), $u_m, PS_DEMO_TODAY )['rows'];
ps_assert( 1 === count( $byReason ) && $byReason[0]['id'] === $d03, 'filtre par سبب عدم الإرسال (clic depuis le Recap)' );

wp_set_current_user( $u_m );
$c = ps_test_json( 'GET', '/counters' )->get_data();
ps_assert( isset( $c['needDecision'], $c['offres'], $c['livraison'], $c['taches'] ), 'compteurs du menu disponibles' );
ps_assert( $c['offres'] === $c['needDecisionMine'] && $c['offres'] <= $c['needDecision'], 'موظف : compteur du menu = ses أصناف « لازم قرار »' );
wp_set_current_user( $u_adm );
$c = ps_test_json( 'GET', '/counters' )->get_data();
ps_assert_same( $c['needDecision'], $c['offres'], 'الإدارة : compteur du menu = toute l\'équipe' );

ps_test_section( 'مهامي : API par rôle' );

wp_set_current_user( $u_m );
$t = ps_test_json( 'GET', '/tasks?employeeId=' . $emp( 'وفاء حامدي' ) )->get_data();
ps_assert( true === $t['linked'] && $emp( 'محمد حسين' ) === $t['employeeId'], 'موظف : toujours SES tâches (le filtre employé est ignoré)' );
$nolink = $mk( 't3_nolink', 'ps_employee' );
wp_set_current_user( $nolink );
ps_assert( false === ps_test_json( 'GET', '/tasks' )->get_data()['linked'], 'compte non lié à un employé : message, pas de tâches' );
wp_set_current_user( $u_adm );
$t = ps_test_json( 'GET', '/tasks?employeeId=' . $emp( 'غفران دريدي' ) )->get_data();
ps_assert( $emp( 'غفران دريدي' ) === $t['employeeId'], 'الإدارة : filtre par employé' );
ps_assert( null === ps_test_json( 'GET', '/tasks' )->get_data()['employeeId'], 'الإدارة : toute l\'équipe par défaut' );

ps_test_section( 'Fiche projet : alertes calculées' );

$p = ps_test_json( 'GET', '/projects/' . $pid_of( 'D21' ) )->get_data();
ps_assert( 1 === $p['computed']['alerts']['lateOffers'] && true === $p['items'][0]['computed']['late'], 'D21 : 1 offre en retard signalée sur la fiche' );
wp_set_current_user( 0 );
