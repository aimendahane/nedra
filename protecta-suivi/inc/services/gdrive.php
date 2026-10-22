<?php
/**
 * Google Drive : emplacement des fichiers joints (1.5.0, demande du client : « l'espace du serveur est limité »).
 *
 * Les utilisateurs ajoutent et ouvrent les fichiers DANS LE SITE ; les fichiers eux-mêmes sont rangés dans Google
 * Drive (un dossier par projet, dans le dossier « Protecta Suivi »). Le site seul y accède : aucun lien Drive n'est
 * donné aux utilisateurs, le téléchargement passe par l'API du site après contrôle des droits.
 *
 * Deux façons de relier Drive (الإعدادات ← عام ← « تخزين الملفات »), au choix de la الإدارة :
 *   - « oauth » : compte Gmail (ou Workspace) — un « client OAuth » Google Cloud (Client ID + secret), puis le
 *     bouton « ربط Google Drive » (accord donné une fois). Droit limité aux fichiers créés par le site (drive.file).
 *   - « service » : compte de service (clé JSON) + dossier d'un Drive partagé de la société (Workspace).
 *
 * Fonctionnement (règle n°1 : rien n'est perdu) :
 *   1. le fichier est d'abord enregistré sur le serveur (storage = local) ;
 *   2. il est envoyé dans Drive tout de suite si Drive est relié, sinon plus tard (Cron, bouton « مزامنة الآن »,
 *      ou en fin de requête) ;
 *   3. la copie du serveur n'est effacée qu'après vérification : l'empreinte MD5 renvoyée par Google doit être
 *      identique à celle du fichier (storage = drive) ; sinon le fichier reste sur le serveur (drive_error).
 * Un fichier mis à la corbeille du site reste dans Drive (jamais supprimé).
 *
 * Secrets (option ps_gdrive) : jamais renvoyés au navigateur ni écrits dans la sauvegarde JSON.
 */

defined( 'ABSPATH' ) || exit;

const PS_GDRIVE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const PS_GDRIVE_API       = 'https://www.googleapis.com/drive/v3';
const PS_GDRIVE_UPLOAD    = 'https://www.googleapis.com/upload/drive/v3/files';

/** Réglages Drive (option séparée de ps_settings). */
function ps_gdrive_conf(): array {
	return array_merge(
		array( 'method' => '', 'client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'sa_json' => '', 'folder_id' => '', 'account' => '', 'connected_at' => '' ),
		(array) get_option( 'ps_gdrive', array() )
	);
}

function ps_gdrive_save( array $changes ): void {
	update_option( 'ps_gdrive', array_merge( ps_gdrive_conf(), $changes ), false );
	delete_transient( 'ps_gdrive_token' );
}

/** Drive relié et utilisable (jeton obtenu au moins une fois + dossier racine connu). */
function ps_gdrive_connected(): bool {
	$c = ps_gdrive_conf();
	if ( '' === $c['folder_id'] ) {
		return false;
	}
	return ( 'oauth' === $c['method'] && '' !== $c['refresh_token'] ) || ( 'service' === $c['method'] && '' !== $c['sa_json'] );
}

/** Adresse de retour à déclarer dans Google Cloud (client OAuth « Application Web »). */
function ps_gdrive_redirect_uri(): string {
	return add_query_arg( 'ps_gdrive', 'callback', home_url( '/' ) );
}

/* ---------------------------------------------------------------------- */
/* Jeton d'accès                                                           */
/* ---------------------------------------------------------------------- */

/** @return string|WP_Error Jeton d'accès (mis en cache ~55 min). */
function ps_gdrive_token() {
	$cached = get_transient( 'ps_gdrive_token' );
	if ( $cached ) {
		return $cached;
	}
	$c = ps_gdrive_conf();
	if ( 'oauth' === $c['method'] && '' !== $c['refresh_token'] ) {
		$body = array( 'grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token'], 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'] );
	} elseif ( 'service' === $c['method'] && '' !== $c['sa_json'] ) {
		$jwt = ps_gdrive_service_jwt( $c['sa_json'] );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}
		$body = array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt );
	} else {
		return new WP_Error( 'ps_gdrive_off', ps__( 'Google Drive غير مربوط' ), array( 'status' => 409 ) );
	}
	$r    = wp_remote_post( PS_GDRIVE_TOKEN_URL, array( 'timeout' => 20, 'body' => $body ) );
	$data = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( empty( $data['access_token'] ) ) {
		$why = is_wp_error( $r ) ? $r->get_error_message() : ( $data['error_description'] ?? $data['error'] ?? wp_remote_retrieve_response_code( $r ) );
		return new WP_Error( 'ps_gdrive_token', ps__( 'رفضت Google الاتصال: ' ) . $why, array( 'status' => 502 ) );
	}
	set_transient( 'ps_gdrive_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
	return $data['access_token'];
}

