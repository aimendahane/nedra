<?php
/**
 * Version 1.2.0 — Telegram (réglage, liaison, envois) et message vocal des tâches.
 * L'API Telegram est SIMULÉE (filtre pre_http_request) : aucun message ne sort de la machine.
 */

global $wpdb;

ps_notify_flush();
$tg = array( 'calls' => array(), 'updates' => array(), 'fail' => array() );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$tg ) {
		if ( ! preg_match( '#^https://api\.telegram\.org/bot([^/]+)/(\w+)$#', $url, $m ) ) {
			return $pre;
		}
		$method = $m[2];
		$params = str_contains( (string) ( $args['headers']['Content-Type'] ?? '' ), 'json' ) ? json_decode( $args['body'], true ) : array( 'multipart' => strlen( $args['body'] ) );
		if ( str_contains( (string) ( $args['headers']['Content-Type'] ?? '' ), 'multipart' ) ) {
			preg_match( '/name="chat_id"\r\n\r\n([^\r]+)/', $args['body'], $c );
			$params['chat_id'] = $c[1] ?? '';
			$params['caption'] = preg_match( '/name="caption"\r\n\r\n([^\r]*)/', $args['body'], $cap ) ? $cap[1] : '';
		}
		$tg['calls'][] = array( 'token' => $m[1], 'method' => $method, 'params' => $params );
		$ok = static fn( $result ) => array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'ok' => true, 'result' => $result ) ), 'headers' => array(), 'cookies' => array() );
		if ( isset( $tg['fail'][ $method ] ) ) {
			return array( 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'body' => wp_json_encode( array( 'ok' => false, 'description' => $tg['fail'][ $method ] ) ), 'headers' => array(), 'cookies' => array() );
		}
		if ( 'getMe' === $method ) {
			return str_starts_with( $m[1], '999' ) ? array( 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'body' => '{"ok":false,"description":"Unauthorized"}', 'headers' => array(), 'cookies' => array() ) : $ok( array( 'id' => 1, 'is_bot' => true, 'username' => 'ProtectaTestBot' ) );
		}
		if ( 'getUpdates' === $method ) {
			$u              = $tg['updates'];
			$tg['updates'] = array();
			return $ok( $u );
		}
		return $ok( array( 'message_id' => count( $tg['calls'] ) ) );
	},
	10,
	3
);
$sent      = function ( $method, $chat = null ) use ( &$tg ) { // par référence : voit les appels faits ensuite
	return array_values( array_filter( $tg['calls'], fn( $c ) => $c['method'] === $method && ( null === $chat || (string) ( $c['params']['chat_id'] ?? '' ) === (string) $chat ) ) );
};
$update_id = 1000;
$say       = function ( $chat, $text ) use ( &$tg, &$update_id ) {
	$tg['updates'][] = array( 'update_id' => ++$update_id, 'message' => array( 'chat' => array( 'id' => $chat, 'type' => 'private' ), 'text' => $text ) );
};

$mk    = function ( $login, $role, $employee = null ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => $login, 'user_email' => $login . '@example.com' ) );
	if ( $employee ) {
		update_user_meta( $id, 'ps_employee_id', ps_test_lid( 'employee', $employee ) );
	}
	return $id;
};
$u_adm  = $mk( 't8_adm', 'ps_admin' );
$u_emp  = $mk( 't8_emp', 'ps_employee', 'مجدي ميلادي' );
$u_resp = $mk( 't8_resp', 'ps_manager', 'أميرة السعيداني' );
$u_out  = $mk( 't8_out', 'ps_employee', 'هاشم الخراز' );

ps_test_section( 'Telegram : réglage du bot (الإدارة)' );

wp_set_current_user( $u_adm );
$r = ps_test_json( 'PUT', '/settings', array( 'telegram_token' => 'pas-un-token' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['telegram_token'] ), 'token mal formé → refusé' );
$r = ps_test_json( 'PUT', '/settings', array( 'telegram_token' => '999999:' . str_repeat( 'A', 35 ) ) );
ps_assert( 422 === $r->get_status() && str_contains( $r->get_data()['data']['errors']['telegram_token'] ?? '', 'Unauthorized' ), 'token refusé par Telegram → message de Telegram affiché' );
$r = ps_test_json( 'PUT', '/settings', array( 'telegram_enabled' => true ) );
ps_assert( 422 === $r->get_status(), 'activer sans token → refusé' );
$token = '123456789:' . str_repeat( 'AbC', 12 );
$r     = ps_test_json( 'PUT', '/settings', array( 'telegram_token' => $token, 'telegram_enabled' => true ) );
ps_assert( 200 === $r->get_status() && 'ProtectaTestBot' === $r->get_data()['telegramBot'] && true === $r->get_data()['telegramTokenSet'], 'token valide → vérifié (getMe), nom du bot retenu' );
ps_assert( ! str_contains( wp_json_encode( $r->get_data() ), 'AbCAbC' ), 'token jamais renvoyé au navigateur' );
ps_assert( ! str_contains( wp_json_encode( ps_backup_build() ), 'AbCAbC' ), 'token absent de la sauvegarde JSON' );
ps_assert( ps_tg_enabled(), 'Telegram actif' );

