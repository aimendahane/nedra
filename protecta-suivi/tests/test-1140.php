<?php
/**
 * Version 1.14.0 — OneDrive (Microsoft 365) pour les fichiers joints + vitesse.
 * Microsoft Graph est SIMULÉ (filtre pre_http_request) : aucun appel réseau.
 *   - empreinte quickXorHash : comparée à une 2e implémentation, écrite autrement (registre circulaire bit à bit) ;
 *   - liaison : contrôles des identifiants, bibliothèque trouvée, test d'écriture, secret jamais renvoyé ;
 *   - envoi : par morceaux, copie du serveur effacée SEULEMENT après vérification (taille + empreinte, sinon relecture) ;
 *   - un envoi raté / une empreinte différente → le fichier reste sur le serveur (règle n°1) ;
 *   - envoi des fichiers APRÈS la réponse (file d'attente), anciens fichiers transférés par ps_storage_sync ;
 *   - choix du service, ancien site Google Drive inchangé ; vitesse : JS en un seul fichier, Chart.js hors page.
 */

global $wpdb;

/** quickXorHash « naïf » : chaque bit de l'octet i va à la position (11 i + k) mod 160. */
function ps_test_quickxor_ref( string $bytes ): string {
	$bits = array_fill( 0, 160, 0 );
	$n    = strlen( $bytes );
	for ( $i = 0; $i < $n; $i++ ) {
		$b   = ord( $bytes[ $i ] );
		$pos = ( 11 * $i ) % 160;
		for ( $k = 0; $k < 8; $k++ ) {
			if ( ( $b >> $k ) & 1 ) {
				$bits[ ( $pos + $k ) % 160 ] ^= 1;
			}
		}
	}
	$out = '';
	for ( $byte = 0; $byte < 20; $byte++ ) {
		$v = 0;
		for ( $k = 0; $k < 8; $k++ ) {
			$v |= $bits[ $byte * 8 + $k ] << $k;
		}
		$out .= chr( $v );
	}
	$len = pack( 'P', $n );
	for ( $i = 0; $i < 8; $i++ ) {
		$out[ 12 + $i ] = chr( ord( $out[ 12 + $i ] ) ^ ord( $len[ $i ] ) );
	}
	return base64_encode( $out );
}

ps_test_section( '1.14.0 : empreinte quickXorHash (Microsoft)' );

$tmp = wp_tempnam( 'qx' );
$ok  = true;
$det = '';
foreach ( array( '', 'a', 'Hello, World!', str_repeat( 'A', 159 ), str_repeat( 'xyz', 61 ), random_bytes( 1000 ), random_bytes( 4096 + 37 ) ) as $sample ) {
	file_put_contents( $tmp, $sample );
	$a = ps_quickxor_file( $tmp );
	$b = ps_test_quickxor_ref( $sample );
	if ( $a !== $b ) {
		$ok  = false;
		$det = strlen( $sample ) . ' octets : ' . $a . ' ≠ ' . $b;
	}
}
ps_assert( $ok, 'implémentation rapide = implémentation bit à bit (vide, 1 octet, 13, 159, 183, 1000, 4133 octets aléatoires)', $det );
ps_assert_same( 'AAAAAAAAAAAAAAAAAAAAAAAAAAA=', ps_quickxor_file( ( file_put_contents( $tmp, '' ) || true ) ? $tmp : $tmp ), 'fichier vide : 20 octets nuls' );
// Fichier de plus d'un bloc (1 Mo) : le calcul par morceaux donne le même résultat.
$big = random_bytes( 1048576 + 333 );
file_put_contents( $tmp, $big );
$st = array( 'data' => array( 0, 0, 0 ), 'shift' => 0, 'len' => 0 );
ps_quickxor_update( $st, $big );
ps_assert_same( ps_quickxor_final( $st ), ps_quickxor_file( $tmp ), 'fichier de 1 Mo + 333 octets : par blocs = en une fois' );
unlink( $tmp );

/* ------------------------------------------------------------------ */
ps_test_section( '1.14.0 : liaison OneDrive (Microsoft simulé)' );

