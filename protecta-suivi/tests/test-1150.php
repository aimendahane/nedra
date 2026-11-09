<?php
/**
 * Version 1.15.0 — « الإدارة ynjmou zidou des membres » + tri de tous les tableaux (tri : test navigateur step24).
 *   - « عضو جديد » : le nom suffit → employé ajouté à la liste « الموظفون » (ou réutilisé s'il porte ce nom sans
 *     compte), compte lié, mot de passe généré si vide et renvoyé UNE seule fois, e-mail facultatif ;
 *   - droits : الإدارة seulement ; erreurs lisibles ; aucune donnée existante modifiée (employé déjà lié jamais repris).
 */

global $wpdb;
ps_test_reset_data();
$adm = ps_test_user( 't115_adm', 'ps_admin' );
wp_set_current_user( $adm );

/* ------------------------------------------------------------------ */
ps_test_section( '1.15.0 : عضو جديد (le nom suffit)' );

$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) . " WHERE list_key = 'employee'" );
$r      = ps_test_json( 'POST', '/users', array( 'member' => true, 'newEmployee' => true, 'name' => 'سارة الهمامي', 'login' => 'sarra.hammami', 'role' => 'ps_employee', 'email' => '' ) );
ps_assert_same( 200, $r->get_status(), 'membre créé sans e-mail ni mot de passe' );
$d = $r->get_data();
ps_assert( ! empty( $d['access']['password'] ) && $d['access']['generated'] && strlen( $d['access']['password'] ) >= 8 && 'sarra.hammami' === $d['access']['login'], 'mot de passe généré et renvoyé avec l\'identifiant (carte d\'accès)', wp_json_encode( $d['access'] ?? null ) );
ps_assert( wp_check_password( $d['access']['password'], get_userdata( $d['id'] )->user_pass, $d['id'] ), 'le mot de passe renvoyé est bien celui du compte' );
ps_assert_same( $before + 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) . " WHERE list_key = 'employee'" ), 'employé ajouté à la liste « الموظفون »' );
$emp = (int) get_user_meta( $d['id'], 'ps_employee_id', true );
ps_assert( $emp > 0 && 'سارة الهمامي' === $wpdb->get_var( $wpdb->prepare( 'SELECT label FROM ' . ps_table( 'lists' ) . ' WHERE id = %d', $emp ) ), 'compte lié à ce nouvel employé' );
ps_assert( $d['employeeId'] === $emp, 'réponse : employeeId renseigné' );
ps_assert( ! str_contains( wp_json_encode( ps_test_json( 'GET', '/users' )->get_data() ), $d['access']['password'] ), 'le mot de passe n\'est plus jamais renvoyé ensuite' );

// Employé de la liste portant déjà ce nom, sans compte → réutilisé (pas de doublon), même s'il était masqué.
$old = ps_insert_list_value( 'employee', array( 'label' => 'منير الطرابلسي' ), 990 );
$wpdb->update( ps_table( 'lists' ), array( 'is_hidden' => 1 ), array( 'id' => $old ) );
ps_lists_by_id( true );
$r = ps_test_json( 'POST', '/users', array( 'member' => true, 'newEmployee' => true, 'name' => 'منير  الطرابلسي', 'login' => 'mounir', 'role' => 'ps_manager', 'password' => 'Mounir-2026', 'email' => 'mounir@example.com' ) );
ps_assert_same( 200, $r->get_status(), 'membre créé (mot de passe choisi)' );
ps_assert( $old === (int) get_user_meta( $r->get_data()['id'], 'ps_employee_id', true ), 'employé existant du même nom (sans compte) réutilisé, pas de doublon' );
ps_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT is_hidden FROM ' . ps_table( 'lists' ) . ' WHERE id = %d', $old ) ), '… et de nouveau visible dans les listes' );
ps_assert( false === $r->get_data()['access']['generated'] && 'Mounir-2026' === $r->get_data()['access']['password'], 'mot de passe saisi : renvoyé tel quel, « generated » = false' );

// Même nom qu'un employé DÉJÀ lié à un compte → nouvel employé (on ne prend jamais l'employé d'un autre compte).
$r = ps_test_json( 'POST', '/users', array( 'member' => true, 'newEmployee' => true, 'name' => 'منير الطرابلسي', 'login' => 'mounir2', 'role' => 'ps_employee' ) );
ps_assert( 200 === $r->get_status() && $old !== (int) get_user_meta( $r->get_data()['id'], 'ps_employee_id', true ), 'employé déjà lié à un compte : jamais repris (nouvelle valeur créée)' );

// Employé choisi dans la liste (sans compte).
$free = ps_insert_list_value( 'employee', array( 'label' => 'هالة بن علي' ), 995 );
ps_lists_by_id( true );
$r = ps_test_json( 'POST', '/users', array( 'member' => true, 'employeeId' => $free, 'name' => 'هالة بن علي', 'login' => 'hela', 'role' => 'ps_employee' ) );
ps_assert( 200 === $r->get_status() && $free === (int) get_user_meta( $r->get_data()['id'], 'ps_employee_id', true ), 'employé choisi dans la liste : lié' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.15.0 : erreurs et droits' );

$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) . " WHERE list_key = 'employee'" );
$e = ps_test_json( 'POST', '/users', array( 'member' => true, 'newEmployee' => true, 'name' => '', 'login' => 'sarra.hammami', 'role' => 'ps_employee', 'email' => 'pas-un-email' ) );
ps_assert_same( 422, $e->get_status(), 'nom vide / identifiant pris / e-mail invalide → 422' );
$errs = $e->get_data()['data']['errors'];
ps_assert( isset( $errs['name'], $errs['login'], $errs['email'] ), 'erreurs sous chaque خانة (name, login, email)', wp_json_encode( $errs ) );
ps_assert_same( $n, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'lists' ) . " WHERE list_key = 'employee'" ), 'aucun employé créé quand le compte est refusé' );

// Compte existant : e-mail effacé accepté (facultatif), le reste inchangé.
$mid = (int) get_user_by( 'login', 'mounir' )->ID;
$r   = ps_test_json( 'PUT', '/users/' . $mid, array( 'email' => '' ) );
ps_assert( 200 === $r->get_status() && '' === get_userdata( $mid )->user_email && 'ps_manager' === get_userdata( $mid )->roles[0], 'compte existant : e-mail vidé accepté, rôle inchangé' );

// Sans « member » : comportement 1.14.0 (mot de passe obligatoire, pas de carte d'accès).
$r = ps_test_json( 'POST', '/users', array( 'name' => 'x', 'login' => 'xyz', 'role' => 'ps_employee' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['password'] ), 'formulaire « حساب جديد » : mot de passe toujours obligatoire' );

wp_set_current_user( get_user_by( 'login', 'hela' )->ID );
ps_assert_same( 403, ps_test_json( 'POST', '/users', array( 'member' => true, 'newEmployee' => true, 'name' => 'دخيل', 'login' => 'intrus', 'role' => 'ps_admin' ) )->get_status(), 'موظف : ajout de membre refusé (403)' );
wp_set_current_user( get_user_by( 'login', 'mounir' )->ID );
ps_assert_same( 403, ps_test_json( 'POST', '/users', array( 'member' => true, 'newEmployee' => true, 'name' => 'دخيل', 'login' => 'intrus', 'role' => 'ps_admin' ) )->get_status(), 'مسؤول مشروع : ajout de membre refusé (403)' );
ps_assert( ! username_exists( 'intrus' ), 'aucun compte créé' );
wp_set_current_user( $adm );
