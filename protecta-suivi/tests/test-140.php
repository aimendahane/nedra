<?php
/**
 * Version 1.4.0 — « هامش الربح » du الوسيط ; un موظف ne modifie pas les أصناف ni les montants des AUTRES
 * employés (même pendant ses 24 h de créateur).
 */

global $wpdb;

ps_test_section( '1.4.0 : الوسيط — هامش الربح' );

ps_test_reset_data();
$adm = ps_test_user( 't14_adm', 'ps_admin' );
$e1  = ps_test_user( 't14_e1', 'ps_employee', 'هاشم الخراز' );   // créateur
$e2  = ps_test_user( 't14_e2', 'ps_employee', 'بدر ناجي' );      // autre employé
$mgr = ps_test_user( 't14_mgr', 'ps_manager', 'وليد جدير' );     // المسؤول عن المشروع
$hx  = ps_test_lid( 'employee', 'هاشم الخراز' );
$bn  = ps_test_lid( 'employee', 'بدر ناجي' );

wp_set_current_user( $e1 );
$pay = ps_test_payload(
	array(
		'offerAmount'    => '10000',
		'purchaseAmount' => '7000',
		'items'          => array(
			array( 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => '1', 'employeeId' => $hx, 'dueDate' => '2026-10-20' ),
			array( 'itemTypeId' => ps_test_lid( 'item_type', 'CABLE' ), 'quantity' => '5', 'employeeId' => $bn, 'dueDate' => '2026-10-21' ),
		),
	)
);
$pay['parties']['broker'] = array( 'companyName' => 'شركة الوساطة', 'person' => 'عادل', 'margin' => '10%' );
$r = ps_test_json( 'POST', '/projects', $pay );
ps_assert_same( 201, $r->get_status(), 'موظف : crée un projet (2 أصناف : le sien + celui d\'un collègue)' );
$p   = $r->get_data();
$pid = (int) $p['id'];
$get = fn() => ps_test_json( 'GET', '/projects/' . $pid )->get_data();
ps_assert( '10%' === $get()['parties']['broker']['margin'], 'الوسيط : « هامش الربح » enregistré et relu (10%)' );
ps_assert( true === $get()['permissions']['ownItemsOnly'], 'API : le créateur موظف est limité à ses أصناف (ownItemsOnly)' );

ps_test_section( '1.4.0 : un موظف ne touche pas aux أصناف / montants des autres' );

$items = $get()['items'];
$mine  = array_values( array_filter( $items, fn( $i ) => $i['employeeId'] === $hx ) )[0];
$other = array_values( array_filter( $items, fn( $i ) => $i['employeeId'] === $bn ) )[0];
$sent  = ps_list_id_by_code( 'offer_status', 'sent' );
ps_assert_same( 403, ps_test_json( 'PUT', '/items/' . $other['id'], array( 'offerStatusId' => $sent, 'projectSentDate' => '2026-10-05' ) )->get_status(), 'créateur : statut d\'offre du صنف d\'un collègue → refusé (403)' );
ps_assert_same( 200, ps_test_json( 'PUT', '/items/' . $mine['id'], array( 'offerStatusId' => $sent, 'projectSentDate' => '2026-10-05' ) )->get_status(), 'créateur : son propre صنف → accepté' );

