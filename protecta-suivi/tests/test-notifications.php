<?php
/**
 * Version 1.0.2 — notifications 🔔 : qui est prévenu, regroupement par sauvegarde,
 * jamais l'auteur, cloche (API), « lu », e-mail immédiat sur demande.
 */

global $wpdb;

ps_notify_flush(); // vide ce que les tests précédents ont laissé dans le tampon
$emp = fn( $label ) => ps_test_lid( 'employee', $label );
$mk  = function ( $login, $role, $employee = null ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => $login, 'user_email' => $login . '@example.com' ) );
	if ( $employee ) {
		update_user_meta( $id, 'ps_employee_id', ps_test_lid( 'employee', $employee ) );
	}
	return $id;
};
// Employés réservés à ce test (aucun autre compte n'y est lié).
$u_resp = $mk( 't7_resp', 'ps_manager', 'أميرة السعيداني' );   // المسؤول عن المشروع
$u_fol  = $mk( 't7_fol', 'ps_employee', 'مروان فرحاني' );      // المتابع
$u_item = $mk( 't7_item', 'ps_employee', 'صفاء' );             // employé du صنف
$u_new  = $mk( 't7_new', 'ps_employee', 'بدر ناجي' );          // reçoit le صنف ensuite
$u_out  = $mk( 't7_out', 'ps_employee', 'هاشم الخراز' );       // non concerné
$u_adm  = $mk( 't7_adm', 'ps_admin' );

$count  = fn( $uid ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ps_table( 'notifications' ) . ' WHERE user_id = %d', $uid ) );
$last   = fn( $uid ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'notifications' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $uid ), ARRAY_A );
$snap   = fn() => array_map( $count, array( 'resp' => $u_resp, 'fol' => $u_fol, 'item' => $u_item, 'new' => $u_new, 'out' => $u_out, 'adm' => $u_adm ) );
$delta  = fn( $before ) => array_map( fn( $a, $b ) => $a - $b, $snap(), $before );

ps_test_section( 'Notifications : nouveau projet' );

wp_set_current_user( $u_adm );
$before = $snap();
$r      = ps_test_json(
	'POST',
	'/projects',
	ps_test_payload(
		array(
			'name'             => 'مشروع الإشعارات',
			'responsibleEmpId' => $emp( 'أميرة السعيداني' ),
			'followerEmpId'    => $emp( 'مروان فرحاني' ),
			'items'            => array( array( 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => '2', 'employeeId' => $emp( 'صفاء' ), 'dueDate' => '2026-10-20' ) ),
		)
	)
);
$p = $r->get_data();
ps_assert( 201 === $r->get_status() && ! empty( $p['id'] ), 'projet créé par la الإدارة' );
ps_notify_flush();
$d = $delta( $before );
ps_assert( 1 === $d[0] && 1 === $d[1] && 1 === $d[2], 'المسؤول, المتابع et l\'employé du صنف : 1 notification chacun', implode( ',', $d ) );
ps_assert( 0 === $d[3] && 0 === $d[4], 'employés non concernés : rien' );
ps_assert_same( 0, $d[5], 'l\'auteur (الإدارة) n\'est pas notifié de sa propre action' );
$n = $last( $u_resp );
ps_assert( 'created' === $n['kind'] && str_contains( $n['title'], $p['projectNo'] ) && str_contains( $n['title'], 'مشروع الإشعارات' ) && (int) $n['actor_id'] === $u_adm, 'notification « مشروع جديد » : numéro, nom, auteur' );

ps_test_section( 'Notifications : changement de statut, suivi, fichier' );

$item_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d', $p['id'] ) );
wp_set_current_user( $u_item );
$before = $snap();
$r      = ps_test_json( 'PUT', '/items/' . $item_id, array( 'offerStatusId' => ps_list_id_by_code( 'offer_status', 'sent' ), 'projectSentDate' => '2026-10-02' ) );
ps_assert_same( 200, $r->get_status(), 'l\'employé passe son صنف à « مرسل »' );
ps_notify_flush();
$d = $delta( $before );
ps_assert( 1 === $d[0] && 1 === $d[1] && 0 === $d[2], 'المسؤول + المتابع prévenus ; pas l\'employé lui-même', implode( ',', $d ) );
$n = $last( $u_fol );
ps_assert( 'status' === $n['kind'] && str_contains( $n['body'], 'حالة العرض' ) && str_contains( $n['body'], 'مرسل' ) && str_contains( $n['body'], 'UPS' ), 'message : « حالة العرض (UPS): … ← مرسل »', $n['body'] );

wp_set_current_user( $u_fol );
$before = $snap();
$req    = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $p['id'] . '/followups' );
$req->set_param( 'date', '2026-10-05' );
$req->set_param( 'text', 'اتصل الحريف، ينتظر موافقة المدير' );
$req->set_param( 'nextDate', '2026-10-12' );
ps_assert_same( 200, rest_get_server()->dispatch( $req )->get_status(), 'المتابع ajoute au سجل المتابعة' );
ps_notify_flush();
$d = $delta( $before );
ps_assert( 1 === $d[0] && 0 === $d[1], 'المسؤول prévenu, pas l\'auteur du suivi', implode( ',', $d ) );
ps_assert( str_contains( $last( $u_resp )['body'], 'ينتظر موافقة المدير' ), 'le texte du suivi figure dans la notification' );

ps_test_section( 'Notifications : une sauvegarde = une seule notification par personne' );

