<?php
/**
 * Version 1.0.1 — tâches libres « مهمة جديدة » (droits, statuts, مهامي, compteurs, e-mails)
 * et envoi des e-mails (expéditeur, SMTP, vraie raison d'un échec).
 */

global $wpdb;

$lid = fn( $key, $label ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'lists' ) . ' WHERE list_key = %s AND label = %s', $key, $label ) );
$mk  = function ( $login, $role, $employee = null ) use ( $lid ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => $login, 'user_email' => $login . '@example.com' ) );
	if ( $employee ) {
		update_user_meta( $id, 'ps_employee_id', $lid( 'employee', $employee ) );
	}
	return $id;
};
$e_majdi = $lid( 'employee', 'مجدي ميلادي' );
$e_hachem = $lid( 'employee', 'هاشم الخراز' );
$u_resp  = $mk( 't6_ghofrane', 'ps_manager', 'غفران دريدي' );
$u_majdi = $mk( 't6_majdi', 'ps_employee', 'مجدي ميلادي' );
$u_other = $mk( 't6_hachem', 'ps_employee', 'هاشم الخراز' );
$u_adm   = $mk( 't6_admin', 'ps_admin' );

remove_all_filters( 'pre_wp_mail' );
$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
}, 10, 2 );

ps_test_section( 'Tâches libres : création (droits et règles)' );

$post = fn( $body ) => ps_test_json( 'POST', '/free-tasks', $body );
$put  = fn( $id, $body ) => ps_test_json( 'PUT', '/free-tasks/' . $id, $body );
$get  = fn( $q = '' ) => ps_test_json( 'GET', '/free-tasks' . $q );

wp_set_current_user( $u_majdi );
ps_assert_same( 403, $post( array( 'employeeId' => $e_hachem, 'title' => 'x' ) )->get_status(), 'موظف : ne peut pas envoyer de tâche (403)' );

wp_set_current_user( $u_resp );
$r = $post( array( 'title' => '' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['employeeId'], $r->get_data()['data']['errors']['title'] ), 'sans موظف ni عنوان → refusé avec les deux erreurs' );
$r = $post( array( 'employeeId' => $lid( 'category', 'إنارة' ), 'title' => 'x' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['employeeId'] ), 'موظف pris dans une autre liste → refusé' );
$r = $post( array( 'employeeId' => $e_majdi, 'title' => 'x', 'dueDate' => '2026-02-30' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['dueDate'] ), 'date impossible → refusée' );
$r = $post( array( 'employeeId' => $e_majdi, 'title' => 'x', 'projectId' => 999999 ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['projectId'] ), 'projet inexistant → refusé' );

$pid   = (int) $wpdb->get_var( 'SELECT id FROM ' . ps_table( 'projects' ) . ' WHERE deleted_at IS NULL ORDER BY id LIMIT 1' );
$mails = array();
$r     = $post( array( 'employeeId' => $e_majdi, 'title' => 'اتصل بالحريف لتأكيد الطلبية', 'dueDate' => '2020-01-01', 'note' => "سطر 1\nسطر 2", 'projectId' => $pid ) );
$t1    = $r->get_data();
ps_assert( 200 === $r->get_status() && 'open' === $t1['status'] && $t1['employeeId'] === $e_majdi && $t1['projectId'] === $pid && $t1['projectNo'], 'مسؤول مشروع : tâche créée (ouverte, liée au projet)' );
ps_assert( true === $t1['late'] && 't6_ghofrane' === $t1['createdByName'], 'أجل dépassé → « متأخرة » ; auteur enregistré' );
ps_assert( 1 === count( $mails ) && 't6_majdi@example.com' === $mails[0]['to'] && str_contains( $mails[0]['subject'], 'مهمة جديدة' ) && str_contains( $mails[0]['message'], 'اتصل بالحريف' ), 'e-mail immédiat à l\'employé concerné' );
ps_assert( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'changelog' ) . " WHERE entity='task' AND entity_id=%d AND action='create'", $t1['id'] ) ), 'création inscrite au journal' );

wp_set_current_user( $u_adm );
$t2 = $post( array( 'employeeId' => $e_hachem, 'title' => 'حضّر كتالوج Legrand', 'dueDate' => '2099-12-31' ) )->get_data();
ps_assert( ! empty( $t2['id'] ) && false === $t2['late'], 'الإدارة : tâche créée ; أجل futur → pas en retard' );

ps_test_section( 'Tâches libres : qui voit quoi' );

wp_set_current_user( $u_majdi );
$ids = array_column( $get()->get_data()['tasks'], 'id' );
ps_assert( in_array( $t1['id'], $ids, true ) && ! in_array( $t2['id'], $ids, true ), 'موظف : voit ses tâches, pas celles des autres' );
$tasks = ps_test_json( 'GET', '/tasks' )->get_data();
ps_assert( 1 === count( $tasks['assigned'] ) && $tasks['assigned'][0]['id'] === $t1['id'], 'مهامي : bloc « مهام مُسندة » avec sa tâche' );
ps_assert( 1 <= ps_counters( $u_majdi )['taches'], 'compteur du menu مهامي inclut la tâche' );

