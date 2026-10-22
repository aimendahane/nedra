# CLAUDE.md — Protecta Suivi

Thème WordPress « protecta-suivi » = application web de suivi des projets et des offres
(interface arabe RTL). Le client installe le zip lui-même sur HostGator (mutualisé,
PHP 8.1+, MySQL), sans aucun plugin.

## 📍 ÉTAT DU PROJET (mis à jour le 19/10/2026 — à lire en premier)

Le client a demandé d'enchaîner les étapes 2 à 6 **sans attendre son accord** entre les étapes,
en lançant tous les tests à la fin de chaque étape (ne passer à la suivante que si tout passe) et en
notant ici chaque décision prise seul. Ne lui poser une question que si l'on est vraiment bloqué.

| Étape | Contenu | État | Version / commit |
|---|---|---|---|
| 0 | Environnement local (WordPress + MariaDB + Apache) | ✅ terminée | — |
| 1 | Fondations (schéma, migrations, rôles, connexion, coquille) | ✅ validée par le client | 0.1.0 · `ff82507` |
| 2 | Fiche Projet, Base Projets, annuaire, سجل المتابعة, fichiers, journal | ✅ terminée, tests verts | 0.2.0 · `5709e25` |
| 3 | Suivi Offres / Livraison, مهامي, tous les calculs §6, jeu de démo 30 projets | ✅ terminée, tests verts | 0.3.0 · `fe9eaca` |
| 4 | Recap (Chart.js 4.5.1 inclus), comparaison N-1, clics filtrants, impression | ✅ terminée, tests verts | 0.4.0 · `6fb1871` + `Tests: fresh demo site…` |
| 5 | الإعدادات, comptes, fusion annuaire, import prototype, export, sauvegarde/restauration, e-mail du matin, Cron | ✅ terminée, tests verts | 0.5.0 |
| 6 | Zip final, test installation vierge + mise à jour depuis 0.1.0, docs, release GitHub | ✅ terminée (sauf publication GitHub, bloquée) | 1.0.0 |
| 1.0.1 | Demande client : tâches libres « مهمة جديدة » + e-mail (SMTP, vraie erreur) + explication Cron | ✅ terminée, tests verts | 1.0.1 · base v2 |
| 1.0.2 | Demande client : notifications 🔔 (cloche + e-mail immédiat facultatif) | ✅ terminée, tests verts | 1.0.2 · base v3 |
| 1.1.0 | Demande client : nouveau design « world-class » (CSS/icônes/animations, aucune donnée touchée) | ✅ terminée, tests verts | 1.1.0 · base v3 |
| 1.2.0 | Demande client : notifications Telegram + messages vocaux (الإدارة/مسؤول → موظف) | ✅ terminée, tests verts | 1.2.0 · base v4 |
| 1.3.0 | Demande client : interface AR / EN / FR | ✅ terminée, tests verts | 1.3.0 · base v4 |
| 1.3.1 | Retour client : message vocal sans son (WebM → Ogg pour Telegram, niveau du micro) | ✅ terminée, tests verts | 1.3.1 · base v4 |
| 1.3.2 | Retour client : toujours muet sur Android + Telegram → détection du silence, secours de lecture | ✅ terminée, tests verts | 1.3.2 · base v4 |
| 1.3.3 | Retour client : toujours pas de son → panneau « اختبار الصوت » (diagnostic) + étapes Telegram | ✅ terminée, tests verts | 1.3.3 · base v4 |
| 1.3.4 | Retour client : son OK sur mobile, muet sur PC → liste de choix du micro | ✅ terminée, tests verts | 1.3.4 · base v4 |
| 1.3.5 | Capture client : cloche sans « ربط Telegram » (Telegram pas activé) + lecteur « 0:00 » → indication + durée | ✅ terminée, tests verts | 1.3.5 · base v4 |
| 1.4.0 | Demande client : import du vrai fichier Excel, الوسيط هامش الربح, règles موظف, ورقة الحسابات, Telegram à la connexion | ✅ terminée, tests verts | 1.4.0 · base v5 |
| 1.5.0 | Demande client : nom du projet d'abord + recherche par numéro, fichiers joints (règles), stockage Google Drive | ✅ terminée, tests verts | **1.5.0** · base v6 |

