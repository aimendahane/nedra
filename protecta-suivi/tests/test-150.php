<?php
/**
 * Version 1.5.0 — nom du projet d'abord + recherche par numéro (ancien et nouveau) ; fichiers joints (tout type sauf
 * exécutables, 20 Mo réglables, nom d'affichage, droits de retrait et de téléchargement) ; stockage Google Drive.
 * Google est SIMULÉ (filtre pre_http_request) : aucun appel réseau.
 */

global $wpdb;

ps_test_section( '1.5.0 : nom du projet d\'abord, recherche par numéro' );

ps_test_reset_data();
$adm  = ps_test_user( 't15_adm', 'ps_admin' );
$resp = ps_test_user( 't15_resp', 'ps_manager', 'محمد حسين' );
$e1   = ps_test_user( 't15_e1', 'ps_employee', 'صفاء' );
$e2   = ps_test_user( 't15_e2', 'ps_employee', 'وفاء حامدي' );
wp_set_current_user( $adm );
$p = ps_test_json(
	'POST',
	'/projects',
	ps_test_payload(
		array(
			'name'             => 'مستشفى الأمل الجديد',
			'responsibleEmpId' => ps_test_lid( 'employee', 'محمد حسين' ),
			'items'            => array( array( 'itemTypeId' => ps_test_lid( 'item_type', 'UPS' ), 'quantity' => '1', 'employeeId' => ps_test_lid( 'employee', 'صفاء' ), 'dueDate' => '2026-10-25' ) ),
		)
	)
)->get_data();
$pid = (int) $p['id'];
// Projet importé dont l'ancien numéro (P777) diffère du numéro actuel.
$wpdb->update( ps_table( 'projects' ), array( 'legacy_id' => 'xlsx:P777' ), array( 'id' => $pid ) );
$find = fn( $q ) => array_column( ps_test_json( 'GET', '/projects?q=' . rawurlencode( $q ) )->get_data()['rows'], 'id' );
ps_assert( in_array( $pid, $find( 'الأمل الجديد' ), true ) && in_array( $pid, $find( $p['projectNo'] ), true ) && in_array( $pid, $find( 'p777' ), true ), 'recherche : par le nom, par le numéro actuel (PRJ-…) et par l\'ancien numéro (P777)' );
$row = array_values( array_filter( ps_test_json( 'GET', '/projects?q=P777' )->get_data()['rows'], fn( $r ) => $r['id'] === $pid ) )[0];
ps_assert( 'P777' === $row['oldNo'] && 'P777' === ps_test_json( 'GET', '/projects/' . $pid )->get_data()['oldNo'], 'API : ancien numéro fourni (affiché sous le nom)' );
$items = ps_test_json( 'GET', '/items?q=P777' )->get_data();
ps_assert( 1 === count( $items['rows'] ?? $items ), 'Suivi Offres : recherche par l\'ancien numéro aussi', wp_json_encode( $items ) );

ps_test_section( '1.5.0 : fichiers joints — types, taille, nom d\'affichage' );