wp_set_current_user( $u_resp );
$tasks = ps_test_json( 'GET', '/tasks' )->get_data();
ps_assert( in_array( $t1['id'], array_column( $tasks['sent'], 'id' ), true ), 'auteur : la tâche apparaît dans « مهام أرسلتها »' );
ps_assert( ! in_array( $t2['id'], array_column( $get( '?status=all' )->get_data()['tasks'], 'id' ), true ), 'مسؤول مشروع : ne voit pas les tâches envoyées par d\'autres à d\'autres' );

wp_set_current_user( $u_adm );
$all = array_column( $get( '?status=all' )->get_data()['tasks'], 'id' );
ps_assert( in_array( $t1['id'], $all, true ) && in_array( $t2['id'], $all, true ), 'الإدارة : voit toutes les tâches' );
$team = ps_test_json( 'GET', '/tasks' )->get_data();
ps_assert( 2 <= count( $team['assigned'] ), 'الإدارة : مهامي de l\'équipe inclut toutes les tâches ouvertes' );

ps_test_section( 'Tâches libres : تمت / rouvrir / modifier / annuler' );

wp_set_current_user( $u_other );
ps_assert_same( 404, $put( $t1['id'], array( 'action' => 'done' ) )->get_status(), 'autre employé : ne peut pas toucher la tâche (404)' );
wp_set_current_user( $u_majdi );
ps_assert_same( 403, $put( $t1['id'], array( 'action' => 'cancel' ) )->get_status(), 'employé concerné : ne peut pas annuler (403)' );
ps_assert_same( 403, $put( $t1['id'], array( 'action' => 'edit', 'title' => 'x' ) )->get_status(), 'employé concerné : ne peut pas modifier (403)' );
$r = $put( $t1['id'], array( 'action' => 'done', 'doneNote' => 'تم الاتصال' ) );
ps_assert( 200 === $r->get_status() && 'done' === $r->get_data()['status'] && 't6_majdi' === $r->get_data()['doneByName'] && false === $r->get_data()['late'], 'employé concerné : « تمت » (qui et quand enregistrés ; plus en retard)' );
ps_assert_same( 409, $put( $t1['id'], array( 'action' => 'done' ) )->get_status(), 'déjà faite → 409' );
ps_assert_same( array(), ps_test_json( 'GET', '/tasks' )->get_data()['assigned'], 'tâche faite : disparaît de مهامي' );
wp_set_current_user( $u_resp );
$sent = ps_test_json( 'GET', '/tasks' )->get_data()['sent'];
ps_assert( 'done' === ( array_column( $sent, 'status', 'id' )[ $t1['id'] ] ?? '' ), 'auteur : voit qu\'elle est « تمت » (14 derniers jours)' );
ps_assert_same( 200, $put( $t1['id'], array( 'action' => 'reopen' ) )->get_status(), 'auteur : peut la rouvrir' );
$r = $put( $t1['id'], array( 'action' => 'edit', 'employeeId' => $e_hachem, 'dueDate' => '' ) );
ps_assert( 200 === $r->get_status() && $r->get_data()['employeeId'] === $e_hachem && null === $r->get_data()['dueDate'], 'auteur : modifie l\'employé et retire le أجل' );
ps_assert_same( 200, $put( $t1['id'], array( 'action' => 'cancel' ) )->get_status(), 'auteur : annule' );
ps_assert_same( 'cancelled', $wpdb->get_var( 'SELECT status FROM ' . ps_table( 'tasks' ) . ' WHERE id = ' . (int) $t1['id'] ), 'tâche annulée : gardée en base (statut « cancelled »), jamais supprimée' );
ps_assert_same( 409, $put( $t1['id'], array( 'action' => 'edit', 'title' => 'y' ) )->get_status(), 'tâche annulée : plus modifiable' );
ps_assert_same( 404, $put( $t2['id'], array( 'action' => 'cancel' ) )->get_status(), 'مسؤول مشروع : la tâche d\'un autre (pour un autre) lui est invisible (404)' );
wp_set_current_user( $u_adm );
ps_assert_same( 422, $put( $t2['id'], array( 'action' => 'zzz' ) )->get_status(), 'action inconnue refusée' );

ps_test_section( 'Tâches libres : e-mail du matin et sauvegarde' );

