<?php
/**
 * Version 1.11.0 — factures par OFFRE (عرض entier du projet, pas par صنف) + « آخر أجل للرد » saisi à la main +
 * rappel tous les N jours (vérifié dans test-160).
 */

global $wpdb;
ps_test_reset_data();
$adm = ps_test_user( 't111_adm', 'ps_admin' );
wp_set_current_user( $adm );
$it2 = array( 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => '1', 'employeeId' => ps_test_lid( 'employee', 'محمد حسين' ), 'dueDate' => '2026-11-15' );
$body          = ps_test_payload( array( 'isProject' => 1, 'name' => 'عرض بصنفين', 'confirmDuplicates' => true ) );
$body['items'] = array( $body['items'][0], $it2 );
$p             = ps_test_json( 'POST', '/projects', $body )->get_data();
ps_assert( 2 === count( $p['items'] ), 'projet avec 2 أصناف' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.11.0 : une facture pour l\'offre entière' );

$r = ps_test_json( 'POST', '/projects/' . $p['id'] . '/invoices', array( 'invoiceNo' => 'F111-1', 'sentDate' => '2026-10-01', 'replyDeadline' => '2026-10-20' ) );
ps_assert( 200 === $r->get_status(), 'POST /projects/{id}/invoices', wp_json_encode( $r->get_data() ) );
$f = $r->get_data();
ps_assert( 0 === $f['itemId'] && 2 === count( $f['itemTypeIds'] ), 'facture de l\'offre : pas de صنف, les 2 أصناف affichés', wp_json_encode( $f['itemTypeIds'] ) );
ps_assert( '2026-10-20' === $f['replyDeadline'] && '2026-10-20' === $f['computed']['deadline'], 'آخر أجل للرد saisi (20/10) et utilisé' );
$f2 = ps_test_json( 'POST', '/items/' . $p['items'][1]['id'] . '/invoices', array( 'invoiceNo' => 'F111-2', 'sentDate' => '2026-10-05' ) )->get_data();
ps_assert( 0 === $f2['itemId'] && '2026-10-20' === $f2['replyDeadline'], 'depuis un صنف : la facture suit l\'offre entière ; آخر أجل vide → proposé (+15 j = 20/10)' );
$l1 = ps_test_json( 'GET', '/invoices?status=all&itemId=' . $p['items'][0]['id'] )->get_data();
$l2 = ps_test_json( 'GET', '/invoices?status=all&itemId=' . $p['items'][1]['id'] )->get_data();
ps_assert( 2 === $l1['total'] && 2 === $l2['total'], 'les 2 factures visibles depuis chaque صنف de l\'offre' );
$offers = array_values( array_filter( ps_items_list( array( 'view' => 'offers' ), $adm )['rows'], fn( $x ) => $x['projectId'] === $p['id'] ) );
ps_assert( 2 === $offers[0]['invoices']['total'] && 2 === $offers[1]['invoices']['total'], 'Suivi Offres : 🧾 2 sur chaque ligne de l\'offre' );
$r = ps_test_json( 'POST', '/projects/' . $p['id'] . '/invoices', array( 'invoiceNo' => 'F111-3', 'sentDate' => '2026-10-10', 'replyDeadline' => '2026-10-01' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['replyDeadline'] ), 'آخر أجل avant la date d\'envoi → refusé' );
ps_assert_same( 200, ps_test_json( 'PUT', '/invoices/' . $f['id'], array( 'replyDeadline' => '2026-11-01' ) )->get_status(), 'modifier آخر أجل' );
$log = array_column( ps_changelog_for_project( $p['id'] ), 'field' );
ps_assert( in_array( 'reply_deadline', $log, true ), 'journal : changement de آخر أجل enregistré' );
ps_assert( false === ps_invoices_list( array( 'id' => $f['id'] ), $adm, '2026-10-25' )['rows'][0]['computed']['late'], 'nouvelle échéance (01/11) : pas en retard au 25/10' );