ps_test_section( 'Telegram : liaison d\'un compte' );

wp_set_current_user( $u_emp );
$r = ps_test_json( 'GET', '/me/telegram' )->get_data();
ps_assert( true === $r['enabled'] && false === $r['linked'] && 'ProtectaTestBot' === $r['bot'], 'employé : Telegram disponible, pas encore lié' );
$url = ps_test_json( 'POST', '/me/telegram/link' )->get_data()['url'] ?? '';
ps_assert( (bool) preg_match( '#^https://t\.me/ProtectaTestBot\?start=([a-f0-9]{24})$#', $url, $code ), 'lien t.me/<bot>?start=<code aléatoire>', $url );
$say( 555, '/start ' . str_repeat( 'a', 24 ) );
ps_assert( false === ps_test_json( 'POST', '/me/telegram/check' )->get_data()['linked'], 'code inconnu → non lié' );
ps_assert( 1 === count( $sent( 'sendMessage', 555 ) ), 'message « رابط غير صالح » envoyé à l\'inconnu' );
$say( 777, '/start ' . $code[1] );
ps_assert( true === ps_test_json( 'POST', '/me/telegram/check' )->get_data()['linked'], 'bon code → compte lié' );
ps_assert_same( '777', ps_tg_chat_id( $u_emp ), 'identifiant de discussion enregistré' );
ps_assert( str_contains( $sent( 'sendMessage', 777 )[0]['params']['text'] ?? '', 't8_emp' ), 'message de bienvenue avec le nom du compte' );
$say( 888, '/start ' . $code[1] );
ps_tg_poll();
ps_assert_same( '777', ps_tg_chat_id( $u_emp ), 'code à usage unique : réutilisé par quelqu\'un d\'autre → refusé' );
// Code expiré.
wp_set_current_user( $u_resp );
ps_test_json( 'POST', '/me/telegram/link' );
update_user_meta( $u_resp, 'ps_tg_code', explode( '|', get_user_meta( $u_resp, 'ps_tg_code', true ) )[0] . '|' . ( time() - 10 ) );
$say( 999, '/start ' . explode( '|', get_user_meta( $u_resp, 'ps_tg_code', true ) )[0] );
ps_tg_poll();
ps_assert_same( '', ps_tg_chat_id( $u_resp ), 'code expiré (2 h) → refusé' );
$url = ps_test_json( 'POST', '/me/telegram/link' )->get_data()['url'];
preg_match( '#start=([a-f0-9]{24})#', $url, $c2 );
$say( 999, '/start ' . $c2[1] );
ps_cron_run();
ps_assert_same( '999', ps_tg_chat_id( $u_resp ), 'le Cron termine aussi les liaisons en attente' );

ps_test_section( 'Telegram : envois (notifications, tâches, résumé)' );

$tg['calls'] = array();
wp_set_current_user( $u_adm );
$p = ps_test_json(
	'POST',
	'/projects',
	ps_test_payload(
		array(
			'name'             => 'مشروع تيليغرام',
			'responsibleEmpId' => ps_test_lid( 'employee', 'أميرة السعيداني' ),
			'followerEmpId'    => ps_test_lid( 'employee', 'مجدي ميلادي' ),
		)
	)
)->get_data();
ps_notify_flush();
$m = $sent( 'sendMessage', 777 )[0]['params'] ?? array();
ps_assert( str_contains( $m['text'] ?? '', $p['projectNo'] ) && str_contains( $m['text'] ?? '', 'مشروع جديد' ) && 'HTML' === ( $m['parse_mode'] ?? '' ), 'notification 🔔 reçue sur Telegram (projet, changement)' );
ps_assert( 1 === count( $sent( 'sendMessage', 999 ) ), 'المسؤول lié reçoit aussi la notification' );
ps_assert( ! isset( $m['reply_markup'] ), 'pas de bouton vers une adresse locale (Telegram exige https)' );
ps_assert( str_contains( ps_tg_notification_html( '<b>x</b>', array( 'a < b' ), null ), '&lt;b&gt;x' ), 'textes échappés (HTML Telegram)' );

