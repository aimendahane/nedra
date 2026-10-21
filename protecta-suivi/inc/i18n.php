<?php
/**
 * Langues de l'interface (version 1.3.0) : العربية (référence), English, Français.
 *
 * Principe : le texte ARABE écrit dans le code est la clé. ps__( 'نص' ) renvoie sa traduction dans la langue
 * de l'utilisateur (dictionnaires inc/i18n/en.json et fr.json : { "نص عربي": "traduction" }), ou le texte
 * arabe s'il manque une traduction. Côté navigateur, PS.t( 'نص' ) fait la même chose avec le même dictionnaire.
 *
 * Langue choisie : compte (méta « ps_lang », menu de l'en-tête) → cookie (page de connexion) → réglage
 * « default_lang » (العربية par défaut). Les e-mails et messages Telegram partent dans la langue du destinataire
 * (ps_with_lang).
 *
 * Les données saisies (noms de projets, sociétés, valeurs de listes renommées…) ne sont jamais traduites ;
 * seules les valeurs de listes restées identiques aux valeurs par défaut le sont (elles sont dans le dictionnaire).
 *
 * AJOUTER UN TEXTE : l'écrire en arabe dans ps__( '…' ) / PS.t( '…' ) puis ajouter sa traduction dans
 * en.json et fr.json (le test tests/test-i18n.php signale toute traduction manquante).
 */

defined( 'ABSPATH' ) || exit;

/** Langues disponibles : code => [ nom, sens d'écriture, locale ]. */
function ps_languages(): array {
	return array(
		'ar' => array( 'label' => 'العربية', 'dir' => 'rtl', 'locale' => 'ar' ),
		'en' => array( 'label' => 'English', 'dir' => 'ltr', 'locale' => 'en' ),
		'fr' => array( 'label' => 'Français', 'dir' => 'ltr', 'locale' => 'fr' ),
	);
}

/** Langue forcée temporairement (e-mail ou Telegram dans la langue du destinataire). */
function ps_lang_force( ?string $set = null, bool $clear = false ): ?string {
	static $forced = null;
	if ( $clear ) {
		$forced = null;
	} elseif ( null !== $set ) {
		$forced = $set;
	}
	return $forced;
}

/** Langue d'un compte (sa préférence, sinon celle du site). */
function ps_user_lang( int $user_id ): string {
	$l = (string) get_user_meta( $user_id, 'ps_lang', true );
	return isset( ps_languages()[ $l ] ) ? $l : ps_default_lang();
}

function ps_default_lang(): string {
	$l = function_exists( 'ps_setting' ) ? (string) ps_setting( 'default_lang' ) : 'ar';
	return isset( ps_languages()[ $l ] ) ? $l : 'ar';
}

/** Langue de la requête en cours. */
function ps_lang(): string {
	$forced = ps_lang_force();
	if ( $forced ) {
		return $forced;
	}
	$uid = get_current_user_id();
	if ( $uid && isset( ps_languages()[ (string) get_user_meta( $uid, 'ps_lang', true ) ] ) ) {
		return (string) get_user_meta( $uid, 'ps_lang', true );
	}
	$c = ps_cookie_lang();
	return '' !== $c ? $c : ps_default_lang();
}

/** Langue choisie sur la page de connexion (cookie), ou ''. */
function ps_cookie_lang(): string {
	$c = sanitize_key( $_COOKIE['ps_lang'] ?? '' ); // phpcs:ignore
	return isset( ps_languages()[ $c ] ) ? $c : '';
}

/** À la connexion : la langue choisie sur la page de connexion devient celle du compte s'il n'en a pas encore. */
add_action(
	'wp_login',
	static function ( $login, $user ) {
		if ( '' !== ps_cookie_lang() && '' === (string) get_user_meta( $user->ID, 'ps_lang', true ) ) {
			update_user_meta( $user->ID, 'ps_lang', ps_cookie_lang() );
		}
	},
	10,
	2
);

function ps_dir(): string {
	return ps_languages()[ ps_lang() ]['dir'];
}

/** Dictionnaire d'une langue (arabe → traduction), lu une fois par requête. */
function ps_dict( string $lang ): array {
	static $cache = array();
	if ( 'ar' === $lang ) {
		return array();
	}
	if ( ! isset( $cache[ $lang ] ) ) {
		$file            = PS_DIR . '/inc/i18n/' . $lang . '.json';
		$cache[ $lang ] = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
	}
	return $cache[ $lang ];
}

/**
 * Traduit un texte arabe dans la langue en cours. Les espaces autour du texte sont conservés
 * (« منها  » + nombre…), la clé du dictionnaire est le texte sans ces espaces.
 */
function ps__( string $ar ): string {
	$lang = ps_lang();
	if ( 'ar' === $lang || '' === $ar ) {
		return $ar;
	}
	if ( ! preg_match( '/^(\s*)(.*?)(\s*)$/su', $ar, $m ) ) {
		return $ar;
	}
	$dict = ps_dict( $lang );
	return isset( $dict[ $m[2] ] ) ? $m[1] . $dict[ $m[2] ] . $m[3] : $ar;
}

/**
 * Exécute $fn dans la langue d'un compte (e-mail, Telegram) puis revient à la langue précédente.
 *
 * @param WP_User|string $who Compte ou code de langue.
 */
function ps_with_lang( $who, callable $fn ) {
	$prev = ps_lang_force();
	ps_lang_force( $who instanceof WP_User ? ps_user_lang( (int) $who->ID ) : (string) $who );
	try {
		return $fn();
	} finally {
		null === $prev ? ps_lang_force( null, true ) : ps_lang_force( $prev );
	}
}

/**
 * Choix de la langue depuis la page de connexion (?ps_lang=en) : cookie d'un an, puis retour à l'accueil.
 * Un compte connecté enregistre aussi sa préférence.
 */
add_action(
	'template_redirect',
	static function () {
		$l = isset( $_GET['ps_lang'] ) ? sanitize_key( wp_unslash( $_GET['ps_lang'] ) ) : ''; // phpcs:ignore
		if ( '' === $l || ! isset( ps_languages()[ $l ] ) ) {
			return;
		}
		setcookie( 'ps_lang', $l, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
		if ( get_current_user_id() ) {
			update_user_meta( get_current_user_id(), 'ps_lang', $l );
		}
		wp_safe_redirect( remove_query_arg( 'ps_lang' ) );
		exit;
	},
	1
);

/** Affiche un texte traduit, échappé pour le HTML (gabarits templates/*.php). */
function ps_e( string $ar ): void {
	echo esc_html( ps__( $ar ) );
}

/** Attributs lang et dir de la balise <html> pour la langue en cours. */
function ps_html_lang_attrs(): string {
	return 'lang="' . esc_attr( ps_lang() ) . '" dir="' . esc_attr( ps_dir() ) . '"';
}
