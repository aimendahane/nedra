<?php
/**
 * OneDrive / SharePoint (Microsoft 365) : emplacement des fichiers joints (1.14.0, demande du client : « les fichiers
 * doivent être enregistrés dans OneDrive, mais l'utilisateur ne doit pas s'en rendre compte : il ajoute et retrouve
 * ses fichiers dans le site comme d'habitude »).
 *
 * Liaison (الإعدادات ← عام ← « تخزين الملفات », الإدارة seulement) : une « application » enregistrée une fois dans
 * Microsoft Entra ID (portail Azure) avec l'autorisation Microsoft Graph « Files.ReadWrite.All » (type Application,
 * accord de l'administrateur) → Tenant ID + Client ID + Client Secret. Destination au choix :
 *   - le OneDrive d'un compte de la société (adresse e-mail, ex. archive@societe.com) ;
 *   - la bibliothèque de documents d'un site SharePoint (adresse du site).
 * Les fichiers y sont rangés dans « Protecta Suivi / <رقم المشروع — اسم المشروع> / ».
 *
 * Fonctionnement (règle n°1 : rien n'est perdu) :
 *   1. le fichier est d'abord enregistré sur le serveur (storage = local) — l'envoi de l'utilisateur est immédiat ;
 *   2. il est envoyé dans OneDrive APRÈS la réponse au navigateur (fin de requête), sinon par le Cron / « مزامنة الآن » ;
 *   3. la copie du serveur n'est effacée qu'après vérification : taille + empreinte « quickXorHash » de Microsoft
 *      identiques (en cas de doute, le fichier est relu depuis OneDrive et comparé en MD5) ; sinon il reste sur le
 *      serveur (drive_error) et repartira plus tard ;
 *   4. lecture : le site demande à Microsoft un lien de téléchargement temporaire et TRANSMET le fichier au navigateur
 *      au fil de l'eau (aucun lien OneDrive n'est montré ; contrôle des droits du site avant).
 * Un fichier mis à la corbeille du site reste dans OneDrive (jamais supprimé).
 *
 * Secrets (option ps_onedrive) : jamais renvoyés au navigateur ni écrits dans la sauvegarde JSON.
 */

defined( 'ABSPATH' ) || exit;

const PS_GRAPH = 'https://graph.microsoft.com/v1.0';

/** Réglages OneDrive (option séparée de ps_settings, hors sauvegarde JSON). */
function ps_onedrive_conf(): array {
	return array_merge(
		array( 'tenant_id' => '', 'client_id' => '', 'client_secret' => '', 'target_type' => 'user', 'target' => '', 'drive_id' => '', 'drive_name' => '', 'root' => 'Protecta Suivi', 'connected_at' => '' ),
		(array) get_option( 'ps_onedrive', array() )
	);
}

function ps_onedrive_save( array $changes ): void {
	update_option( 'ps_onedrive', array_merge( ps_onedrive_conf(), $changes ), false );
	delete_transient( 'ps_onedrive_token' );
}

/** Relié et utilisable (identifiants + bibliothèque trouvée). */
function ps_onedrive_connected(): bool {
	$c = ps_onedrive_conf();
	return '' !== $c['tenant_id'] && '' !== $c['client_id'] && '' !== $c['client_secret'] && '' !== $c['drive_id'];
}

/* ---------------------------------------------------------------------- */
/* Jeton et appels Microsoft Graph                                         */
/* ---------------------------------------------------------------------- */

