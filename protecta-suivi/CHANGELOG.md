# Journal des versions — Protecta Suivi

Format : chaque version indique la version du thème et la version de la base (`PS_DB_VERSION`).
Une mise à jour du thème conserve toujours toutes les données (voir README.md).

## 1.3.1 — 2026-10-21 · base v4 — Messages vocaux : le son arrive partout

- **Telegram : message vocal muet corrigé.** Chrome, Edge et Android enregistrent en WebM, format que Telegram
  ne lit pas (le fichier arrivait sans son). Le serveur en fait maintenant une copie **Ogg/Opus** (conversion en
  PHP pur, sans réencodage ni extension) envoyée comme vrai message vocal Telegram. L'original reste pour le navigateur.
- Enregistrement en **MP4/AAC** quand le navigateur le permet (lisible partout, iPhone compris), sinon Ogg, sinon WebM.
- Lecture dans l'application plus robuste sur l'hébergement : tampons PHP vidés et compression gzip coupée
  pour le fichier audio (rien ne peut plus abîmer le son).
- **Barre de niveau du micro** pendant l'enregistrement + alerte « لم يُلتقط أي صوت » si le micro n'a rien capté.
- Tests : 656 tests PHP (vrai enregistrement Chrome → Ogg vérifié avec ffmpeg : lisible, durée, volume) ;
  navigateur : le fichier servi est décodé et contient du son.

## 1.3.0 — 2026-10-21 · base v4 — Interface en العربية / English / Français

- **Trois langues** : العربية (par défaut, de droite à gauche), English et Français (de gauche à droite).
  Choix sur la page de connexion (liens « العربية · English · Français ») ou dans l'en-tête (bouton « AR / EN / FR »).
  La langue est enregistrée sur le compte : chacun garde la sienne sur tous ses appareils.
