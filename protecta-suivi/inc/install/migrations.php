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