$tmp  = wp_tempnam( 'ps' );
$up   = function ( string $name, string $content, int $user, ?string $display = null ) use ( $pid, $tmp ) {
	file_put_contents( $tmp, $content );
	return ps_file_store( $pid, array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $content ), 'error' => 0 ), $user, $display );
};
$http = function ( string $name, string $content ) use ( $pid, $tmp ) {
	file_put_contents( $tmp, $content );
	$req = new WP_REST_Request( 'POST', '/' . PS_REST_NS . '/projects/' . $pid . '/files' );
	$req->set_file_params( array( 'file' => array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $content ), 'error' => 0 ) ) );
	return rest_get_server()->dispatch( $req );
};
wp_set_current_user( $e1 );
$r = $http( 'shell.php', '<?php system($_GET["c"]);' );
ps_assert( 400 === $r->get_status() && str_contains( $r->get_data()['message'], 'غير مسموح' ) && str_contains( $r->get_data()['message'], '.php' ), 'envoi d\'un .php → refusé avec un message clair en arabe', wp_json_encode( $r->get_data(), JSON_UNESCAPED_UNICODE ) );
foreach ( array( 'photo.php.jpg' => "\xFF\xD8\xFF\xE0JFIF", 'x.phtml' => 'a', 'run.exe' => 'MZ..', 'a.bat' => 'echo', 'b.sh' => 'ls', 'c.js' => 'alert(1)', 'd.html' => '<b>x</b>', 'e.svg' => '<svg/>', '.htaccess' => 'x' ) as $n => $c ) {
	ps_assert( is_wp_error( $up( $n, $c, $e1 ) ), "« $n » refusé" );
}
ps_assert( is_wp_error( $up( 'note.txt', "hello\n<?php echo 1; ?>", $e1 ) ) && is_wp_error( $up( 'plan.pdf', "MZ\x90\x00 programme", $e1 ) ), 'contenu exécutable (PHP, programme Windows) refusé même avec une extension anodine' );
$ok = array();
foreach ( array( 'plans.zip' => "PK\x03\x04" . str_repeat( 'z', 50 ), 'dessin.dwg' => 'AC1032' . str_repeat( "\0", 20 ), 'devis.pdf' => "%PDF-1.4\n%%EOF", 'notes.txt' => 'نص عادي' ) as $n => $c ) {
	$ok[ $n ] = $up( $n, $c, $e1, 'devis.pdf' === $n ? 'عرض سعر المورد' : null );
	ps_assert( is_int( $ok[ $n ] ), "« $n » accepté (tout type de fichier non exécutable)", is_wp_error( $ok[ $n ] ) ? $ok[ $n ]->get_error_message() : '' );
}
$f = ps_file_get( $ok['devis.pdf'] );
ps_assert( 'عرض سعر المورد' === $f['display_name'] && 'devis.pdf' === $f['original_name'] && (int) $f['uploaded_by'] === $e1 && $f['created_at'], 'nom d\'affichage saisi + nom d\'origine, auteur et date gardés' );
ps_assert( preg_match( '#^\d{4}/\d{2}/[0-9a-f]{32}\.bin$#', $f['stored_name'] ) && ! str_contains( $f['stored_name'], 'devis' ), 'nom sur le disque = identifiant aléatoire « .bin » (jamais le nom saisi)' );
ps_assert( 'عرض سعر المورد.pdf' === ps_file_download_name( $f ), 'téléchargement nommé « nom d\'affichage + .pdf »' );
ps_assert( str_starts_with( realpath( ps_storage_dir( 'files/' . $f['stored_name'] ) ), realpath( wp_upload_dir()['basedir'] . '/protecta-suivi/files' ) ) && file_exists( ps_storage_dir( 'files/.htaccess' ) ), 'stockage dans le dossier protégé (pas la Médiathèque)' );
ps_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ), 'aucun fichier dans la Médiathèque WordPress' );
ps_update_settings( array( 'file_max_mb' => 1 ) );
$big = $up( 'gros.zip', "PK\x03\x04" . str_repeat( 'x', MB_IN_BYTES + 10 ), $e1 );
ps_assert( is_wp_error( $big ) && 'ps_upload_size' === $big->get_error_code() && str_contains( $big->get_error_message(), 'MB' ), 'au-delà du réglage « الحجم الأقصى » (ici 1 Mo) → refusé', is_wp_error( $big ) ? $big->get_error_message() : '' );
ps_update_settings( array( 'file_max_mb' => 20 ) );
ps_assert( 20 * MB_IN_BYTES === ps_file_max_bytes() || ps_file_max_bytes() === (int) wp_max_upload_size(), '20 Mo par défaut (ou la limite de l\'hébergeur si elle est plus basse)' );

ps_test_section( '1.5.0 : fichiers joints — retrait et téléchargement' );

