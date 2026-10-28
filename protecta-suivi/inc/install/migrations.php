<?php
/**
 * Migrations de base de données versionnées.
 *
 * Fonctionnement :
 *   - l'option « ps_db_version » mémorise la dernière migration appliquée ;
 *   - à chaque chargement, si ps_db_version < PS_DB_VERSION, les migrations
 *     manquantes sont exécutées dans l'ordre, APRÈS une sauvegarde JSON automatique ;
 *   - un verrou empêche deux exécutions simultanées.
 *
 * AJOUTER UNE MIGRATION (voir CLAUDE.md) :
 *   1. modifier le schéma dans schema.php si besoin (ajout de colonne/table seulement) ;
 *   2. ajouter une fonction ps_migration_00N() ci-dessous et l'inscrire dans ps_migrations() ;
 *   3. augmenter PS_DB_VERSION dans inc/config.php ;
 *   4. noter le changement dans CHANGELOG.md.
 *
 * INTERDIT dans une migration : DROP, TRUNCATE, DELETE en masse, remise à zéro d'une
 * liste, réécriture de libellés ou de réglages existants. Une migration AJOUTE.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liste ordonnée des migrations : numéro => fonction.
 * Le filtre « ps_migrations » sert aux tests (simulation d'une mise à jour).
 */
function ps_migrations(): array {
	$migrations = array(
		1 => 'ps_migration_001_initial',
		2 => 'ps_migration_002_tasks',
		3 => 'ps_migration_003_notifications',
		4 => 'ps_migration_004_task_voice',
		5 => 'ps_migration_005_broker_margin',
		6 => 'ps_migration_006_files_drive',
		7 => 'ps_migration_007_invoices',
		8 => 'ps_migration_008_invoice_deadline',
	);
	return apply_filters( 'ps_migrations', $migrations );
}

/**
 * Version cible : la plus grande migration connue.
 */
function ps_target_db_version(): int {
	$keys = array_keys( ps_migrations() );
	return max( PS_DB_VERSION, $keys ? max( $keys ) : 0 );
}

/**
 * Migration 1 : installation initiale.
 * Tout est idempotent : la relancer ne crée pas de doublons et n'écrase rien.
 */
function ps_migration_001_initial(): void {
	ps_apply_schema();
	ps_seed_lists();
	ps_register_roles();
	ps_init_settings();
	ps_ensure_storage();

	// Fuseau horaire : Tripoli, seulement si le site n'en a pas déjà un.
	if ( ! get_option( 'timezone_string' ) && ! (float) get_option( 'gmt_offset' ) ) {
		update_option( 'timezone_string', 'Africa/Tripoli' );
	}
}

/**
 * Migration 2 (1.0.1) : tâches libres « مهمة جديدة ».
 *   - CREATE TABLE {prefix}ps_tasks (dbDelta : ajoute seulement) ;
 *   - nouvelle capacité ps_assign_tasks (مسؤول مشروع et الإدارة) ajoutée aux rôles existants.
 */
function ps_migration_002_tasks(): void {
	ps_apply_schema();
	ps_register_roles();
}

/**
 * Migration 3 (1.0.2) : notifications 🔔 — CREATE TABLE {prefix}ps_notifications (dbDelta : ajoute seulement).
 */
function ps_migration_003_notifications(): void {
	ps_apply_schema();
}

/**
 * Migration 4 (1.2.0) : message vocal d'une tâche — ADD COLUMN voice_path, voice_mime, voice_size, voice_seconds
 * sur {prefix}ps_tasks (dbDelta : ajoute seulement, les tâches existantes restent sans vocal).
 */
function ps_migration_004_task_voice(): void {
	ps_apply_schema();
	ps_ensure_storage();
}

/**
 * Migration 5 (1.4.0) : « هامش الربح » du الوسيط — ADD COLUMN margin sur {prefix}ps_project_parties
 * (dbDelta : ajoute seulement ; « الرتبة » existante est gardée).
 */