/** Assertion JWT signée (RS256) d'un compte de service. @return string|WP_Error */
function ps_gdrive_service_jwt( string $json ) {
	$key = json_decode( $json, true );
	if ( empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
		return new WP_Error( 'ps_gdrive_key', ps__( 'ملف المفتاح (JSON) غير صالح: يجب أن يحتوي على client_email و private_key' ), array( 'status' => 422 ) );
	}
	$b64   = static fn( $s ) => rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	$now   = time();
	$head  = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claim = $b64( wp_json_encode( array( 'iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive', 'aud' => PS_GDRIVE_TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600 ) ) );
	$sig   = '';
	$pkey  = function_exists( 'openssl_pkey_get_private' ) ? openssl_pkey_get_private( $key['private_key'] ) : false;
	if ( ! $pkey || ! openssl_sign( $head . '.' . $claim, $sig, $pkey, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'ps_gdrive_key', ps__( 'تعذّر استعمال المفتاح الخاص (private_key)' ), array( 'status' => 422 ) );
	}
	return $head . '.' . $claim . '.' . $b64( $sig );
}

/**
 * Appel de l'API Drive (JSON). @return array|WP_Error
 */
function ps_gdrive_api( string $method, string $url, ?array $json = null, array $extra = array() ) {
	$token = ps_gdrive_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array_merge(
		array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		),
		$extra
	);
	if ( null !== $json ) {
		$args['headers']['Content-Type'] = 'application/json; charset=UTF-8';
		$args['body']                    = wp_json_encode( $json );
	}
	$r = wp_remote_request( $url, $args );
	if ( is_wp_error( $r ) ) {
		return new WP_Error( 'ps_gdrive_http', ps__( 'تعذّر الاتصال بـ Google Drive: ' ) . $r->get_error_message(), array( 'status' => 502 ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( $code >= 300 ) {
		if ( 401 === $code ) {
			delete_transient( 'ps_gdrive_token' );
		}
		return new WP_Error( 'ps_gdrive_api', 'Google Drive: ' . ( $data['error']['message'] ?? 'HTTP ' . $code ), array( 'status' => 502, 'http' => $code ) );
	}
	return array( 'data' => is_array( $data ) ? $data : array(), 'response' => $r );
}

/* ---------------------------------------------------------------------- */
/* Dossiers et envoi                                                       */
/* ---------------------------------------------------------------------- */

/** Crée un dossier Drive. @return string|WP_Error id */
function ps_gdrive_create_folder( string $name, ?string $parent ) {
	$meta = array( 'name' => $name, 'mimeType' => 'application/vnd.google-apps.folder' );
	if ( $parent ) {
		$meta['parents'] = array( $parent );
	}
	$r = ps_gdrive_api( 'POST', PS_GDRIVE_API . '/files?supportsAllDrives=true&fields=id', $meta );
	return is_wp_error( $r ) ? $r : (string) ( $r['data']['id'] ?? '' );
}

/** Dossier du projet (créé à la première utilisation : « اسم المشروع — PRJ-… »). @return string|WP_Error */
function ps_gdrive_project_folder( int $project_id ) {
	global $wpdb;
	$p = ps_project_row( $project_id );
	if ( ! $p ) {
		return new WP_Error( 'ps_not_found', ps__( 'المشروع غير موجود' ) );
	}
	if ( ! empty( $p['drive_folder_id'] ) ) {
		return $p['drive_folder_id'];
	}
	$id = ps_gdrive_create_folder( mb_substr( $p['name'] . ' — ' . $p['project_no'], 0, 200 ), ps_gdrive_conf()['folder_id'] );
	if ( ! is_wp_error( $id ) && '' !== $id ) {
		$wpdb->update( ps_table( 'projects' ), array( 'drive_folder_id' => $id ), array( 'id' => $project_id ) );
	}
	return $id;
}

/**
 * Envoie un fichier du serveur dans Drive, vérifie l'empreinte, puis libère l'espace du serveur.
 *
 * @return true|WP_Error
 */
function ps_gdrive_push( array $f ) {
	global $wpdb;
	if ( 'local' !== ( $f['storage'] ?? 'local' ) ) {
		return true;
	}
	$path = ps_storage_dir( 'files/' . $f['stored_name'] );
	$fail = static function ( WP_Error $e ) use ( $f, $wpdb ) {
		$wpdb->update( ps_table( 'files' ), array( 'drive_error' => mb_substr( $e->get_error_message(), 0, 250 ), 'drive_tries' => (int) $f['drive_tries'] + 1 ), array( 'id' => (int) $f['id'] ) );
		return $e;
	};
	if ( ! is_readable( $path ) ) {
		return $fail( new WP_Error( 'ps_gdrive_local', ps__( 'الملف غير موجود على الخادم' ) ) );
	}
	$md5 = $f['md5'] ?: md5_file( $path );
	$folder = ps_gdrive_project_folder( (int) $f['project_id'] );
	if ( is_wp_error( $folder ) ) {
		return $fail( $folder );
	}
	// Envoi « resumable » : 1) métadonnées → adresse d'envoi ; 2) contenu (jusqu'à plusieurs dizaines de Mo).
	$meta  = array( 'name' => ps_file_download_name( $f ), 'parents' => array( $folder ), 'description' => 'Protecta Suivi #' . (int) $f['id'] );
	$start = ps_gdrive_api(
		'POST',
		PS_GDRIVE_UPLOAD . '?uploadType=resumable&supportsAllDrives=true',
		$meta,
		array( 'headers' => array( 'X-Upload-Content-Type' => $f['mime'] ?: 'application/octet-stream', 'X-Upload-Content-Length' => (string) filesize( $path ) ) )
	);
	if ( is_wp_error( $start ) ) {
		return $fail( $start );
	}
	$location = wp_remote_retrieve_header( $start['response'], 'location' );
	if ( ! $location ) {
		return $fail( new WP_Error( 'ps_gdrive_api', 'Google Drive: no upload URL' ) );
	}
	$put = ps_gdrive_api(
		'PUT',
		add_query_arg( 'fields', 'id,md5Checksum,size', $location ),
		null,
		array( 'timeout' => 300, 'body' => (string) file_get_contents( $path ), 'headers' => array( 'Content-Type' => $f['mime'] ?: 'application/octet-stream' ) )
	);
	if ( is_wp_error( $put ) ) {
		return $fail( $put );
	}
	$d = $put['data'];
	if ( empty( $d['id'] ) || strtolower( (string) ( $d['md5Checksum'] ?? '' ) ) !== strtolower( $md5 ) ) {
		// Contenu différent : on garde le fichier du serveur (jamais de perte), on réessaiera.
		return $fail( new WP_Error( 'ps_gdrive_md5', ps__( 'تحقق Google Drive فشل (البصمة مختلفة): بقي الملف على الخادم' ) ) );
	}
	$wpdb->update( ps_table( 'files' ), array( 'storage' => 'drive', 'drive_id' => $d['id'], 'md5' => $md5, 'drive_error' => null ), array( 'id' => (int) $f['id'] ) );
	unlink( $path ); // copie du serveur effacée APRÈS vérification de l'empreinte
	return true;
}

/**
 * Récupère un fichier Drive dans un fichier temporaire (pour le téléchargement par le site).
 *
 * @return string|WP_Error Chemin du fichier temporaire (à effacer après envoi).
 */
function ps_gdrive_fetch( array $f ) {
	if ( ! ps_gdrive_connected() ) {
		return new WP_Error( 'ps_gdrive_off', ps__( 'الملف محفوظ في Google Drive، لكن الربط مقطوع: اطلب من الإدارة إعادة ربط Google Drive (الإعدادات ← عام).' ), array( 'status' => 503 ) );
	}
	$tmp = trailingslashit( get_temp_dir() ) . 'ps-drive-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
	$r   = ps_gdrive_api( 'GET', PS_GDRIVE_API . '/files/' . rawurlencode( (string) $f['drive_id'] ) . '?alt=media&supportsAllDrives=true', null, array( 'timeout' => 120, 'stream' => true, 'filename' => $tmp ) );
	if ( is_wp_error( $r ) ) {
		if ( is_file( $tmp ) ) {
			unlink( $tmp );
		}
		return new WP_Error( 'ps_gdrive_fetch', ps__( 'تعذّر جلب الملف من Google Drive: ' ) . $r->get_error_message(), array( 'status' => 502 ) );
	}
	return $tmp;
}

/**
 * Envoie dans Drive les fichiers encore sur le serveur (anciens fichiers compris).
 *
 * @return array { sent, failed, pending }
 */
function ps_gdrive_sync( int $limit = 20, float $max_seconds = 25.0 ): array {
	global $wpdb;
	$out = array( 'sent' => 0, 'failed' => 0, 'pending' => 0, 'errors' => array() );
	if ( ps_gdrive_connected() ) {
		$t0   = microtime( true );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'files' ) . " WHERE storage = 'local' ORDER BY drive_tries, id LIMIT %d", $limit ), ARRAY_A );
		foreach ( $rows as $f ) {
			if ( microtime( true ) - $t0 > $max_seconds ) {
				break;
			}
			$r = ps_gdrive_push( $f );
			if ( true === $r ) {
				++$out['sent'];
			} else {
				++$out['failed'];
				$out['errors'][] = ps_file_label( $f ) . ': ' . $r->get_error_message();
			}
		}
	}
	$out['pending'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ps_table( 'files' ) . " WHERE storage = 'local'" );
	return $out;
}

