<?php
/**
 * Version 1.3.0 — langues العربية / English / Français.
 * Dictionnaires complets, traduction côté serveur, préférence du compte, notifications dans la langue du destinataire.
 */

global $wpdb;

ps_test_section( 'Langues : dictionnaires' );

$dicts = array();
foreach ( array( 'en', 'fr' ) as $l ) {
	$raw          = (string) file_get_contents( PS_DIR . '/inc/i18n/' . $l . '.json' );
	$dicts[ $l ] = json_decode( $raw, true );
	ps_assert( is_array( $dicts[ $l ] ) && count( $dicts[ $l ] ) > 800, "inc/i18n/$l.json : JSON valide", json_last_error_msg() );
}
ps_assert_same( array_keys( $dicts['en'] ), array_keys( $dicts['fr'] ), 'en.json et fr.json ont exactement les mêmes clés' );

// Tous les textes du code : PS.t('…') (JS), ps__( '…' ) / ps_e( '…' ) (PHP), libellés par défaut des listes.
$keys = array();
$add  = function ( $s ) use ( &$keys ) {
	$s = trim( $s );
	if ( '' !== $s && preg_match( '/[\x{0600}-\x{06FF}]/u', $s ) ) {
		$keys[ $s ] = true;
	}
};
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PS_DIR, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	$p = (string) $f;
	if ( str_contains( $p, '/tests/' ) || str_contains( $p, '/vendor/' ) ) {
		continue;
	}
	if ( str_ends_with( $p, '.js' ) ) {
		preg_match_all( "/PS\.t\('((?:[^'\\\\]|\\\\.)*)'\)/u", (string) file_get_contents( $p ), $m );
		array_map( fn( $s ) => $add( stripcslashes( $s ) ), $m[1] );
	} elseif ( str_ends_with( $p, '.php' ) ) {
		preg_match_all( "/ps_(?:_|e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/u", (string) file_get_contents( $p ), $m );
		array_map( fn( $s ) => $add( str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $s ) ), $m[1] );
	}
}
foreach ( ps_default_lists() as $lk => $vals ) {
	if ( 'employee' !== $lk ) {
		foreach ( $vals as $v ) {
			$add( $v['label'] );
		}
	}
}
foreach ( array( 'en', 'fr' ) as $l ) {
	$missing = array_diff( array_keys( $keys ), array_keys( $dicts[ $l ] ) );
	ps_assert( ! $missing, "$l : chaque texte de l'interface a sa traduction (" . count( $keys ) . ' textes)', implode( ' | ', array_slice( $missing, 0, 10 ) ) );
	$bad = array();
	foreach ( $dicts[ $l ] as $ar => $tr ) {
		preg_match_all( '/%(\d+\$)?[sd]/', $ar, $a );
		preg_match_all( '/%(\d+\$)?[sd]/', $tr, $t );
		$s1 = $a[0];
		$s2 = $t[0];
		sort( $s1 );
		sort( $s2 );
		if ( '' === trim( $tr ) || $s1 !== $s2 || preg_match( '/[\x{0600}-\x{06FF}]/u', $tr ) ) {
			$bad[] = $ar;
		}
	}
	ps_assert( ! $bad, "$l : traductions non vides, sans arabe, mêmes %s / %d que l'original", implode( ' | ', array_slice( $bad, 0, 10 ) ) );
}

ps_test_section( 'Langues : traduction côté serveur' );

$mk = function ( $login, $role, $employee = null, $lang = null ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => $login, 'user_email' => $login . '@example.com' ) );
	if ( $employee ) {
		update_user_meta( $id, 'ps_employee_id', ps_test_lid( 'employee', $employee ) );
	}
	if ( $lang ) {
		update_user_meta( $id, 'ps_lang', $lang );
	}
	return $id;
};
$u_adm = $mk( 't9_adm', 'ps_admin' );
wp_set_current_user( $u_adm );
ps_assert_same( 'ar', ps_lang(), 'sans préférence : العربية (réglage par défaut)' );
ps_assert_same( 'rtl', ps_dir(), 'العربية → rtl' );
ps_assert_same( 'حفظ', ps__( 'حفظ' ), 'en arabe le texte est rendu tel quel' );

update_user_meta( $u_adm, 'ps_lang', 'en' );
ps_assert_same( 'Save', ps__( 'حفظ' ), 'compte en English → « Save »' );
ps_assert_same( 'ltr', ps_dir(), 'English → ltr' );
ps_assert_same( 'lang="en" dir="ltr"', ps_html_lang_attrs(), 'balise <html lang="en" dir="ltr">' );
ps_assert_same( ' Save ', ps__( ' حفظ ' ), 'espaces autour du texte conservés' );
ps_assert_same( 'نص غير موجود في القاموس', ps__( 'نص غير موجود في القاموس' ), 'texte sans traduction → arabe (jamais vide)' );
ps_assert_same( 'Too many attempts. Try again in 5 minutes.', sprintf( ps__( 'محاولات كثيرة. حاول مرة أخرى بعد %d دقيقة.' ), 5 ), 'textes avec nombre (sprintf)' );
ps_assert_same( 'Enregistrer', ps_with_lang( 'fr', fn() => ps__( 'حفظ' ) ), 'ps_with_lang( fr ) → « Enregistrer »' );
ps_assert_same( 'Save', ps__( 'حفظ' ), '… puis retour à la langue du compte' );
$cfg = ps_client_config();
ps_assert( 'en' === $cfg['i18n']['lang'] && 'ltr' === $cfg['i18n']['dir'] && 'Save' === ( (array) $cfg['i18n']['dict'] )['حفظ'], 'configuration du navigateur : langue, sens, dictionnaire anglais' );
ps_assert_same( array( 'ar', 'en', 'fr' ), array_keys( $cfg['i18n']['languages'] ), 'menu des langues : العربية, English, Français' );

