<?php
/**
 * Emplacement des fichiers joints (1.14.0) : un seul point d'entrée pour le serveur, OneDrive (Microsoft 365) et
 * Google Drive. Les utilisateurs ne voient aucune différence : ils ajoutent et ouvrent les fichiers dans le site.
 *
 *   - ps_storage_provider()      : 'onedrive' | 'gdrive' | '' (serveur seulement), choisi par la الإدارة ;
 *   - ps_storage_queue( $id )     : fichier à envoyer APRÈS la réponse au navigateur (envoi instantané pour l'usager) ;
 *   - ps_storage_sync()           : envoie les fichiers encore sur le serveur (Cron, « مزامنة الآن », fin de requête) ;
 *   - ps_stream_remote()          : transmet un fichier distant au navigateur au fil de l'eau.
 * Les fichiers déjà rangés dans l'autre service restent lisibles (chaque fichier garde son emplacement).
 */

defined( 'ABSPATH' ) || exit;

/** Service choisi. Ancien site relié à Google Drive sans choix enregistré → Google Drive (comme avant). */
function ps_storage_provider(): string {
	$p = (string) get_option( 'ps_storage_provider', '' );
	if ( 'onedrive' === $p ) {
		return ps_onedrive_connected() ? 'onedrive' : '';
	}
	if ( 'gdrive' === $p || ( '' === $p && ps_gdrive_connected() ) ) {
		return ps_gdrive_connected() ? 'gdrive' : '';
	}
	return '';
}

/** Envoie un fichier vers le service choisi. @return true|WP_Error */
function ps_storage_push( array $f ) {
	switch ( ps_storage_provider() ) {
		case 'onedrive':
			return ps_onedrive_push( $f );
		case 'gdrive':
			return ps_gdrive_push( $f );
	}
	return new WP_Error( 'ps_storage_off', ps__( 'لا يوجد تخزين سحابي مربوط' ) );
}

/** Fichiers à envoyer en fin de requête (après la réponse). */
function ps_storage_queue( ?int $file_id = null ): array {
	static $queue = array();
	if ( $file_id ) {
		$queue[ $file_id ] = true;
	}
	return array_keys( $queue );
}

/**
 * Envoie les fichiers encore sur le serveur (anciens fichiers compris), les moins essayés d'abord.
 *
 * @return array { sent, failed, pending, errors }
 */
function ps_storage_sync( int $limit = 20, float $max_seconds = 25.0 ): array {
	global $wpdb;
	$out = array( 'sent' => 0, 'failed' => 0, 'pending' => 0, 'errors' => array() );
	if ( ps_storage_provider() ) {
		$t0   = microtime( true );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ps_table( 'files' ) . " WHERE storage = 'local' ORDER BY drive_tries, id LIMIT %d", $limit ), ARRAY_A );
		foreach ( $rows as $f ) {
			if ( microtime( true ) - $t0 > $max_seconds ) {
				break;
			}
			$r = ps_storage_push( $f );
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

/** État commun pour الإعدادات. */
function ps_storage_status(): array {
	global $wpdb;
	$ft = ps_table( 'files' );
	return array(
		'provider'   => ps_storage_provider(),
		'chosen'     => (string) get_option( 'ps_storage_provider', '' ),
		'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft WHERE storage = 'local'" ), // phpcs:ignore
		'localBytes' => (int) $wpdb->get_var( "SELECT COALESCE(SUM(size),0) FROM $ft WHERE storage = 'local'" ), // phpcs:ignore
		'inOneDrive' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft WHERE storage = 'onedrive'" ), // phpcs:ignore
		'inDrive'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ft WHERE storage = 'drive'" ), // phpcs:ignore
		'lastError'  => (string) $wpdb->get_var( "SELECT drive_error FROM $ft WHERE storage = 'local' AND drive_error IS NOT NULL ORDER BY id DESC LIMIT 1" ), // phpcs:ignore
	);
}

/**
 * Transmet au navigateur un fichier distant, morceau par morceau (le premier octet part tout de suite, pas
 * d'attente du fichier entier). En-têtes (type, nom, taille) déjà envoyés par l'appelant.
 * Secours sans cURL : copie temporaire puis envoi.
 *
 * @return true|WP_Error
 */
function ps_stream_remote( string $url, array $headers = array() ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url ); // phpcs:ignore
		$h  = array();
		foreach ( $headers as $k => $v ) {
			$h[] = $k . ': ' . $v;
		}
		$ok = false;
		curl_setopt_array( // phpcs:ignore
			$ch,
			array(
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_HTTPHEADER     => $h,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT        => 600,
				CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				CURLOPT_WRITEFUNCTION  => static function ( $c, $data ) use ( &$ok ) {
					$code = (int) curl_getinfo( $c, CURLINFO_RESPONSE_CODE ); // phpcs:ignore
					if ( $code >= 300 ) {
						return 0; // erreur du service : on n'envoie pas sa page d'erreur au navigateur
					}
					$ok = true;
					echo $data; // phpcs:ignore
					flush();
					return strlen( $data );
				},
			)
		);
		curl_exec( $ch ); // phpcs:ignore
		$err = curl_error( $ch ); // phpcs:ignore
		curl_close( $ch ); // phpcs:ignore
		return $ok ? true : new WP_Error( 'ps_stream', $err ?: 'HTTP error' );
	}
	$tmp = trailingslashit( get_temp_dir() ) . 'ps-st-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
	$r   = wp_remote_get( $url, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmp, 'headers' => $headers ) );
	if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) >= 300 ) {
		if ( is_file( $tmp ) ) {
			unlink( $tmp );
		}
		return new WP_Error( 'ps_stream', is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
	}
	readfile( $tmp ); // phpcs:ignore
	unlink( $tmp );
	return true;
}

/*
 * Fin de requête : fichiers ajoutés pendant cette requête, puis quelques anciens (au plus toutes les 10 min) —
 * APRÈS avoir rendu la réponse au navigateur (fastcgi_finish_request) : l'usager n'attend jamais le service distant.
 */
add_action(
	'shutdown',
	static function () {
		if ( defined( 'PS_TESTING' ) || ! ps_storage_provider() ) {
			return;
		}
		$ids = ps_storage_queue();
		$bg  = ! get_transient( 'ps_storage_bg' );
		if ( ! $ids && ! $bg ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		ignore_user_abort( true );
		foreach ( $ids as $id ) {
			$f = ps_file_get( (int) $id );
			if ( $f && 'local' === $f['storage'] ) {
				ps_storage_push( $f ); // échec non bloquant : le fichier reste sur le serveur et repartira plus tard
			}
		}
		if ( $bg ) {
			set_transient( 'ps_storage_bg', 1, 10 * MINUTE_IN_SECONDS );
			ps_storage_sync( 3, 20.0 );
		}
	},
	20
);