/** État pour الإعدادات (sans aucun secret). */
function ps_gdrive_status(): array {
	global $wpdb;
	$c  = ps_gdrive_conf();
	$ft = ps_table( 'files' );
	return array(
		'method'       => $c['method'],
		'connected'    => ps_gdrive_connected(),
		'account'      => $c['account'],
		'connectedAt'  => $c['connected_at'],
		'clientId'     => $c['client_id'],
		'hasSecret'    => '' !== $c['client_secret'],
		'hasKey'       => '' !== $c['sa_json'],
		'folderId'     => $c['folder_id'],
		'redirectUri'  => ps_gdrive_redirect_uri(),
		'pending'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft WHERE storage = 'local'" ), // phpcs:ignore
		'inDrive'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft WHERE storage = 'drive'" ), // phpcs:ignore
		'localBytes'   => (int) $wpdb->get_var( "SELECT COALESCE(SUM(size),0) FROM $ft WHERE storage = 'local'" ), // phpcs:ignore
		'lastError'    => (string) $wpdb->get_var( "SELECT drive_error FROM $ft WHERE storage = 'local' AND drive_error IS NOT NULL ORDER BY id DESC LIMIT 1" ), // phpcs:ignore
	);
}

/* ---------------------------------------------------------------------- */
/* Liaison OAuth (compte Gmail)                                            */
/* ---------------------------------------------------------------------- */

