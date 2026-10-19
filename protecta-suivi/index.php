<?php
/**
 * Unique page du thème : affiche la connexion ou l'application.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	require PS_DIR . '/templates/login.php';
	return;
}

require PS_DIR . '/templates/app-shell.php';