ps_test_reset_data();
delete_option( 'ps_onedrive' );
delete_option( 'ps_storage_provider' );
delete_option( 'ps_gdrive' );
delete_transient( 'ps_onedrive_token' );
$adm = ps_test_user( 't114_adm', 'ps_admin' );
$emp = ps_test_user( 't114_emp', 'ps_employee', 'محمد حسين' );
wp_set_current_user( $adm );

$od = array( 'calls' => array(), 'files' => array(), 'uploads' => array(), 'down' => false, 'bad_hash' => false, 'corrupt' => false, 'deny' => false, 'next' => 1 );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$od ) {
		if ( ! preg_match( '#^https://(login\.microsoftonline\.com|graph\.microsoft\.com|upload\.example\.sharepoint\.com|dl\.example\.sharepoint\.com)/#', $url ) ) {
			return $pre;
		}
		$m              = $args['method'] ?? 'GET';
		$od['calls'][]  = $m . ' ' . $url;
		$res            = fn( $code, $data, $headers = array() ) => array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => is_string( $data ) ? $data : wp_json_encode( $data ), 'headers' => $headers, 'cookies' => array() );
		if ( $od['down'] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		}
		if ( str_contains( $url, 'login.microsoftonline.com' ) ) {
			$b = (array) $args['body'];
			return 'S3cret' === ( $b['client_secret'] ?? '' ) && 'client_credentials' === $b['grant_type']
				? $res( 200, array( 'access_token' => 'AT-graph', 'expires_in' => 3600 ) )
				: $res( 401, array( 'error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.' ) );
		}
		if ( str_contains( $url, '/users/archive%40protecta.ly/drive' ) ) {
			return $od['deny'] ? $res( 403, array( 'error' => array( 'code' => 'accessDenied', 'message' => 'Access denied' ) ) ) : $res( 200, array( 'id' => 'b!DRIVE', 'name' => 'OneDrive', 'driveType' => 'business' ) );
		}
		if ( str_contains( $url, '/users/' ) ) {
			return $res( 404, array( 'error' => array( 'code' => 'itemNotFound', 'message' => 'User not found' ) ) );
		}
		if ( 'PUT' === $m && str_contains( $url, 'protecta-test.txt:/content' ) ) {
			return $res( 201, array( 'id' => 'TEST1' ) );
		}
		if ( 'DELETE' === $m && str_contains( $url, 'graph.microsoft.com' ) ) {
			return $res( 204, '' );
		}
		if ( str_contains( $url, ':/createUploadSession' ) ) {
			$id                   = 'U' . $od['next']++;
			$od['uploads'][ $id ] = array( 'path' => rawurldecode( preg_replace( '#^.*/root:/(.*):/createUploadSession$#', '$1', $url ) ), 'body' => '' );
			return $res( 200, array( 'uploadUrl' => 'https://upload.example.sharepoint.com/up/' . $id ) );
		}
		if ( str_starts_with( $url, 'https://upload.example.sharepoint.com/up/' ) ) {
			$id = substr( $url, strrpos( $url, '/' ) + 1 );
			if ( 'DELETE' === $m ) {
				return $res( 204, '' );
			}
			$od['auth_on_upload'] = ! empty( $args['headers']['Authorization'] ) || ! empty( $od['auth_on_upload'] );
			preg_match( '#bytes (\d+)-(\d+)/(\d+)#', $args['headers']['Content-Range'], $r );
			$od['uploads'][ $id ]['body'] .= $args['body'];
			$od['uploads'][ $id ]['ranges'][] = $r[0];
			if ( (int) $r[2] + 1 < (int) $r[3] ) {
				return $res( 202, array( 'nextExpectedRanges' => array( ( $r[2] + 1 ) . '-' ) ) );
			}
			$body                 = $od['corrupt'] ? strrev( $od['uploads'][ $id ]['body'] ) : $od['uploads'][ $id ]['body'];
			$item                 = 'ITEM-' . $id;
			$od['files'][ $item ] = array( 'body' => $body, 'path' => $od['uploads'][ $id ]['path'] );
			$hash                 = $od['bad_hash'] ? 'AAAAAAAAAAAAAAAAAAAAAAAAAAA=' : ps_test_quickxor_ref( $body );
			return $res( 201, array( 'id' => $item, 'size' => strlen( $body ), 'file' => array( 'hashes' => array( 'quickXorHash' => $hash ) ) ) );
		}
		if ( preg_match( '#/drives/b%21DRIVE/items/([^?]+)\?#', $url, $mm ) ) {
			return isset( $od['files'][ $mm[1] ] ) ? $res( 200, array( 'id' => $mm[1], '@microsoft.graph.downloadUrl' => 'https://dl.example.sharepoint.com/f/' . $mm[1] ) ) : $res( 404, array( 'error' => array( 'code' => 'itemNotFound', 'message' => 'Item not found' ) ) );
		}
		if ( str_starts_with( $url, 'https://dl.example.sharepoint.com/f/' ) ) {
			$item = substr( $url, strrpos( $url, '/' ) + 1 );
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $od['files'][ $item ]['body'] );
			}
			return $res( 200, '' );
		}
		return $res( 400, array( 'error' => array( 'code' => 'unexpected', 'message' => 'unexpected ' . $url ) ) );
	},
	10,
	3
);

