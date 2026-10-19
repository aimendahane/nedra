<?php
/**
 * Chargement des CSS / JS (sans build : fichiers servis tels quels).
 *
 * Pour ajouter une page :
 *   1. créer assets/js/pages/ma-page.js (elle s'inscrit avec PS.router.register) ;
 *   2. ajouter son nom dans ps_page_scripts() ci-dessous.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fichiers JS du cœur, dans l'ordre de chargement.
 */
function ps_core_scripts(): array {
	return array( 'format', 'ui', 'api', 'session', 'router' );
}

/**
 * Un fichier JS par page (assets/js/pages/<nom>.js), dans l'ordre du menu.
 */
function ps_page_scripts(): array {
	return array( 'mes-taches', 'fiche-projet', 'base-projets', 'suivi-offres', 'suivi-livraison', 'recap', 'annuaire', 'parametres' );
}

add_action( 'wp_enqueue_scripts', 'ps_enqueue_assets' );
function ps_enqueue_assets(): void {
	$v = PS_VERSION;

	// Styles WordPress inutiles pour une application.
	foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $h ) {
		wp_dequeue_style( $h );
	}

	wp_enqueue_style( 'ps-variables', PS_URL . '/assets/css/variables.css', array(), $v );
	wp_enqueue_style( 'ps-app', PS_URL . '/assets/css/app.css', array( 'ps-variables' ), $v );
	wp_enqueue_style( 'ps-print', PS_URL . '/assets/css/print.css', array( 'ps-app' ), $v, 'print' );

	if ( ! is_user_logged_in() || ! current_user_can( 'ps_access' ) ) {
		return; // Page de connexion : CSS seulement.
	}

	$prev = array();
	foreach ( ps_core_scripts() as $name ) {
		$handle = 'ps-core-' . $name;
		wp_enqueue_script( $handle, PS_URL . '/assets/js/core/' . $name . '.js', $prev, $v, true );
		$prev = array( $handle );
	}
	$router = $prev;
	foreach ( ps_page_scripts() as $name ) {
		wp_enqueue_script( 'ps-page-' . $name, PS_URL . '/assets/js/pages/' . $name . '.js', $router, $v, true );
	}

	wp_add_inline_script( 'ps-core-format', 'window.PS = window.PS || {}; PS.config = ' . wp_json_encode( ps_client_config(), JSON_UNESCAPED_UNICODE ) . ';', 'before' );
}

/**
 * Configuration transmise au JavaScript (PS.config).
 * Les capacités servent uniquement à l'affichage : le serveur revérifie tout.
 */
function ps_client_config(): array {
	return array(
		'version'  => PS_VERSION,
		'restUrl'  => esc_url_raw( rest_url( PS_REST_NS . '/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'homeUrl'  => esc_url_raw( home_url( '/' ) ),
		'assetUrl' => esc_url_raw( PS_URL . '/assets/' ),
		'user'     => ps_current_user_payload(),
		'settings' => array(
			'inactivityMinutes' => (int) ps_setting( 'inactivity_minutes' ),
		),
	);
}

/**
 * Données de l'utilisateur connecté (aussi renvoyées par GET /me).
 */
function ps_current_user_payload(): array {
	$u    = wp_get_current_user();
	$caps = array();
	foreach ( ps_all_caps() as $cap ) {
		$caps[ $cap ] = user_can( $u, $cap );
	}
	$theme = get_user_meta( $u->ID, 'ps_theme', true );
	return array(
		'id'         => (int) $u->ID,
		'name'       => $u->display_name,
		'login'      => $u->user_login,
		'role'       => ps_user_role_label( $u ),
		'employeeId' => (int) get_user_meta( $u->ID, 'ps_employee_id', true ) ?: null,
		'caps'       => $caps,
		'prefs'      => array(
			'theme'      => in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : 'auto',
			'dailyEmail' => (bool) get_user_meta( $u->ID, 'ps_daily_email', true ),
		),
	);
}
