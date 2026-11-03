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
	return array( 'i18n', 'format', 'ui', 'icons', 'api', 'lists', 'session', 'router', 'notifications' );
}

/**
 * Un fichier JS par page (assets/js/pages/<nom>.js), dans l'ordre du menu.
 */
function ps_page_scripts(): array {
	return array( 'mes-taches', 'fiche-projet', 'fiche-projet-onglets', 'base-projets', 'suivi-offres', 'suivi-livraison', 'factures', 'recap', 'annuaire', 'parametres', 'parametres-utilisateurs', 'parametres-donnees' );
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

	// 1.14.0 (vitesse) : un seul fichier JS (cœur + pages, concaténés et mis en cache) au lieu de 22 requêtes ;
	// Chart.js n'est plus chargé ici : la page Recap le charge quand elle s'ouvre (PS.loadChart).
	$bundle = ps_js_bundle_url();
	if ( $bundle ) {
		wp_enqueue_script( 'ps-core-i18n', $bundle, array(), null, true ); // même identifiant : la configuration s'y attache
	} else {
		// Secours (dossier uploads non inscriptible) : un fichier par module, comme avant.
		$prev = array();
		foreach ( ps_core_scripts() as $name ) {
			$handle = 'ps-core-' . $name;
			wp_enqueue_script( $handle, PS_URL . '/assets/js/core/' . $name . '.js', $prev, $v, true );
			$prev = array( $handle );
		}
		foreach ( ps_page_scripts() as $name ) {
			wp_enqueue_script( 'ps-page-' . $name, PS_URL . '/assets/js/pages/' . $name . '.js', $prev, $v, true );
		}
	}

	wp_add_inline_script( 'ps-core-i18n', 'window.PS = window.PS || {}; PS.config = ' . wp_json_encode( ps_client_config(), JSON_UNESCAPED_UNICODE ) . ';', 'before' );
}

/**
 * Configuration transmise au JavaScript (PS.config).
 * Les capacités servent uniquement à l'affichage : le serveur revérifie tout.
 */
function ps_client_config(): array {
	return array(
		'version'  => PS_VERSION,
		// Langue de l'interface (1.3.0) : dictionnaire de la langue en cours seulement.
		'i18n'     => array(
			'lang'      => ps_lang(),
			'dir'       => ps_dir(),
			'dict'      => (object) ps_dict( ps_lang() ),
			'languages' => array_map( fn( $l ) => $l['label'], ps_languages() ),
		),
		'restUrl'  => esc_url_raw( rest_url( PS_REST_NS . '/' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'homeUrl'  => esc_url_raw( home_url( '/' ) ),
		'assetUrl' => esc_url_raw( PS_URL . '/assets/' ),
		'user'     => ps_current_user_payload(),
		'settings' => array(
			'inactivityMinutes' => (int) ps_setting( 'inactivity_minutes' ),
			'creatorEditHours'  => (int) ps_setting( 'creator_edit_hours' ),
			'validation'        => ps_validation_rules(),
			'maxUpload'         => ps_file_max_bytes(), // 1.5.0 : réglage « file_max_mb », plafonné par la limite du serveur
			'serverMaxUpload'   => (int) wp_max_upload_size(),
			'blockedExts'       => ps_file_blocked_exts(),
			'invoiceReplyDays'  => ps_invoice_reply_days(), // 1.6.0
			'invoiceRemindDays' => max( 1, (int) ps_setting( 'invoice_remind_days' ) ), // 1.11.0
		),
		'invoiceAnswers' => ps_invoice_answers(), // 1.6.0 : pending / yes / no
		'partyRoles' => ps_party_roles(),
		'today'      => ps_today(),
		'chartUrl'   => esc_url_raw( PS_URL . '/assets/vendor/chart.umd.min.js?ver=4.5.1' ), // 1.14.0 : chargé par Recap
		// 1.11.0 : listes ; 1.14.0 : compteurs du menu, notifications non lues, état Telegram → 3 appels de moins.
		'preload'    => array(
			'lists'    => ps_lists_payload(),
			'counters' => ps_counters( get_current_user_id() ),
			'unread'   => ps_notifications_unread( get_current_user_id() ),
			'telegram' => array(
				'enabled' => ps_tg_enabled(),
				'bot'     => ps_tg_bot(),
				'linked'  => '' !== ps_tg_chat_id( get_current_user_id() ),
			),
		),
	);
}

/**
 * 1.14.0 — Fichier JS unique : cœur + pages concaténés dans l'ordre (uploads/ps-cache/app-<empreinte>.js).
 * L'empreinte change avec la version et la date des fichiers → le navigateur garde le fichier en cache sans risque.
 * Rien n'est « construit » : les sources restent les fichiers de assets/js. Renvoie '' si l'écriture est impossible.
 */
function ps_js_bundle_url(): string {
	if ( defined( 'PS_NO_BUNDLE' ) && PS_NO_BUNDLE ) {
		return '';
	}
	$files = array();
	foreach ( ps_core_scripts() as $name ) {
		$files[] = PS_DIR . '/assets/js/core/' . $name . '.js';
	}
	foreach ( ps_page_scripts() as $name ) {
		$files[] = PS_DIR . '/assets/js/pages/' . $name . '.js';
	}
	$sig = PS_VERSION;
	foreach ( $files as $f ) {
		$sig .= '|' . $f . '|' . (int) @filemtime( $f ) . '|' . (int) @filesize( $f ); // phpcs:ignore
	}
	$name = 'app-' . substr( md5( $sig ), 0, 12 ) . '.js';
	$up   = wp_upload_dir( null, false );
	if ( ! empty( $up['error'] ) ) {
		return '';
	}
	$dir  = trailingslashit( $up['basedir'] ) . 'ps-cache';
	$path = $dir . '/' . $name;
	if ( ! is_file( $path ) ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			// Cache long (le nom change à chaque version) + compression, si l'hébergeur a ces modules.
			file_put_contents( $dir . '/.htaccess', "<IfModule mod_expires.c>\nExpiresActive On\nExpiresByType application/javascript \"access plus 1 year\"\nExpiresByType text/javascript \"access plus 1 year\"\n</IfModule>\n<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE application/javascript text/javascript\n</IfModule>\n" ); // phpcs:ignore
		}
		$js = "/* Protecta Suivi " . PS_VERSION . " — fichier généré (sources : assets/js/core, assets/js/pages) */\n";
		foreach ( $files as $f ) {
			$js .= "\n/* " . basename( dirname( $f ) ) . '/' . basename( $f ) . " */\n" . (string) file_get_contents( $f ) . "\n;\n";
		}
		$tmp = $path . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $js ) || ! rename( $tmp, $path ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return is_file( $path ) ? trailingslashit( $up['baseurl'] ) . 'ps-cache/' . $name : '';
		}
		// Anciennes versions du fichier : effacées (elles ne servent plus).
		foreach ( (array) glob( $dir . '/app-*.js' ) as $old ) {
			if ( $old !== $path && filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				@unlink( $old ); // phpcs:ignore
			}
		}
	}
	return trailingslashit( $up['baseurl'] ) . 'ps-cache/' . $name;
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
			'theme'        => in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : 'auto',
			'dailyEmail'   => (bool) get_user_meta( $u->ID, 'ps_daily_email', true ),
			'instantEmail' => (bool) get_user_meta( $u->ID, 'ps_instant_email', true ), // 1.0.2
			'lang'         => ps_lang(), // 1.3.0
		),
	);
}
