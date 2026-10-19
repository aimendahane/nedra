<?php
/**
 * Protecta Suivi — point d'entrée du thème.
 *
 * Ce fichier ne contient que le chargement des modules.
 * Voir CLAUDE.md pour l'architecture et README.md (arabe) pour « où modifier quoi ».
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/storage.php';
require_once __DIR__ . '/inc/roles.php';
require_once __DIR__ . '/inc/install/schema.php';
require_once __DIR__ . '/inc/install/defaults.php';
require_once __DIR__ . '/inc/services/backup.php';
require_once __DIR__ . '/inc/install/migrations.php';
require_once __DIR__ . '/inc/install/activator.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/assets.php';
require_once __DIR__ . '/inc/repo/lists.php';
require_once __DIR__ . '/inc/rest/base.php';
require_once __DIR__ . '/inc/rest/session.php';
require_once __DIR__ . '/inc/rest/lists.php';