$del = fn( $id ) => rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/' . PS_REST_NS . '/files/' . $id ) )->get_status();
$get = fn( $id ) => rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . PS_REST_NS . '/files/' . $id ) )->get_status();
wp_set_current_user( $e2 );
ps_assert_same( 403, $del( $ok['plans.zip'] ), 'un autre موظف ne retire pas le fichier' );
wp_set_current_user( $e1 );
ps_assert_same( 200, $del( $ok['notes.txt'] ), 'l\'auteur retire son fichier pendant 24 h' );
$wpdb->update( ps_table( 'files' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => $ok['dessin.dwg'] ) );
ps_assert_same( 403, $del( $ok['dessin.dwg'] ), 'après 24 h, l\'auteur موظف ne peut plus retirer' );
wp_set_current_user( $resp );
ps_assert_same( 200, $del( $ok['dessin.dwg'] ), 'مسؤول المشروع retire le fichier' );
wp_set_current_user( $adm );
ps_assert_same( 200, $del( $ok['plans.zip'] ), 'الإدارة retire le fichier' );
ps_assert( file_exists( ps_storage_dir( 'files/' . ps_file_get( $ok['plans.zip'] )['stored_name'] ) ), 'retiré = corbeille : le fichier reste sur le disque (aucune perte)' );

$pdf = $ok['devis.pdf'];
wp_set_current_user( 0 );
ps_assert_same( 401, $get( $pdf ), 'téléchargement sans connexion → refusé (401)' );
$sub = wp_insert_user( array( 'user_login' => 't15_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
ps_assert_same( 403, $get( $pdf ), 'téléchargement par un compte WordPress sans accès à l\'application → refusé (403)' );
update_user_meta( $e2, 'ps_disabled', 1 );
wp_set_current_user( $e2 );
ps_assert_same( 403, $get( $pdf ), 'téléchargement par un compte désactivé → refusé (403)' );
delete_user_meta( $e2, 'ps_disabled' );
ps_assert( ps_file_can_download( ps_file_get( $pdf ), $e2 ), 'un employé de l\'équipe peut télécharger (tous voient tous les projets, décision n°1)' );
$wpdb->update( ps_table( 'projects' ), array( 'deleted_at' => ps_now() ), array( 'id' => $pid ) );
ps_assert( ! ps_file_can_download( ps_file_get( $pdf ), $e2 ) && ps_file_can_download( ps_file_get( $pdf ), $adm ), 'projet à la corbeille : fichiers réservés à la الإدارة' );
$wpdb->update( ps_table( 'projects' ), array( 'deleted_at' => null ), array( 'id' => $pid ) );

/* ====================================================================== */
ps_test_section( '1.5.0 : Google Drive (simulé) — liaison Gmail (OAuth)' );

$gd = array( 'files' => array(), 'calls' => array(), 'down' => false, 'bad_md5' => false, 'next' => 1, 'pubkey' => null, 'can_add' => true );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$gd ) {
		if ( ! preg_match( '#^https://(oauth2|www)\.googleapis\.com/#', $url ) ) {
			return $pre;
		}
		$method          = $args['method'] ?? 'GET';
		$gd['calls'][]   = $method . ' ' . $url;
		$ok              = fn( $data, $headers = array() ) => array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $data ), 'headers' => $headers, 'cookies' => array() );
		if ( $gd['down'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		}
		if ( str_starts_with( $url, 'https://oauth2.googleapis.com/token' ) ) {
			$b = is_array( $args['body'] ) ? $args['body'] : array();
			if ( 'urn:ietf:params:oauth:grant-type:jwt-bearer' === ( $b['grant_type'] ?? '' ) ) {
				$parts = explode( '.', $b['assertion'] );
				$sig   = base64_decode( strtr( $parts[2], '-_', '+/' ) );
				$valid = 1 === openssl_verify( $parts[0] . '.' . $parts[1], $sig, $gd['pubkey'], OPENSSL_ALGO_SHA256 );
				return $valid ? $ok( array( 'access_token' => 'AT-sa', 'expires_in' => 3600 ) ) : array( 'response' => array( 'code' => 400 ), 'body' => '{"error":"invalid_grant"}', 'headers' => array(), 'cookies' => array() );
			}
			$gd['last_token_body'] = $b;
			return $ok( array( 'access_token' => 'AT-oauth', 'expires_in' => 3600, 'refresh_token' => 'authorization_code' === ( $b['grant_type'] ?? '' ) ? 'RT-123' : null ) );
		}
		if ( str_contains( $url, '/oauth2/v2/userinfo' ) ) {
			return $ok( array( 'email' => 'protecta.files@gmail.com' ) );
		}
		if ( str_contains( $url, 'uploadType=resumable' ) && 'POST' === $method ) {
			$gd['pending_meta'] = json_decode( $args['body'], true );
			return $ok( array(), array( 'location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=U' . $gd['next'] ) );
		}
		if ( str_contains( $url, 'upload_id=' ) && 'PUT' === $method ) {
			$id                 = 'DF' . $gd['next']++;
			$gd['files'][ $id ] = array( 'body' => $args['body'], 'meta' => $gd['pending_meta'] );
			return $ok( array( 'id' => $id, 'md5Checksum' => $gd['bad_md5'] ? str_repeat( '0', 32 ) : md5( $args['body'] ), 'size' => (string) strlen( $args['body'] ) ) );
		}
		if ( preg_match( '#/drive/v3/files/([^/?]+)\?alt=media#', $url, $m ) ) {
			file_put_contents( $args['filename'], $gd['files'][ $m[1] ]['body'] ?? '' );
			return array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array(), 'cookies' => array(), 'filename' => $args['filename'] );
		}
		if ( preg_match( '#/drive/v3/files/([^/?]+)\?#', $url, $m ) && 'GET' === $method ) {
			return $ok( array( 'id' => $m[1], 'name' => 'Shared', 'capabilities' => array( 'canAddChildren' => $gd['can_add'] ) ) );
		}
		if ( str_contains( $url, '/drive/v3/files?' ) && 'POST' === $method ) {
			$meta                 = json_decode( $args['body'], true );
			$id                   = 'FOLDER' . $gd['next']++;
			$gd['folders'][ $id ] = $meta;
			return $ok( array( 'id' => $id ) );
		}
		return array( 'response' => array( 'code' => 404 ), 'body' => '{"error":{"message":"not mocked"}}', 'headers' => array(), 'cookies' => array() );
	},
	10,
	3
);
delete_option( 'ps_gdrive' );
delete_transient( 'ps_gdrive_token' );
wp_set_current_user( $adm );

