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
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/calculs.php';
require_once __DIR__ . '/inc/storage.php';
require_once __DIR__ . '/inc/roles.php';
require_once __DIR__ . '/inc/install/schema.php';
require_once __DIR__ . '/inc/install/defaults.php';
require_once __DIR__ . '/inc/services/backup.php';
require_once __DIR__ . '/inc/install/migrations.php';
require_once __DIR__ . '/inc/install/activator.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/permissions.php';
require_once __DIR__ . '/inc/validation.php';
require_once __DIR__ . '/inc/services/arabic.php';
require_once __DIR__ . '/inc/services/duplicates.php';
require_once __DIR__ . '/inc/services/stats.php';
require_once __DIR__ . '/inc/services/recap.php';
require_once __DIR__ . '/inc/mail.php';
require_once __DIR__ . '/inc/services/voice-ogg.php';
require_once __DIR__ . '/inc/services/telegram.php';
require_once __DIR__ . '/inc/services/digest.php';
require_once __DIR__ . '/inc/services/notifications.php';
require_once __DIR__ . '/inc/services/importer.php';
require_once __DIR__ . '/inc/services/exporter.php';
require_once __DIR__ . '/inc/cron.php';
require_once __DIR__ . '/inc/assets.php';
require_once __DIR__ . '/inc/repo/lists.php';
require_once __DIR__ . '/inc/repo/changelog.php';
require_once __DIR__ . '/inc/repo/directory.php';
require_once __DIR__ . '/inc/repo/projects.php';
require_once __DIR__ . '/inc/repo/followups.php';
require_once __DIR__ . '/inc/repo/files.php';
require_once __DIR__ . '/inc/repo/items.php';
require_once __DIR__ . '/inc/repo/tasks.php';
require_once __DIR__ . '/inc/rest/base.php';
require_once __DIR__ . '/inc/rest/session.php';
require_once __DIR__ . '/inc/rest/lists.php';
require_once __DIR__ . '/inc/rest/projects.php';
require_once __DIR__ . '/inc/rest/followups.php';
require_once __DIR__ . '/inc/rest/directory.php';
require_once __DIR__ . '/inc/rest/items.php';
require_once __DIR__ . '/inc/rest/recap.php';
require_once __DIR__ . '/inc/rest/settings.php';
require_once __DIR__ . '/inc/rest/data.php';
require_once __DIR__ . '/inc/rest/tasks.php';
require_once __DIR__ . '/inc/rest/notifications.php';
require_once __DIR__ . '/inc/rest/telegram.php';
