<?php
/**
 * Version 1.13.0 — demandes du client (03/11) :
 *   - listes triées par NUMÉRO de projet croissant (Base Projets, Suivi Offres / Livraison, الفواتير) ;
 *   - facture pour l'offre entière OU pour un seul صنف (« الاثنان ممكنان ») ;
 *   - numéros de facture renvoyés sur chaque ligne de صنف (invoices.nos).
 */

global $wpdb;
ps_test_reset_data();
$adm = ps_test_user( 't113_adm', 'ps_admin' );
wp_set_current_user( $adm );

/* ------------------------------------------------------------------ */
ps_test_section( '1.13.0 : ordre naturel des numéros' );

ps_assert( ps_project_no_cmp( 'P009', 'P010' ) < 0 && ps_project_no_cmp( 'P099', 'P100' ) < 0 && ps_project_no_cmp( 'P199', 'PRJ-2026-201' ) < 0, 'P009 < P010 < P100 < PRJ-…' );
ps_assert( ps_project_no_cmp( 'PRJ-2026-009', 'PRJ-2026-010' ) < 0 && ps_project_no_cmp( 'PRJ-2025-300', 'PRJ-2026-001' ) < 0, 'PRJ : année puis numéro' );

$mk = fn( $name, $date, $items = 1 ) => ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => $name, 'receivedDate' => $date, 'confirmDuplicates' => true, 'items' => array_fill( 0, $items, array( 'itemTypeId' => ps_test_lid( 'item_type', 'إنارة' ), 'employeeId' => ps_test_lid( 'employee', 'محمد حسين' ), 'dueDate' => '2026-12-01' ) ) ) ) )->get_data();
$c = $mk( 'مشروع جيم', '2026-03-01' );
$a = $mk( 'مشروع ألف', '2026-09-01', 2 );
$b = $mk( 'مشروع باء', '2025-06-01' );
$nos = array_column( ps_projects_list( array() )['rows'], 'projectNo' );
ps_assert_same( array( 'PRJ-2025-001', 'PRJ-2026-001', 'PRJ-2026-002' ), $nos, 'Base Projets : par numéro croissant par défaut' );
$nos = array_column( ps_projects_list( array( 'dir' => 'desc' ) )['rows'], 'projectNo' );
ps_assert_same( 'PRJ-2026-002', $nos[0], '… et décroissant au 2e clic (dir=desc)' );
$rows = ps_items_list( array( 'view' => 'offers' ), $adm, '2026-10-20' )['rows'];
ps_assert_same( array( 'PRJ-2025-001', 'PRJ-2026-001', 'PRJ-2026-002', 'PRJ-2026-002' ), array_column( $rows, 'projectNo' ), 'Suivi Offres : par numéro de projet, أصناف d\'un projet ensemble' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.13.0 : facture pour l\'offre entière ou pour un صنف' );

$i1 = $a['items'][0]['id'];
$i2 = $a['items'][1]['id'];
$r  = ps_test_json( 'POST', '/items/' . $i1 . '/invoices', array( 'invoiceNo' => 'F-OFFRE', 'sentDate' => '2026-10-01' ) );
ps_assert( 200 === $r->get_status() && 0 === $r->get_data()['itemId'], 'sans choix : facture de l\'offre entière (comme 1.11.0)' );
$r = ps_test_json( 'POST', '/items/' . $i2 . '/invoices', array( 'invoiceNo' => 'F-SENF-2', 'sentDate' => '2026-10-02', 'scope' => 'item' ) );
ps_assert( 200 === $r->get_status() && $i2 === $r->get_data()['itemId'] && 1 === count( $r->get_data()['itemTypeIds'] ), '« هذا الصنف فقط » : facture du صنف 2' );
$r = ps_test_json( 'POST', '/projects/' . $a['id'] . '/invoices', array( 'invoiceNo' => 'F-X', 'sentDate' => '2026-10-02', 'scope' => 'item', 'itemId' => $c['items'][0]['id'] ) );
ps_assert_same( 422, $r->get_status(), 'صنف d\'un autre projet → refusé' );
$by = array();
foreach ( ps_items_list( array( 'view' => 'offers' ), $adm, '2026-10-20' )['rows'] as $row ) {
	$by[ $row['id'] ] = $row['invoices'];
}
ps_assert( array( 'F-OFFRE' ) === $by[ $i1 ]['nos'] && 1 === $by[ $i1 ]['total'], 'ligne صنف 1 : la facture de l\'offre seulement', wp_json_encode( $by[ $i1 ] ) );
ps_assert( array( 'F-OFFRE', 'F-SENF-2' ) === $by[ $i2 ]['nos'] && 2 === $by[ $i2 ]['total'], 'ligne صنف 2 : facture de l\'offre + la sienne', wp_json_encode( $by[ $i2 ] ) );
ps_assert( 0 === $by[ $b['items'][0]['id'] ]['total'], 'autre projet : aucune facture' );
$list = ps_invoices_list( array( 'itemId' => $i1, 'status' => 'all' ), $adm, '2026-10-20' );
ps_assert_same( 2, $list['total'], 'fenêtre 🧾 d\'un صنف : toutes les factures du projet (avec الأصناف de chacune)' );
ps_assert( array( 'F-SENF-2' ) === $by[ $i1 ]['otherNos'] && array() === $by[ $i2 ]['otherNos'], 'ligne صنف 1 : numéro de la facture du صنف 2 du même projet (otherNos, affiché en petit)' );
