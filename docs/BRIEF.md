# Projet : application web de suivi des projets et des offres (thème WordPress)

> Brief complémentaire du client (19/10/2026), recopié tel quel depuis la conversation.
> Il prime sur le cahier des charges en cas de différence. Les réponses du client aux
> questions du plan sont dans CLAUDE.md (« Décisions validées »).

## 0. Fichiers fournis (dans ce dossier)
- cahier-des-charges.docx : le cahier des charges complet.
- prototype.html : le prototype actuel (fonctionnel, données dans le navigateur).
- logo.png (ou logo.svg) : notre logo pour l'en-tête et la page de connexion.
- logo-marques.png : logos de nos marques pour le pied de page (si présent).
- backup.json : backup réel du prototype, à utiliser pour tester l'import (si présent).
  Si un logo manque, extrais-le du prototype.html (images en base64).

Lis le cahier des charges et le prototype avant de commencer. Respecte le cahier des
charges à 100 %, avec les compléments et précisions ci-dessous (en cas de différence,
ce document prime).

## 1. Livraison et hébergement
- Résultat final : un thème WordPress en .zip que j'installe moi-même sur HostGator
  (Apparence > Thèmes > Téléverser). Hébergement mutualisé standard (PHP 8.1+, MySQL),
  aucun plugin obligatoire.
- À l'activation, le thème crée lui-même ses tables, ses rôles et ses listes par défaut.

## 2. Apparence
- Le site doit ressembler à une vraie application web, pas à WordPress : page de
  connexion personnalisée avec notre logo, pas de barre admin, pas d'accès à wp-admin
  pour les employés.
- Interface arabe RTL, mobile + PC, mode nuit, police arabe lisible, couleurs de notre
  logo (orange et bleu) dans des variables CSS.

## 3. Fonctionnement général
- WordPress sert seulement de moteur : comptes et rôles (موظف / مسؤول مشروع / الإدارة
  selon §1), base MySQL partagée (tables dédiées), upload de fichiers.
- Permissions vérifiées côté serveur (API REST), pas seulement dans l'interface.
- Pages : مهامي (accueil), Fiche Projet, Base Projets, Suivi Offres, Suivi Livraison,
  Recap, الشركات والمنافسون, الإعدادات (dont gestion des utilisateurs pour la الإدارة).
- Toutes les règles de validation (§11) et les listes déroulantes (§8), modifiables
  depuis الإعدادات (ajouter, renommer, masquer, réordonner) sans toucher aux projets existants.
- Import du backup JSON du prototype (backup.json), avec conversion des anciennes
  valeurs (§8.1, §8.3).
- Export CSV / Excel et sauvegarde / restauration JSON (الإدارة).
- Journal des modifications (qui, quoi, quand) par projet.

## 4. Compléments à la conception
1. Annuaire des sociétés : chaque société (مالك، شركة منفذة، وسيط) enregistrée une seule
   fois avec ses coordonnées. Dans Fiche Projet : autocomplétion ; choisir une société
   remplit ses informations ; sinon « + إضافة شركة جديدة ». La الإدارة peut fusionner
   des doublons (les projets liés sont réaffectés).
2. Annuaire des منافسين : même principe (autocomplétion + fusion).
3. Champ obligatoire « مصدر الطلب » (liste dans الإعدادات) : مباشر، موقع العطاءات، وسيط،
   إيميل، هاتف، معرض، أخرى. Retirer « موقع العطاءات » de la liste des employés ;
   à l'import, les projets dont l'employé est « موقع العطاءات » reçoivent ce مصدر.
4. Journal de suivi (سجل المتابعة) : chronologie par projet, chaque entrée = date,
   auteur, texte, prochaine date de suivi. NOTE reste pour le texte de la demande.
5. Page d'accueil « مهامي » : pour chaque utilisateur, ses offres en retard, ses أصناف
   « لازم قرار », ses suivis prévus aujourd'hui ou dépassés, ses livraisons de la semaine.
   La الإدارة voit toute l'équipe, filtrable par employé.
6. E-mail récapitulatif chaque matin (tâches pour chaque employé, résumé global pour la
   الإدارة), activable par utilisateur. Explique dans le README comment créer le
   Cron Job dans cPanel HostGator.