$cur  = $get();
$edit = function ( callable $change ) use ( $cur, $pay ) {
	$b = array_merge( $pay, array( 'sentDate' => '2026-10-05', 'offerNo' => $cur['offerNo'] ) );
	$b['items'] = array_map( fn( $i ) => array( 'id' => $i['id'], 'itemTypeId' => $i['itemTypeId'], 'quantity' => (string) $i['quantity'], 'employeeId' => $i['employeeId'], 'dueDate' => $i['dueDate'] ), $cur['items'] );
	$change( $b );
	return ps_test_json( 'PUT', '/projects/' . $cur['id'], $b );
};
$r = $edit( function ( &$b ) use ( $bn ) {
	foreach ( $b['items'] as &$i ) {
		if ( $i['employeeId'] === $bn ) {
			$i['quantity'] = '50';
		}
	}
} );
ps_assert_same( 403, $r->get_status(), 'créateur : quantité du صنف d\'un collègue → refusé' );
$r = $edit( function ( &$b ) use ( $bn ) {
	$b['items'] = array_values( array_filter( $b['items'], fn( $i ) => $i['employeeId'] !== $bn ) );
} );
ps_assert_same( 403, $r->get_status(), 'créateur : retirer le صنف d\'un collègue → refusé' );
$r = $edit( function ( &$b ) {
	$b['purchaseAmount'] = '6500';
} );
ps_assert( 403 === $r->get_status() && isset( $r->get_data()['data']['errors']['purchase_amount'] ), 'créateur : مبلغ الشراء (projet avec أصناف d\'autres) → refusé' );
$r = $edit( function ( &$b ) {
	$b['name'] = 'إنارة مدرسة الأمل (معدّل)';
} );
ps_assert_same( 200, $r->get_status(), 'créateur : ses infos générales (nom…) restent modifiables pendant 24 h' );
ps_assert( 7000.0 === (float) $wpdb->get_var( $wpdb->prepare( 'SELECT purchase_amount FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $pid ) ), 'montant d\'achat inchangé' );

// Projet dont TOUS les أصناف sont les siens : montants modifiables par le créateur.
$solo          = $pay;
$solo['offerNo'] = 'OFR-SOLO-1';
$solo['items'] = array( $pay['items'][0] );
$solo['name']  = 'مشروع فردي للموظف';
$solo['confirmDuplicates'] = true;
$sp            = ps_test_json( 'POST', '/projects', $solo )->get_data();
$sb            = $solo;
$sb['offerNo'] = $sp['offerNo'];
$sb['items']   = array( array( 'id' => $sp['items'][0]['id'], 'itemTypeId' => $sp['items'][0]['itemTypeId'], 'quantity' => '1', 'employeeId' => $hx, 'dueDate' => '2026-10-20' ) );
$sb['purchaseAmount'] = '6000';
ps_assert_same( 200, ps_test_json( 'PUT', '/projects/' . $sp['id'], $sb )->get_status(), 'créateur : projet avec seulement ses أصناف → montants modifiables' );

// L'autre employé : modifie son propre صنف, pas le projet.
wp_set_current_user( $e2 );
ps_assert_same( 200, ps_test_json( 'PUT', '/items/' . $other['id'], array( 'offerStatusId' => $sent, 'projectSentDate' => '2026-10-05' ) )->get_status(), 'le collègue modifie SON صنف' );
ps_assert_same( 403, ps_test_json( 'PUT', '/items/' . $mine['id'], array( 'offerStatusId' => ps_list_id_by_code( 'offer_status', 'accepted' ) ) )->get_status(), 'le collègue ne touche pas au صنف du créateur' );

// المسؤول عن المشروع et الإدارة : tout.
wp_set_current_user( $mgr );
$cur = $get();
ps_assert( false === $cur['permissions']['ownItemsOnly'], 'المسؤول : aucune limite' );
$r = $edit( function ( &$b ) {
	$b['purchaseAmount'] = '6800';
	$b['items'][1]['quantity'] = '6';
} );
ps_assert_same( 200, $r->get_status(), 'المسؤول عن المشروع : montants et أصناف de tous → accepté' );
wp_set_current_user( 0 );

ps_test_section( '1.4.0 : ورقة حسابات الفريق' );

wp_set_current_user( $adm );
$free = array_values( array_filter( ps_lists_all()['employee'], fn( $v ) => ! get_users( array( 'meta_key' => 'ps_employee_id', 'meta_value' => (string) $v['id'], 'fields' => 'ids' ) ) ) );
ps_assert( count( $free ) >= 2, 'des employés de la liste n\'ont pas encore de compte', count( $free ) );
$r = ps_test_json( 'POST', '/users/access-sheet', array( 'create' => array( array( 'employeeId' => $free[0]['id'], 'login' => 'sheet.one', 'role' => 'ps_employee' ), array( 'employeeId' => $free[1]['id'], 'login' => 't14_e1', 'role' => 'ps_employee' ) ) ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['create.1.login'] ) && ! username_exists( 'sheet.one' ), 'identifiant déjà pris → erreur sur la ligne, AUCUN compte créé' );
$r = ps_test_json( 'POST', '/users/access-sheet', array( 'create' => array( array( 'employeeId' => $hx, 'login' => 'sheet.x', 'role' => 'ps_employee' ) ) ) );
ps_assert_same( 422, $r->get_status(), 'employé déjà lié à un compte → refusé' );
$r = ps_test_json(
	'POST',
	'/users/access-sheet',
	array(
		'create' => array( array( 'employeeId' => $free[0]['id'], 'login' => 'sheet.one', 'role' => 'ps_employee' ), array( 'employeeId' => $free[1]['id'], 'login' => 'sheet.two', 'role' => 'ps_manager', 'email' => 'two@example.com' ) ),
		'reset'  => array( $e2 ),
	)
);
$d = $r->get_data();
ps_assert( 200 === $r->get_status() && 3 === count( $d['accounts'] ) && home_url( '/' ) === $d['site'], 'feuille : 2 comptes créés + 1 nouveau mot de passe, avec l\'adresse du site', wp_json_encode( $d ) );
$one = get_user_by( 'login', 'sheet.one' );
ps_assert( $one && (int) get_user_meta( $one->ID, 'ps_employee_id', true ) === (int) $free[0]['id'] && $free[0]['label'] === $one->display_name && in_array( 'ps_employee', $one->roles, true ), 'compte créé : lié à l\'employé, nom = nom de la liste, rôle موظف' );
ps_assert( wp_check_password( $d['accounts'][0]['password'], $one->user_pass, $one->ID ) && 10 === strlen( $d['accounts'][0]['password'] ) && ! preg_match( '/[0O1lI]/', $d['accounts'][0]['password'] ), 'mot de passe : 10 caractères lisibles (sans 0/O/1/l/I), valide pour la connexion' );
ps_assert( wp_check_password( $d['accounts'][2]['password'], get_userdata( $e2 )->user_pass, $e2 ) && false === $d['accounts'][2]['new'], 'compte existant : nouveau mot de passe actif' );
ps_assert( ! str_contains( wp_json_encode( ps_test_json( 'GET', '/users' )->get_data() ), $d['accounts'][0]['password'] ), 'le mot de passe n\'est plus jamais renvoyé ensuite' );
ps_assert_same( 422, ps_test_json( 'POST', '/users/access-sheet', array( 'reset' => array( $adm ) ) )->get_status(), 'son propre compte : pas de réinitialisation ici (422)' );
wp_set_current_user( $e1 );
ps_assert_same( 403, ps_test_json( 'POST', '/users/access-sheet', array( 'reset' => array( $e2 ) ) )->get_status(), 'réservé à la الإدارة (403 pour un موظف)' );
wp_set_current_user( 0 );
