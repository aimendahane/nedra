# CLAUDE.md — Protecta Suivi

Thème WordPress « protecta-suivi » = application web de suivi des projets et des offres
(interface arabe RTL). Le client installe le zip lui-même sur HostGator (mutualisé,
PHP 8.1+, MySQL), sans aucun plugin.

Documents de référence (à relire avant toute modification importante) :
- `docs/PLAN.md` — plan validé : architecture, schéma de la base, permissions, étapes.
- Cahier des charges (docx, fourni par le client) + brief complémentaire (prime en cas de différence).
- `protecta-suivi/CHANGELOG.md` — historique des versions.

## ⚠️ Règle n°1 : ne JAMAIS perdre de données

Le client installe régulièrement de nouvelles versions (remplacement du zip). Les données
(projets, أصناف, sociétés, utilisateurs, réglages, listes, fichiers) doivent TOUJOURS être conservées.

- Tout changement de structure = **nouvelle migration** dans `inc/install/migrations.php`
  + augmentation de `PS_DB_VERSION` (`inc/config.php`). Les migrations **ajoutent** seulement :
  `CREATE TABLE`, `ADD COLUMN`, `ADD INDEX`, insertion de valeurs manquantes.
- **Interdit** : `DROP`, `TRUNCATE`, `DELETE` en masse, renommer/supprimer une colonne,
  réécrire des libellés, réglages ou listes existants.
- `schema.php` contient toujours le schéma complet le plus récent (appliqué via dbDelta, additif).
- Les listes de الإعدادات ne sont remplies par défaut **qu'une seule fois** (option
  `ps_seeded_lists`). Jamais de remise à zéro. Seules les valeurs `is_system=1` (logique de calcul)
  sont recréées si leur code a disparu ; une valeur existante n'est jamais modifiée.
- Les réglages (`ps_settings`) : les défauts complètent les clés manquantes, n'écrasent rien.
- Une sauvegarde JSON automatique est écrite avant chaque migration ; pas de sauvegarde = pas de migration.
- La désactivation du thème ne supprime rien.
- Le test `tests/test-migrations.php` doit toujours passer ; l'étendre à chaque nouvelle migration.
- À chaque livraison : augmenter `Version:` (style.css) **et** `PS_VERSION` (config.php), compléter CHANGELOG.md.

## Architecture (résumé)

- WordPress = moteur uniquement (comptes, rôles, MySQL, mail, upload). Tables dédiées `{prefix}ps_*`.
- Une seule page (accueil) : `index.php` → `templates/login.php` ou `templates/app-shell.php`.
  Navigation par hash `#/page`. Toute autre URL redirige vers l'accueil.
- API REST `ps/v1` (`inc/rest/*.php`). **Chaque route utilise `ps_rest_permission($cap)`** ;
  les règles de propriété (mon projet / mon صنف / fenêtre créateur) sont vérifiées dans le contrôleur.
  Ne jamais se fier à l'interface pour la sécurité.
- **Tous les calculs métier dans `inc/calculs.php`** (fonctions pures, commentées, paramètre
  « aujourd'hui » pour les tests). L'API renvoie les valeurs calculées ; pas de duplication en JS.
- Les projets référencent les listes par **id** (`ps_lists.id`), jamais par le texte. Les calculs
  utilisent `code` (urgence, statut d'offre, livraison) ou `type` (حالة المتابعة), jamais le libellé.
- Front-end sans build : JS vanilla, un fichier par page dans `assets/js/pages/`, cœur dans
  `assets/js/core/`. Couleurs/polices dans `assets/css/variables.css` uniquement.
  Toujours échapper les données (`PS.ui.html` / `PS.ui.esc`).
- Librairies et polices incluses dans le thème (aucun CDN) : Chart.js UMD (étape 4), police Cairo.
- Fichiers et sauvegardes dans `uploads/protecta-suivi/{files,backups}` : `.htaccess` deny all,
  servis uniquement via l'API après contrôle des droits.

## Décisions validées par le client (ne pas remettre en cause sans lui demander)

1. **موظف** : voit tous les projets (lecture) ; modifie seulement ses أصناف (offre/livraison),
   ajoute au سجل المتابعة, joint des fichiers. **Le créateur d'un projet peut modifier ses infos
   générales pendant 24 h** après l'enregistrement (réglage `creator_edit_hours`).
   مسؤول مشروع : toutes les données de ses projets. الإدارة : tout.
2. **Recap** : الإدارة seulement.
3. **Import du prototype** : employé « موقع العطاءات » → مصدر = موقع العطاءات et
   المتابع = المسؤول عن المشروع. Tous les autres anciens projets → مصدر = **« غير محدد »** :
   - « غير محدد » (code `unspecified`) réservé à l'import, jamais proposé à la saisie ;
   - Base Projets : filtre « مصدر غير محدد » + modification groupée du مصدر ;
   - Recap : « غير محدد » affiché à part dans le tableau par source et exclu de نسبة النجاح par source.
4. Devises : LYD (défaut), USD, EUR, TND. Montants jamais additionnés entre devises.
5. تاريخ إرسال العرض obligatoire dès qu'un صنف est مرسل, مقبول ou مرفوض ; pré-rempli avec la date
   du jour (modifiable) quand un صنف passe à مرسل et que la date est vide.
6. Ancien « عاجل » → **U** par défaut, modifiable dans l'aperçu d'import (en attente de la direction).
7. Couleurs : orange `#E8571F`, bleu `#385B9A` (logo AMC). Bannière Protecta+AMC à la connexion,
   logos des marques en pied de page.
8. `backup.json` réel : en attente ; tester l'import avec un exemple au format du prototype.
9. التصنيف : liste provisoire (كهرباء، إنارة، شبكات، أنظمة أمان وإنذار، تجهيزات، أخرى) en attente de la liste réelle.
10. Déconnexion après inactivité : **2 h par défaut**, réglable dans الإعدادات.
11. Dossier des sauvegardes automatiques protégé comme les fichiers des projets (aucune URL publique).
12. Jeton Cron : long et aléatoire (64 hex = 256 bits), régénérable depuis الإعدادات.

## Méthode de travail demandée par le client

- Avancer par étapes (§13 du cahier, voir `docs/PLAN.md` §9). **À la fin de chaque étape :
  montrer ce qui a été fait + résultats des vérifications, puis attendre son accord.**
- Tester sur un WordPress local + tests automatiques ; montrer les résultats.
- Pas de build, code commenté, README.md en arabe tenu à jour.

## Tests locaux

Voir `protecta-suivi/tests/README.md`. En bref :
- `PS_TEST=1 php protecta-suivi/tests/run-tests.php` (base de test séparée `wptest_`, réinstallée à chaque fois) ;
- `node protecta-suivi/tests/e2e-smoke.js` (navigateur, Playwright) ;
- `./tools/build-zip.sh` → `dist/protecta-suivi-<version>.zip` (sans le dossier tests).