/** @return string|WP_Error Jeton d'application (« client credentials », mis en cache ~55 min). */
function ps_onedrive_token() {
	$cached = get_transient( 'ps_onedrive_token' );
	if ( $cached ) {
		return $cached;
	}
	$c = ps_onedrive_conf();
	if ( '' === $c['tenant_id'] || '' === $c['client_id'] || '' === $c['client_secret'] ) {
		return new WP_Error( 'ps_onedrive_off', ps__( 'OneDrive غير مربوط' ), array( 'status' => 409 ) );
	}
	$r    = wp_remote_post(
		'https://login.microsoftonline.com/' . rawurlencode( $c['tenant_id'] ) . '/oauth2/v2.0/token',
		array(
			'timeout' => 20,
			'body'    => array( 'grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'scope' => 'https://graph.microsoft.com/.default' ),
		)
	);
	$data = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( empty( $data['access_token'] ) ) {
		$why = is_wp_error( $r ) ? $r->get_error_message() : ( $data['error_description'] ?? $data['error'] ?? wp_remote_retrieve_response_code( $r ) );
		return new WP_Error( 'ps_onedrive_token', ps__( 'رفضت Microsoft الاتصال: ' ) . mb_substr( (string) $why, 0, 300 ), array( 'status' => 502 ) );
	}
	set_transient( 'ps_onedrive_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 300 ) );
	return $data['access_token'];
}

/** Appel JSON de Microsoft Graph. @return array|WP_Error { data, code } */
function ps_onedrive_api( string $method, string $url, ?array $json = null, array $extra = array() ) {
	$token = ps_onedrive_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array_merge( array( 'method' => $method, 'timeout' => 60, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ), $extra );
	if ( null !== $json ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $json );
	}
	$r = wp_remote_request( str_starts_with( $url, 'http' ) ? $url : PS_GRAPH . $url, $args );
	if ( is_wp_error( $r ) ) {
		return new WP_Error( 'ps_onedrive_http', ps__( 'تعذّر الاتصال بـ OneDrive: ' ) . $r->get_error_message(), array( 'status' => 502 ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	if ( $code >= 300 ) {
		if ( 401 === $code ) {
			delete_transient( 'ps_onedrive_token' );
		}
		$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
		return new WP_Error( 'ps_onedrive_api', 'OneDrive: ' . mb_substr( (string) $msg, 0, 300 ), array( 'status' => 502, 'http' => $code, 'graph' => $data['error']['code'] ?? '' ) );
	}
	return array( 'data' => is_array( $data ) ? $data : array(), 'code' => $code );
}

/* ---------------------------------------------------------------------- */
/* Liaison                                                                  */
/* ---------------------------------------------------------------------- */

/**
 * Trouve la bibliothèque (drive) de la destination choisie et vérifie qu'on peut y écrire (petit fichier test
 * créé puis effacé). @return array|WP_Error { drive_id, drive_name }
 */
function ps_onedrive_resolve( array $c ) {
	if ( 'site' === $c['target_type'] ) {
		$u = wp_parse_url( trim( $c['target'] ) );
		if ( empty( $u['host'] ) ) {
			return new WP_Error( 'ps_validation', ps__( 'رابط موقع SharePoint غير صحيح (مثال: https://societe.sharepoint.com/sites/Archives)' ), array( 'status' => 422, 'errors' => array( 'target' => ps__( 'رابط موقع SharePoint غير صحيح (مثال: https://societe.sharepoint.com/sites/Archives)' ) ) ) );
		}
		$path = rtrim( (string) ( $u['path'] ?? '' ), '/' );
		$site = ps_onedrive_api( 'GET', '/sites/' . $u['host'] . ( $path ? ':' . str_replace( '%2F', '/', rawurlencode( $path ) ) : '' ) . '?$select=id,displayName' );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$drive = ps_onedrive_api( 'GET', '/sites/' . rawurlencode( (string) $site['data']['id'] ) . '/drive?$select=id,name,driveType' );
		$label = ( $site['data']['displayName'] ?? '' ) . ' (SharePoint)';
	} else {
		$email = trim( $c['target'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'ps_validation', ps__( 'اكتب بريد الحساب الذي يُحفظ فيه (مثال: archive@societe.com)' ), array( 'status' => 422, 'errors' => array( 'target' => ps__( 'اكتب بريد الحساب الذي يُحفظ فيه (مثال: archive@societe.com)' ) ) ) );
		}
		$drive = ps_onedrive_api( 'GET', '/users/' . rawurlencode( $email ) . '/drive?$select=id,name,driveType' );
		$label = $email;
	}
	if ( is_wp_error( $drive ) ) {
		return $drive;
	}
	$id = (string) ( $drive['data']['id'] ?? '' );
	// Test d'écriture : Files.ReadWrite.All bien accordé (sinon « Access denied » dès maintenant, pas au 1er fichier).
	$test = ps_onedrive_api( 'PUT', '/drives/' . rawurlencode( $id ) . '/root:/' . ps_onedrive_path( $c['root'], '.protecta-test.txt' ) . ':/content', null, array( 'body' => 'Protecta Suivi ' . ps_now(), 'headers' => array( 'Authorization' => 'Bearer ' . ps_onedrive_token(), 'Content-Type' => 'text/plain' ) ) );
	if ( is_wp_error( $test ) ) {
		return $test;
	}
	if ( ! empty( $test['data']['id'] ) ) {
		ps_onedrive_api( 'DELETE', '/drives/' . rawurlencode( $id ) . '/items/' . rawurlencode( (string) $test['data']['id'] ) );
	}
	return array( 'drive_id' => $id, 'drive_name' => $label );
}

/** Chemin Graph encodé (segments séparés par « / »), noms nettoyés des caractères refusés par OneDrive. */
function ps_onedrive_path( string ...$segments ): string {
	$out = array();
	foreach ( $segments as $s ) {
		$s = preg_replace( '/[\\\\\/:*?"<>|#%\x00-\x1F]+/u', ' ', $s );
		$s = trim( preg_replace( '/\s+/u', ' ', (string) $s ), ' .' );
		$out[] = rawurlencode( mb_substr( '' === $s ? '_' : $s, 0, 150 ) );
	}
	return implode( '/', $out );
}

/* ---------------------------------------------------------------------- */
/* Empreinte quickXorHash (Microsoft)                                      */
/* ---------------------------------------------------------------------- */

/**
 * Empreinte « quickXorHash » d'un fichier (base64), celle que OneDrive / SharePoint donne pour chaque fichier.
 * Principe : registre circulaire de 160 bits ; l'octet n° i est « XOR-é » à la position (11 × i) mod 160 ; à la fin
 * la longueur (64 bits, petit-boutiste) est XOR-ée dans les 8 derniers octets. Traité par blocs (mémoire constante).
 */
function ps_quickxor_file( string $path ): ?string {
	$h = fopen( $path, 'rb' ); // phpcs:ignore
	if ( ! $h ) {
		return null;
	}
	$st = array( 'data' => array( 0, 0, 0 ), 'shift' => 0, 'len' => 0 );
	while ( ! feof( $h ) ) {
		$chunk = fread( $h, 1048576 ); // phpcs:ignore
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		ps_quickxor_update( $st, $chunk );
	}
	fclose( $h ); // phpcs:ignore
	return ps_quickxor_final( $st );
}

/** Ajoute un bloc d'octets à l'empreinte (algorithme de référence de Microsoft, en entiers 64 bits). */
function ps_quickxor_update( array &$st, string $bytes ): void {
	$n = strlen( $bytes );
	if ( ! $n ) {
		return;
	}
	$arr   = unpack( 'C*', $bytes ); // indices à partir de 1
	$data  = &$st['data'];
	$index = intdiv( $st['shift'], 64 );
	$off   = $st['shift'] % 64;
	$iter  = min( $n, 160 );
	for ( $i = 0; $i < $iter; $i++ ) {
		$last = 2 === $index;
		$bits = $last ? 32 : 64;
		if ( $off <= $bits - 8 ) {
			for ( $j = $i; $j < $n; $j += 160 ) {
				$data[ $index ] ^= $arr[ $j + 1 ] << $off;
			}
		} else {
			$x = 0;
			for ( $j = $i; $j < $n; $j += 160 ) {
				$x ^= $arr[ $j + 1 ];
			}
			$data[ $index ] ^= $x << $off;
			$data[ $last ? 0 : $index + 1 ] ^= $x >> ( $bits - $off );
		}
		$off += 11;
		while ( $off >= $bits ) {
			$index = $last ? 0 : $index + 1;
			$off  -= $bits;
			$last  = 2 === $index;
			$bits  = $last ? 32 : 64;
		}
	}
	$st['shift'] = ( $st['shift'] + 11 * ( $n % 160 ) ) % 160;
	$st['len']  += $n;
}

function ps_quickxor_final( array $st ): string {
	$rgb = substr( pack( 'P', $st['data'][0] ), 0, 8 ) . substr( pack( 'P', $st['data'][1] ), 0, 8 ) . substr( pack( 'P', $st['data'][2] ), 0, 4 );
	$len = pack( 'P', $st['len'] );
	for ( $i = 0; $i < 8; $i++ ) {
		$rgb[ 12 + $i ] = chr( ord( $rgb[ 12 + $i ] ) ^ ord( $len[ $i ] ) );
	}
	return base64_encode( $rgb );
}

/* ---------------------------------------------------------------------- */
/* Envoi, lecture, synchronisation                                         */
/* ---------------------------------------------------------------------- */

/**
 * Envoie un fichier du serveur dans OneDrive (session d'envoi par morceaux), vérifie, puis libère le serveur.
 *
 * @return true|WP_Error
 */
function ps_onedrive_push( array $f ) {
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
		return $fail( new WP_Error( 'ps_onedrive_local', ps__( 'الملف غير موجود على الخادم' ) ) );
	}
	$c       = ps_onedrive_conf();
	$p       = ps_project_row( (int) $f['project_id'] );
	$folder  = $p ? $p['project_no'] . ' — ' . $p['name'] : 'project-' . (int) $f['project_id'];
	$target  = ps_onedrive_path( $c['root'], $folder, ps_file_download_name( $f ) );
	$session = ps_onedrive_api( 'POST', '/drives/' . rawurlencode( $c['drive_id'] ) . '/root:/' . $target . ':/createUploadSession', array( 'item' => array( '@microsoft.graph.conflictBehavior' => 'rename' ) ) );
	if ( is_wp_error( $session ) ) {
		return $fail( $session );
	}
	$url  = (string) ( $session['data']['uploadUrl'] ?? '' );
	$size = filesize( $path );
	if ( '' === $url ) {
		return $fail( new WP_Error( 'ps_onedrive_api', 'OneDrive: no upload URL' ) );
	}
	// Morceaux de 5 Mo (multiple de 320 Kio, exigence Microsoft). L'adresse d'envoi ne demande pas de jeton.
	$step = max( 1, (int) apply_filters( 'ps_onedrive_chunk_units', 16 ) ) * 327680;
	$h    = fopen( $path, 'rb' ); // phpcs:ignore
	$item = null;
	for ( $pos = 0; $pos < $size || 0 === $size; ) {
		$chunk = 0 === $size ? '' : (string) fread( $h, $step ); // phpcs:ignore
		$end   = $pos + strlen( $chunk ) - 1;
		$r     = wp_remote_request( $url, array( 'method' => 'PUT', 'timeout' => 180, 'body' => $chunk, 'headers' => array( 'Content-Length' => (string) strlen( $chunk ), 'Content-Range' => 'bytes ' . $pos . '-' . max( $end, 0 ) . '/' . $size ) ) );
		$code  = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( ! in_array( $code, array( 200, 201, 202 ), true ) ) {
			fclose( $h ); // phpcs:ignore
			wp_remote_request( $url, array( 'method' => 'DELETE', 'timeout' => 15 ) );
			return $fail( new WP_Error( 'ps_onedrive_upload', 'OneDrive: ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . $code ) ) );
		}
		if ( 202 !== $code ) {
			$item = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			break;
		}
		$pos += strlen( $chunk );
		if ( 0 === $size ) {
			break;
		}
	}
	fclose( $h ); // phpcs:ignore
	if ( empty( $item['id'] ) ) {
		return $fail( new WP_Error( 'ps_onedrive_upload', 'OneDrive: upload incomplete' ) );
	}
	// Vérification avant d'effacer la copie du serveur.
	$remote = (string) ( $item['file']['hashes']['quickXorHash'] ?? '' );
	$ok     = (int) ( $item['size'] ?? -1 ) === (int) $size && '' !== $remote && hash_equals( $remote, (string) ps_quickxor_file( $path ) );
	if ( ! $ok && (int) ( $item['size'] ?? -1 ) === (int) $size ) {
		// Empreinte absente / différente : on relit le fichier depuis OneDrive et on compare (MD5).
		$tmp = ps_onedrive_fetch_tmp( (string) $item['id'] );
		$ok  = ! is_wp_error( $tmp ) && md5_file( $tmp ) === ( $f['md5'] ?: md5_file( $path ) );
		if ( ! is_wp_error( $tmp ) && is_file( $tmp ) ) {
			unlink( $tmp );
		}
	}
	if ( ! $ok ) {
		return $fail( new WP_Error( 'ps_onedrive_hash', ps__( 'تحقق OneDrive فشل (الملف مختلف): بقي الملف على الخادم' ) ) );
	}
	$wpdb->update( ps_table( 'files' ), array( 'storage' => 'onedrive', 'drive_id' => (string) $item['id'], 'drive_error' => null ), array( 'id' => (int) $f['id'] ) );
	unlink( $path ); // copie du serveur effacée APRÈS vérification
	return true;
}

/** Lien de téléchargement temporaire (pré-authentifié, ~1 h) d'un élément OneDrive. @return string|WP_Error */
function ps_onedrive_download_url( string $item_id ) {
	$c = ps_onedrive_conf();
	$r = ps_onedrive_api( 'GET', '/drives/' . rawurlencode( $c['drive_id'] ) . '/items/' . rawurlencode( $item_id ) . '?$select=id,size,@microsoft.graph.downloadUrl' );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$url = (string) ( $r['data']['@microsoft.graph.downloadUrl'] ?? '' );
	return '' === $url ? new WP_Error( 'ps_onedrive_api', 'OneDrive: no download URL' ) : $url;
}

/** Copie temporaire d'un fichier OneDrive (vérification après envoi). @return string|WP_Error */
function ps_onedrive_fetch_tmp( string $item_id ) {
	$url = ps_onedrive_download_url( $item_id );
	if ( is_wp_error( $url ) ) {
		return $url;
	}
	$tmp = trailingslashit( get_temp_dir() ) . 'ps-od-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
	$r   = wp_remote_get( $url, array( 'timeout' => 180, 'stream' => true, 'filename' => $tmp ) );
	if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
		if ( is_file( $tmp ) ) {
			unlink( $tmp );
		}
		return new WP_Error( 'ps_onedrive_fetch', ps__( 'تعذّر جلب الملف من OneDrive' ) );
	}
	return $tmp;
}

/**
 * Lecture d'un fichier OneDrive pour le navigateur : adresse temporaire à transmettre au fil de l'eau
 * (ps_stream_remote). @return string|WP_Error
 */
function ps_onedrive_source( array $f ) {
	if ( ! ps_onedrive_connected() ) {
		return new WP_Error( 'ps_onedrive_off', ps__( 'الملف محفوظ في OneDrive، لكن الربط مقطوع: اطلب من الإدارة إعادة ربط OneDrive (الإعدادات ← عام).' ), array( 'status' => 503 ) );
	}
	$url = ps_onedrive_download_url( (string) $f['drive_id'] );
	return is_wp_error( $url ) ? new WP_Error( 'ps_onedrive_fetch', ps__( 'تعذّر جلب الملف من OneDrive: ' ) . $url->get_error_message(), array( 'status' => 502 ) ) : $url;
}

/** État pour الإعدادات (sans aucun secret). */
function ps_onedrive_status(): array {
	global $wpdb;
	$c  = ps_onedrive_conf();
	$ft = ps_table( 'files' );
	return array(
		'connected'   => ps_onedrive_connected(),
		'tenantId'    => $c['tenant_id'],
		'clientId'    => $c['client_id'],
		'hasSecret'   => '' !== $c['client_secret'],
		'targetType'  => $c['target_type'],
		'target'      => $c['target'],
		'driveName'   => $c['drive_name'],
		'root'        => $c['root'],
		'connectedAt' => $c['connected_at'],
		'inOneDrive'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft WHERE storage = 'onedrive'" ), // phpcs:ignore
	);
}
