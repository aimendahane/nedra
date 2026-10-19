<?php
/**
 * Connexion, sécurité de session et « look application ».
 *
 *  1. Page de connexion personnalisée (templates/login.php), traitée ici.
 *  2. Limitation des tentatives de connexion (réglable : 5 échecs / 15 min par défaut).
 *  3. Déconnexion après inactivité (réglable : 2 h par défaut), contrôlée côté serveur.
 *  4. Pas de barre admin ; wp-admin et wp-login.php inaccessibles aux employés.
 *  5. L'application ne répond que sur la page d'accueil (toute autre URL y est redirigée).
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Traitement du formulaire de connexion
 * ---------------------------------------------------------------------- */

add_action( 'init', 'ps_handle_login_post', 5 );
function ps_handle_login_post(): void {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( $_POST['ps_action'] ?? '' ) !== 'login' ) {
		return;
	}

	$home = home_url( '/' );
	if ( ! isset( $_POST['ps_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ps_login_nonce'] ) ), 'ps_login' ) ) {
		wp_safe_redirect( add_query_arg( 'ps_msg', 'failed', $home ) );
		exit;
	}

	$user = wp_signon(
		array(
			'user_login'    => sanitize_user( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore -- mot de passe : pas de nettoyage.
			'remember'      => false,
		),
		is_ssl()
	);

	if ( is_wp_error( $user ) ) {
		$code = in_array( $user->get_error_code(), array( 'ps_locked', 'ps_disabled' ), true ) ? substr( $user->get_error_code(), 3 ) : 'failed';
		wp_safe_redirect( add_query_arg( 'ps_msg', $code, $home ) );
		exit;
	}

	wp_safe_redirect( $home );
	exit;
}

/* -------------------------------------------------------------------------
 * 2. Limitation des tentatives de connexion
 *    Compteurs dans des transients : par (IP + identifiant) et par IP seule
 *    (l'IP seule a une limite 4 fois plus haute, contre l'essai de nombreux identifiants).
 * ---------------------------------------------------------------------- */