// Fichier ajouté AVANT la liaison : reste sur le serveur.
wp_set_current_user( $e1 );
$before = $up( 'avant-drive.pdf', "%PDF-1.4 avant\n%%EOF", $e1 );
ps_assert( 'local' === ps_file_get( $before )['storage'] && ! ps_gdrive_connected(), 'Drive non relié : le fichier reste sur le serveur (rien n\'est refusé ni perdu)' );
wp_set_current_user( $e1 );
ps_assert_same( 403, ps_test_json( 'GET', '/gdrive' )->get_status(), 'réglages Drive : réservés à la الإدارة' );

wp_set_current_user( $adm );
$r = ps_test_json( 'POST', '/gdrive/config', array( 'method' => 'oauth', 'clientId' => 'faux', 'clientSecret' => 'x' ) );
ps_assert( 422 === $r->get_status() && isset( $r->get_data()['data']['errors']['clientId'] ), 'Client ID mal copié → erreur claire' );
$r = ps_test_json( 'POST', '/gdrive/config', array( 'method' => 'oauth', 'clientId' => '123456-abc.apps.googleusercontent.com', 'clientSecret' => 'GOCSPX-secret' ) );
$auth = (string) ( $r->get_data()['authUrl'] ?? '' );
ps_assert( str_starts_with( $auth, 'https://accounts.google.com/o/oauth2/v2/auth' ) && str_contains( $auth, '123456-abc.apps.googleusercontent.com' ) && str_contains( $auth, 'access_type=offline' ) && str_contains( $auth, rawurlencode( ps_gdrive_redirect_uri() ) ), 'Gmail : lien d\'accord Google (client, retour vers le site, accès durable)' );
parse_str( (string) wp_parse_url( $auth, PHP_URL_QUERY ), $qs );
ps_assert( is_wp_error( ps_gdrive_oauth_callback( 'CODE', 'mauvais-etat', $adm ) ), 'retour Google avec un « state » inconnu → refusé (protection)' );
$res = ps_gdrive_oauth_callback( 'CODE-1', $qs['state'], $adm );
$c   = ps_gdrive_conf();
ps_assert( true === $res && 'RT-123' === $c['refresh_token'] && 'protecta.files@gmail.com' === $c['account'] && str_starts_with( $c['folder_id'], 'FOLDER' ) && ps_gdrive_connected(), 'accord donné : jeton durable enregistré, compte affiché, dossier « Protecta Suivi » créé', wp_json_encode( $res instanceof WP_Error ? $res->get_error_message() : $c ) );
ps_assert( 'Protecta Suivi' === ( $gd['folders'][ $c['folder_id'] ]['name'] ?? '' ), 'dossier racine « Protecta Suivi »' );
$st = ps_test_json( 'GET', '/gdrive' )->get_data();
ps_assert( ! str_contains( wp_json_encode( $st ), 'GOCSPX' ) && ! str_contains( wp_json_encode( $st ), 'RT-123' ) && true === $st['hasSecret'], 'secrets jamais renvoyés au navigateur' );
ps_assert( ! str_contains( wp_json_encode( ps_backup_build() ), 'RT-123' ), 'secrets absents de la sauvegarde JSON' );

