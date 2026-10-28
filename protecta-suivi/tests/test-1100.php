<?php
/**
 * Version 1.10.0 — suppression de projets par الإدارة :
 *   - POST /projects/bulk-delete : plusieurs projets → corbeille (restaurables) ;
 *   - POST /projects/purge : suppression définitive, depuis la corbeille seulement, mot « حذف نهائي », الإدارة
 *     seulement, sauvegarde automatique AVANT ; données liées effacées, tâches libres gardées (lien retiré).
 * Et : à la création depuis l'interface, « مشروع؟ » n'est plus demandé (l'interface envoie « لم يُحدد بعد »).
 */

global $wpdb;
ps_test_reset_data();
$adm = ps_test_user( 't110_adm', 'ps_admin' );
$mgr = ps_test_user( 't110_mgr', 'ps_manager', 'وليد جدير' );
wp_set_current_user( $adm );
$mk = fn( $name ) => ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => $name, 'confirmDuplicates' => true ) ) )->get_data();
$a  = $mk( 'مشروع للحذف أ' );
$b  = $mk( 'مشروع للحذف ب' );
$c  = $mk( 'مشروع يبقى' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.10.0 : suppression en lot (corbeille)' );

wp_set_current_user( $mgr );
ps_assert_same( 403, ps_test_json( 'POST', '/projects/bulk-delete', array( 'ids' => array( $a['id'] ) ) )->get_status(), 'مسؤول مشروع : pas de suppression (403)' );
wp_set_current_user( $adm );
$r = ps_test_json( 'POST', '/projects/bulk-delete', array( 'ids' => array( $a['id'], $b['id'], $a['id'] ) ) );
ps_assert( 200 === $r->get_status() && 2 === $r->get_data()['deleted'], 'الإدارة : 2 projets mis à la corbeille (doublon ignoré)' );
ps_assert( 1 === ps_projects_list( array() )['total'] && 2 === ps_projects_list( array( 'trash' => 1 ) )['total'], 'liste : 1 projet ; corbeille : 2' );
ps_assert_same( 200, ps_test_json( 'POST', '/projects/' . $b['id'] . '/restore' )->get_status(), 'restauration toujours possible' );
ps_test_json( 'DELETE', '/projects/' . $b['id'] );

/* ------------------------------------------------------------------ */
ps_test_section( '1.10.0 : suppression définitive (depuis la corbeille)' );

// Données liées au projet A : suivi, facture, tâche libre.
ps_test_json( 'POST', '/projects/' . $a['id'] . '/restore' );
ps_test_json( 'POST', '/projects/' . $a['id'] . '/followups', array( 'entryDate' => '2026-10-10', 'note' => 'متابعة' ) );
ps_test_json( 'POST', '/items/' . $a['items'][0]['id'] . '/invoices', array( 'invoiceNo' => 'F110-1', 'sentDate' => '2026-10-01' ) );
$task = ps_task_create( array( 'title' => 'مهمة مرتبطة', 'employeeId' => ps_test_lid( 'employee', 'وليد جدير' ), 'projectId' => $a['id'] ), $adm );
ps_test_json( 'DELETE', '/projects/' . $a['id'] );

wp_set_current_user( $mgr );
ps_assert_same( 403, ps_test_json( 'POST', '/projects/purge', array( 'ids' => array( $a['id'] ), 'confirm' => 'حذف نهائي' ) )->get_status(), 'مسؤول مشروع : pas de suppression définitive (403)' );
wp_set_current_user( $adm );
ps_assert_same( 422, ps_test_json( 'POST', '/projects/purge', array( 'ids' => array( $a['id'] ) ) )->get_status(), 'sans le mot « حذف نهائي » → refusé' );
ps_assert_same( 422, ps_test_json( 'POST', '/projects/purge', array( 'ids' => array( $c['id'] ), 'confirm' => 'حذف نهائي' ) )->get_status(), 'projet hors corbeille → refusé (rien effacé)' );
ps_assert( null !== ps_project_row( $c['id'] ), 'le projet hors corbeille est intact' );

$backups = count( ps_backup_list() );
$r       = ps_test_json( 'POST', '/projects/purge', array( 'ids' => array( $a['id'], $b['id'], $c['id'] ), 'confirm' => 'حذف نهائي' ) );
ps_assert( 200 === $r->get_status() && 2 === $r->get_data()['deleted'], '2 projets de la corbeille supprimés définitivement (le 3e, hors corbeille, ignoré)', wp_json_encode( $r->get_data() ) );
ps_assert( count( ps_backup_list() ) === $backups + 1 && str_contains( $r->get_data()['backup'], 'before-project-delete' ), 'sauvegarde automatique écrite AVANT' );
ps_assert( null === ps_project_row( $a['id'] ) && null === ps_project_row( $b['id'] ) && null !== ps_project_row( $c['id'] ), 'projets A et B effacés, C intact' );
$left = 0;
foreach ( array( 'items', 'project_parties', 'project_competitors', 'followups', 'invoices' ) as $t ) {
	$left += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( $t ) . ' WHERE project_id IN (%d,%d)', $a['id'], $b['id'] ) );
}
ps_assert_same( 0, $left, 'données liées (أصناف، أطراف، منافسون، متابعة، فواتير) effacées' );
ps_assert( 1 === count( ps_test_json( 'GET', '/projects/' . $c['id'] )->get_data()['items'] ), 'les أصناف du projet C sont intactes' );
$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'tasks' ) . ' WHERE id = %d', (int) $task ), ARRAY_A );
ps_assert( $t && null === $t['project_id'], 'tâche libre liée : gardée, lien retiré' );
ps_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'changelog' ) . " WHERE project_id = %d AND action = 'purge'", $a['id'] ) ), 'journal : ligne « حذف نهائي »' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.10.0 : création sans « مشروع؟ » (l\'interface envoie « لم يُحدد بعد »)' );

$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 'pending', 'name' => 'طلب جديد بدون قرار', 'confirmDuplicates' => true ) ) );
ps_assert( 201 === $r->get_status() && 'pending' === $r->get_data()['projectState'], 'nouveau projet : « ⏳ لم يُحدد بعد »' );
$r = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'name' => 'طلب عبر API قديمة', 'confirmDuplicates' => true ) ) );
ps_assert( 201 === $r->get_status() && 'yes' === $r->get_data()['projectState'], 'API sans le champ (ancienne interface, import) : نعم comme avant' );
