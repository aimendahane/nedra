<?php
/**
 * Routes de الإعدادات pour l'emplacement des fichiers (1.14.0) — الإدارة seulement (ps_manage_settings) :
 *   GET  /storage                → état commun (service choisi, fichiers en attente…) + OneDrive + Google Drive
 *   POST /storage/provider       → { provider: onedrive | gdrive | local }
 *   POST /storage/sync           → envoie maintenant les fichiers encore sur le serveur
 *   POST /onedrive/config        → { tenantId, clientId, clientSecret?, targetType: user|site, target } : vérifie et relie
 *   POST /onedrive/disconnect    → oublie le secret (les fichiers déjà dans OneDrive y restent)
 * Aucun secret n'est jamais renvoyé.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'ps_register_onedrive_routes' );
function ps_register_onedrive_routes(): void {
	$perm  = ps_rest_permission( 'ps_manage_settings' );
	$state = static fn() => array( 'storage' => ps_storage_status(), 'onedrive' => ps_onedrive_status(), 'gdrive' => ps_gdrive_status() );
	register_rest_route( PS_REST_NS, '/storage', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => static fn() => rest_ensure_response( $state() ) ) );
	register_rest_route(
		PS_REST_NS,
		'/storage/provider',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function ( WP_REST_Request $r ) use ( $state ) {
				$p = (string) $r->get_param( 'provider' );
				if ( ! in_array( $p, array( 'onedrive', 'gdrive', 'local' ), true ) ) {
					return new WP_Error( 'ps_validation', ps__( 'اختيار غير صحيح' ), array( 'status' => 422 ) );
				}
				if ( ( 'onedrive' === $p && ! ps_onedrive_connected() ) || ( 'gdrive' === $p && ! ps_gdrive_connected() ) ) {
					return new WP_Error( 'ps_validation', ps__( 'اربط هذا التخزين أولًا' ), array( 'status' => 422 ) );
				}
				update_option( 'ps_storage_provider', $p, false );
				ps_log( null, 'update', 'settings', null, 'storage_provider', null, $p );
				return rest_ensure_response( $state() );
			},
		)
	);
	register_rest_route(
		PS_REST_NS,
		'/storage/sync',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static fn() => rest_ensure_response( ps_storage_sync( 20, 25.0 ) + $state() ),
		)
	);
	register_rest_route( PS_REST_NS, '/onedrive/config', array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'ps_rest_onedrive_config' ) );
	register_rest_route(
		PS_REST_NS,
		'/onedrive/disconnect',
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => static function () use ( $state ) {
				ps_onedrive_save( array( 'client_secret' => '', 'drive_id' => '', 'connected_at' => '' ) );
				ps_log( null, 'update', 'settings', null, 'onedrive', null, 'disconnect' );
				return rest_ensure_response( $state() );
			},
		)
	);
}

function ps_rest_onedrive_config( WP_REST_Request $r ) {
	$guid   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	$tenant = trim( (string) $r->get_param( 'tenantId' ) );
	$client = trim( (string) $r->get_param( 'clientId' ) );
	$secret = trim( (string) $r->get_param( 'clientSecret' ) );
	$type   = 'site' === $r->get_param( 'targetType' ) ? 'site' : 'user';
	$target = trim( (string) $r->get_param( 'target' ) );
	$prev   = ps_onedrive_conf();
	$errors = array();
	if ( ! preg_match( $guid, $tenant ) && ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $tenant ) ) {
		$errors['tenantId'] = ps__( 'Tenant ID غير صحيح (مثال: 72f988bf-86f1-41af-91ab-2d7cd011db47)' );
	}
	if ( ! preg_match( $guid, $client ) ) {
		$errors['clientId'] = ps__( 'Client ID غير صحيح (Application ID بنفس الشكل)' );
	}
	if ( '' === $secret && '' === $prev['client_secret'] ) {
		$errors['clientSecret'] = ps__( 'الصق Client Secret (Value)' );
	}
	if ( '' === $target ) {
		$errors['target'] = 'site' === $type ? ps__( 'رابط موقع SharePoint غير صحيح (مثال: https://societe.sharepoint.com/sites/Archives)' ) : ps__( 'اكتب بريد الحساب الذي يُحفظ فيه (مثال: archive@societe.com)' );
	}
	if ( $errors ) {
		return new WP_Error( 'ps_validation', ps__( 'يرجى تصحيح الخانات المعلّمة' ), array( 'status' => 422, 'errors' => $errors ) );
	}
	$conf = array_merge( $prev, array( 'tenant_id' => $tenant, 'client_id' => $client, 'client_secret' => '' !== $secret ? $secret : $prev['client_secret'], 'target_type' => $type, 'target' => $target ) );
	ps_onedrive_save( array_merge( $conf, array( 'drive_id' => '' ) ) ); // le jeton est demandé avec ces identifiants
	$found = ps_onedrive_resolve( $conf );
	if ( is_wp_error( $found ) ) {
		ps_onedrive_save( $prev['drive_id'] ? $prev : array( 'drive_id' => '' ) ); // l'ancienne liaison (si elle existait) reste active
		$hint = ps_onedrive_hint( $found );
		return new WP_Error( 'ps_onedrive_check', $found->get_error_message() . ( $hint ? ' — ' . $hint : '' ), array( 'status' => 422 ) );
	}
	ps_onedrive_save( $found + array( 'connected_at' => ps_now() ) );
	update_option( 'ps_storage_provider', 'onedrive', false ); // relier OneDrive = l'utiliser pour les nouveaux fichiers
	ps_log( null, 'update', 'settings', null, 'onedrive', null, $found['drive_name'] );
	return rest_ensure_response( array( 'storage' => ps_storage_status(), 'onedrive' => ps_onedrive_status(), 'gdrive' => ps_gdrive_status() ) );
}

/** Explication en clair des refus fréquents de Microsoft. */
function ps_onedrive_hint( WP_Error $e ): string {
	$msg = $e->get_error_message();
	if ( preg_match( '/AADSTS7000215|invalid_client|secret/i', $msg ) ) {
		return ps__( 'تحقق من Client Secret: انسخ «Value» وليس «Secret ID»، وتأكد أنه غير منتهي الصلاحية.' );
	}
	if ( preg_match( '/AADSTS700016|AADSTS90002|tenant/i', $msg ) ) {
		return ps__( 'تحقق من Tenant ID و Client ID (صفحة Overview للتطبيق).' );
	}
	if ( preg_match( '/Access ?denied|accessDenied|Authorization_RequestDenied|Forbidden|403/i', $msg ) ) {
		return ps__( 'أضف صلاحية Microsoft Graph «Files.ReadWrite.All» من نوع Application ثم اضغط «Grant admin consent».' );
	}
	if ( preg_match( '/itemNotFound|ResourceNotFound|404|mysite/i', $msg ) ) {
		return ps__( 'الحساب أو الموقع غير موجود، أو لم يفتح صاحبه OneDrive مرة واحدة على الأقل.' );
	}
	return '';
}