### Tests (dernier passage complet, version 1.5.0) — détail : `docs/TEST-RESULTS.txt`
- `sh protecta-suivi/tests/run-all.sh` → **787 tests PHP réussis / 0 échoué** + **tous** les contrôles navigateur
  (étapes 1 à 5 + step6 = 1.0.1 + step7 = 1.0.2 + step8 = 1.2.0 + step9 = 1.3.0 langues + step10 = 1.4.0 + step11 = 1.5.0) passés.
- `sh protecta-suivi/tests/release-check.sh $PWD/dist/protecta-suivi-1.5.0.zip $PWD/dist/protecta-suivi-1.4.0.zip` (chemins absolus) →
  installation vierge (connexion, saisie, Recap) OK ; mise à jour 1.4.0 → 1.5.0 avec données : **aucune perte**.

### Prochaines actions
1. Dès que l'accès GitHub est rétabli : `git push -u origin claude/sweet-hopper-le9393` puis
   (FAIT : branche poussée ; les tags sont refusés par le proxy → le workflow crée lui-même la release
   quand style.css change, ou via Actions → Release → Run workflow).
2. Retours du client sur les points ouverts ci-dessous ; toute évolution = nouvelle version (1.0.1…), CHANGELOG,
   et nouvelle migration si la structure change (tests/test-migrations.php à étendre).

