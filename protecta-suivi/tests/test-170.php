<?php
/**
 * Version 1.7.0 — listes courtes : compteurs des filtres rapides (facets) renvoyés par l'API.
 *   - Base Projets : facets.isProject {yes, no} calculés APRÈS la recherche et AVANT le filtre « مشروع؟ » ;
 *   - Suivi Offres : facets {all, late, <code de حالة العرض>} avant le filtre de statut ;
 *   - Factures : facets {all, pending, yes, no, answered, late} avant le filtre de statut.
 * Aucune donnée n'est modifiée par ces compteurs (lecture seule).
 */

ps_test_reset_data();
$adm = ps_test_user( 't17_adm', 'ps_admin' );
wp_set_current_user( $adm );

/* ------------------------------------------------------------------ */
ps_test_section( '1.7.0 : compteurs « مشروع؟ » de Base Projets' );

$mk = function ( $over ) {
	return ps_test_json( 'POST', '/projects', ps_test_payload( array_replace( array( 'isProject' => 1, 'confirmDuplicates' => true ), $over ) ) )->get_data();
};
$a = $mk( array( 'name' => 'مشروع ألفا', 'items' => array( array( 'dueDate' => '2026-12-01' ) ) ) );
$b = $mk( array( 'name' => 'مشروع بيتا', 'items' => array( array( 'dueDate' => '2026-09-01' ) ) ) );
$c = $mk( array( 'name' => 'طلب جاما', 'isProject' => 0, 'nonProjectReasonId' => ps_test_lid( 'non_project_reason', 'خارج الاختصاص' ) ) );
ps_assert( ! empty( $a['id'] ) && ! empty( $b['id'] ) && ! empty( $c['id'] ), '3 projets créés (2 « نعم », 1 « لا »)' );

$l = ps_projects_list( array( 'isProject' => '0' ) );
ps_assert( 1 === $l['total'] && array( 'yes' => 2, 'no' => 1 ) === $l['facets']['isProject'], 'filtre « لا » : 1 ligne, compteurs نعم 2 / لا 1 (avant le filtre)', wp_json_encode( $l['facets'] ) );
$l = ps_projects_list( array( 'q' => 'جاما' ) );
ps_assert( 1 === $l['total'] && array( 'yes' => 0, 'no' => 1 ) === $l['facets']['isProject'], 'les compteurs suivent la recherche', wp_json_encode( $l['facets'] ) );
$r = ps_test_json( 'GET', '/projects?isProject=1' );
ps_assert( 200 === $r->get_status() && 2 === $r->get_data()['total'] && 3 === array_sum( $r->get_data()['facets']['isProject'] ), 'API GET /projects renvoie facets' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.7.0 : compteurs par حالة العرض (Suivi Offres)' );

$all = ps_items_list( array( 'view' => 'offers' ), $adm, '2026-10-20' );
$f   = $all['facets'];
$sum = array_sum( array_diff_key( $f, array( 'all' => 1, 'late' => 1 ) ) );
ps_assert( 2 === $f['all'] && $sum === $f['all'], 'facets.all = 2 offres (le « غير مشروع » exclu) = somme des statuts', wp_json_encode( $f ) );
ps_assert( 1 === $f['late'] && 2 === ( $f['preparing'] ?? 0 ), 'facets.late = 1 (أجل dépassé), 2 « قيد الإعداد »', wp_json_encode( $f ) );
$late = ps_items_list( array( 'view' => 'offers', 'late' => 1 ), $adm, '2026-10-20' );
ps_assert( 1 === $late['total'] && 2 === $late['facets']['all'], 'filtre لازم قرار : 1 ligne, compteurs inchangés' );
$sent = ps_items_list( array( 'view' => 'offers', 'offerStatus' => 'sent' ), $adm, '2026-10-20' );
ps_assert( 0 === $sent['total'] && 2 === $sent['facets']['all'], 'filtre « مرسل » : 0 ligne, compteurs inchangés' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.7.0 : compteurs des factures' );

$item = $a['items'][0]['id'];
ps_test_json( 'POST', '/items/' . $item . '/invoices', array( 'invoiceNo' => 'F17-1', 'sentDate' => '2026-09-01' ) );
$f2 = ps_test_json( 'POST', '/items/' . $item . '/invoices', array( 'invoiceNo' => 'F17-2', 'sentDate' => '2026-10-15' ) )->get_data();
ps_test_json( 'PUT', '/invoices/' . $f2['id'], array( 'answer' => 'yes' ) );
$inv = ps_invoices_list( array( 'status' => 'no' ), $adm, '2026-10-20' );
ps_assert( 0 === $inv['total'], 'filtre « لا » : aucune facture' );
ps_assert( array( 'all' => 2, 'pending' => 1, 'yes' => 1, 'no' => 0, 'answered' => 1, 'late' => 1 ) === $inv['facets'], 'facets : 2 factures, 1 en attente (en retard), 1 « نعم »', wp_json_encode( $inv['facets'] ) );
$inv = ps_invoices_list( array( 'status' => 'all', 'q' => 'F17-2' ), $adm, '2026-10-20' );
ps_assert( 1 === $inv['facets']['all'] && 1 === $inv['total'], 'les compteurs des factures suivent la recherche' );
