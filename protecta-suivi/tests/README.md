# Tests (développement uniquement — non inclus dans le zip)

## Environnement local utilisé

- WordPress dans `/srv/wp`, Apache sur le port 8080 (`.htaccess` actifs, comme HostGator), MariaDB 10.11, PHP 8.3.
- Le thème est un lien symbolique : `/srv/wp/wp-content/themes/protecta-suivi → protecta-suivi/`.
- `wp-config.php` local :
  ```php
  $table_prefix = getenv( 'PS_TEST' ) ? 'wptest_' : 'wp_'; // PS_TEST=1 : base de test séparée
  ```

## Lancer les tests

```bash
# Tests PHP (installation, listes, rôles, mise à jour sans perte, sécurité, API REST).
# Efface et réinstalle les tables wptest_* à chaque lancement.
cd /srv/wp && sudo -u www-data env PS_TEST=1 php /chemin/protecta-suivi/tests/run-tests.php

# Test navigateur (connexion, menus par rôle, mode nuit, mobile, blocage des tentatives).
# Comptes de développement : idara (الإدارة), masoul (مسؤول مشروع), mowadhaf (موظف) — mot de passe Test-pass-2026.
BASE_URL=http://localhost:8080 SHOTS=/tmp/captures node tests/e2e-smoke.js
```

## Fichiers

| Fichier | Contenu |
|---|---|
| `run-tests.php` | Lanceur : installe un site de test neuf puis exécute les fichiers `test-*.php` |
| `install-test-site.php` | Réinstallation de la base de test (refuse de tourner sans `PS_TEST=1`) |
| `lib.php` | Mini-bibliothèque d'assertions |
| `test-install.php` | Tables, colonnes, index uniques, listes par défaut, réglages, rôles, réactivation |
| `test-migrations.php` | Mise à jour simulée : données, listes modifiées et réglages conservés ; sauvegarde préalable |
| `test-security.php` | Dossiers protégés, limite de tentatives, inactivité, accès wp-admin |
| `test-rest.php` | Permissions de l'API, préférences, activité / expiration de session |
| `e2e-smoke.js` | Parcours dans un vrai navigateur (Chromium) |

## Tout lancer

```bash
sh tests/run-all.sh                                   # PHP + navigateur (étapes 1 à 5)
sh tests/release-check.sh <zip final> <zip 0.1.0>     # installation vierge + mise à jour avec données
```
Fichiers ajoutés : `test-projects.php`, `test-calculs.php` (jeu de démo, `fixtures/demo-expected.md`), `test-items.php`,
`test-recap.php`, `test-admin.php`, `test-tasks.php` (1.0.1), `e2e-app.js`, `e2e-setup.php` (site de test 8081), `release-check.php|.sh`,
`e2e-release.js` (sites vierges 8082 et 8083).