### Vérification avec le fichier Excel réel du client (19/10/2026) — `docs/verif-excel/`
9 problèmes listés au client — 03, 04, 05, 06, 07, 08 corrigés en 1.1.0 (design) ; restent 01, 02 (09 corrigé en 1.4.0 : numéros P… conservés à l'import) : 01 date d'envoi = date du jour au lieu
de تاريخ إرسال العرض du projet (bug) ; 02 champs date au format du navigateur ; 03 flèche verte pour المرفوضة ↑ ;
04 tableaux Recap coupés sur mobile ; 05 fond du menu coupé ; 06 menu « إدخال مشروع » surligné sur une fiche ;
07 lecture seule peu visible ; 08 numéro de projet coupé ; 09 numérotation P176… non conservée.
Questions au client : champs obligatoires, téléphones multiples, الأهمية « M », sens des colonnes حالة العرض/المتابعة.

### Points ouverts (attente client ou blocage)
- **Envoi GitHub bloqué** : `git push` → 403 (application Claude GitHub sans droit d'écriture sur
  `aimendahane/nedra`, dépôt vide). Le client doit installer/reconnecter l'app (claude.ai/connect-github).
  Il n'existe pas d'outil « créer une release » : prévu à l'étape 6 → workflow `.github/workflows/release.yml`
  qui construit le zip (`tools/build-zip.sh`) et publie la release quand on pousse un tag `vX.Y.Z`.
- À confirmer par le client : règle حالة العرض « مرفوض + لم يُرسل → قيد الإعداد » (appliquée à la lettre) ;
  « عاجل » → U (attente direction) ; liste réelle des التصنيف ; `backup.json` réel (test fait avec l'exemple).

### Environnement local (conteneur éphémère : à recréer si besoin)
- `/srv/wp` (WordPress 6.7 tiré de npm `@wp-playground/wordpress-builds` car wordpress.org est bloqué),
  MariaDB (`service mariadb start`), Apache (`service apache2 start`) : port 8080 = base `wp_` (dev),
  port 8081 = base `wptest_` (tests navigateur, `SetEnv PS_TEST 1`). Thème = lien symbolique vers `protecta-suivi/`.
- Test de mise à jour depuis 0.1.0 (étape 6) : `dist/` n'est pas versionné → reconstruire le zip 0.1.0 avec
  `git archive ff82507 protecta-suivi | …` (en excluant `tests/`). Copie WP séparée `/srv/wp2` (préfixe `wpzip_`)
  et script `tests/zip-update-flow.php`.

Documents de référence (à relire avant toute modification importante) :
- `docs/BRIEF.md` — brief complet du client (prime sur le cahier).
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

### Étape 3 — Suivi Offres / Livraison / مهامي / calculs
- **Projet ملغى** (toutes ses أصناف ملغى) : compté à part et exclu des autres indicateurs
  (المؤكدة، المرفوضة، قيد الدراسة، المرسلة، لم تُرسل).
- **العروض المرسلة** : règle du brief appliquée telle quelle (statut d'offre OU type de suivi) ; un projet
  « لم يُرسل » dont le suivi indique une réponse du client (مؤكد/مرفوض/مسلّم) compte donc comme envoyé.
- **« عرض متأخر » et « لازم قرار »** ont la même définition (brief §5 et §6) : un seul bloc dans مهامي
  (« عروض متأخرة — لازم قرار »). Le jour même du أجل n'est pas un retard.
- **Compteur du menu** « لازم قرار » : ses propres أصناف pour un موظف, toute l'équipe pour الإدارة
  (et pour un compte non lié à un employé). En haut de Suivi Offres : total + « منها X لك ».
- **مهامي** : blocs (1) offres en retard, (2) suivis prévus aujourd'hui ou dépassés (projets dont il est
  المتابع ou المسؤول, non clôturés ; seule la prochaine date de la DERNIÈRE entrée compte), (3) projets sans
  suivi depuis plus de 7 jours, (4) livraisons de la semaine = non livrées avec أجل ≤ aujourd'hui + 7 jours
  (retards compris).
- **Passage à مرسل** sans تاريخ تقديم العرض → date du jour (modifiable).
- **« متأخر »** (livraison) n'est jamais choisi à la main : il s'affiche automatiquement ; le statut choisi
  (في الانتظار / جاهز / تم التسليم) est conservé dessous.
- Retirer « لم يُرسل » d'un صنف ne supprime pas le motif saisi (il reste en mémoire, ignoré dans les calculs).

### Étape 4 — Recap
- **Comparaison N-1** : variation en % pour les nombres et les montants (par devise) ; pour les taux
  (نسبة النجاح، نسبة الإرسال) l'écart est donné en **points**. Si l'année précédente vaut 0 : « جديد ».
- **Répartitions** (mois, تصنيف, حالة, استعجال, موظف, مكان, مصدر) : projets ملغى exclus (comptés à part).
- **حسب الموظف** = الموظف المتابع ; « مسلّمة » = suivi de type مسلّم.
- **حسب الصنف** : un projet compte une fois par صنف demandé (y compris un صنف annulé).
- **أسباب عدم الإرسال** : comptées par صنف « لم يُرسل » (un projet peut avoir plusieurs motifs).
- **Couleurs des barres mensuelles** reprises des réglages : جارية = couleur de la 1re حالة de type جارية,
  مرفوضة = celle de « رفض العرض », مؤكدة = celle de la حالة de type مسلّم.
- **Anneau التصنيف** : 7 parts + « أخرى » au-delà de 8 ; palette de 8 couleurs testée pour le daltonisme.
- **Top sociétés** : classées par nombre de projets puis par nombre de مؤكدة ; **concurrents** : 15 premiers.
- Chaque graphique a une vue « عرض كجدول » (accessibilité) ; impression A4 via le bouton « طباعة / PDF ».

### Étape 5 — décisions déjà prises (code serveur écrit)
- **Listes** : suppression seulement si la valeur n'est utilisée nulle part (projets, أصناف, concurrents, comptes) ;
  sinon proposer de la masquer. Valeurs « système » (codes utilisés par les calculs) : renommables et recolorables,
  jamais masquées ni supprimées ; « غير محدد » toujours masquée. Le TYPE d'une حالة المتابعة est modifiable.
- **Comptes** : jamais supprimés, seulement **désactivés** (connexion refusée, sessions fermées, plus aucun droit) ;
  un employé de la liste ne peut être lié qu'à un seul compte ; mot de passe ≥ 8 caractères ; on ne peut pas
  changer son propre rôle ni désactiver son propre compte ; le compte administrateur WordPress n'est pas modifiable ici.
- **Fusion annuaire** : projets réaffectés, coordonnées vides complétées, fiche fusionnée conservée (merged_into_id).
- **Import du prototype** : un projet déjà présent (même رقم المشروع ou même identifiant du prototype) est ignoré ;
  رقم العرض déjà pris → suffixe « -N » ; date de réception manquante → déduite de l'identifiant du prototype ;
  valeurs inconnues ajoutées aux listes ; ancien « متأخر » (livraison) → « في الانتظار » (le retard est recalculé) ;
  aucune règle de validation imposée aux anciennes données (signalées dans les avertissements).
- **Restauration** : remplace les données par celles de la sauvegarde, APRÈS sauvegarde automatique de l'état
  actuel ; confirmation « استرجاع » obligatoire ; refusée si la sauvegarde vient d'une version plus récente ;
  comptes manquants recréés avec le même mot de passe, jamais supprimés ; migrations rejouées ensuite.
  Les fichiers joints ne sont pas dans le JSON (sauvegarde cPanel du dossier uploads).
- **CSV** : UTF-8 avec BOM, virgule, formules neutralisées ; **Excel** : .xlsx natif RTL (CSV si ZipArchive absent).
- **E-mail du matin** : interrupteur général (désactivé par défaut) + case par compte ; rien n'est envoyé à un
  employé sans tâche ; une seule fois par jour ; الإدارة reçoit le résumé de toute l'équipe + indicateurs de l'année.
- **Cron** : URL secrète `?ps_cron=<jeton>` (wget dans cPanel) ou `php …/cron.php` ; mauvais jeton → 403.

### Version 1.0.1 — décisions prises
- **Tâches libres** liées à un EMPLOYÉ de la liste (comme les أصناف), pas à un compte : visibles dans مهامي de tout
  compte lié à cet employé. Créer : مسؤول مشروع + الإدارة (capacité `ps_assign_tasks`), vers n'importe quel employé.
  Voir : الإدارة tout ; les autres leurs tâches + celles qu'ils ont envoyées (sinon 404). « تمت »/rouvrir : employé
  concerné, auteur, الإدارة. Modifier/annuler : auteur ou الإدارة. Jamais supprimée (statut cancelled).
  Retard = ouverte et أجل < aujourd'hui (`ps_calc_task_late`). Projet lié facultatif (bouton dans Fiche Projet).
  E-mail immédiat à l'employé (pas à l'auteur lui-même) ; échec d'envoi non bloquant.
- **E-mail** : SMTP intégré via `phpmailer_init` (aucune extension). Mot de passe dans l'option `ps_smtp_pass`,
  hors `ps_settings`, jamais renvoyé ni sauvegardé dans le JSON (à ressaisir après une restauration).
  Expéditeur : réglage, sinon utilisateur SMTP, sinon celui de WordPress.

### Version 1.0.2 — décisions prises (notifications)
- Source unique : le journal `ps_log` (+ `ps_followup_add`, non journalisé) alimente un tampon PAR REQUÊTE,
  vidé au `shutdown` (`ps_notify_flush`) → une notification par personne et par projet et par sauvegarde.
- Destinataires = comptes actifs liés à : المسؤول + المتابع (+ employés des أصناف pour un nouveau projet,
  + nouvel employé d'un صنف). Jamais l'auteur. Ignorés : import du prototype, fusion annuaire, tâches libres.
  Le الوسيط n'est pas notifié (non demandé).
- E-mail immédiat : préférence par compte `ps_instant_email` (désactivée par défaut), réglable dans la cloche.
- Table `ps_notifications` jamais vidée (règle n°1) ; la cloche montre les 30 dernières.

### Version 1.1.0 — design (décisions)
- Refonte purement visuelle : mêmes classes CSS (les tests navigateur s'en servent), jetons dans variables.css
  (ombres, rayons, durées, dégradés, menu sombre `--ps-nav-*`), icônes SVG maison dans `assets/js/core/icons.js`
  (émojis gardés en secours). Animations coupées par `prefers-reduced-motion`.
- Menu latéral bleu nuit dans les deux modes ; page active = fond clair + barre orange.
- Recap : couleur de la comparaison N-1 selon le sens (`inverse` pour المرفوضة، لم تُرسل، retards).

### Version 1.3.0 — langues (décisions)
- Le texte ARABE du code est la clé : `ps__()` / `ps_e()` (PHP), `PS.t()` (JS) ; dictionnaires `inc/i18n/{en,fr}.json`.
  Tout nouveau texte DOIT être ajouté aux deux fichiers (tests/test-i18n.php échoue sinon).
- Langue = préférence du compte (méta `ps_lang`) → cookie de la page de connexion (copié sur le compte à la connexion
  s'il n'a pas de préférence) → réglage `default_lang` (ar). E-mails/Telegram : langue du destinataire (`ps_with_lang`).
- Données saisies jamais traduites ; valeurs de listes traduites seulement si le libellé est encore celui par défaut.
- « Item » / « Article » pour صنف ; « Decision needed » / « Décision requise » pour لازم قرار.

### Version 1.5.0 — règles demandées par le client (fichiers, affichage)
- **Affichage** : partout où un projet est listé, NOM en gras puis numéro en petit (`PS.ui.projectTitle`) ; ancien
  numéro d'import (`oldNo` = legacy_id « xlsx:P029 » ou « no » du prototype) affiché à côté. Recherche sur le nom,
  project_no et l'ancien numéro (Base Projets et Suivi Offres / Livraison).
- **Fichiers joints** : plusieurs par projet, tout type SAUF exécutables/scripts (`ps_file_blocked_exts()`, testé sur
  chaque partie du nom) + contenu vérifié (`ps_file_check_upload`) ; message en arabe. Taille max = réglage
  `file_max_mb` (20 Mo) plafonné par la limite PHP. Nom d'affichage (`display_name`), date, auteur. Nom sur le disque
  = hex aléatoire « .bin ». Stockage local : uploads/protecta-suivi/files (dossier protégé existant, choix du
  client), jamais la Médiathèque. Téléchargement UNIQUEMENT via GET /ps/v1/files/<id> (connexion + `ps_access` +
  projet non supprimé ou الإدارة). Ajout : intervenants du projet (موظف compris). Retrait (corbeille) :
  مسؤول المشروع + الإدارة + l'auteur pendant 24 h (choix du client).
- **Google Drive** (`inc/services/gdrive.php`, choix du client : « les fichiers vivent dans Drive, le site sert
  d'accès ») : OAuth Gmail (scope drive.file, refresh token) ou compte de service (JWT RS256, Drive partagé). Fichier
  d'abord local, puis envoyé (resumable) ; copie locale effacée seulement si md5Checksum Google == md5 local.
  Drive absent/en panne → le fichier attend sur le serveur (choix du client), envoyé par Cron / « مزامنة الآن » /
  shutdown (verrou 10 min). Lecture : le serveur récupère le fichier (stream) puis l'envoie. Secrets dans l'option
  `ps_gdrive` (hors JSON, jamais renvoyés). Corbeille du site : le fichier reste dans Drive. Testé avec Google
  SIMULÉ (pre_http_request) : aucune vérification possible contre le vrai Google depuis ce conteneur.

### Version 1.4.0 — décisions
- **Import Excel** (`inc/services/import-excel.php`) : colonnes reconnues par leur titre ; une ligne = un صنف ; même
  « رقم » = un projet (date de réception = la plus ancienne, envoi = la plus récente, حالة المتابعة = la plus avancée).
  Passe par `ps_import_prototype` (aperçu ROLLBACK, sauvegarde, jamais d'écrasement, legacy_id `xlsx:P029`).
  « حالة المتابعة » du fichier = offre remise (تم التسليم → مرسل) / en attente d'infos (→ قيد الإعداد) ;
  « حالة العرض » du fichier = réponse du client (→ حالة المتابعة du projet ; رفض → مرفوض, تأكيد/تسليم/دفع → مقبول).
  المسؤول عن المشروع = الموظف المتابع (pas de colonne المسؤول). Numéro P029 gardé (project_no « P029 », seq 29) ;
  les projets saisis ensuite gardent PRJ-AAAA-NNN (suite des seq). Date 2005 corrigée si السنة + الشهر concordent.
  الأهمية 1/2/3X/M/L ajoutées à la liste telles quelles (signification à confirmer). Correspondance tolérante
  (espaces, ظ/ض). Le vrai fichier n'est PAS dans le dépôt (données client) ; test = fixtures/excel-equipe.xlsx fictif.
- **الوسيط** : champ `margin` (« هامش الربح », texte libre) ; « الرتبة » masquée pour le الوسيط sauf si déjà remplie.
- **موظف créateur (24 h)** : `ps_creator_limits_check()` → pas de modification/suppression des أصناف d'autres
  employés ni des montants si le projet a des أصناف d'autres ; PUT /items : le créateur موظف ne touche qu'aux siens.
- **ورقة الحسابات** : POST /users/access-sheet (ps_manage_users), tout ou rien, e-mail facultatif, mot de passe
  10 caractères lisibles, renvoyé une seule fois ; impression/CSV côté navigateur.
- **Telegram à la connexion** : fenêtre une fois par session (sessionStorage) tant que non lié et Telegram activé.

### Version 1.2.0 — décisions (Telegram, vocal)
- Telegram SANS webhook : getUpdates (bouton « تحقق » automatique toutes les 3 s pendant 2 min + Cron) → marche
  sur HostGator et en local. Liaison par lien t.me/<bot>?start=<code 24 hex, usage unique, 2 h>. chat_id en méta
  `ps_tg_chat_id`. Token dans l'option `ps_telegram_token` (hors JSON). Boutons « فتح » seulement si l'adresse
  du site est https publique (exigence Telegram).
- Tout compte lié reçoit ses notifications 🔔 + tâches + résumé du matin (case « البريد الصباحي » = résumé,
  e-mail et/ou Telegram). Bot bloqué → délié automatiquement.
- « vocal » interprété comme : message vocal joint à une tâche libre (création seulement, pas en modification),
  enregistré dans le navigateur (MediaRecorder : ogg/webm/mp4 selon le navigateur ; https obligatoire), 5 min,
  12 Mo max, type vérifié sur le contenu, stocké dans uploads/protecta-suivi/files/voice (protégé), lu via
  GET /free-tasks/{id}/voice (droits = voir la tâche, Range supporté). Telegram : sendVoice (ogg) sinon
  sendAudio puis sendDocument.
- 1.3.1 : « pas de son » = WebM (Chrome/Android) envoyé à Telegram, qui ne le lit pas. Copie `.ogg` (même nom) créée
  à l'enregistrement par `inc/services/voice-ogg.php` (remux EBML → Ogg, PHP pur, sans réencodage), ou à la volée
  pour les anciens messages ; l'original WebM reste servi au navigateur.
- 1.3.2 : `ps_voice_is_silent()` (90 % des paquets Opus ≤ 20 octets = muet ; WebM et Ogg ; MP4 non vérifiable → null)
  → envoi refusé (422). Ordre d'enregistrement : ogg → webm → mp4/AAC (Opus vérifiable d'abord). Lecteur : en cas
  d'erreur, fetch + blob (secours Android) et message d'erreur du serveur affiché. Cause réelle chez le client
  non confirmée (pas d'Android ici) : la détection dira si c'est le micro.
- 1.3.3 : POST /voice-test (ps_assign_tasks) analyse sans stocker (temp dans get_temp_dir, supprimé) ; panneau dans
  الإعدادات ← عام (nغمة WAV générée, enregistreur partagé PS.voice, rapport ✔/✘ + userAgent).
- 1.3.4 : cause trouvée par le client = micro du PC (mobile OK). Sélecteur `[data-voice-mic]` (enumerateDevices, visible
  si ≥ 2 entrées, choix en localStorage `ps_mic`, repli sur le micro par défaut si débranché).