/** Adresse Google où la الإدارة donne son accord. */
function ps_gdrive_auth_url( int $user_id ): string {
	$state = bin2hex( random_bytes( 16 ) );
	set_transient( 'ps_gdrive_state_' . $state, $user_id, 30 * MINUTE_IN_SECONDS );
	return add_query_arg(
		array(
			'client_id'     => rawurlencode( ps_gdrive_conf()['client_id'] ),
			'redirect_uri'  => rawurlencode( ps_gdrive_redirect_uri() ),
			'response_type' => 'code',
			'scope'         => rawurlencode( 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email' ),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		),
		'https://accounts.google.com/o/oauth2/v2/auth'
	);
}

/**
 * Retour de Google (?ps_gdrive=callback&code=…&state=…) : échange du code contre le jeton longue durée,
 * création du dossier « Protecta Suivi ». @return true|WP_Error
 */
function ps_gdrive_oauth_callback( string $code, string $state, int $user_id ) {
	$owner = get_transient( 'ps_gdrive_state_' . $state );
	delete_transient( 'ps_gdrive_state_' . $state );
	if ( ! $owner || (int) $owner !== $user_id || ! user_can( $user_id, 'ps_manage_settings' ) ) {
		return new WP_Error( 'ps_gdrive_state', ps__( 'رابط الربط منتهي أو غير صالح: أعد المحاولة من الإعدادات.' ) );
	}
	$c    = ps_gdrive_conf();
	$r    = wp_remote_post( PS_GDRIVE_TOKEN_URL, array( 'timeout' => 20, 'body' => array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => ps_gdrive_redirect_uri() ) ) );
	$data = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( empty( $data['refresh_token'] ) ) {
		return new WP_Error( 'ps_gdrive_token', ps__( 'رفضت Google الاتصال: ' ) . ( is_wp_error( $r ) ? $r->get_error_message() : ( $data['error_description'] ?? $data['error'] ?? '?' ) ) );
	}
	$email = '';
	if ( ! empty( $data['access_token'] ) ) {
		$me    = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $data['access_token'] ) ) );
		$email = (string) ( json_decode( (string) wp_remote_retrieve_body( $me ), true )['email'] ?? '' );
	}
	ps_gdrive_save( array( 'method' => 'oauth', 'refresh_token' => $data['refresh_token'], 'account' => $email, 'connected_at' => ps_now() ) );
	if ( ! empty( $data['access_token'] ) ) {
		set_transient( 'ps_gdrive_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
	}
	if ( '' === ps_gdrive_conf()['folder_id'] ) {
		$folder = ps_gdrive_create_folder( 'Protecta Suivi', null );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		ps_gdrive_save( array( 'folder_id' => $folder ) );
	}
	ps_log( null, 'update', 'settings', null, 'gdrive', null, $email ?: 'oauth' );
	return true;
}