7. Détection de doublons : à l'enregistrement, avertir (sans bloquer) si un projet de la
   même société avec un nom proche existe dans les 60 derniers jours.
8. Sécurité : limiter les tentatives de connexion, déconnexion après inactivité ;
   rappeler dans le README d'activer HTTPS et une sauvegarde quotidienne automatique.

## 5. Offres non envoyées (لم يُرسل)
- Statuts d'offre d'un صنف : قيد الإعداد / مرسل / لم يُرسل / مقبول / مرفوض / ملغى.
  ملغى = annulé par le client ; لم يُرسل = nous avons décidé de ne pas envoyer.
- « لم يُرسل » rend obligatoire « سبب عدم الإرسال » (liste dans الإعدادات) + note libre
  optionnelle. Valeurs par défaut : المنتج غير متوفر، السعر غير منافس، الأجل قصير،
  خارج اختصاصنا، معلومات ناقصة من الحريف، قرار الإدارة، أخرى.
- « تاريخ إرسال العرض » est optionnel dans Fiche Projet et devient obligatoire dès qu'un
  صنف passe à « مرسل » (la règle الإرسال ≥ الاستلام reste valable).
- Alerte « لازم قرار » : أصناف dont الأجل المطلوب est dépassé et toujours « قيد الإعداد »
  (hors urgence L). Compteur sur le menu et en haut de Suivi Offres.

## 6. Calculs (définitions exactes)
Tous les calculs sont centralisés dans UN fichier commenté (et son équivalent PHP si
nécessaire), pour que je puisse les modifier facilement.

Types de حالة المتابعة (réglables dans الإعدادات ; les calculs utilisent le TYPE,
jamais le texte du statut) :
- جارية : في دراسة، في انتظار معلومات، في انتظار الرد
- مرفوض : رفض العرض
- مؤكد : تم تأكيد الطلبية، في انتظار الدفع، في انتظار تكملة الدفع، في انتظار التوريد، تم تسليم جزء
- مسلّم : تم التسليم (compte aussi comme مؤكد)
- مغلق = مرفوض ou مسلّم.

حالة العرض d'un projet (depuis ses أصناف, en ignorant les ملغى) :
- tous ملغى → ملغى ; au moins un مقبول → مقبول ; tous مرفوض → مرفوض ;
  au moins un مرسل → مرسل ; tous لم يُرسل → لم يُرسل ; sinon → قيد الإعداد.

Indicateurs :
- العروض المرسلة : projets dont حالة العرض ∈ {مرسل، مقبول، مرفوض} OU dont le statut de
  suivi est de type مؤكد / مرفوض / مسلّم. Les projets « لم يُرسل » n'y comptent pas.
- نسبة النجاح = المؤكدة ÷ العروض المرسلة × 100 (1 décimale ; « — » si 0).
- نسبة الإرسال = المرسلة ÷ (المرسلة + لم تُرسل) × 100 (1 décimale).
- Montants (offres, projets confirmés) TOUJOURS séparés par devise, jamais additionnés.
- هامش الربح = مبلغ العرض − مبلغ الشراء ; نسبة الهامش = الهامش ÷ مبلغ العرض × 100
  (seulement si les deux montants existent, même devise).