$u_hachem_mail = get_userdata( $u_other );
update_user_meta( $u_other, 'ps_daily_email', 1 );
$mails = array();
ps_digest_send_to_user( $u_hachem_mail, false );
$m = $mails[0] ?? array( 'message' => '' );
ps_assert( str_contains( $m['message'], 'مهام مُسندة' ) && str_contains( $m['message'], 'حضّر كتالوج Legrand' ), 'e-mail du matin : bloc « مهام مُسندة » avec la tâche' );
$b = ps_backup_build();
ps_assert( in_array( (int) $t2['id'], array_map( 'intval', array_column( $b['tables']['tasks'], 'id' ) ), true ), 'sauvegarde JSON : contient les tâches' );

ps_test_section( 'E-mail : expéditeur, SMTP et vraie raison d\'un échec' );

wp_set_current_user( $u_adm );
$r = ps_test_json( 'PUT', '/settings', array( 'mail_from_email' => 'pas-un-email', 'smtp_port' => 0, 'smtp_secure' => 'zzz' ) );
$e = $r->get_data()['data']['errors'] ?? array();
ps_assert( 422 === $r->get_status() && isset( $e['mail_from_email'], $e['smtp_port'], $e['smtp_secure'] ), 'réglages invalides refusés (e-mail, port, chiffrement)' );
$r = ps_test_json( 'PUT', '/settings', array( 'smtp_enabled' => true, 'smtp_host' => '' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['smtp_host'] ), 'SMTP activé sans serveur → refusé' );
$r = ps_test_json( 'PUT', '/settings', array( 'mail_from_email' => 'app@protecta.ly', 'mail_from_name' => 'Protecta', 'smtp_enabled' => true, 'smtp_host' => 'mail.protecta.ly', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => 'app@protecta.ly', 'smtp_pass' => 'S3cret!' ) );
ps_assert( 200 === $r->get_status() && true === $r->get_data()['smtpPassSet'], 'réglages SMTP enregistrés' );
ps_assert( ! str_contains( wp_json_encode( $r->get_data() ), 'S3cret' ), 'mot de passe SMTP jamais renvoyé au navigateur' );
ps_assert( ! str_contains( wp_json_encode( ps_backup_build() ), 'S3cret' ), 'mot de passe SMTP absent de la sauvegarde JSON' );
ps_test_json( 'PUT', '/settings', array( 'smtp_pass' => '' ) );
ps_assert_same( 'S3cret!', get_option( 'ps_smtp_pass' ), 'champ mot de passe vide → l\'ancien est gardé' );
ps_assert_same( 'app@protecta.ly', apply_filters( 'wp_mail_from', 'wordpress@localhost' ), 'expéditeur = adresse des réglages' );
ps_assert_same( 'Protecta', apply_filters( 'wp_mail_from_name', 'WordPress' ), 'nom de l\'expéditeur = réglages' );

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
$pm = new PHPMailer\PHPMailer\PHPMailer( true );
do_action_ref_array( 'phpmailer_init', array( &$pm ) );
ps_assert( 'smtp' === $pm->Mailer && 'mail.protecta.ly' === $pm->Host && 465 === $pm->Port && 'ssl' === $pm->SMTPSecure && $pm->SMTPAuth && 'app@protecta.ly' === $pm->Username && 'S3cret!' === $pm->Password, 'PHPMailer configuré en SMTP (serveur, port, SSL, compte)' );

// Échec réel : serveur SMTP injoignable → le bouton d'essai renvoie la vraie raison.
remove_all_filters( 'pre_wp_mail' );
ps_test_json( 'PUT', '/settings', array( 'smtp_host' => '127.0.0.1', 'smtp_port' => 1, 'smtp_secure' => 'none' ) );
$r = ps_test_json( 'POST', '/settings/test-email', array( 'to' => 'idara@example.com' ) );
ps_assert( 500 === $r->get_status() && str_contains( $r->get_data()['message'], 'SMTP' ), 'échec d\'envoi → message avec la vraie raison', $r->get_data()['message'] ?? '' );
$r = ps_test_json( 'POST', '/settings/test-email', array( 'to' => 'pas-un-email' ) );
ps_assert_same( 422, $r->get_status(), 'adresse d\'essai invalide → 422' );
add_filter( 'pre_wp_mail', fn() => true );
$r = ps_test_json( 'POST', '/settings/test-email', array( 'to' => 'autre@example.com' ) );
ps_assert( 200 === $r->get_status() && 'autre@example.com' === $r->get_data()['to'] && true === $r->get_data()['smtp'], 'envoi d\'essai vers une autre adresse : OK' );
wp_set_current_user( $u_resp );
ps_assert_same( 403, ps_test_json( 'POST', '/settings/test-email' )->get_status(), 'bouton d\'essai réservé à la الإدارة' );

// Retour à l'état normal pour d'éventuels tests suivants.
remove_all_filters( 'pre_wp_mail' );
ps_update_settings( array( 'smtp_enabled' => false, 'smtp_host' => '', 'mail_from_email' => '', 'smtp_user' => '' ) );
delete_option( 'ps_smtp_pass' );
wp_set_current_user( 0 );