wp_set_current_user( $u_adm );
$cur    = ps_test_json( 'GET', '/projects/' . $p['id'] )->get_data();
$upd    = ps_test_payload(
	array(
		'name'             => 'مشروع الإشعارات (معدّل)',
		'note'             => 'ملاحظة جديدة',
		'offerAmount'      => '5000',
		'sentDate'         => '2026-10-02',
		'responsibleEmpId' => $emp( 'أميرة السعيداني' ),
		'followerEmpId'    => $emp( 'مروان فرحاني' ),
		'followupStatusId' => ps_test_lid( 'followup_status', 'في انتظار الرد' ),
		'items'            => array( array( 'id' => $item_id, 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => '2', 'employeeId' => $emp( 'بدر ناجي' ), 'dueDate' => '2026-10-20' ) ),
	)
);
$upd['offerNo'] = $cur['offerNo'];
$before         = $snap();
$r              = ps_test_json( 'PUT', '/projects/' . $p['id'], $upd );
ps_assert( 200 === $r->get_status(), 'الإدارة modifie plusieurs champs + change l\'employé du صنف', wp_json_encode( $r->get_data()['data']['errors'] ?? $r->get_data(), JSON_UNESCAPED_UNICODE ) );
ps_notify_flush();
$d = $delta( $before );
ps_assert( 1 === $d[0] && 1 === $d[1], 'plusieurs changements → 1 seule notification pour المسؤول et المتابع', implode( ',', $d ) );
ps_assert_same( 1, $d[3], 'nouvel employé du صنف prévenu' );
ps_assert_same( 0, $d[4], 'employé non concerné : toujours rien' );
$body = $last( $u_resp )['body'];
ps_assert( str_contains( $body, 'اسم المشروع' ) && str_contains( $body, 'حالة المتابعة' ) && str_contains( $body, 'في انتظار الرد' ), 'le détail des changements est listé', $body );
ps_assert( 'status' === $last( $u_resp )['kind'], 'type principal = changement de حالة المتابعة' );

ps_test_section( 'Notifications : cloche (API) et « lu »' );

wp_set_current_user( $u_resp );
$r = ps_test_json( 'GET', '/notifications' )->get_data();
ps_assert( 4 === $r['unread'] && 4 === count( $r['items'] ) && false === $r['items'][0]['read'] && $r['items'][0]['projectId'] === $p['id'], 'المسؤول : 4 notifications non lues (projet, statut, suivi, modification), la plus récente d\'abord' );
ps_assert( 't7_adm' === $r['items'][0]['actorName'], 'nom de l\'auteur affiché' );
ps_assert_same( 4, ps_test_json( 'GET', '/notifications?limit=0' )->get_data()['unread'], 'limit=0 → seulement le nombre (pour la cloche)' );
$first = $r['items'][0]['id'];
wp_set_current_user( $u_out );
ps_assert_same( 0, ps_test_json( 'POST', '/notifications/read', array( 'ids' => array( $first ) ) )->get_data()['marked'], 'un autre compte ne peut pas marquer les notifications d\'autrui' );
ps_assert_same( array(), ps_test_json( 'GET', '/notifications' )->get_data()['items'], 'un compte ne voit que SES notifications' );
wp_set_current_user( $u_resp );
ps_assert_same( 3, ps_test_json( 'POST', '/notifications/read', array( 'ids' => array( $first ) ) )->get_data()['unread'], 'marquer une notification lue → 3 non lues' );
ps_assert_same( 0, ps_test_json( 'POST', '/notifications/read', array( 'all' => true ) )->get_data()['unread'], '« تعليم الكل كمقروء » → 0' );
ps_assert_same( 4, $count( $u_resp ), 'les notifications lues restent enregistrées (jamais supprimées)' );

ps_test_section( 'Notifications : e-mail immédiat (préférence du compte)' );

$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true;
}, 10, 2 );
wp_set_current_user( $u_fol );
ps_assert( true === ps_test_json( 'POST', '/me/prefs', array( 'instantEmail' => true ) )->get_data()['prefs']['instantEmail'], 'المتابع active « بريد فوري عند كل تغيير »' );
wp_set_current_user( $u_new );
ps_test_json( 'PUT', '/items/' . $item_id, array( 'offerStatusId' => ps_list_id_by_code( 'offer_status', 'accepted' ) ) );
ps_notify_flush();
$to = array_column( $mails, 'to' );
ps_assert( in_array( 't7_fol@example.com', $to, true ), 'المتابع reçoit l\'e-mail immédiat' );
ps_assert( ! in_array( 't7_resp@example.com', $to, true ), 'المسؤول (sans la préférence) : pas d\'e-mail, seulement la cloche' );
$m = array_values( array_filter( $mails, fn( $x ) => 't7_fol@example.com' === $x['to'] ) )[0] ?? array( 'subject' => '', 'message' => '' );
ps_assert( str_contains( $m['subject'], $p['projectNo'] ) && str_contains( $m['message'], 'مقبول' ) && str_contains( $m['message'], 'dir="rtl"' ), 'e-mail : projet, changement, RTL' );
remove_all_filters( 'pre_wp_mail' );

ps_test_section( 'Notifications : rien pour l\'import, ni pour un compte désactivé' );

ps_notify_flush();
$before = $snap();
ps_log( (int) $p['id'], 'import', 'project', (int) $p['id'], null, null, 'x', $u_adm );
update_user_meta( $u_fol, 'ps_disabled', 1 );
ps_log( (int) $p['id'], 'update', 'project', (int) $p['id'], 'note', 'a', 'b', $u_adm );
ps_notify_flush();
$d = $delta( $before );
ps_assert( 1 === $d[0] && 0 === $d[1], 'import ignoré ; compte désactivé (المتابع) non notifié', implode( ',', $d ) );
delete_user_meta( $u_fol, 'ps_disabled' );
ps_assert( in_array( 'notifications', array_keys( ps_backup_build()['tables'] ), true ), 'notifications incluses dans la sauvegarde JSON' );
wp_set_current_user( 0 );