// Tâche avec message vocal.
$ogg = wp_tempnam( 'voice' );
file_put_contents( $ogg, "OggS\0\x02" . str_repeat( "\0", 20 ) . "\x01\x1e\x01vorbis" . str_repeat( "\0", 400 ) );
$voice_req = function ( array $fields, array $file ) {
	$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/free-tasks' );
	$req->set_body_params( $fields );
	$req->set_file_params( array( 'voice' => $file ) );
	return rest_get_server()->dispatch( $req );
};
$tg['calls'] = array();
$r           = $voice_req( array( 'employeeId' => ps_test_lid( 'employee', 'مجدي ميلادي' ), 'title' => '', 'voiceSeconds' => 42 ), array( 'name' => 'voice.ogg', 'tmp_name' => $ogg, 'size' => filesize( $ogg ), 'error' => 0 ) );
$t           = $r->get_data();
ps_assert( 200 === $r->get_status() && true === $t['hasVoice'] && 42 === $t['voiceSeconds'], 'tâche avec message vocal créée (durée retenue)', wp_json_encode( $t ) );
ps_assert( str_contains( $t['title'], 'رسالة صوتية' ), 'message vocal seul → titre par défaut « 🎙 رسالة صوتية »' );
$row = ps_task_row( $t['id'] );
ps_assert( str_starts_with( $row['voice_path'], 'voice/' ) && 'audio/ogg' === $row['voice_mime'] && is_file( ps_storage_dir( 'files/' . $row['voice_path'] ) ), 'fichier rangé dans le dossier protégé files/voice/' );
ps_assert( 1 === count( $sent( 'sendMessage', 777 ) ) && 1 === count( $sent( 'sendVoice', 777 ) ), 'Telegram : texte de la tâche + message vocal (sendVoice)' );
$tg['fail'] = array( 'sendVoice' => 'Bad Request: wrong file' );
ps_task_telegram( get_userdata( $u_emp ), $row, get_userdata( $u_adm ) );
ps_assert( 1 === count( $sent( 'sendDocument', 777 ) ), 'si sendVoice échoue → envoi comme document' );
$tg['fail'] = array();

$txt = wp_tempnam( 'notaudio' );
file_put_contents( $txt, 'hello world, not audio' );
$r = $voice_req( array( 'employeeId' => ps_test_lid( 'employee', 'مجدي ميلادي' ), 'title' => 'x' ), array( 'name' => 'voice.ogg', 'tmp_name' => $txt, 'size' => filesize( $txt ), 'error' => 0 ) );
ps_assert_same( 400, $r->get_status(), 'fichier qui n\'est pas de l\'audio → refusé (type vérifié sur le contenu)' );
$r = $voice_req( array( 'employeeId' => ps_test_lid( 'employee', 'مجدي ميلادي' ), 'title' => 'x' ), array( 'name' => 'voice.ogg', 'tmp_name' => $ogg, 'size' => PS_VOICE_MAX_BYTES + 1, 'error' => 0 ) );
ps_assert_same( 400, $r->get_status(), 'message trop long (taille) → refusé' );
wp_set_current_user( $u_emp );
ps_assert_same( 403, $voice_req( array( 'employeeId' => ps_test_lid( 'employee', 'هاشم الخراز' ), 'title' => 'x' ), array( 'name' => 'voice.ogg', 'tmp_name' => $ogg, 'size' => filesize( $ogg ), 'error' => 0 ) )->get_status(), 'موظف : ne peut pas envoyer de message vocal (403)' );
wp_set_current_user( $u_out );
ps_assert_same( 404, ps_test_json( 'GET', '/free-tasks/' . $t['id'] . '/voice' )->get_status(), 'un autre employé ne peut pas écouter le message (404)' );

// Résumé du matin.
$tg['calls'] = array();
add_filter( 'pre_wp_mail', '__return_true' );
ps_digest_send_to_user( get_userdata( $u_emp ), false );
$d = $sent( 'sendMessage', 777 )[0]['params']['text'] ?? '';
ps_assert( str_contains( $d, 'مهامك اليوم' ) && str_contains( $d, 'مهام مُسندة' ), 'résumé du matin envoyé aussi sur Telegram' );
remove_filter( 'pre_wp_mail', '__return_true' );

ps_test_section( 'Telegram : arrêt' );

$tg['fail'] = array( 'sendMessage' => 'Forbidden: bot was blocked by the user' );
ps_tg_send_user( get_userdata( $u_resp ), 'x' );
ps_assert_same( '', ps_tg_chat_id( $u_resp ), 'bot bloqué par l\'utilisateur → compte délié automatiquement' );
$tg['fail'] = array();
$say( 777, '/stop' );
ps_tg_poll();
ps_assert_same( '', ps_tg_chat_id( $u_emp ), '« /stop » dans Telegram → délié' );
wp_set_current_user( $u_adm );
ps_assert_same( 409, ps_test_json( 'POST', '/settings/telegram-test' )->get_status(), 'message d\'essai : compte non lié → message clair' );
ps_test_json( 'PUT', '/settings', array( 'telegram_enabled' => false ) );
wp_set_current_user( $u_emp );
ps_assert_same( 409, ps_test_json( 'POST', '/me/telegram/link' )->get_status(), 'Telegram désactivé → liaison impossible' );

remove_all_filters( 'pre_http_request' );
wp_set_current_user( 0 );