add_action(
	'template_redirect',
	static function () {
		if ( 'callback' !== ( $_GET['ps_gdrive'] ?? '' ) ) { // phpcs:ignore
			return;
		}
		$uid = get_current_user_id();
		$res = isset( $_GET['code'], $_GET['state'] ) && $uid // phpcs:ignore
			? ps_gdrive_oauth_callback( sanitize_text_field( wp_unslash( $_GET['code'] ) ), sanitize_key( wp_unslash( $_GET['state'] ) ), $uid ) // phpcs:ignore
			: new WP_Error( 'ps_gdrive_denied', ps__( 'لم يتم الربط (تم الإلغاء أو لم تسجّل الدخول).' ) );
		$msg = is_wp_error( $res ) ? 'error:' . $res->get_error_message() : 'ok';
		wp_safe_redirect( home_url( '/#/parametres?tab=general&gdrive=' . rawurlencode( $msg ) ) );
		exit;
	},
	2
);

/* ---------------------------------------------------------------------- */
/* Envoi en arrière-plan                                                   */
/* ---------------------------------------------------------------------- */

/**
 * Fin de requête : s'il reste des fichiers sur le serveur, en envoie quelques-uns (au plus toutes les 10 min),
 * après avoir rendu la réponse au navigateur.
 */
add_action(
	'shutdown',
	static function () {
		if ( defined( 'PS_TESTING' ) || ! ps_gdrive_connected() || get_transient( 'ps_gdrive_bg' ) ) {
			return;
		}
		set_transient( 'ps_gdrive_bg', 1, 10 * MINUTE_IN_SECONDS );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		ps_gdrive_sync( 3, 20.0 );
	},
	20
);
