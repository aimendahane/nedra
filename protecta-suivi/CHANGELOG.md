# Journal des versions — Protecta Suivi

Format : chaque version indique la version du thème et la version de la base (`PS_DB_VERSION`).
Une mise à jour du thème conserve toujours toutes les données (voir README.md).

## 0.5.0 — 2026-10-19 · base v1 — Étape 5 : الإعدادات et données

- الإعدادات : listes (ajouter, renommer, couleur, type, masquer, réordonner, supprimer si jamais utilisée),
  règles de validation (champs obligatoires + 3 règles), réglages (inactivité, tentatives, alertes, doublons,
  fenêtre du créateur), e-mail du matin, commande Cron avec jeton régénérable, e-mail d'essai.
- Comptes : création, rôle, employé lié, e-mail du matin, mot de passe, désactivation (jamais de suppression).
- Annuaire : doublons probables et fusion (projets réaffectés, fiche fusionnée conservée).
- Import du backup du prototype : aperçu sans écriture, choix « عاجل » → U/VU et devise, conversions §8.1/§8.3,
  « غير محدد » / « موقع العطاءات », projets déjà présents ignorés, sauvegarde automatique avant.
- Export CSV (UTF-8 BOM) et Excel (.xlsx natif, RTL) : projets, offres, livraisons.
- Sauvegarde JSON complète, liste des sauvegardes serveur, restauration confirmée (sauvegarde automatique avant).
- E-mail du matin (tâches par employé, résumé pour la الإدارة), Cron par URL secrète ou ligne de commande.
- Correction : export Excel impossible depuis l'application (fonction réservée à wp-admin).

## 0.4.0 — 2026-10-19 · base v1 — Étape 4 : Recap

- Page Recap (الإدارة) : filtres السنة / الشهر / الموظف / المكان / المصدر ; 12 indicateurs avec comparaison
  à la même période de l'année précédente (↑/↓).
- Graphiques Chart.js 4.5.1 (inclus, sans CDN) : mois (barres empilées + total), تصنيف (anneau), أصناف,
  أماكن, أسباب عدم الإرسال, لم تُرسل حسب الصنف — valeurs affichées, RTL, mode nuit, téléphone.
- Tableaux : حالة المتابعة, الاستعجال, الموظف (triable), المصدر (« غير محدد » à part), أهم 10 شركات, المنافسون.
- Clic sur une barre / part / ligne → page filtrée ; bouton « طباعة / PDF ».

## 0.3.0 — 2026-10-19 · base v1 — Étape 3 : suivi des offres et des livraisons

- Tous les calculs du brief §6 dans `inc/calculs.php` (statut d'offre du projet, المرسلة، لم تُرسل، نسبة النجاح،
  نسبة الإرسال، montants par devise, priorité, marge, retards, تأخير، حالة التسليم automatique, alerte de suivi).
- Suivi Offres : une ligne par صنف, lignes rouges (retard) / vertes (مقبول), compteur « لازم قرار »,
  modification directe ; « لم يُرسل » avec motif obligatoire ; تاريخ إرسال العرض demandé à l'envoi.
- Suivi Livraison : أصناف مقبولة, تأخير calculé, « متأخر » automatique.
- Page d'accueil مهامي (et vue équipe filtrable pour la الإدارة) ; compteurs sur le menu.
- Jeu de démonstration de 30 projets + résultats calculés à la main (tests/fixtures).
- Correction : les écouteurs d'événements d'une page ne restent plus actifs sur les pages suivantes.

## 0.2.0 — 2026-10-19 · base v1 — Étape 2 : Fiche Projet et Base Projets

- Fiche Projet : création et modification (معلومات، أطراف، أصناف، مبالغ، منافسون), champs obligatoires en jaune,
  validation serveur réglable (§11), رقم المشروع automatique PRJ-AAAA-NNN, suggestion de رقم العرض,
  ajout rapide d'un مكان ou d'un صنف, brouillon conservé, هامش الربح et الأولوية calculés.
- Annuaire des sociétés et des منافسين : autocomplétion, une seule fiche par société (normalisation arabe).
- Détection de doublons (même société, nom proche, ±60 jours) : avertissement « حفظ رغم ذلك ».
- Onglets d'un projet : سجل المتابعة, fichiers (stockage protégé), journal des modifications.
- Droits vérifiés par le serveur : الإدارة, مسؤول المشروع, créateur pendant 24 h ; lecture pour tous.
- Base Projets : 22 colonnes du cahier + مصدر et الأولوية, recherche arabe tolérante, filtres, tri, pagination,
  cartes sur téléphone ; الإدارة : filtre « مصدر غير محدد », modification groupée du مصدر, corbeille.
- Page الشركات والمنافسون (liste, recherche, ajout ; modification pour la الإدارة).
- Aucun changement de structure de la base (pas de migration).

## 0.1.0 — 2026-10-19 · base v1 — Étape 1 : fondations

- Thème-application : page de connexion personnalisée (logo), une seule page, pas de barre admin,
  wp-admin et wp-login.php inaccessibles aux employés, toute autre URL redirigée vers l'application.
- Base de données : 10 tables dédiées (`ps_lists`, `ps_projects`, `ps_project_parties`, `ps_companies`,
  `ps_competitors`, `ps_project_competitors`, `ps_items`, `ps_followups`, `ps_files`, `ps_changelog`).
- Migrations versionnées (additives) avec sauvegarde JSON automatique avant chaque migration et verrou.
- 14 listes déroulantes par défaut (cahier §8 + brief), remplies une seule fois.
  « موقع العطاءات » déplacé des employés vers مصدر الطلب ; « غير محدد » réservé à l'import.
- Rôles موظف / مسؤول مشروع / الإدارة et leurs capacités.
- Sécurité : 5 tentatives / 15 min, déconnexion après 2 h d'inactivité (réglables), XML-RPC désactivé,
  liste des utilisateurs WordPress non publique, dossiers `files/` et `backups/` inaccessibles par URL.
- Jeton Cron aléatoire de 64 caractères (régénérable).
- Interface : arabe RTL, police Cairo incluse, couleurs du logo en variables CSS, mode nuit
  (auto / nuit / jour, mémorisé par utilisateur), menu adapté au mobile.
- Pages en place (contenu aux étapes suivantes) ; الإعدادات affiche les listes en lecture seule.
