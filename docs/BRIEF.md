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

---

# Mise à jour du brief (27/10/2026) — Fiche Projet, Offres, Factures et alertes Telegram

> Recopiée depuis la demande du client ; **prime** sur les sections précédentes en cas de différence.
> Décisions prises seul pour l'appliquer : CLAUDE.md, « Version 1.6.0 — décisions ».

## 0. Règles générales
- Migrations additives uniquement (CREATE IF NOT EXISTS, ADD COLUMN, ADD INDEX). Aucune suppression ni écrasement.
- Tester sur le site de test et sur WordPress local avant de livrer. Ne poser une question que si l'on est vraiment
  bloqué ; sinon décider et noter la décision dans CLAUDE.md.

## 1. Fiche Projet (création et modification)
- Case « مشروع؟ » (oui / non), obligatoire. « non » → « سبب عدم اعتباره مشروعا » obligatoire (liste dans الإعدادات :
  غير مؤكد، خارج الاختصاص، معلومات غير كافية، أخرى) + note libre optionnelle. Un projet « non » n'apparaît pas dans les
  offres, ni dans les KPI d'offres ni dans نسبة النجاح ; il reste visible dans Base Projets (filtre dédié) et a un
  compteur séparé dans le Recap.
- « NOTE / نص الطلب » : optionnel. « ملاحظة المشروع » : texte libre, optionnel, distinct des autres notes.
- Upload de fichiers possible dès la création (optionnel), mêmes règles que les fichiers joints (version 1.5.0).
- Dates affichées et saisies au format jj/mm/aaaa.
- « الشخص المباشر », « الشخص المسؤول », « الهاتف » de la société : viennent de l'annuaire des sociétés (§4), pas
  saisis à chaque projet.

## 2. Offres (Suivi Offres)
- Colonnes « المسؤول عن المشروع » et « الموظف المتابع » : pré-remplies depuis le projet, modifiables au niveau de
  l'offre ; tout changement journalisé (qui, quand, ancienne, nouvelle valeur, justification obligatoire) ;
  modifiables par مسؤول المشروع du projet et la الإدارة.
- Champs de l'offre : رقم فاتورة العرض (unique), fichier joint, تاريخ إرسال العرض (règles du §5 ci-dessus).
- Trois notes distinctes : ملاحظة المشروع (fiche), ملاحظة العرض (offre), ملاحظة الفاتورة (facture).

## 3. Factures
- Liée à une offre ; plusieurs factures par offre (tranches), choix réversible sans perte.
- رقم الفاتورة (unique), تاريخ إرسال الفاتورة (obligatoire, jj/mm/aaaa), آخر أجل للرد CALCULÉ = envoi + N jours
  (paramètre, défaut 15), جواب الحريف نعم / لا / في الانتظار (défaut في الانتظار, liste fermée), ملاحظة الفاتورة,
  fichier joint.
- Retard : « في الانتظار » et آخر أجل dépassé ; jours de retard affichés. Active dans les alertes tant que pas de
  réponse نعم / لا. Recap : en attente, en retard, taux نعم / لا ; montants séparés par devise.

## 4. Alertes Telegram
- Token dans wp-config.php (constante PS_TELEGRAM_TOKEN), jamais dans le code, le dépôt, l'export JSON ni le HTML.
  README en arabe : @BotFather, wp-config.php, liaison des utilisateurs, Cron.
- Liaison : code court valable 10 min généré dans le profil, « /start CODE » au bot, chat_id enregistré ; statut
  « مربوط بتيليغرام / غير مربوط » dans les paramètres de l'utilisateur et dans la page des utilisateurs ; déliaison
  à tout moment.
- Événements : rappel 3 jours avant آخر أجل (facture في الانتظار), jour de l'échéance, retard (1 message par jour
  max) — destinataire : المتابع de l'offre, à défaut المسؤول عن المشروع. Option : résumé quotidien (مهامي).
- Contenu : numéro de facture, numéro de projet, date limite, type d'alerte, lien #/factures/ID. Ni client, ni
  montant, ni détail du projet.
- Fiabilité : jamais deux fois le même événement le même jour pour la même personne ; Telegram indisponible →
  journaliser, réessayer au Cron suivant, e-mail de secours ; aucune perte. Bouton « إرسال رسالة تجريبية ».
  Interrupteur global « تفعيل التنبيهات ».
- Cron cPanel chaque matin (URL + jeton long, régénérable).

