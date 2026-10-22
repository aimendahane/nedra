<?php
/**
 * Version 1.5.3 — la الإدارة efface les tâches (une par une, ou « 🧹 تنظيف المهام » : terminées / toutes).
 */

global $wpdb;

ps_test_section( '1.5.3 : suppression des tâches par la الإدارة' );

ps_test_reset_data();
$adm = ps_test_user( 't153_adm', 'ps_admin' );
$mgr = ps_test_user( 't153_mgr', 'ps_manager', 'وليد جدير' );
$emp = ps_test_user( 't153_emp', 'ps_employee', 'هاشم الخراز' );
$hx  = ps_test_lid( 'employee', 'هاشم الخراز' );
$cnt = fn( $w = '1=1' ) => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'tasks' ) . " WHERE $w" ); // phpcs:ignore

wp_set_current_user( $mgr );
$ids = array();
foreach ( array( 'مهمة 1', 'مهمة 2', 'مهمة 3', 'مهمة 4' ) as $t ) {
	$ids[] = (int) ps_test_json( 'POST', '/free-tasks', array( 'employeeId' => $hx, 'title' => $t ) )->get_data()['id'];
}
ps_test_json( 'PUT', '/free-tasks/' . $ids[1], array( 'action' => 'cancel' ) );
wp_set_current_user( $emp );
ps_test_json( 'PUT', '/free-tasks/' . $ids[2], array( 'action' => 'done' ) );
ps_assert( 4 === $cnt() && 2 === $cnt( "status IN ('done','cancelled')" ), 'préparation : 4 tâches dont 1 تمت et 1 ملغاة' );

ps_assert_same( 403, ps_test_json( 'DELETE', '/free-tasks/' . $ids[0] )->get_status(), 'موظف : suppression refusée (403)' );
wp_set_current_user( $mgr );
ps_assert_same( 403, ps_test_json( 'DELETE', '/free-tasks/' . $ids[0] )->get_status(), 'مسؤول مشروع (auteur) : suppression refusée — réservée à la الإدارة' );
ps_assert_same( 403, ps_test_json( 'POST', '/free-tasks/purge', array( 'scope' => 'closed' ) )->get_status(), 'مسؤول مشروع : « تنظيف المهام » refusé' );

wp_set_current_user( $adm );
$r = ps_test_json( 'DELETE', '/free-tasks/' . $ids[0] );
ps_assert( 200 === $r->get_status() && 3 === $cnt() && ! $cnt( 'id = ' . $ids[0] ), 'الإدارة : une tâche ouverte effacée définitivement' );
ps_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'changelog' ) . " WHERE entity = 'task' AND action = 'delete' AND entity_id = %d AND old_value LIKE %s", $ids[0], '%مهمة 1%' ) ), 'journal : titre de la tâche effacée gardé' );
ps_assert_same( 404, ps_test_json( 'DELETE', '/free-tasks/' . $ids[0] )->get_status(), 'tâche déjà effacée → 404' );

$c = ps_test_json( 'GET', '/free-tasks/purge' )->get_data();
ps_assert( 2 === $c['closed'] && 3 === $c['all'], 'compteurs : 2 terminées/annulées, 3 en tout', wp_json_encode( $c ) );
$r = ps_test_json( 'POST', '/free-tasks/purge', array( 'scope' => 'closed' ) )->get_data();
ps_assert( 2 === $r['deleted'] && 1 === $cnt() && 1 === $cnt( "status = 'open'" ), '« حذف المهام المنجزة والملغاة » : 2 effacées, la tâche ouverte reste' );
ps_assert( ! empty( $r['backup'] ) && file_exists( ps_storage_dir( 'backups/' . $r['backup'] ) ), 'sauvegarde faite AVANT l\'effacement' );
$bk = json_decode( (string) file_get_contents( ps_storage_dir( 'backups/' . $r['backup'] ) ), true );
ps_assert( in_array( 'مهمة 3', array_column( $bk['tables']['tasks'] ?? array(), 'title' ), true ), 'la sauvegarde contient les tâches effacées' );
ps_assert_same( 422, ps_test_json( 'POST', '/free-tasks/purge', array( 'scope' => 'all' ) )->get_status(), '« كل المهام » sans le mot « مسح الكل » → refusé' );
ps_assert_same( 1, $cnt(), '… rien n\'est effacé' );
$r = ps_test_json( 'POST', '/free-tasks/purge', array( 'scope' => 'all', 'confirm' => 'مسح الكل' ) )->get_data();
ps_assert( 1 === $r['deleted'] && 0 === $cnt(), '« حذف كل المهام » confirmé : plus aucune tâche' );
ps_assert( 0 === ps_test_json( 'POST', '/free-tasks/purge', array( 'scope' => 'closed' ) )->get_data()['deleted'], 'rien à effacer → 0, sans erreur' );
ps_assert_same( 422, ps_test_json( 'POST', '/free-tasks/purge', array( 'scope' => 'x' ) )->get_status(), 'choix inconnu → 422' );
wp_set_current_user( 0 );