ps_test_section( '1.5.0 : Google Drive (simulé) — envoi, vérification, lecture' );

wp_set_current_user( $e1 );
$content = "%PDF-1.4 dans drive " . str_repeat( 'x', 2000 ) . "\n%%EOF";
$fid     = $up( 'offre-finale.pdf', $content, $e1, 'العرض النهائي' );
$f       = ps_file_get( $fid );
$local   = ps_storage_dir( 'files/' . $f['stored_name'] );
ps_assert( 'drive' === $f['storage'] && $f['drive_id'] && md5( $content ) === $f['md5'], 'nouveau fichier envoyé tout de suite dans Drive (empreinte MD5 vérifiée)' );
ps_assert( ! file_exists( $local ), 'copie du serveur effacée APRÈS vérification (espace libéré)' );
$sent = $gd['files'][ $f['drive_id'] ];
ps_assert( $content === $sent['body'] && 'العرض النهائي.pdf' === $sent['meta']['name'], 'contenu identique dans Drive, nommé avec le nom d\'affichage' );
$proj_folder = $wpdb->get_var( $wpdb->prepare( 'SELECT drive_folder_id FROM ' . ps_table( 'projects' ) . ' WHERE id = %d', $pid ) );
ps_assert( $proj_folder && $sent['meta']['parents'] === array( $proj_folder ) && str_contains( $gd['folders'][ $proj_folder ]['name'], 'مستشفى الأمل الجديد' ) && $gd['folders'][ $proj_folder ]['parents'] === array( $c['folder_id'] ), 'un dossier par projet (« nom — numéro ») dans « Protecta Suivi »' );
$tmpf = ps_gdrive_fetch( $f );
ps_assert( is_string( $tmpf ) && $content === file_get_contents( $tmpf ), 'ouverture depuis le site : le serveur récupère le fichier dans Drive (aucun lien Drive donné)' );
unlink( $tmpf );
ps_assert( 'drive' === ps_file_to_api( $f, $e1 )['storage'], 'liste des fichiers : emplacement « ☁ Drive » indiqué' );

$sync = ps_gdrive_sync();
ps_assert( 0 === $sync['pending'] && 'drive' === ps_file_get( $before )['storage'], 'synchronisation : les fichiers ajoutés avant la liaison partent aussi dans Drive' );
ps_assert( 'drive' === ps_file_get( $ok['plans.zip'] )['storage'], 'fichiers à la corbeille du site aussi conservés dans Drive (jamais perdus)' );

