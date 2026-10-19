# Plan de réalisation — Thème WordPress « Protecta Suivi »

> Statut : **proposition à valider**. Aucun code ne sera écrit avant validation.
> Sources : cahier des charges (17/10/2026), prototype HTML (18/10/2026), brief complémentaire (prime en cas de différence).

---

## 1. Principes d'architecture

| Principe | Choix |
|---|---|
| Livraison | Thème `protecta-suivi` en `.zip`, installable via Apparence > Thèmes > Téléverser. Aucun plugin requis. |
| Rôle de WordPress | Moteur uniquement : comptes/rôles, connexion, base MySQL (`$wpdb`), `wp_mail`, upload. Aucun article/page WP utilisé. |
| Interface | Une seule « coquille » d'application (`index.php`) servie sur la page d'accueil du site. Navigation par hash (`/#/offres`) → fonctionne quels que soient les permaliens HostGator, pas de règles de réécriture à gérer. |
| Données | Tables dédiées `{prefix}ps_*` (jamais `wp_posts`/`wp_postmeta`). |
| API | REST `wp-json/ps/v1/...`. **Chaque endpoint vérifie la capacité et la propriété côté serveur** (nonce `wp_rest` + `current_user_can` + règle « mon projet / mon صنف »). |
| Calculs | **Un seul fichier PHP commenté : `inc/calculs.php`**. Les valeurs dérivées (حالة العرض، تأخير، الأولوية، alertes, couleurs de lignes…) sont calculées côté serveur et renvoyées par l'API → une seule source de vérité, pas de double implémentation JS à garder synchronisée. |
| Front-end | JavaScript « vanilla » + CSS simples, **sans build** (pas de npm/webpack). Un fichier JS par page. Couleurs, polices, rayons dans des variables CSS. |
| Librairies incluses | Chart.js 4.x UMD + plugin `chartjs-plugin-datalabels` (valeurs sur les graphiques), copiés dans `assets/vendor/`. Police arabe **Cairo** (licence OFL) incluse en `.woff2`. Aucun CDN. |
| Mises à jour | Migrations versionnées, **additives uniquement**, sauvegarde JSON automatique avant chaque migration. Listes par défaut insérées **une seule fois** (jamais réinitialisées). |

---

## 2. Structure des fichiers

