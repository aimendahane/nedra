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

## Décisions prises par Claude (à valider par le client ; modifiables)

Le client a demandé d'enchaîner les étapes 2 à 6 sans attendre son accord et de noter ici
chaque décision prise seul.

### Étape 2 — Fiche Projet / Base Projets
- **« Ses projets » du مسؤول مشروع** = projets dont il est « المسؤول عن المشروع » (lien compte ↔ employé :
  méta `ps_employee_id`, réglé dans la gestion des utilisateurs).
- **Ajouter au سجل المتابعة / joindre un fichier** : الإدارة, le créateur, ou un employé qui intervient
  (المسؤول، المتابع، الوسيط، موظف d'un صنف). Modifier une entrée de suivi / retirer un fichier : son auteur
  pendant 24 h (même réglage que la fenêtre du créateur), ou الإدارة. Rien n'est effacé (fichier retiré = corbeille).
- **Numéro de projet** : attribué à la création selon l'année de تاريخ استلام الطلب, jamais modifié ensuite
  (même si la date est corrigée). Les colonnes السنة/الشهر affichées suivent toujours la date de réception.
- **رقم العرض** : saisi à la main (obligatoire par défaut), bouton « اقتراح رقم » (OFR-AAAA-NNN libre).
- **Intervenants** : coordonnées gardées par projet ; la fiche de l'annuaire n'est que COMPLÉTÉE (champs vides),
  jamais écrasée. Un nom saisi sans choisir dans la liste réutilise la fiche au nom identique (après
  normalisation arabe) au lieu de créer un doublon.
- **Doublons** : même société المالك + similarité de nom ≥ 75 % + تاريخ الاستلام à ±60 jours (réglage).
  En modification, l'avertissement ne revient que si le nom, la société ou la date changent.
- **أصناف** : un صنف ne peut être retiré de la fiche que s'il est encore « قيد الإعداد » ; sinon le passer à « ملغى ».
- **Montant sans devise** → devise par défaut (LYD).
- **Ajout rapide pendant la saisie** limité aux listes المكان et الأصناف (cahier §8.4-8.5) ; doublons évités
  (même nom normalisé ou même code).
- **Fichiers acceptés** : PDF, Excel (xls/xlsx/csv), Word (doc/docx), images (jpg/png/gif/webp) ; type vérifié
  sur le contenu ; taille max = limite PHP de l'hébergeur.
- **Règle حالة العرض appliquée à la lettre** (brief §6) : un mélange « مرفوض + لم يُرسل » sans مرسل/مقبول donne
  « قيد الإعداد » (« sinon »). Point à confirmer avec le client.
- Le brouillon d'un nouveau projet est gardé dans le navigateur jusqu'à l'enregistrement.