$gd['bad_md5'] = true;
$bad           = $up( 'controle.pdf', "%PDF-1.4 md5\n%%EOF", $e1 );
$bf            = ps_file_get( $bad );
ps_assert( 'local' === $bf['storage'] && file_exists( ps_storage_dir( 'files/' . $bf['stored_name'] ) ) && str_contains( (string) $bf['drive_error'], 'البصمة' ), 'empreinte différente renvoyée par Google → le fichier RESTE sur le serveur (erreur notée)' );
$gd['bad_md5'] = false;
$gd['down']    = true;
$down          = $up( 'panne.pdf', "%PDF-1.4 panne\n%%EOF", $e1 );
ps_assert( is_int( $down ) && 'local' === ps_file_get( $down )['storage'], 'Google indisponible : l\'ajout réussit quand même, le fichier attend sur le serveur' );
$gd['down'] = false;
$sync       = ps_gdrive_sync();
ps_assert( 2 === $sync['sent'] && 0 === $sync['pending'], 'Google revenu : « مزامنة الآن » / Cron envoient les fichiers en attente' );
ps_assert( str_contains( ps_cron_run(), 'drive=' ), 'Cron : envoi Drive inclus dans la tâche quotidienne' );

wp_set_current_user( $adm );
ps_test_json( 'POST', '/gdrive/disconnect' );
$gone = ps_gdrive_fetch( ps_file_get( $fid ) );
ps_assert( is_wp_error( $gone ) && str_contains( $gone->get_error_message(), 'Google Drive' ), 'Drive délié : message clair (les fichiers restent dans Drive)' );

ps_test_section( '1.5.0 : Google Drive (simulé) — compte de service (Workspace)' );

$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $key, $pem );
$gd['pubkey'] = openssl_pkey_get_details( $key )['key'];
$sa           = wp_json_encode( array( 'type' => 'service_account', 'client_email' => 'suivi@protecta.iam.gserviceaccount.com', 'private_key' => $pem ) );
delete_transient( 'ps_gdrive_token' );
$gd['can_add'] = false;
$r             = ps_test_json( 'POST', '/gdrive/config', array( 'method' => 'service', 'saJson' => $sa, 'folderId' => 'https://drive.google.com/drive/folders/SHARED123' ) );
ps_assert( 422 === $r->get_status() && ! ps_gdrive_connected(), 'compte de service sans droit d\'ajout dans le dossier → refusé, rien n\'est changé' );
$gd['can_add'] = true;
$r             = ps_test_json( 'POST', '/gdrive/config', array( 'method' => 'service', 'saJson' => $sa, 'folderId' => 'https://drive.google.com/drive/folders/SHARED123' ) );
$c             = ps_gdrive_conf();
ps_assert( 200 === $r->get_status() && 'service' === $c['method'] && 'SHARED123' === $c['folder_id'] && ps_gdrive_connected(), 'clé JSON signée (RS256 vérifiée par le faux Google) + lien du dossier → relié', wp_json_encode( $r->get_data(), JSON_UNESCAPED_UNICODE ) );
ps_assert( 'suivi@protecta.iam.gserviceaccount.com' === $r->get_data()['status']['account'] && ! str_contains( wp_json_encode( $r->get_data() ), 'PRIVATE KEY' ), 'compte affiché, clé privée jamais renvoyée' );
$wpdb->update( ps_table( 'projects' ), array( 'drive_folder_id' => null ), array( 'id' => $pid ) );
wp_set_current_user( $e1 );
$sf = $up( 'workspace.pdf', "%PDF-1.4 ws\n%%EOF", $e1 );
ps_assert( 'drive' === ps_file_get( $sf )['storage'], 'envoi par le compte de service dans le Drive partagé' );

delete_option( 'ps_gdrive' );
delete_transient( 'ps_gdrive_token' );
wp_set_current_user( 0 );