```
protecta-suivi/
├── style.css                    # En-tête du thème (Version: x.y.z) — rien d'autre
├── functions.php                # Chargement des modules (require) — court
├── index.php                    # Coquille de l'application (ou page de connexion si non connecté)
├── screenshot.png
├── README.md                    # En arabe : installation HostGator, mise à jour, Cron, sauvegarde, « où modifier quoi »
├── CHANGELOG.md
├── CLAUDE.md                    # Architecture + règles (conservation des données !)
│
├── inc/
│   ├── config.php               # PS_VERSION, PS_DB_VERSION, noms de tables, réglages par défaut
│   ├── calculs.php              # ★ TOUS les calculs §6 (fichier unique, commenté)
│   ├── validation.php           # Règles §11 lues depuis الإعدادات (obligatoire, dates, e-mail, téléphone…)
│   ├── roles.php                # Rôles موظف / مسؤول مشروع / الإدارة + capacités + règles de propriété
│   ├── auth.php                 # Page de connexion, limitation des tentatives, inactivité, blocage wp-admin, barre admin masquée
│   ├── assets.php               # Enqueue CSS/JS, injection config (nonce, utilisateur, listes)
│   ├── install/
│   │   ├── activator.php        # after_switch_theme + contrôle de version à chaque chargement
│   │   ├── schema.php           # Définition des tables (dbDelta : ajoute, ne supprime jamais)
│   │   ├── migrations.php       # Migrations numérotées 1, 2, 3… (additives)
│   │   └── defaults.php         # Listes par défaut (insérées seulement si la liste n'existe pas encore)
│   ├── repo/                    # Accès BDD (requêtes préparées), une classe par table
│   │   ├── projects.php  items.php  parties.php  companies.php  competitors.php
│   │   ├── lists.php  followups.php  files.php  changelog.php  settings.php
│   ├── rest/                    # Contrôleurs REST (vérification des permissions ici)
│   │   ├── projects.php  items.php  followups.php  files.php  companies.php
│   │   ├── competitors.php  lists.php  users.php  dashboard.php (مهامي)
│   │   ├── recap.php  import.php  export.php  backup.php
│   ├── services/
│   │   ├── duplicates.php       # Détection de doublons (nom proche, même société, 60 j)
│   │   ├── arabic.php           # Normalisation arabe (أ/إ/آ→ا، ة→ه، ى→ي، tashkeel…) pour recherche et doublons
│   │   ├── importer.php         # Import backup.json du prototype + table de conversion §8.1/§8.3
│   │   ├── exporter.php         # CSV (UTF-8 BOM) + XLSX natif (ZipArchive)
│   │   ├── backup.php           # Sauvegarde / restauration JSON complète
│   │   └── digest.php           # E-mail récapitulatif du matin
│   └── cron.php                 # Point d'entrée Cron (URL avec jeton secret, ou CLI)
│
├── templates/
│   ├── login.php                # Page de connexion personnalisée (logo)
│   └── app-shell.php            # En-tête, menu + compteurs, zone de page, pied de page (logos marques)
│
├── assets/
│   ├── css/
│   │   ├── variables.css        # ★ Couleurs (orange/bleu du logo), polices, mode nuit
│   │   ├── app.css              # Mise en page, composants, RTL, responsive
│   │   └── print.css            # Impression / PDF (Recap)
│   ├── js/
│   │   ├── core/
│   │   │   ├── api.js           # fetch + nonce + gestion des erreurs/expiration
│   │   │   ├── router.js        # Routage par hash, compteurs du menu
│   │   │   ├── ui.js            # Tableaux (tri/filtre), modales, toasts, badges, autocomplétion
│   │   │   ├── format.js        # Dates, mois arabes (جانفي…ديسمبر), montants par devise
│   │   │   └── session.js       # Déconnexion après inactivité, mode nuit
│   │   └── pages/
│   │       ├── mes-taches.js       # مهامي (accueil)
│   │       ├── fiche-projet.js     # Création + édition (onglets : معلومات، أطراف، أصناف، منافسون، سجل المتابعة، ملفات، سجل التعديلات)
│   │       ├── base-projets.js
│   │       ├── suivi-offres.js
│   │       ├── suivi-livraison.js
│   │       ├── recap.js
│   │       ├── annuaire.js          # الشركات والمنافسون (+ fusion)
│   │       └── parametres.js        # الإعدادات : listes, règles, utilisateurs, import/export/sauvegarde
│   ├── vendor/ chart.umd.min.js  chartjs-plugin-datalabels.min.js
│   ├── fonts/  cairo-*.woff2
│   └── img/    logo.png  logo-marques.png  (extraits du prototype)
│
└── tests/                       # Non nécessaires en production (exclus du zip ou inoffensifs)
    ├── run-tests.php            # Lance les tests (CLI : php tests/run-tests.php)
    ├── test-calculs.php         # Chaque calcul §6 vs résultats attendus calculés à la main
    ├── test-import.php          # Conversion des anciennes valeurs
    ├── test-migrations.php      # Mise à jour sans perte de données
    ├── test-permissions.php     # Accès REST refusés/acceptés selon le rôle
    └── fixtures/
        ├── demo-projects.json   # ~30 projets de démonstration
        ├── demo-expected.md     # Tableau des résultats attendus (calculés à la main, lisible)
        └── prototype-sample.json# Exemple au format du prototype pour tester l'import
```

---

## 3. Schéma de la base de données

Préfixe : `{wp_prefix}ps_`. Toutes les tables ont `created_at`, `updated_at` (DATETIME). Encodage `utf8mb4`.

### 3.1 `ps_lists` — toutes les listes déroulantes (§8)