- Tout est traduit : menus, pages, formulaires, messages d'erreur, Recap (graphiques remis dans le bon sens),
  exports CSV/Excel, valeurs par défaut des listes (un libellé renommé par la الإدارة reste tel qu'il a été saisi).
- **E-mails et Telegram dans la langue du destinataire** (notifications, tâches, résumé du matin) : une même
  modification peut partir en arabe à l'un et en français à l'autre.
- الإعدادات ← عام : « لغة الواجهة الافتراضية » (comptes sans choix, page de connexion).
- Aucune donnée saisie n'est traduite ni modifiée ; aucune migration (base v4 inchangée).
- Ajouter un texte : l'écrire en arabe dans `ps__()` / `PS.t()` et sa traduction dans `inc/i18n/en.json` et `fr.json`.
- Tests : 650 tests PHP (dont dictionnaires complets : 897 textes × 2 langues) ; navigateur step9 (connexion en anglais,
  passage au français, retour à l'arabe, mobile).

## 1.2.0 — 2026-10-21 · base v4 — Telegram et messages vocaux

- **Notifications Telegram** (sans extension, sans adresse publique) : la الإدارة colle le token d'un bot
  créé avec @BotFather (vérifié auprès de Telegram) ; chaque compte se lie en un clic (🔔 → « ربط Telegram »,
  lien t.me avec code à usage unique valable 2 h). Reçus sur Telegram : notifications 🔔 des projets, tâches
  confiées (avec le message vocal), résumé du matin. « /stop » ou « فك الربط » arrête tout ; bot bloqué →
  compte délié automatiquement. Token jamais renvoyé au navigateur ni écrit dans la sauvegarde JSON.
- **Messages vocaux** : dans « مهمة جديدة », la الإدارة / le مسؤول enregistre un message (🎙, 5 min max,
  réécoute avant envoi). L'employé l'écoute dans مهامي (fichier protégé, servi après contrôle des droits,
  lecture par morceaux) et le reçoit sur Telegram. Message vocal seul accepté (titre « 🎙 رسالة صوتية »).
- **Base v4 (migration 4, additive)** : colonnes `voice_*` sur `ps_tasks`.
- Tests : 616 tests PHP (Telegram simulé, vocal, migration 4) ; navigateur avec microphone simulé.

## 1.1.0 — 2026-10-20 · base v3 — Nouveau design

- **Nouvelle identité visuelle** (aucun changement de données ni de base) : palette « ardoise » + bleu/orange
  du logo, ombres et rayons doux, police Cairo plus aérée, mode nuit retravaillé (couleurs dans variables.css).
- **Menu latéral sombre** (bleu nuit) avec icônes dessinées (SVG intégrés, plus d'émojis), indicateur orange
  de la page active, compteurs en pastilles ; fond sur toute la hauteur ; plein écran sur téléphone.
- **En-tête en verre dépoli** avec liseré orange → bleu, avatar avec l'initiale, boutons ronds (🔔, mode nuit, sortie).
- **Page de connexion** en deux parties : panneau de marque animé + carte de connexion.
- **Animations** discrètes : apparition des pages, cartes et indicateurs en cascade, fenêtres et menus,
  graphiques Recap animés (barres arrondies, infobulles). Désactivées si l'appareil demande « moins d'animations ».
- Tableaux : en-têtes collants, ombre sur le côté quand on peut faire défiler (téléphone), bande de couleur
  pour les lignes en retard / مقبول ; champs avec halo de focus ; champs en lecture seule bien visibles.
- Corrections d'affichage de la vérification Excel : **03** flèche de comparaison colorée selon le sens
  (plus de المرفوضة = rouge), **05** fond du menu coupé, **06** « المشاريع » surligné sur une fiche,
  **07** lecture seule visible, **08** numéro de projet sur une ligne, **04** repère de défilement des tableaux.

## 1.0.2 — 2026-10-19 · base v3 — Notifications 🔔

- **Cloche 🔔** dans l'en-tête : nombre de nouveautés (rafraîchi chaque minute), liste des dernières
  notifications, clic → fiche du projet (notification marquée lue), « تعليم الكل كمقروء ».
- **Qui est prévenu** : nouveau projet → المسؤول + المتابع + employés des أصناف ; صنف ajouté / confié à un autre
  employé → cet employé ; tout autre changement (حالة العرض, حالة المتابعة, dates, montants, أطراف, سجل المتابعة,
  fichiers, suppression) → المسؤول + المتابع. Jamais l'auteur de la modification. Pas pour l'import ni la restauration.
- **Une sauvegarde = une notification** par personne et par projet, avec le détail des changements.
- **E-mail immédiat** facultatif, par compte (case « بريد فوري عند كل تغيير » dans la cloche), désactivé par défaut.
- **Base v3 (migration 3, additive)** : table `ps_notifications` (jamais vidée), incluse dans la sauvegarde JSON.
- Tests : 580 tests PHP (+ notifications, migration 3) ; parcours navigateur « 1.0.2 » (PC et mobile).

## 1.0.1 — 2026-10-19 · base v2 — Tâches libres et e-mail

- **Tâches libres « مهمة جديدة »** : الإدارة et مسؤول مشروع envoient une tâche à un employé (موظف, المهمة,
  الأجل, ملاحظة, projet lié facultatif). Elle apparaît dans مهامي (bloc « مهام مُسندة », bouton « ✔ تمت »),
  dans le compteur du menu et dans l'e-mail du matin ; l'employé reçoit aussi un e-mail immédiat.
  L'auteur suit ses demandes (bloc « مهام أرسلتها » : ouvertes + faites depuis 14 jours), peut les modifier,
  rouvrir ou annuler. Bouton « 📌 مهمة » dans Fiche Projet. Rien n'est supprimé (annulée = statut « ملغاة »).
- **Base v2 (migration 2, additive)** : nouvelle table `ps_tasks` + capacité `ps_assign_tasks`
  (مسؤول مشروع, الإدارة). Les tâches sont incluses dans la sauvegarde / restauration JSON.
- **E-mail** : réglages « إعدادات إرسال البريد » (adresse et nom de l'expéditeur, envoi SMTP avec un vrai compte
  e-mail cPanel, sans extension). Le bouton d'essai accepte une autre adresse et affiche la **vraie raison**
  d'un échec (message du serveur) au lieu d'un message général. Mot de passe SMTP jamais renvoyé au
  navigateur ni écrit dans la sauvegarde JSON.
- Tests : 545 tests PHP (+ tâches, e-mail, migration 2) ; parcours navigateur étape « 1.0.1 ».

## 1.0.0 — 2026-10-19 · base v1 — Étape 6 : version livrée

- Version complète (étapes 1 à 5) testée : 492 tests automatiques + parcours navigateur, installation sur
  WordPress vierge depuis le zip, mise à jour depuis 0.1.0 avec données (aucune perte).
- README arabe : installation HostGator, mise à jour, HTTPS, sauvegarde, Cron, « où modifier quoi ».
- Publication automatique du zip dans les Releases GitHub (tag vX.Y.Z).

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
