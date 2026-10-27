<?php
/**
 * Version 1.8.0 — « مشروع؟ » نعم / لا changé directement depuis la liste des projets
 * (POST /projects/{id}/is-project) : droits, motif obligatoire, journal, effet sur Suivi Offres.
 */

ps_test_reset_data();
$adm  = ps_test_user( 't18_adm', 'ps_admin' );
$emp  = ps_test_user( 't18_emp', 'ps_employee', 'هاشم الخراز' ); // ni المسؤول ni créateur
wp_set_current_user( $adm );
$p = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => 'طلب يصبح عرضًا', 'confirmDuplicates' => true ) ) )->get_data();
$in_offers = fn() => in_array( $p['id'], array_column( ps_items_list( array( 'view' => 'offers' ), $adm )['rows'], 'projectId' ), true );

/* ------------------------------------------------------------------ */
ps_test_section( '1.8.0 : « مشروع؟ » depuis la liste' );

$row = array_values( array_filter( ps_projects_list( array() )['rows'], fn( $r ) => $r['id'] === $p['id'] ) )[0];
ps_assert( true === $row['canEdit'], 'liste : canEdit = vrai pour الإدارة (interrupteur affiché)' );
wp_set_current_user( $emp );
$row = array_values( array_filter( ps_projects_list( array() )['rows'], fn( $r ) => $r['id'] === $p['id'] ) )[0];
ps_assert( false === $row['canEdit'], 'liste : canEdit = faux pour un موظف sans lien (simple badge)' );
ps_assert_same( 403, ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => 1 ) )->get_status(), 'موظف sans lien → 403' );

wp_set_current_user( $adm );
ps_assert_same( 422, ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => '' ) )->get_status(), 'valeur vide → 422' );
$r = ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => 0 ) );
ps_assert( 422 === $r->get_status(), '« لا » sans سبب → 422' );
$r = ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => 0, 'nonProjectReasonId' => ps_test_lid( 'source', 'direct' ) ) );
ps_assert( 422 === $r->get_status(), 'سبب d\'une autre liste → 422' );
ps_assert( $in_offers(), 'avant : le projet est dans Suivi Offres' );

$reason = ps_test_lid( 'non_project_reason', 'غير مؤكد' );
$r      = ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => 0, 'nonProjectReasonId' => $reason, 'nonProjectNote' => 'طلب أسعار فقط' ) );
ps_assert( 200 === $r->get_status() && false === $r->get_data()['isProject'], '« لا » + سبب → enregistré', wp_json_encode( $r->get_data() ) );
$full = ps_test_json( 'GET', '/projects/' . $p['id'] )->get_data();
ps_assert( false === $full['isProject'] && $reason === $full['nonProjectReasonId'] && 'طلب أسعار فقط' === $full['nonProjectNote'], 'fiche : لا + سبب + ملاحظة' );
ps_assert( ! $in_offers(), '« لا » → retiré de Suivi Offres (rien n\'est effacé)' );
$log = array_column( ps_changelog_for_project( $p['id'] ), 'field' );
ps_assert( in_array( 'is_project', $log, true ) && in_array( 'non_project_reason_id', $log, true ), 'journal : مشروع؟ et السبب enregistrés' );

$r = ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => 1 ) );
ps_assert( 200 === $r->get_status() && 1 === $r->get_data()['itemsCount'], '« نعم » → enregistré (1 صنف)' );
$full = ps_test_json( 'GET', '/projects/' . $p['id'] )->get_data();
ps_assert( true === $full['isProject'] && null === $full['nonProjectReasonId'] && null === $full['nonProjectNote'], '« نعم » efface le سبب et la note' );
ps_assert( $in_offers(), '« نعم » → le projet devient une offre (Suivi Offres)' );
ps_assert( 1 === count( $full['items'] ), 'les أصناف sont intactes' );