| Colonne | Type | Rôle |
|---|---|---|
| id | BIGINT PK | Référencé par les projets (les projets stockent l'**id**, pas le texte) |
| list_key | VARCHAR(40) | `urgency`, `employee`, `followup_status`, `item_type`, `place`, `category`, `importance`, `source`, `not_sent_reason`, `loss_reason`, `currency`, `offer_status`, `delivery_status`, `availability` |
| code | VARCHAR(40) NULL | Code stable : `VU`/`U`/`N`/`L`, `T`/`M`/`BG`, `preparing`/`sent`/`not_sent`/`accepted`/`rejected`/`cancelled`, `LYD`/`USD`/`EUR`/`TND`… |
| label | VARCHAR(190) | Texte affiché (renommable) |
| type | VARCHAR(20) NULL | Pour `followup_status` : `ongoing` / `rejected` / `confirmed` / `delivered` — **les calculs utilisent ce type** |
| weight | INT NULL | Poids pour la priorité (VU=4…, عالية=3…) |
| color | VARCHAR(20) NULL | Couleur (pastilles, graphiques) |
| user_id | BIGINT NULL | Pour `employee` : lien vers le compte WordPress |
| sort_order | INT | Ordre |
| is_hidden | TINYINT | Masqué = plus proposé, mais les projets existants gardent la valeur |
| is_system | TINYINT | Valeurs dont dépend la logique (statuts d'offre, urgences) : renommables / recolorables, non supprimables |
| aliases | TEXT NULL | Anciennes valeurs reconnues à l'import (ex. `عاجل` pour U) |
| meta | TEXT (JSON) NULL | Extensions futures (ex. taux → LYD pour `currency`) |

Renommer change l'affichage partout (le projet référence l'id) ; masquer retire de la liste de saisie sans toucher aux projets ; supprimer n'est possible que si la valeur n'est utilisée nulle part (sinon : masquer).

### 3.2 `ps_projects`

| Colonne | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| project_no | VARCHAR(30) **UNIQUE** | `PRJ-2026-001`, généré serveur |
| year, seq | SMALLINT, INT | **UNIQUE(year, seq)** — année/mois déduits de تاريخ استلام الطلب |
| month | TINYINT | |
| offer_no | VARCHAR(50) **UNIQUE** NULL→requis | Saisi manuellement (suggestion pré-remplie modifiable) |
| name | VARCHAR(255) | اسم المشروع |
| name_norm | VARCHAR(255) | Nom normalisé (recherche, doublons) |
| received_date | DATE | تاريخ استلام الطلب |
| sent_date | DATE NULL | تاريخ إرسال العرض (obligatoire dès qu'un صنف est مرسل) |
| responsible_emp_id, follower_emp_id, intermediary_emp_id | BIGINT → ps_lists | المسؤول / المتابع / الوسيط |
| place_id, category_id, urgency_id, importance_kind_id, importance_size_id, followup_status_id, source_id, loss_reason_id | BIGINT → ps_lists | |
| offer_amount, offer_currency_id | DECIMAL(15,3), BIGINT | مبلغ العرض + devise |
| purchase_amount, purchase_currency_id | DECIMAL(15,3), BIGINT | مبلغ الشراء + devise (marge seulement si même devise) |
| files_link | VARCHAR(500) | Lien Drive/OneDrive |
| note | TEXT | NOTE / نص الطلب |
| created_by, updated_by | BIGINT → wp_users | |
| deleted_at, deleted_by | DATETIME NULL | Corbeille (suppression réservée à la الإدارة, restaurable) |
| legacy_id, legacy_raw | VARCHAR, LONGTEXT NULL | Traçabilité de l'import du prototype |

### 3.3 `ps_project_parties` — الأطراف المتدخلة

| Colonne | Notes |
|---|---|
| id, project_id | |
| role | `owner` (المالك) / `executor` (الشركة المنفذة) / `broker` (الوسيط) / `direct` (الشخص المباشر) |
| company_id → ps_companies NULL | Société de l'annuaire (NULL pour الشخص المباشر sans société) |
| person, email, phone, address, position, rank, location | Contact **pour ce projet** (pré-rempli depuis l'annuaire, modifiable) |

### 3.4 `ps_companies` — annuaire des sociétés

`id, name, name_norm (index), person, email, phone, address, position, rank, website, notes, merged_into_id NULL, created_by…`
Fusion : les `project_parties.company_id` de la société B sont réaffectés vers A, B est marquée `merged_into_id = A` (jamais supprimée physiquement → traçabilité et annulation possible).

### 3.5 `ps_competitors` + `ps_project_competitors`

- `ps_competitors` : `id, name, name_norm, notes, merged_into_id`.
- `ps_project_competitors` : `id, project_id, competitor_id, brand, item_type_ids (liste d'ids), notes`.

### 3.6 `ps_items` — الأصناف (source de Suivi Offres et Suivi Livraison)

| Colonne | Notes |
|---|---|
| id, project_id, sort_order | |
| item_type_id → lists | الصنف |
| quantity | DECIMAL NULL |
| employee_id → lists | الموظف المسؤول |
| due_date | الأجل المطلوب |
| offer_status_id → lists (code) | قيد الإعداد / مرسل / لم يُرسل / مقبول / مرفوض / ملغى |
| offer_date | تاريخ تقديم العرض |
| not_sent_reason_id, not_sent_note | Obligatoire si لم يُرسل |
| offer_invoice_no, offer_notes | |
| supplier_invoice_no | فاتورة المورد |
| availability_id | نعم / لا / جزئي |
| delivery_due_date, delivery_actual_date | |
| delivery_status_id | في الانتظار / جاهز / تم التسليم (« متأخر » est **calculé**, affiché automatiquement) |
| delivery_notes | |

### 3.7 `ps_followups` — سجل المتابعة

`id, project_id, entry_date, author_id, text, next_date NULL, created_at`.

### 3.8 `ps_files` — fichiers du projet

`id, project_id, original_name, stored_name, mime, size, uploaded_by, created_at`.
Fichiers stockés dans `wp-content/uploads/protecta-suivi/` protégé (`.htaccess deny`), servis uniquement via l'API après contrôle des droits. Types autorisés : PDF, Excel, Word, images.

### 3.9 `ps_changelog` — journal des modifications

`id, project_id, user_id, action (create/update/delete/restore/merge/import), entity (project/item/party/…), entity_id, field, old_value, new_value, created_at`. Affiché dans la Fiche Projet.

### 3.10 Réglages (table `wp_options`, préfixe `ps_`)

- `ps_db_version` — version du schéma appliqué.
- `ps_settings` — JSON : règles de validation (champs obligatoires activables, règle des dates, formats e-mail/téléphone), seuil d'alerte suivi (7 j), fenêtre doublons (60 j), inactivité (30 min), tentatives de connexion (5 / 15 min), heure et activation du récapitulatif e-mail, jeton Cron.
- Méta utilisateur : `ps_employee_id`, `ps_daily_email`, `ps_dark_mode`, `ps_last_activity`.

---

## 4. Rôles et permissions (vérifiés dans l'API)

| Action | موظف | مسؤول مشروع | الإدارة |
|---|---|---|---|
| Voir les projets / offres / livraisons | ✔ (tous — *à confirmer, Q1*) | ✔ | ✔ |
| Créer un projet | ✔ | ✔ | ✔ |
| Modifier les infos d'un projet | ✗ (sauf ceux qu'il a créés tant que… *Q1*) | ✔ ses projets (المسؤول عن المشروع) | ✔ tous |
| Modifier statut d'offre / livraison d'un صنف | ✔ les أصناف dont il est الموظف المسؤول | ✔ tous les أصناف de ses projets | ✔ |
| Ajouter au سجل المتابعة, joindre un fichier | ✔ projets où il intervient | ✔ | ✔ |
| Recap | ✗ (*Q2*) | ✗ (*Q2*) | ✔ |
| Annuaire : ajouter une société / un concurrent | ✔ | ✔ | ✔ |
| Annuaire : modifier / fusionner | ✗ | ✗ | ✔ |
| الإعدادات (listes, règles, utilisateurs) | ✗ | ✗ | ✔ |
| Export CSV/Excel, sauvegarde/restauration, import | ✗ | ✗ | ✔ |
| Supprimer (corbeille) | ✗ (passe en ملغى) | ✗ | ✔ |

Le compte administrateur WordPress reçoit automatiquement les droits الإدارة. Les autres comptes n'ont jamais accès à wp-admin (redirection) ni à la barre admin.

---

## 5. Calculs (`inc/calculs.php`)

Fonctions pures, une par définition du §6 du brief, chacune commentée avec la règle en français/arabe :
`followup_type()`, `is_closed()`, `project_offer_status()`, `is_offer_sent()`, `success_rate()`, `send_rate()`, `amounts_by_currency()`, `margin()`, `margin_pct()`, `is_offer_late()`, `is_delivery_late()`, `delivery_delay_days()`, `effective_delivery_status()`, `row_color()`, `needs_followup()`, `auto_priority()`, `needs_decision()` (لازم قرار).
« Aujourd'hui » = date du fuseau WordPress (réglé sur Africa/Tripoli à l'activation si non défini) ; les fonctions acceptent une date « aujourd'hui » en paramètre pour des tests reproductibles.

---

## 6. Import du prototype (`backup.json`)

Format du prototype : `{ p: [ { no, offno, year, seq, name, resp, emp, mid, recv, sent, place, cls, urg, imc, imv, amount, note, fu, own_*, exe_*, dir_*, mid2_*, comp:[{a,c,b,n}], items:[{n,q,emp,due,st,od,inv,on,sinv,av,dd,da,ds,ln}] } ] }`.

1. **Aperçu (simulation)** : rapport avant écriture — nb de projets, nouvelles valeurs de listes à créer, sociétés détectées/fusionnées, conflits de numéros, avertissements.
2. **Conversion** :
   - Urgence : `عاجل → U` (ou VU, choix proposé dans l'écran d'aperçu), `متوسط → U`, `عادي → N`.
   - حالة المتابعة : `مقبول → تم تأكيد الطلبية`, `مرفوض → رفض العرض`, `قيد المتابعة → في دراسة`, `في انتظار الحريف → في انتظار الرد`, `مغلق → تم التسليم`, `جديد → في دراسة` (*valeur du prototype non citée par le CdC, proposition*).
   - Lieu : `"T – Tripoli"` → code `T` ; texte libre inconnu → nouveau lieu créé.
   - Employés : rapprochement par nom normalisé ; inconnu → ajouté à la liste. Employé « موقع العطاءات » → مصدر الطلب = موقع العطاءات (*et le الموظف المتابع ? voir Q3*). Les autres projets reçoivent مصدر = « مباشر » par défaut (*Q3*).
   - Sociétés : المالك / المنفذة / الوسيط → annuaire, dédoublonnage sur nom normalisé exact (les quasi-doublons restent pour la fusion manuelle).
   - Concurrents → annuaire des منافسين ; أصناف/ماركة conservés.
   - Montant prototype sans devise → devise par défaut choisie dans l'aperçu (LYD ?).
3. **Sans perte** : un projet dont le `project_no` existe déjà est ignoré (rapport) ; rien n'est écrasé. Le JSON brut de chaque projet est conservé (`legacy_raw`).

---

## 7. Mises à jour sans perte de données

1. `style.css` porte `Version: x.y.z` ; `inc/config.php` porte `PS_DB_VERSION` (entier).
2. À chaque chargement : si `ps_db_version < PS_DB_VERSION` → verrou → **sauvegarde JSON automatique** dans `uploads/protecta-suivi/backups/` → migrations manquantes une par une → mise à jour de `ps_db_version`.
3. Migrations : uniquement `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN`, `ADD INDEX`, insertions de nouvelles valeurs de liste **si absentes** (par `code`). Interdit : `DROP`, `TRUNCATE`, `DELETE` en masse, réécriture de libellés existants.
4. Désactivation / changement de thème : aucune suppression.
5. Test automatique `test-migrations.php` : base v1 avec données → migration → vérification que tout est intact.
6. CHANGELOG.md + numéro de version à chaque livraison.

---

## 8. Autres fonctions

- **مهامي** : 4 blocs (offres en retard, لازم قرار, suivis du jour/dépassés, livraisons de la semaine) ; الإدارة : toute l'équipe + filtre par employé.
- **Doublons** : à l'enregistrement, si même société (المالك) + nom normalisé similaire (similarité ≥ 80 %) + reçu dans les 60 j → avertissement avec liens, bouton « حفظ رغم ذلك ».
- **Compteurs** « لازم قرار » sur le menu et en haut de Suivi Offres.
- **E-mail du matin** : `wp_mail`, HTML RTL ; par employé (ses tâches) + résumé global pour الإدارة ; activable par utilisateur. Déclenché par Cron cPanel (`wget -q -O- "https://site/?ps_cron=JETON"`), instructions détaillées dans le README.
- **Sécurité** : 5 tentatives / 15 min par IP+identifiant, déconnexion après 30 min d'inactivité (contrôlée aussi côté serveur), nonces, requêtes préparées, échappement, uploads protégés. README : HTTPS + sauvegarde quotidienne (JetBackup / cPanel).
- **Export** : CSV (UTF-8 BOM, ouvrable dans Excel) + XLSX natif. **Sauvegarde/restauration JSON** (restauration = sauvegarde automatique préalable).
- **Mode nuit** : bascule manuelle mémorisée + `prefers-color-scheme`.
- **Prêt pour v2** (non développé) : dates centralisées (calendrier), `meta.rate_to_lyd` sur les devises (conversion LYD), emplacement prévu pour `manifest.json` / service worker (PWA).

---

## 9. Étapes (§13 du cahier) et tests

| Étape | Contenu | Vérification |
|---|---|---|
| 0 | Environnement local : MariaDB + WordPress + PHP 8.3 | Thème activé, tables créées |
| 1 | Squelette thème, schéma, migrations, listes par défaut, rôles, connexion, coquille, CSS (RTL, nuit, mobile) | Activation, réactivation, mise à jour simulée sans perte |
| 2 | Fiche Projet (création/édition, annuaire, autocomplétion, validation, numérotation, doublons, سجل المتابعة, fichiers, journal) + Base Projets | Tests de validation + permissions REST |
| 3 | Suivi Offres, Suivi Livraison, لم يُرسل, alertes, مهامي | **Tests de tous les calculs §6** sur le jeu de démo |
| 4 | Recap (KPI + comparaison N-1, 11 graphiques/tableaux, clics filtrants, impression) | Tests Recap vs résultats attendus à la main |
| 5 | الإعدادات, utilisateurs, annuaire + fusion, import prototype, export, sauvegarde/restauration, e-mail, Cron | Test import + aller-retour sauvegarde/restauration |
| 6 | Jeu de démo ~30 projets, README arabe, CLAUDE.md, CHANGELOG, zip final | Résultats des tests présentés + zip |

Jeu de démonstration : ~30 projets couvrant plusieurs devises (LYD, USD, EUR, TND), retards d'offre et de livraison, urgence L (exclue des retards), لم يُرسل avec raisons, ملغى, doublons de sociétés (orthographes proches), tous les statuts de suivi, deux années (pour la comparaison N-1). Les résultats attendus sont rédigés à la main dans `tests/fixtures/demo-expected.md` puis vérifiés par les tests.

---

## 10. Questions à valider

Ma proposition par défaut est indiquée entre crochets ; si elle vous convient, il suffit de répondre « OK ».

1. **Droits du موظف** : voit-il tous les projets ou seulement les siens ? Peut-il modifier les infos générales d'un projet qu'il a créé ?
   [Il voit tout en lecture ; il modifie seulement ses أصناف (offre/livraison), ajoute au سجل المتابعة et joint des fichiers. Les infos générales : مسؤول المشروع + الإدارة.]
2. **Recap** : réservé à la الإدارة (cahier) ou aussi au مسؤول مشروع ? [الإدارة seulement.]
3. **Import** : pour les projets dont l'employé est « موقع العطاءات », qui devient الموظف المتابع ? Et quel مصدر pour les autres anciens projets ?
   [المتابع = المسؤول عن المشروع ; autres projets : مصدر = « مباشر ».]
4. **Devises** : liste et devise par défaut ? [LYD (défaut), USD, EUR, TND. Les montants du prototype sans devise → LYD.]
5. **تاريخ إرسال العرض** : obligatoire aussi quand un صنف passe directement à مقبول ou مرفوض (une offre acceptée a forcément été envoyée) ? [Oui. Et quand un صنف passe à مرسل, si la date du projet est vide, elle est pré-remplie avec la date du jour, modifiable.]
6. **Conversion « عاجل »** : VU ou U ? [U, modifiable dans l'aperçu d'import.]
7. **Couleurs / logos** : le logo PROTECTA GROUP est noir ; je reprends l'orange `#E8571F` et le bleu `#385B9A` du logo AMC Group (extraits du prototype). Page de connexion : bannière complète Protecta + AMC (celle du prototype) ? [Oui ; logos des marques (RELLI, inim, VDS, legrand, ELAN, FADINI) dans le pied de page.]
8. **Fichiers manquants** : `backup.json` n'a pas été fourni. Pouvez-vous l'envoyer ? Sinon je teste l'import avec un exemple construit au format du prototype. (Les fichiers joints du prototype étaient stockés hors du backup et ne peuvent pas être importés.)
9. **التصنيف** (point ouvert §14 du cahier) : avez-vous la liste réelle ? [Par défaut : كهرباء، إنارة، شبكات، أنظمة أمان وإنذار، تجهيزات، أخرى — modifiable dans الإعدادات.]