## 5. Tests demandés
Projet « non » exclu des offres / KPI / نسبة النجاح et présent dans Base Projets avec son filtre ; آخر أجل = envoi
+ 15 j et changement de N sans toucher aux dates d'envoi ; plusieurs factures par offre ; changement du المتابع
journalisé avec justification et refusé sans droit ; liaison Telegram (code valable, expiré refusé, déliaison) ;
envois simulés J-3 / J / retard 1 fois par jour / pas de doublon ; Telegram indisponible (échec journalisé, réessai,
aucune perte) ; token absent de l'export JSON et du HTML ; upload à la création ; mise à jour avec données sans perte.

## 6. Livraison
Version semver (style.css, CHANGELOG, README, CLAUDE.md) ; README arabe (bot, wp-config.php, liaison, Cron, « où
modifier quoi ») ; zip + résultat complet des tests + liste des décisions prises seul.

## 7. Points ouverts (valeurs par défaut appliquées)
« mon projet » et « ZZ » : non implémentés ; « 3X » → « غير محدد » à l'import ; « عاجل » → U ; délai de réponse 15 j
(paramètre global) ; raisons « non projet » = valeurs par défaut ci-dessus.

---

# Correction validée par le client (27/10/2026) — ordre des champs et des colonnes (version 1.6.1)

Règle générale : **les champs importants en premier, dans l'ordre ci-dessous ; tout le reste ensuite.**
« مشروع؟ » est une vraie colonne (visible, triable, filtrable) de la liste des projets.

## 1. Fiche projet (إدخال مشروع), de haut en bas
1. 📎 الملفات المرفقة (création : envoyés juste après l'enregistrement ; projet existant : en haut de l'onglet البيانات)
2. مشروع؟ نعم / لا (obligatoire)
3. سبب عدم اعتباره مشروعا + ملاحظة — affichés immédiatement sous la case, seulement si « لا »
4. Le reste : رقم المشروع، رقم العرض، اسم المشروع، dates, المسؤول، المتابع… puis الأطراف، الأصناف، المبالغ، المنافسون.

## 2. Base Projets (المشاريع), colonnes
رقم المشروع | الاسم | مشروع؟ | سبب عدم الاعتبار (vide si نعم) | المسؤول عن المشروع | الموظف المتابع |
تاريخ الاستلام | الحالة (حالة المتابعة) | ensuite : حالة العرض، السنة، الشهر، المكان، الاستعجال، الأهمية، التصنيف،
الشركة، الشخص المباشر، الهاتف، الأصناف، تاريخ الإرسال، الوسيط، المصدر، الأولوية، NOTE، الرابط.
L'ancienne colonne « رقم » (numéro d'ordre) est retirée (doublon de رقم المشروع).

## 3. Suivi Offres, colonnes
رقم المشروع (nom en petit dessous) | رقم فاتورة العرض | المسؤول عن المشروع | الموظف المتابع | تاريخ الإرسال |
حالة العرض | 📎 (icône + nombre de fichiers) | ملاحظة العرض | ensuite : الصنف، موظف الصنف، الاستعجال، الأجل المطلوب، 🧾 الفواتير.

## 4. Factures (page + fenêtre « 🧾 » d'une offre), colonnes
رقم الفاتورة | رقم المشروع (nom en petit) | تاريخ الإرسال | آخر أجل | جواب الحريف | المبلغ والعملة |
📎 (icône + nombre de fichiers) | ملاحظة الفاتورة | ensuite : الصنف، الموظف المتابع.

## 5. Projet « لا »
Visible dans Base Projets (« مشروع؟ = لا » + motif) ; absent de Suivi Offres, de Suivi Livraison et des factures ;
compté à part dans le Recap. Pas de colonne « مشروع؟ » dans Suivi Offres / Factures (elle vaudrait toujours نعم).

---

# Retour client (27/10/2026) — expérience d'utilisation (version 1.7.0)

Constat du client : « l'expérience n'est pas assez bonne ». Réponses au questionnaire : tous les écrans ; trop de
colonnes, info difficile à trouver, trop de clics, écrans chargés ; sur PC ET téléphone. Solution choisie :
**« tableau court »** :
1. Listes (المشاريع، العروض، الفواتير) : seules les colonnes importantes (ordre de la correction 1.6.1) par défaut ;
   bouton « الأعمدة » pour en ajouter.
2. Filtres rapides avec compteurs au-dessus des listes.
3. Clic sur une ligne → panneau latéral avec tous les détails et les actions (modification de l'offre comprise).
4. Fiche projet : raccourcis de sections, champs secondaires repliés (« المزيد »), sauf champs obligatoires.
