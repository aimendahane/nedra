<?php
/**
 * Dossiers de stockage protégés :
 *   wp-content/uploads/protecta-suivi/          ← racine, accès web interdit
 *   wp-content/uploads/protecta-suivi/files/    ← fichiers des projets
 *   wp-content/uploads/protecta-suivi/backups/  ← sauvegardes JSON automatiques
 *
 * Aucun de ces fichiers n'est accessible par une URL publique :
 *   - .htaccess « deny all » (Apache 2.2 et 2.4, utilisé par HostGator) à chaque niveau ;
 *   - index.php vide pour empêcher le listage ;
 *   - les fichiers sont servis uniquement par l'application, après vérification des droits.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chemin absolu de la racine de stockage (sans slash final).
 */
function ps_storage_dir( string $sub = '' ): string {
	$uploads = wp_upload_dir( null, false );
	$base    = trailingslashit( $uploads['basedir'] ) . 'protecta-suivi';
	return $sub ? $base . '/' . $sub : $base;
}

/**
 * Contenu .htaccess qui interdit tout accès direct.
 */
function ps_deny_htaccess(): string {
	return "# Protecta Suivi : accès direct interdit (fichiers servis par l'application uniquement)\n"
		. "Options -Indexes\n"
		. "<IfModule mod_authz_core.c>\n"
		. "\tRequire all denied\n"
		. "</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n"
		. "\tOrder deny,allow\n"
		. "\tDeny from all\n"
		. "</IfModule>\n";
}

/**
 * Crée (ou répare) les dossiers protégés. Idempotent : peut être appelé à chaque
 * migration ou avant chaque écriture de fichier. Ne supprime jamais rien.
 *
 * @return bool true si tous les dossiers sont prêts et protégés.
 */
function ps_ensure_storage(): bool {
	$ok = true;
	foreach ( array( '', 'files', 'backups' ) as $sub ) {
		$dir = ps_storage_dir( $sub );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$ok = false;
			continue;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) || file_get_contents( $htaccess ) !== ps_deny_htaccess() ) {
			$ok = ( false !== file_put_contents( $htaccess, ps_deny_htaccess() ) ) && $ok;
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			$ok = ( false !== file_put_contents( $index, "<?php\n// Silence.\n" ) ) && $ok;
		}
	}
	return $ok;
}