ps_test_section( 'Langues : préférence du compte (API)' );

update_user_meta( $u_adm, 'ps_lang', 'ar' );
ps_assert_same( 200, ps_test_json( 'POST', '/me/prefs', array( 'lang' => 'fr' ) )->get_status(), 'POST /me/prefs { lang: fr }' );
ps_assert_same( 'fr', get_user_meta( $u_adm, 'ps_lang', true ), 'préférence enregistrée sur le compte' );
ps_test_json( 'POST', '/me/prefs', array( 'lang' => 'xx' ) );
ps_assert_same( 'fr', get_user_meta( $u_adm, 'ps_lang', true ), 'langue inconnue ignorée' );
ps_assert_same( 'Non autorisé', ps__( 'غير مسموح' ), 'les messages (API, erreurs) suivent la langue du compte' );
update_user_meta( $u_adm, 'ps_lang', 'ar' );

wp_set_current_user( $u_adm );
ps_assert_same( 422, ps_test_json( 'PUT', '/settings', array( 'default_lang' => 'de' ) )->get_status(), 'الإعدادات : langue par défaut inconnue refusée' );
ps_assert_same( 200, ps_test_json( 'PUT', '/settings', array( 'default_lang' => 'en' ) )->get_status(), 'الإعدادات : langue par défaut = English' );
ps_assert( 'en' === ps_default_lang() && 'en' === ps_user_lang( $mk( 't9_nopref', 'ps_employee' ) ), 'un compte sans préférence suit la langue par défaut du site' );
ps_test_json( 'PUT', '/settings', array( 'default_lang' => 'ar' ) );

ps_test_section( 'Langues : notifications dans la langue de chaque destinataire' );

ps_notify_flush();
$u_resp = $mk( 't9_resp', 'ps_manager', 'وليد جدير', 'fr' );
$u_fol  = $mk( 't9_fol', 'ps_employee', 'أمير بن مبروك', 'ar' );
$r      = ps_test_json(
	'POST',
	'/projects',
	ps_test_payload(
		array(
			'name'             => 'مشروع اللغات',
			'responsibleEmpId' => ps_test_lid( 'employee', 'وليد جدير' ),
			'followerEmpId'    => ps_test_lid( 'employee', 'أمير بن مبروك' ),
			'items'            => array( array( 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => '1', 'employeeId' => ps_test_lid( 'employee', 'وليد جدير' ), 'dueDate' => '2026-10-25' ) ),
		)
	)
);
ps_assert_same( 201, $r->get_status(), 'projet créé (الإدارة, en arabe)' );
ps_notify_flush();
$last = fn( $uid ) => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'notifications' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $uid ), ARRAY_A );
ps_assert_same( 'Nouveau projet', $last( $u_resp )['body'], 'المسؤول (Français) : « Nouveau projet »' );
ps_assert_same( 'مشروع جديد', $last( $u_fol )['body'], 'المتابع (العربية) : « مشروع جديد »' );

$item_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ps_table( 'items' ) . ' WHERE project_id = %d', $r->get_data()['id'] ) );
ps_test_json( 'PUT', '/items/' . $item_id, array( 'offerStatusId' => ps_list_id_by_code( 'offer_status', 'sent' ), 'projectSentDate' => '2026-10-02' ) );
ps_notify_flush();
$fr = $last( $u_resp )['body'];
$ar = $last( $u_fol )['body'];
ps_assert( str_contains( $fr, "Statut de l'offre" ) && str_contains( $fr, 'Envoyé' ) && ! str_contains( $fr, 'مرسل' ), 'changement de statut en français', $fr );
ps_assert( str_contains( $ar, 'حالة العرض' ) && str_contains( $ar, 'مرسل' ), '… et en arabe pour l\'autre destinataire (même sauvegarde)', $ar );

update_user_meta( $u_resp, 'ps_instant_email', 1 );
$mails = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );
ps_test_json( 'PUT', '/items/' . $item_id, array( 'offerStatusId' => ps_list_id_by_code( 'offer_status', 'accepted' ), 'projectSentDate' => '2026-10-02' ) );
ps_notify_flush();
$m = end( $mails );
ps_assert( $m && str_contains( $m['message'], 'dir="ltr"' ) && str_contains( $m['message'], 'Ouvrir le projet' ), 'e-mail immédiat en français, sens ltr', $m ? mb_substr( $m['message'], 0, 300 ) : 'aucun e-mail' );
ps_assert_same( 'ar', ps_lang(), 'après les envois, la requête revient à la langue de l\'auteur' );
wp_set_current_user( 0 );