function ps_migration_005_broker_margin(): void {
	ps_apply_schema();
}

/**
 * Migration 6 (1.5.0) : fichiers joints — nom d'affichage, empreinte MD5, emplacement (serveur / Google Drive)
 * sur {prefix}ps_files ; dossier Drive du projet sur {prefix}ps_projects. ADD COLUMN seulement : les fichiers
 * existants restent « local » (sur le serveur), rien n'est déplacé par la migration.
 */
function ps_migration_006_files_drive(): void {
	ps_apply_schema();
}

/**
 * Migration 7 (1.6.0) : « مشروع؟ » + سبب + ملاحظة المشروع (ps_projects), المسؤول / المتابع au niveau de l'offre
 * (ps_items, vides = ceux du projet), fichiers liés à une offre ou une facture (ps_files), tables ps_invoices et
 * ps_alerts, liste « سبب عدم اعتباره مشروعا ». ADD COLUMN / CREATE TABLE seulement : les projets existants
 * restent « مشروع = نعم » (valeur par défaut 1), rien n'est modifié.
 */
function ps_migration_007_invoices(): void {
	ps_apply_schema();
	ps_seed_lists();   // nouvelle liste non_project_reason (remplie une seule fois)
	ps_init_settings(); // nouveaux réglages : délai de réponse, interrupteur des alertes (clés manquantes seulement)
	ps_register_roles();
}

/**
 * Migration 8 (1.11.0) : « آخر أجل للرد » saisi à la main (ps_invoices.reply_deadline, ajout seulement) ; réglage
 * « تذكير كل N يوم » (invoice_remind_days, clé manquante seulement). Les factures existantes ne sont pas touchées
 * (reply_deadline vide = échéance calculée comme avant).
 */
function ps_migration_008_invoice_deadline(): void {
	ps_apply_schema();
	ps_init_settings();
}

/**
 * Exécute les migrations en attente. Appelée à l'activation et à chaque chargement.
 *
 * @return array Rapport : [ 'from' => int, 'to' => int, 'ran' => int[], 'backup' => string|null, 'error' => string|null ]
 */
function ps_maybe_upgrade(): array {
	$current = (int) get_option( 'ps_db_version', 0 );
	$target  = ps_target_db_version();
	$report  = array( 'from' => $current, 'to' => $current, 'ran' => array(), 'backup' => null, 'error' => null );

	if ( $current >= $target ) {
		return $report;
	}

	// Verrou (add_option échoue si l'option existe déjà). Verrou périmé après 10 min.
	$lock = get_option( 'ps_migration_lock' );
	if ( $lock && ( time() - (int) $lock ) < 600 ) {
		$report['error'] = 'locked';
		return $report;
	}
	delete_option( 'ps_migration_lock' );
	if ( ! add_option( 'ps_migration_lock', time(), '', false ) ) {
		$report['error'] = 'locked';
		return $report;
	}

	try {
		// Sauvegarde automatique avant toute modification (sauf installation neuve).
		if ( $current > 0 ) {
			$backup = ps_backup_write_auto( 'before-migration-' . $current . '-to-' . $target );
			if ( false === $backup ) {
				// Pas de sauvegarde = pas de migration : on ne prend aucun risque.
				$report['error'] = 'backup_failed';
				return $report;
			}
			$report['backup'] = $backup;
		}

		foreach ( ps_migrations() as $number => $callback ) {
			if ( $number <= $current ) {
				continue;
			}
			call_user_func( $callback );
			update_option( 'ps_db_version', $number, true );
			$report['ran'][] = $number;
			$report['to']    = $number;
		}
	} catch ( Throwable $e ) {
		$report['error'] = $e->getMessage();
		error_log( '[protecta-suivi] migration error: ' . $e->getMessage() ); // phpcs:ignore
	} finally {
		delete_option( 'ps_migration_lock' );
	}

	return $report;
}