function ps_client_ip(): string {
	// REMOTE_ADDR uniquement : les en-têtes X-Forwarded-For peuvent être falsifiés.
	return preg_replace( '/[^0-9a-fA-F:.]/', '', (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
}

function ps_login_keys( string $username ): array {
	$ip = ps_client_ip();
	return array(
		'pair' => 'ps_lf_' . md5( $ip . '|' . strtolower( trim( $username ) ) ),
		'ip'   => 'ps_lfip_' . md5( $ip ),
	);
}

/**
 * Vrai si cet identifiant (depuis cette IP) est temporairement bloqué.
 */
function ps_login_is_locked( string $username ): bool {
	$max  = max( 1, (int) ps_setting( 'login_max_attempts' ) );
	$keys = ps_login_keys( $username );
	return (int) get_transient( $keys['pair'] ) >= $max || (int) get_transient( $keys['ip'] ) >= $max * 4;
}

// Priorité 99 : après la vérification du mot de passe → même un mot de passe correct
// est refusé pendant le blocage.
add_filter( 'authenticate', 'ps_login_block_if_locked', 99, 2 );
function ps_login_block_if_locked( $user, $username ) {
	if ( '' !== (string) $username && ps_login_is_locked( (string) $username ) ) {
		$minutes = (int) ps_setting( 'login_window_minutes' );
		return new WP_Error( 'ps_locked', sprintf( 'محاولات كثيرة. حاول مرة أخرى بعد %d دقيقة.', $minutes ) );
	}
	return $user;
}

// Compte désactivé par la الإدارة : connexion refusée et plus aucun droit dans l'application.
add_filter( 'authenticate', 'ps_login_block_disabled', 98, 2 );
function ps_login_block_disabled( $user ) {
	if ( $user instanceof WP_User && get_user_meta( $user->ID, 'ps_disabled', true ) ) {
		return new WP_Error( 'ps_disabled', 'هذا الحساب معطّل. اتصل بالإدارة.' );
	}
	return $user;
}
add_filter( 'user_has_cap', 'ps_disabled_user_caps', 10, 4 );
function ps_disabled_user_caps( array $allcaps, array $caps, array $args, WP_User $user ): array {
	if ( $user->ID && get_user_meta( $user->ID, 'ps_disabled', true ) ) {
		foreach ( array_keys( $allcaps ) as $cap ) {
			if ( str_starts_with( $cap, 'ps_' ) ) {
				$allcaps[ $cap ] = false;
			}
		}
	}
	return $allcaps;
}

add_action( 'wp_login_failed', 'ps_login_record_failure' );
function ps_login_record_failure( $username ): void {
	$window = max( 1, (int) ps_setting( 'login_window_minutes' ) ) * MINUTE_IN_SECONDS;
	foreach ( ps_login_keys( (string) $username ) as $key ) {
		set_transient( $key, (int) get_transient( $key ) + 1, $window );
	}
}

add_action( 'wp_login', 'ps_login_success', 10, 2 );
function ps_login_success( $username, $user ): void {
	delete_transient( ps_login_keys( (string) $username )['pair'] );
	update_user_meta( $user->ID, 'ps_last_activity', time() );
}

/* -------------------------------------------------------------------------
 * 3. Déconnexion après inactivité (contrôle serveur)
 *    « ps_last_activity » est mis à jour par les vraies actions de l'utilisateur
 *    (chargement de page, appels API). Les rafraîchissements automatiques envoient
 *    l'en-tête X-PS-Passive et ne prolongent pas la session.
 * ---------------------------------------------------------------------- */

/**
 * Vrai si la session de l'utilisateur a dépassé le délai d'inactivité.
 */
function ps_session_is_expired( int $user_id, ?int $now = null ): bool {
	$last = (int) get_user_meta( $user_id, 'ps_last_activity', true );
	if ( ! $last ) {
		return false;
	}
	$limit = max( 1, (int) ps_setting( 'inactivity_minutes' ) ) * MINUTE_IN_SECONDS;
	return ( ( $now ?? time() ) - $last ) > $limit;
}

/**
 * Enregistre une activité (au plus une écriture par minute).
 */
function ps_touch_activity( int $user_id ): void {
	$last = (int) get_user_meta( $user_id, 'ps_last_activity', true );
	if ( time() - $last >= 60 ) {
		update_user_meta( $user_id, 'ps_last_activity', time() );
	}
}

add_action( 'init', 'ps_enforce_inactivity', 2 );
function ps_enforce_inactivity(): void {
	$uid = get_current_user_id();
	if ( ! $uid || ! ps_session_is_expired( $uid ) ) {
		return;
	}
	wp_logout();
	wp_set_current_user( 0 );
	$GLOBALS['ps_session_expired'] = true;

	// Requête de page : retour à la connexion avec un message.
	if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! str_contains( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/wp-json/' ) ) {
		wp_safe_redirect( add_query_arg( 'ps_msg', 'expired', home_url( '/' ) ) );
		exit;
	}
}

/* -------------------------------------------------------------------------
 * 4. Pas de WordPress visible : barre admin, wp-admin, wp-login.php
 * ---------------------------------------------------------------------- */

add_filter( 'show_admin_bar', '__return_false' );

/**
 * Seuls les administrateurs WordPress (manage_options) accèdent à wp-admin.
 */
function ps_user_can_access_wp_admin( ?WP_User $user ): bool {
	return $user && $user->exists() && user_can( $user, 'manage_options' );
}

add_action( 'admin_init', 'ps_block_wp_admin', 1 );
function ps_block_wp_admin(): void {
	if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! ps_user_can_access_wp_admin( wp_get_current_user() ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}

// wp-login.php : seules les actions techniques restent possibles (déconnexion,
// lien de réinitialisation, reconnexion interne de wp-admin pour l'administrateur).
add_action( 'login_init', 'ps_redirect_wp_login' );
function ps_redirect_wp_login(): void {
	$action  = sanitize_key( $_REQUEST['action'] ?? 'login' );
	$allowed = array( 'logout', 'postpass', 'rp', 'resetpass', 'confirmaction' );
	if ( in_array( $action, $allowed, true ) || isset( $_REQUEST['interim-login'] ) ) {
		return;
	}
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// Les liens de connexion de WordPress pointent vers notre page.
add_filter( 'login_url', fn() => home_url( '/' ), 10, 0 );

// XML-RPC inutile → désactivé (surface d'attaque en moins).
add_filter( 'xmlrpc_enabled', '__return_false' );

// La liste des utilisateurs de l'API WordPress n'est pas publique.
add_filter( 'rest_pre_dispatch', 'ps_protect_wp_users_endpoint', 10, 3 );
function ps_protect_wp_users_endpoint( $result, $server, $request ) {
	if ( str_starts_with( $request->get_route(), '/wp/v2/users' ) && ! current_user_can( 'list_users' ) ) {
		return new WP_Error( 'rest_forbidden', 'غير مسموح', array( 'status' => 401 ) );
	}
	return $result;
}

/* -------------------------------------------------------------------------
 * 5. Une seule page : l'application
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'ps_only_app_page', 1 );
function ps_only_app_page(): void {
	if ( ( is_front_page() || is_home() ) && ! is_feed() && ! is_search() && ! is_paged() ) {
		return;
	}
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// Nettoyage de l'en-tête HTML (rien qui trahisse WordPress).
add_action( 'after_setup_theme', 'ps_clean_head' );
function ps_clean_head(): void {
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'feed_links', 2 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	add_theme_support( 'title-tag' );
}

// Application interne : pas d'indexation par les moteurs de recherche.
add_filter( 'wp_robots', fn( $r ) => array( 'noindex' => true, 'nofollow' => true ) );
add_filter( 'pre_get_document_title', fn() => 'متابعة المشاريع والعروض — Protecta Group' );