$cfg = array( 'tenantId' => '72f988bf-86f1-41af-91ab-2d7cd011db47', 'clientId' => '11111111-2222-3333-4444-555555555555', 'clientSecret' => 'S3cret', 'targetType' => 'user', 'target' => 'archive@protecta.ly' );
$r   = ps_test_json( 'POST', '/onedrive/config', array( 'tenantId' => 'x', 'clientId' => 'y', 'targetType' => 'user', 'target' => '' ) );
$e   = $r->get_data()['data']['errors'] ?? array();
ps_assert( 422 === $r->get_status() && isset( $e['tenantId'], $e['clientId'], $e['clientSecret'], $e['target'] ), 'identifiants mal formés → erreurs sous chaque خانة' );
wp_set_current_user( $emp );
ps_assert_same( 403, ps_test_json( 'GET', '/storage' )->get_status(), 'موظف : aucun accès aux réglages de stockage' );
wp_set_current_user( $adm );
$r = ps_test_json( 'POST', '/onedrive/config', array_merge( $cfg, array( 'clientSecret' => 'faux' ) ) );
ps_assert( 422 === $r->get_status() && str_contains( $r->get_data()['message'], 'Value' ), 'mauvais secret → refus clair (« انسخ Value وليس Secret ID »)', $r->get_data()['message'] );
ps_assert( ! ps_onedrive_connected(), '… et rien n\'est relié' );
$od['deny'] = true;
delete_transient( 'ps_onedrive_token' );
$r = ps_test_json( 'POST', '/onedrive/config', $cfg );
ps_assert( 422 === $r->get_status() && str_contains( $r->get_data()['message'], 'Files.ReadWrite.All' ), 'autorisation manquante → explique « Files.ReadWrite.All + Grant admin consent »', $r->get_data()['message'] );
$od['deny'] = false;
$r = ps_test_json( 'POST', '/onedrive/config', $cfg );
$d = $r->get_data();
ps_assert( 200 === $r->get_status() && $d['onedrive']['connected'] && 'archive@protecta.ly' === $d['onedrive']['driveName'], 'liaison OK : OneDrive de archive@protecta.ly', wp_json_encode( $d ) );
ps_assert( 'onedrive' === $d['storage']['provider'], 'OneDrive devient l\'emplacement des nouveaux fichiers' );
ps_assert( (bool) array_filter( $od['calls'], fn( $c ) => str_contains( $c, 'protecta-test.txt' ) ), 'test d\'écriture fait pendant la liaison (petit fichier créé puis effacé)' );
$json = wp_json_encode( ps_test_json( 'GET', '/storage' )->get_data() );
ps_assert( ! str_contains( $json, 'S3cret' ) && str_contains( $json, '"hasSecret":true' ), 'le secret n\'est jamais renvoyé au navigateur' );
$bk = ps_backup_build( 'test' );
ps_assert( ! str_contains( wp_json_encode( $bk ), 'S3cret' ), '… ni écrit dans la sauvegarde JSON' );
$r = ps_test_json( 'POST', '/onedrive/config', array_merge( $cfg, array( 'clientSecret' => '' ) ) );
ps_assert_same( 200, $r->get_status(), 'secret laissé vide = secret enregistré conservé' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.14.0 : envoi des fichiers vers OneDrive (aucune perte)' );

$p    = ps_test_json( 'POST', '/projects', ps_test_payload( array( 'isProject' => 1, 'name' => 'مشروع المخازن: الجديد?', 'confirmDuplicates' => true ) ) )->get_data();
$mk   = function ( string $name, string $content ) use ( $p, $adm ) {
	$tmp = wp_tempnam( 'od' );
	file_put_contents( $tmp, $content );
	return ps_file_store( (int) $p['id'], array( 'name' => $name, 'tmp_name' => $tmp, 'size' => strlen( $content ), 'error' => 0, 'type' => 'application/pdf' ), $adm, $name, array() );
};
add_filter( 'ps_onedrive_chunk_units', fn() => 1 ); // morceaux de 320 Kio pour le test (5 Mo en vrai)
$fid  = $mk( 'عرض الأسعار.pdf', '%PDF-1.4 ' . random_bytes( 1000000 ) ); // ~1 Mo → 4 morceaux
$f    = ps_file_get( $fid );
$path = ps_storage_dir( 'files/' . $f['stored_name'] );
ps_assert( 'local' === $f['storage'] && is_file( $path ) && in_array( $fid, ps_storage_queue(), true ), 'à l\'ajout : enregistré sur le serveur, envoi prévu APRÈS la réponse (l\'usager n\'attend pas)' );
ps_assert( true === ps_storage_push( $f ), 'envoi vers OneDrive' );
$f   = ps_file_get( $fid );
$up  = end( $od['uploads'] );
ps_assert( 'onedrive' === $f['storage'] && ! is_file( $path ), 'vérifié (taille + quickXorHash) → copie du serveur libérée' );
ps_assert( empty( $od['auth_on_upload'] ), 'adresse d\'envoi de Microsoft : appelée sans jeton (exigence Microsoft)' );
ps_assert( 4 === count( $up['ranges'] ) && str_starts_with( $up['ranges'][1], 'bytes 327680-655359/' ), 'envoi par morceaux (multiples de 320 Kio, exigence Microsoft ; 5 Mo en vrai)', implode( ' | ', $up['ranges'] ) );
ps_assert( str_starts_with( $up['path'], 'Protecta Suivi/' . $p['projectNo'] . ' — مشروع المخازن الجديد/' ), 'rangé dans « Protecta Suivi / رقم المشروع — اسم المشروع / » (caractères interdits retirés)', $up['path'] );
ps_assert_same( 'onedrive', ps_file_to_api( $f, $adm )['storage'], 'API : emplacement connu de la الإدارة' );

// Empreinte absente / fausse mais contenu identique → relu depuis OneDrive et comparé (MD5) → accepté.
$od['bad_hash'] = true;
$fid2 = $mk( 'صورة.pdf', '%PDF-1.4 photo' );
ps_assert( true === ps_storage_push( ps_file_get( $fid2 ) ) && 'onedrive' === ps_file_get( $fid2 )['storage'], 'empreinte non concordante mais fichier relu identique → accepté' );
// Contenu différent → refusé, le fichier RESTE sur le serveur.
$od['corrupt'] = true;
$fid3 = $mk( 'تالف.pdf', '%PDF-1.4 contenu original' );
$f3   = ps_file_get( $fid3 );
$res  = ps_storage_push( $f3 );
$f3   = ps_file_get( $fid3 );
ps_assert( is_wp_error( $res ) && 'local' === $f3['storage'] && is_file( ps_storage_dir( 'files/' . $f3['stored_name'] ) ) && $f3['drive_error'], 'contenu différent dans OneDrive → le fichier reste sur le serveur (erreur notée)' );
$od['corrupt']  = false;
$od['bad_hash'] = false;
// Microsoft injoignable → reste sur le serveur, repartira.
$od['down'] = true;
$fid4 = $mk( 'لاحقا.pdf', '%PDF-1.4 plus tard' );
ps_assert( is_wp_error( ps_storage_push( ps_file_get( $fid4 ) ) ) && 'local' === ps_file_get( $fid4 )['storage'], 'OneDrive injoignable → le fichier attend sur le serveur' );
$od['down'] = false;
$s = ps_storage_sync( 20, 25.0 );
ps_assert( 2 === $s['sent'] && 0 === $s['pending'], 'synchronisation : les fichiers en attente partent (« نقل الملفات الآن » / Cron)', wp_json_encode( $s ) );

// Lecture : adresse temporaire de Microsoft (jamais montrée), droits du site vérifiés avant.
$src = ps_onedrive_source( ps_file_get( $fid ) );
ps_assert( is_string( $src ) && str_starts_with( $src, 'https://dl.example.sharepoint.com/f/' ), 'lecture : lien temporaire demandé à Microsoft au moment du clic' );
$out = '';
ob_start();
$ok = ps_stream_remote( home_url( '/wp-content/themes/protecta-suivi/style.css' ) );
$out = ob_get_clean();
ps_assert( true === $ok && str_contains( $out, 'Theme Name' ), 'transmission au fil de l\'eau (cURL) d\'un fichier distant', is_wp_error( $ok ) ? $ok->get_error_message() : '' );
wp_set_current_user( $emp );
ps_assert( ! str_contains( wp_json_encode( ps_file_to_api( ps_file_get( $fid ), $emp ) ), 'ITEM-' ), 'موظف : aucun identifiant ni lien OneDrive dans l\'API (lien du site seulement)' );
wp_set_current_user( $adm );

/* ------------------------------------------------------------------ */
ps_test_section( '1.14.0 : choix du service, sites déjà reliés à Google Drive' );

ps_assert_same( 422, ps_test_json( 'POST', '/storage/provider', array( 'provider' => 'gdrive' ) )->get_status(), 'Google Drive non relié → ne peut pas être choisi' );
$r = ps_test_json( 'POST', '/storage/provider', array( 'provider' => 'local' ) );
ps_assert( 200 === $r->get_status() && '' === $r->get_data()['storage']['provider'], '« الخادم فقط » : plus aucun envoi' );
update_option( 'ps_gdrive', array( 'method' => 'oauth', 'client_id' => 'x', 'client_secret' => 'y', 'refresh_token' => 'RT', 'folder_id' => 'F' ) );
delete_option( 'ps_storage_provider' );
ps_assert_same( 'gdrive', ps_storage_provider(), 'site relié à Google Drive avant 1.14.0 (aucun choix enregistré) → continue avec Google Drive' );
ps_test_json( 'POST', '/storage/provider', array( 'provider' => 'onedrive' ) );
ps_assert_same( 'onedrive', ps_storage_provider(), 'la الإدارة passe à OneDrive' );
delete_option( 'ps_gdrive' );
$r = ps_test_json( 'POST', '/onedrive/disconnect' );
ps_assert( ! $r->get_data()['onedrive']['connected'] && '' === ps_storage_provider() && 'onedrive' === ps_file_get( $fid )['storage'], 'فك الربط : plus d\'envoi, les fichiers déjà dans OneDrive restent (aucune suppression)' );

/* ------------------------------------------------------------------ */
ps_test_section( '1.14.0 : vitesse — un seul fichier JS, Chart.js à la demande, données préchargées' );

$url = ps_js_bundle_url();
$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'ps-cache/';
ps_assert( (bool) preg_match( '#/ps-cache/app-[0-9a-f]{12}\.js$#', $url ) && is_file( $dir . basename( $url ) ), 'fichier JS unique créé (uploads/ps-cache), nom lié à la version', $url );
$js = (string) file_get_contents( $dir . basename( $url ) );
ps_assert( str_contains( $js, 'PS.router.register' ) && str_contains( $js, 'core/router.js' ) && str_contains( $js, 'pages/recap.js' ) && ! str_contains( $js, 'Chart.js v4' ), 'il contient le cœur et toutes les pages, pas Chart.js' );
ps_assert_same( $url, ps_js_bundle_url(), 'même version → même fichier (cache du navigateur)' );
$cfg = ps_client_config();
ps_assert( isset( $cfg['preload']['counters'], $cfg['preload']['unread'], $cfg['preload']['telegram'] ) && str_contains( $cfg['chartUrl'], 'chart.umd.min.js' ), 'page : compteurs, notifications et Telegram préchargés ; Chart.js chargé par Recap seulement' );
