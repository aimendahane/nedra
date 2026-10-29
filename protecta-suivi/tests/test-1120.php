<?php
/**
 * Version 1.12.0 — expérience utilisateur :
 *   - « ⚡ إدخال سريع » : POST /projects avec quick = 4 خانات (اسم المشروع، الشركة، تاريخ الاستلام، المسؤول) ;
 *     toujours « ⏳ لم يُحدد بعد », sans صنف ; les autres règles « obligatoire » ne s'appliquent pas à ce mode ;
 *   - « مشروع؟ = نعم » depuis la liste : la réponse dit ce qu'il reste à compléter (missing), sans bloquer ;
 *   - la liste renvoie la note de « مشروع مُلغى » (pour « ↩ تراجع »).
 */

global $wpdb;
ps_test_reset_data();
$adm = ps_test_user( 't112_adm', 'ps_admin' );
$mgr = ps_test_user( 't112_mgr', 'ps_manager', 'وليد جدير' );
$emp = ps_test_user( 't112_emp', 'ps_employee', 'محمد حسين' );
$wl  = ps_test_lid( 'employee', 'وليد جدير' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.12.0 : إدخال سريع (4 خانات)' );

wp_set_current_user( $mgr );
$r = ps_test_json( 'POST', '/projects', array( 'quick' => true ) );
$e = $r->get_data()['data']['errors'] ?? array();
ps_assert( 422 === $r->get_status() && isset( $e['name'], $e['received_date'], $e['responsible_emp_id'], $e['parties.owner.company'] ), 'vide → les 4 خانات demandées', wp_json_encode( array_keys( $e ) ) );
ps_assert( ! isset( $e['offer_no'] ) && ! isset( $e['place_id'] ) && ! isset( $e['items'] ), 'les autres règles (رقم العرض، المكان، صنف…) ne s\'appliquent pas' );

$quick = array( 'quick' => true, 'name' => 'طلب سريع ألفا', 'receivedDate' => '2026-10-18', 'responsibleEmpId' => $wl, 'parties' => array( 'owner' => array( 'companyName' => 'شركة سريعة' ) ), 'note' => 'نص الطلب', 'isProject' => 1, 'offerNo' => 'IGNORED-1', 'items' => array( array( 'itemTypeId' => ps_test_lid( 'item_type', 'إنارة' ) ) ) );
$r = ps_test_json( 'POST', '/projects', $quick );
$p = $r->get_data();
ps_assert( 201 === $r->get_status() && 'pending' === $p['projectState'], 'enregistré « ⏳ لم يُحدد بعد »', $r->get_status() . ' ' . wp_json_encode( $p['data'] ?? '' ) );
ps_assert( empty( $p['items'] ) && null === $p['offerNo'], 'seuls les champs du formulaire court sont pris (pas de صنف, pas de رقم العرض)' );
ps_assert( $wl === $p['followerEmpId'] && 'شركة سريعة' === $p['parties']['owner']['companyName'] && 'نص الطلب' === $p['note'], 'المتابع = المسؤول ; الشركة et NOTE gardés' );
ps_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'companies' ) . ' WHERE name = %s', 'شركة سريعة' ) ), 'la société est ajoutée à l\'annuaire' );

$dup = ps_test_json( 'POST', '/projects', array_merge( $quick, array( 'name' => 'طلب سريع ألفا' ) ) );
ps_assert_same( 409, $dup->get_status(), 'même demande (même société, nom proche) → avertissement de doublon' );
ps_assert_same( 201, ps_test_json( 'POST', '/projects', array_merge( $quick, array( 'confirmDuplicates' => true ) ) )->get_status(), '… enregistrée après confirmation' );

wp_set_current_user( $emp );
ps_assert_same( 201, ps_test_json( 'POST', '/projects', array_merge( $quick, array( 'name' => 'طلب موظف سريع', 'parties' => array( 'owner' => array( 'companyName' => 'شركة أخرى' ) ) ) ) )->get_status(), 'موظف : إدخال سريع autorisé (droit de création)' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.12.0 : « نعم » depuis la liste → ce qu\'il reste à compléter' );

wp_set_current_user( $mgr );
$r    = ps_test_json( 'POST', '/projects/' . $p['id'] . '/is-project', array( 'isProject' => 1 ) );
$miss = $r->get_data()['missing'] ?? null;
ps_assert( 200 === $r->get_status() && 'yes' === $r->get_data()['projectState'], 'décision « نعم » enregistrée (non bloquante)' );
ps_assert( is_array( $miss ) && in_array( 'رقم العرض', $miss, true ) && in_array( 'صنف واحد على الأقل', $miss, true ) && ! in_array( 'اسم المشروع', $miss, true ), 'missing : رقم العرض, صنف… (pas le nom, déjà saisi)', wp_json_encode( $miss, JSON_UNESCAPED_UNICODE ) );
$full = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 2, 'name' => 'مشروع كامل', 'confirmDuplicates' => true ) ) )->get_data();
$r    = ps_test_json( 'POST', '/projects/' . $full['id'] . '/is-project', array( 'isProject' => 1 ) );
ps_assert_same( array(), $r->get_data()['missing'], 'projet complet : rien à compléter' );
$r = ps_test_json( 'POST', '/projects/' . $full['id'] . '/is-project', array( 'isProject' => 2 ) );
ps_assert( ! isset( $r->get_data()['missing'] ), '« لم يُحدد بعد » : pas de liste' );

// La fiche complète d'une demande rapide : les règles normales s'appliquent à l'enregistrement.
$put = ps_test_json( 'PUT', '/projects/' . $p['id'], array( 'name' => 'طلب سريع ألفا', 'receivedDate' => '2026-10-18', 'responsibleEmpId' => $wl, 'isProject' => 1 ) );
ps_assert( 422 === $put->get_status() && isset( $put->get_data()['data']['errors']['offer_no'] ), 'fiche complète : رقم العرض & co redeviennent obligatoires' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.12.0 : note du « مشروع مُلغى » dans la liste (« ↩ تراجع »)' );

$reason = ps_test_lid( 'non_project_reason', 'خارج الاختصاص' );
ps_test_json( 'POST', '/projects/' . $full['id'] . '/is-project', array( 'isProject' => 0, 'nonProjectReasonId' => $reason, 'nonProjectNote' => 'سعر غير مناسب' ) );
$row = current( array_filter( ps_projects_list( array() )['rows'], fn( $x ) => $x['id'] === $full['id'] ) );
ps_assert( $reason === $row['nonProjectReasonId'] && 'سعر غير مناسب' === $row['nonProjectNote'], 'liste : سبب + ملاحظة renvoyés' );
ps_test_json( 'POST', '/projects/' . $full['id'] . '/is-project', array( 'isProject' => 1 ) );
$back = ps_test_json( 'POST', '/projects/' . $full['id'] . '/is-project', array( 'isProject' => 0, 'nonProjectReasonId' => $row['nonProjectReasonId'], 'nonProjectNote' => $row['nonProjectNote'] ) )->get_data();
ps_assert( 'no' === $back['projectState'] && $reason === $back['nonProjectReasonId'], '« تراجع » : l\'ancien choix et son سبب reviennent' );
$log = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'changelog' ) . " WHERE project_id = %d AND field = 'is_project'", $full['id'] ) );
ps_assert( $log >= 4, 'chaque changement (et le retour en arrière) est journalisé', (string) $log );
