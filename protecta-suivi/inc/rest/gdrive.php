<?php
/**
 * API « تخزين الملفات — Google Drive » (1.5.0, الإدارة : capacité ps_manage_settings).
 *
 *   GET  /gdrive              état (sans secret)
 *   POST /gdrive/config       { method: oauth|service, clientId, clientSecret, saJson, folderId }
 *                             oauth → enregistre le client puis renvoie { authUrl } (accord à donner chez Google) ;
 *                             service → vérifie la clé et l'accès au dossier, puis relie.
 *   POST /gdrive/sync         envoie maintenant les fichiers encore sur le serveur
 *   POST /gdrive/disconnect   coupe le lien (les fichiers déjà dans Drive y restent)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_gdrive_routes' );
function ps_register_gdrive_routes(): void {
	$perm = ps_rest_permission( 'ps_manage_settings' );
	register_rest_route( PS_REST_NS, '/gdrive', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( ps_gdrive_status() ) ) );
	register_rest_route( PS_REST_NS, '/gdrive/config', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'ps_rest_gdrive_config' ) );
	register_rest_route(
		PS_REST_NS,
		'/gdrive/sync',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static fn() => rest_ensure_response( ps_gdrive_sync( 20, 25.0 ) + array( 'status' => ps_gdrive_status() ) ),
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/gdrive/disconnect',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function () {
				ps_gdrive_save( array( 'refresh_token' => '', 'sa_json' => '', 'account' => '', 'connected_at' => '' ) );
				ps_log( null, 'update', 'settings', null, 'gdrive', null, 'disconnect' );
				return rest_ensure_response( ps_gdrive_status() );
			},
		)
	);
}

function ps_rest_gdrive_config( WP_REST_Request $r ) {
	$method = (string) $r->get_param( 'method' );
	$folder = trim( (string) $r->get_param( 'folderId' ) );
	// Lien complet d'un dossier collé (…/folders/<id>) → identifiant seul.
	if ( preg_match( '#/folders/([A-Za-z0-9_\-]+)#', $folder, $m ) ) {
		$folder = $m[1];
	}
	if ( 'oauth' === $method ) {
		$id     = trim( (string) $r->get_param( 'clientId' ) );
		$secret = trim( (string) $r->get_param( 'clientSecret' ) );
		$errors = array();
		if ( ! preg_match( '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $id ) ) {
			$errors['clientId'] = ps__( 'Client ID غير صحيح (ينتهي بـ apps.googleusercontent.com)' );
		}
		if ( '' === $secret && '' === ps_gdrive_conf()['client_secret'] ) {
			$errors['clientSecret'] = ps__( 'الصق Client Secret' );
		}
		if ( $errors ) {
			return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات' ), array( 'status' => 422, 'errors' => $errors ) );
		}
		$changes = array( 'method' => 'oauth', 'client_id' => $id, 'folder_id' => $folder );
		if ( '' !== $secret ) {
			$changes['client_secret'] = $secret; // vide = garder l'ancien (jamais renvoyé au navigateur)
		}
		if ( ps_gdrive_conf()['client_id'] !== $id ) {
			$changes['refresh_token'] = ''; // autre client → nouvel accord nécessaire
		}
		ps_gdrive_save( $changes );
		return rest_ensure_response( array( 'authUrl' => ps_gdrive_auth_url( get_current_user_id() ), 'status' => ps_gdrive_status() ) );
	}
	if ( 'service' === $method ) {
		$json = trim( (string) $r->get_param( 'saJson' ) );
		if ( '' === $json ) {
			$json = ps_gdrive_conf()['sa_json'];
		}
		$key = json_decode( $json, true );
		if ( empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات' ), array( 'status' => 422, 'errors' => array( 'saJson' => ps__( 'ملف المفتاح (JSON) غير صالح: يجب أن يحتوي على client_email و private_key' ) ) ) );
		}
		if ( '' === $folder ) {
			return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات' ), array( 'status' => 422, 'errors' => array( 'folderId' => ps__( 'الصق رابط أو معرّف مجلد Drive المشترك' ) ) ) );
		}
		$previous = ps_gdrive_conf();
		ps_gdrive_save( array( 'method' => 'service', 'sa_json' => $json, 'folder_id' => $folder, 'account' => $key['client_email'] ) );
		// Vérification réelle : jeton + accès en écriture au dossier.
		$check = ps_gdrive_api( 'GET', PS_GDRIVE_API . '/files/' . rawurlencode( $folder ) . '?supportsAllDrives=true&fields=id,name,capabilities(canAddChildren)' );
		if ( is_wp_error( $check ) || empty( $check['data']['capabilities']['canAddChildren'] ) ) {
			update_option( 'ps_gdrive', $previous, false ); // rien n'est changé si la vérification échoue
			delete_transient( 'ps_gdrive_token' );
			$why = is_wp_error( $check ) ? $check->get_error_message() : ps__( 'لا يملك حساب الخدمة صلاحية الإضافة في هذا المجلد (أضفه كـ «مدير محتوى»).' );
			return new WP_Error( 'ps_gdrive_check', $why, array( 'status' => 422, 'errors' => array( 'folderId' => $why ) ) );
		}
		ps_gdrive_save( array( 'connected_at' => ps_now() ) );
		ps_log( null, 'update', 'settings', null, 'gdrive', null, $key['client_email'] );
		return rest_ensure_response( array( 'status' => ps_gdrive_status() ) );
	}
	return new WP_Error( 'ps_validation', ps__( 'اختر طريقة الربط' ), array( 'status' => 422 ) );
}
