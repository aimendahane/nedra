<?php
/**
 * Version 1.9.0 — « مشروع؟ » à trois états : نعم (1) / لا (0) / لم يُحدد بعد (2).
 * Une demande « لم يُحدد بعد » est enregistrée sans صنف obligatoire, reste dans « المشاريع », n'est ni une offre ni
 * comptée dans les KPI (compteur à part « بانتظار القرار »), apparaît dans مهامي du المسؤول / المتابع, et se décide
 * plus tard (fiche ou liste). Aucune migration (colonne tinyint existante).
 */

ps_test_reset_data();
$adm  = ps_test_user( 't19_adm', 'ps_admin' );
$resp = ps_test_user( 't19_resp', 'ps_manager', 'وليد جدير' );
wp_set_current_user( $adm );

/* ------------------------------------------------------------------ */
ps_test_section( '1.9.0 : « لم يُحدد بعد » à la création' );

$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 'pending', 'name' => 'طلب بانتظار القرار', 'items' => array(), 'confirmDuplicates' => true ) ) );
ps_assert_same( 201, $r->get_status(), '« لم يُحدد بعد » sans صنف → enregistré (صنف non exigé)' );
$p = $r->get_data();
ps_assert( 'pending' === $p['projectState'] && null === $p['isProject'], 'API : projectState = pending, isProject = null', wp_json_encode( array( $p['projectState'], $p['isProject'] ) ) );
$p2 = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 2, 'name' => 'طلب ثاني بانتظار القرار', 'confirmDuplicates' => true ) ) )->get_data();
ps_assert( 'pending' === $p2['projectState'] && 1 === count( $p2['items'] ), 'valeur 2 acceptée aussi ; ses أصناف gardées' );
$yes = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => 'مشروع مؤكد', 'confirmDuplicates' => true ) ) )->get_data();

/* ------------------------------------------------------------------ */
ps_test_section( '1.9.0 : exclu des offres et des indicateurs, compté à part' );

$offers = array_column( ps_items_list( array( 'view' => 'offers' ), $adm )['rows'], 'projectId' );
ps_assert( ! in_array( $p2['id'], $offers, true ) && in_array( $yes['id'], $offers, true ), 'Suivi Offres : « لم يُحدد بعد » absent, « نعم » présent' );
$k = ps_stats_kpis( ps_stats_facts( array( 'year' => 2026 ), '2026-10-20' ) );
ps_assert( 1 === $k['projects'] && 2 === $k['pendingProjects'] && 0 === $k['nonProjects'], 'KPI : 1 projet, 2 « بانتظار القرار », 0 « غير مشروع »', wp_json_encode( array( $k['projects'], $k['pendingProjects'], $k['nonProjects'] ) ) );
$rec = ps_recap( array( 'year' => 2026 ), '2026-10-20' );
ps_assert( 2 === $rec['kpis']['pendingProjects'], 'Recap : compteur « بانتظار القرار »' );
$l = ps_projects_list( array( 'isProject' => '2' ) );
ps_assert( 2 === $l['total'] && array( 'yes' => 1, 'no' => 0, 'pending' => 2 ) === $l['facets']['isProject'], 'Base Projets : filtre « بانتظار القرار » + compteurs', wp_json_encode( $l['facets'] ) );
ps_assert( 'pending' === $l['rows'][0]['projectState'] && null === $l['rows'][0]['isProject'], 'liste : projectState = pending' );
ps_assert_same( 422, ps_test_json( 'POST', '/items/' . $p2['items'][0]['id'] . '/invoices', array( 'invoiceNo' => 'F19-1', 'sentDate' => '2026-10-01' ) )->get_status(), 'pas de facture sur une demande non décidée' );
$exp = ps_export_projects_table( array( 'isProject' => '2' ) );
ps_assert( in_array( 'لم يُحدد بعد', $exp[1][0], true ), 'export : « لم يُحدد بعد » dans la colonne مشروع؟' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.9.0 : مهامي — demandes à décider' );

$emp   = ps_test_lid( 'employee', 'وليد جدير' );
$tasks = ps_tasks( ps_stats_facts( array(), '2026-10-20' ), $emp, '2026-10-20' );
ps_assert( 2 === count( $tasks['pendingDecision'] ) && 19 === $tasks['pendingDecision'][0]['daysWaiting'], 'مهامي du المسؤول : 2 demandes « بانتظار القرار » (depuis 19 jours)', wp_json_encode( $tasks['pendingDecision'] ) );
$other = ps_tasks( ps_stats_facts( array(), '2026-10-20' ), ps_test_lid( 'employee', 'هاشم الخراز' ), '2026-10-20' );
ps_assert( 0 === count( $other['pendingDecision'] ), 'un autre employé ne les voit pas' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.9.0 : décider plus tard (liste et fiche)' );

$r = ps_test_json( 'POST', '/projects/' . $p2['id'] . '/is-project', array( 'isProject' => 1 ) );
ps_assert( 200 === $r->get_status() && 'yes' === $r->get_data()['projectState'], 'liste : « نعم » → devient une offre' );
ps_assert( in_array( $p2['id'], array_column( ps_items_list( array( 'view' => 'offers' ), $adm )['rows'], 'projectId' ), true ), 'Suivi Offres : maintenant présent' );
ps_test_json( 'POST', '/projects/' . $p2['id'] . '/is-project', array( 'isProject' => 0, 'nonProjectReasonId' => ps_test_lid( 'non_project_reason', 'غير مؤكد' ) ) );
$r = ps_test_json( 'POST', '/projects/' . $p2['id'] . '/is-project', array( 'isProject' => 2 ) );
$full = ps_test_json( 'GET', '/projects/' . $p2['id'] )->get_data();
ps_assert( 200 === $r->get_status() && 'pending' === $full['projectState'] && null === $full['nonProjectReasonId'], 'retour à « لم يُحدد بعد » : سبب effacé' );
$log = array_values( array_filter( ps_changelog_for_project( $p2['id'] ), fn( $l ) => 'is_project' === $l['field'] ) );
ps_assert( in_array( 'لم يُحدد بعد', array_merge( array_column( $log, 'new' ), array_column( $log, 'old' ) ), true ), 'journal : « لم يُحدد بعد » lisible', wp_json_encode( $log[0] ?? null ) );
// Fiche : PUT avec isProject = true (le صنف est obligatoire pour « نعم »).
$body          = ps_test_payload( array( 'offerNo' => $p['offerNo'], 'name' => $p['name'], 'isProject' => true, 'confirmDuplicates' => true ) );
$body['items'] = array();
$r             = ps_test_json( 'PUT', '/projects/' . $p['id'], $body );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['items'] ), 'fiche : passer à « نعم » sans صنف → صنف exigé', wp_json_encode( $r->get_data()['data']['errors'] ?? null ) );
