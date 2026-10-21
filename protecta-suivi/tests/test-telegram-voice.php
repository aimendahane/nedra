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
			$params['filename'] = preg_match( '/filename="([^"]+)"\r\n[^\r]*\r\n\r\n(.{4})/s', $args['body'], $fn ) ? $fn[1] . '|' . $fn[2] : '';
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

// 1.3.1 : enregistrement WebM de Chrome / Android (vrai fichier) → copie Ogg/Opus pour Telegram.
$webm = wp_tempnam( 'voice' );
copy( __DIR__ . '/fixtures/voice-chrome.webm', $webm );
$tg['calls'] = array();
$r           = $voice_req( array( 'employeeId' => ps_test_lid( 'employee', 'مجدي ميلادي' ), 'title' => 'صوت كروم', 'voiceSeconds' => 3 ), array( 'name' => 'voice.webm', 'tmp_name' => $webm, 'size' => filesize( $webm ), 'error' => 0 ) );
ps_assert_same( 200, $r->get_status(), 'message vocal WebM (Chrome / Android) accepté' );
$row  = ps_task_row( $r->get_data()['id'] );
$path = ps_storage_dir( 'files/' . $row['voice_path'] );
$oggc = ps_voice_ogg_path( $path );
ps_assert( 'audio/webm' === $row['voice_mime'] && str_ends_with( $path, '.webm' ) && is_file( $oggc ) && 'OggS' === substr( (string) file_get_contents( $oggc ), 0, 4 ), 'original WebM conservé (navigateur) + copie Ogg créée à côté' );
$vc = $sent( 'sendVoice', 777 );
ps_assert( 1 === count( $vc ) && str_starts_with( $vc[0]['params']['filename'] ?? '', 'voice.ogg|OggS' ) && ! $sent( 'sendAudio', 777 ), 'Telegram : vrai message vocal en Ogg/Opus (plus de WebM muet)', wp_json_encode( $vc ) );
$ffmpeg = trim( (string) shell_exec( 'command -v ffmpeg 2>/dev/null' ) );
if ( $ffmpeg ) {
	$probe = shell_exec( escapeshellarg( $ffmpeg ) . ' -v error -i ' . escapeshellarg( $oggc ) . ' -af volumedetect -f null - 2>&1' );
	ps_assert( ! preg_match( '/error|invalid|crc/i', (string) $probe ) && preg_match( '/mean_volume: (-?[\d.]+)/', (string) shell_exec( escapeshellarg( $ffmpeg ) . ' -i ' . escapeshellarg( $oggc ) . ' -af volumedetect -f null - 2>&1' ), $mv ) && (float) $mv[1] > -60, 'copie Ogg lisible sans erreur et non muette (ffmpeg)', (string) $probe );
	ps_assert( preg_match( '/Duration: 00:00:0(\d)\.(\d+)/', (string) shell_exec( 'ffprobe -hide_banner ' . escapeshellarg( $oggc ) . ' 2>&1' ), $du ) && (int) $du[1] >= 3, 'durée conservée (≈ 3,5 s)' );
}
ps_assert( ! ps_voice_webm_to_ogg( $ogg, wp_tempnam( 'x' ) ), 'un fichier qui n\'est pas du WebM n\'est pas converti' );

// 1.3.2 : enregistrement muet (micro coupé) → refusé avec un message clair, rien n'est envoyé.
ps_assert( false === ps_voice_is_silent( __DIR__ . '/fixtures/voice-chrome.webm' ), 'détection du silence : enregistrement avec voix → « contient du son »' );
ps_assert( true === ps_voice_is_silent( __DIR__ . '/fixtures/voice-silent.webm' ) && true === ps_voice_is_silent( __DIR__ . '/fixtures/voice-silent.ogg' ), 'détection du silence : WebM et Ogg muets → « muet »' );
ps_assert( null === ps_voice_is_silent( $ogg ), 'format non vérifiable → aucune décision (jamais de refus à tort)' );
foreach ( array( 'webm', 'ogg' ) as $e ) {
	$tmp = wp_tempnam( 'voice' );
	copy( __DIR__ . '/fixtures/voice-silent.' . $e, $tmp );
	$tg['calls'] = array();
	$before      = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'tasks' ) );
	$r           = $voice_req( array( 'employeeId' => ps_test_lid( 'employee', 'مجدي ميلادي' ), 'title' => 'صامت', 'voiceSeconds' => 3 ), array( 'name' => 'voice.' . $e, 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ) );
	ps_assert( 422 === $r->get_status() && str_contains( $r->get_data()['message'] ?? '', 'بدون صوت' ), "message vocal muet ($e) → refusé : « التسجيل بدون صوت… »", wp_json_encode( $r->get_data(), JSON_UNESCAPED_UNICODE ) );
	ps_assert( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'tasks' ) ) && ! $tg['calls'], "… aucune tâche créée, rien envoyé sur Telegram ($e)" );
}

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