- عرض متأخر : صنف « قيد الإعداد », الأجل المطلوب < aujourd'hui, urgence du projet ≠ L.
- تسليم متأخر : صنف مقبول, حالة التسليم ≠ تم التسليم, et (date réelle > date demandée,
  ou pas de date réelle et date demandée < aujourd'hui).
- تأخير (أيام) = réelle − demandée ; sans date réelle et dépassé : aujourd'hui − demandée ;
  sinon 0. Jamais négatif. حالة التسليم passe automatiquement à « متأخر ».
- Couleurs des lignes : rouge = offre en retard (hors L), vert = مقبول.
- Alerte suivi : projet non مغلق, urgence ≠ L, dernière entrée du سجل المتابعة (ou
  dernière modification si vide) de plus de 7 jours, ou prochaine date de suivi dépassée.
- الأولوية التلقائية : استعجال (VU=4, U=3, N=2, L=1) + الأهمية كحجم (عالية=3,
  متوسطة=2, ضعيفة=1, vide=1) → 6-7 = P1, 4-5 = P2, 2-3 = P3.

## 7. Page Recap
- Filtres : Année (obligatoire), Mois, Employé, Lieu, مصدر (optionnels). La période d'un
  projet = mois/année de « تاريخ استلام الطلب ».
- Chaque KPI affiche la comparaison avec la même période de l'année précédente (↑/↓ et %).
- KPIs : عدد المشاريع (ملغى à part), المؤكدة، المرفوضة، قيد الدراسة، العروض المرسلة،
  لم تُرسل، نسبة النجاح، نسبة الإرسال، المبالغ par devise، العروض المتأخرة، التسليمات المتأخرة.
- Graphiques et tableaux :
  - حسب الشهر : barres empilées sur 12 mois (جانفي…ديسمبر) par type (جارية / مؤكدة /
    مرفوضة), total au-dessus de chaque barre.
  - حسب التصنيف : anneau avec % ; au-delà de 8 parts, regrouper en « أخرى ».
  - حسب حالة المتابعة : tableau عدد + %, couleur de chaque statut.
  - حسب درجة الاستعجال : tableau VU / U / N / L, عدد + %, pastilles de couleur.
  - حسب الموظف المتابع : tableau عدد، مؤكدة، مرفوضة، مسلّمة، لم تُرسل، نسبة النجاح, triable.
  - حسب الصنف المطلوب : barres horizontales triées ; un projet compte une fois par صنف.
  - حسب المكان : barres avec le code (T, M, BG…), nom complet en infobulle.
  - حسب مصدر الطلب : tableau عدد، مؤكدة، نسبة النجاح.
  - أهم 10 شركات (annuaire) : عدد المشاريع، المؤكدة، montants confirmés par devise.
  - المنافسون الأكثر حضورا : المنافس، عدد المشاريع، الماركات، الأصناف.
  - أسباب عدم الإرسال : سبب، عدد، % + barres horizontales ; et لم تُرسل حسب الصنف.
- Chart.js (version UMD stable, fichier inclus dans le thème, pas de CDN). RTL, police
  du site, valeurs affichées sur les graphiques, couleurs reprises des réglages,
  lisibles en mode nuit et sur mobile.
- Clic sur une barre / une part / une raison → page correspondante filtrée.
- Bouton « طباعة / PDF » (feuille de style d'impression propre). État vide clair.

## 8. Mises à jour sans perte de données (très important)
- J'installerai de nouvelles versions du thème (remplacement du zip). Les données
  (projets, offres, sociétés, utilisateurs, paramètres, fichiers) doivent TOUJOURS
  être conservées.
- Tout changement de structure passe par une migration automatique versionnée, qui
  ajoute sans jamais supprimer ni écraser de données.
- Ne jamais remettre les listes de الإعدادات à leurs valeurs par défaut lors d'une mise à jour.
- Numéro de version augmenté à chaque livraison + CHANGELOG.md.

## 9. Facile à modifier plus tard
- Pas de build (pas de npm/webpack) : JavaScript et CSS simples, éditables directement.
- Un fichier JS par page ; couleurs et polices dans des variables CSS.
- Code commenté ; README.md en arabe : installation sur HostGator, mise à jour, Cron Job,
  sauvegarde, et « où modifier quoi ».
- CLAUDE.md décrivant l'architecture et ces règles (surtout la conservation des données),
  pour que les prochaines sessions les respectent.

## 10. Hors version 1
Ne pas développer maintenant, mais garder l'architecture prête : calendrier des
échéances, conversion des devises en LYD, installation PWA sur mobile.

## 11. Méthode
- Propose-moi d'abord le plan, la structure des fichiers et le schéma de la base,
  et attends ma validation avant d'écrire le code.
- Avance par étapes (§13 du cahier), et teste chaque étape sur un WordPress local.
- Crée un jeu de démonstration (~30 projets variés : plusieurs devises, retards,
  لم يُرسل, doublons de sociétés, tous les statuts) et des tests qui vérifient chaque
  calcul de la section 6 avec des résultats attendus calculés à la main.
  Montre-moi le résultat des tests.
- À la fin, donne-moi le zip prêt à installer.
