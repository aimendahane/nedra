# Journal des versions — Protecta Suivi

Format : chaque version indique la version du thème et la version de la base (`PS_DB_VERSION`).
Une mise à jour du thème conserve toujours toutes les données (voir README.md).

## 1.6.1 — 2026-10-27 · base v7 — Ordre des champs et des colonnes (validé par le client)

Aucune migration, aucune donnée touchée : seuls l'ordre et l'affichage changent (docs/BRIEF.md, « Correction validée »).
- **Fiche projet** : 📎 الملفات المرفقة tout en haut (projet existant : en haut de l'onglet البيانات), puis
  « مشروع؟ نعم / لا » dans son propre bloc, le motif et sa note juste dessous si « لا » ; ensuite le reste.
- **Base Projets** : رقم المشروع | الاسم | **مشروع؟** (vraie colonne, triable) | سبب عدم الاعتبار (vide si نعم) |
  المسؤول عن المشروع (nouvelle colonne) | الموظف المتابع | تاريخ الاستلام | الحالة | puis les autres. Filtre
  « مشروع؟ » toujours visible dans la barre. Colonne « رقم » (doublon) retirée.
- **Suivi Offres** : رقم المشروع (numéro d'abord, nom en petit) | رقم فاتورة العرض | المسؤول | المتابع | تاريخ الإرسال |
  حالة العرض | 📎 (nombre de fichiers) | ملاحظة العرض | puis الصنف، موظف الصنف، الاستعجال، الأجل، 🧾.
- Dates lisibles dans les tableaux modifiables (champ jj/mm/aaaa complet).
- **Factures** (page + fenêtre 🧾) : رقم الفاتورة | رقم المشروع | تاريخ الإرسال | آخر أجل | جواب الحريف |
  المبلغ والعملة | 📎 (nombre de fichiers, API filesCount) | ملاحظة الفاتورة | puis الصنف، الموظف المتابع.
- Exports CSV / Excel : مشروع؟، سبب، المسؤول ajoutés (Base Projets) ; المسؤول، المتابع، nombre de factures (Offres),
  en fin de fichier (l'ordre d'origine du fichier Excel reste réimportable).
- Tests : étape navigateur step15 (ordre de chaque écran, tri / filtre « مشروع؟ », 📎).

## 1.6.0 — 2026-10-27 · base v7 — Fiche Projet, Offres, Factures, alertes Telegram

Migration 7 (ajouts seulement : ADD COLUMN / CREATE TABLE ; projets existants = « مشروع = نعم ») :
`ps_projects` is_project, non_project_reason_id, non_project_note, project_note · `ps_items` responsible_emp_id,
follower_emp_id (offre) · `ps_files` item_id, invoice_id · `ps_changelog` reason · tables `ps_invoices`, `ps_alerts` ·
liste « سبب عدم اعتباره مشروعا » · réglages invoice_reply_days (15), alerts_enabled.

- **Fiche Projet** : « مشروع؟ » نعم / لا obligatoire ; « لا » → سبب obligatoire (liste : غير مؤكد، خارج الاختصاص،
  معلومات غير كافية، أخرى) + note. Un « غير مشروع » sort de Suivi Offres / Livraison, des KPI d'offres, de نسبة
  النجاح, des retards et alertes ; il reste dans Base Projets (filtre « مشروع؟ ») et compte à part dans le Recap.
  « ملاحظة المشروع » (distincte de NOTE / نص الطلب, facultative). Fichiers joints dès la création. Coordonnées des
  sociétés liées (الشخص، الهاتف…) reprises de l'annuaire (lecture seule 🔗 ; les vides complètent la fiche).
- **Dates jj/mm/aaaa** partout (saisie et affichage), bouton 📅 calendrier ; l'API reste en AAAA-MM-JJ.
- **Offres** : colonnes « المسؤول عن المشروع » et « الموظف المتابع » (reprises du projet, modifiables par مسؤول
  المشروع / الإدارة) ; tout changement exige une justification, journalisée (qui, quand, ancien → nouveau, raison).
  « رقم فاتورة العرض » unique ; fichiers de l'offre (📎) ; « ملاحظة العرض ». Passage à مرسل : تاريخ تقديم العرض =
  date d'envoi du projet (problème n°01 du fichier Excel).
- **Factures** (nouvelle page « الفواتير », #/factures et #/factures/ID) : plusieurs par offre ; رقم الفاتورة unique,
  تاريخ الإرسال obligatoire, آخر أجل للرد CALCULÉ (= envoi + N jours, N réglable, 15 par défaut), جواب الحريف
  في الانتظار / نعم / لا, montant + devise, ملاحظة الفاتورة, fichiers. Retard + jours affichés. Recap : factures en
  attente, en retard, taux نعم / لا, montants par devise.
- **Alertes Telegram** (Cron du matin) : J-3, jour J, retard (1/jour max) pour le المتابع de l'offre (sinon le
  مسؤول) ; jamais deux fois le même jour (table ps_alerts) ; Telegram indisponible → échec journalisé, e-mail de
  secours, nouvel essai au Cron suivant. Message : n° facture, n° projet, date limite, type, lien — rien d'autre.
  Interrupteur « تفعيل التنبيهات ».
- **Telegram** : token dans wp-config.php (`PS_TELEGRAM_TOKEN`), jamais dans le code, le JSON ni le HTML (l'ancien
  réglage reste accepté s'il existe) ; code de liaison court (8 caractères, 10 min, « /start CODE ») ; statut
  « مربوط / غير مربوط » dans la page des utilisateurs ; bouton « إرسال رسالة تجريبية ».
- Import Excel : الأهمية « 3X » → « غير محدد ».
- Tests : 921 tests PHP (dont tests/test-160.php) + étape navigateur step14.

## 1.5.3 — 2026-10-23 · base v6 — La الإدارة efface les tâches, page مهامي plus courte

- **🗑 sur chaque tâche** (الإدارة seulement) : suppression DÉFINITIVE après confirmation. Le titre reste dans le journal.
- **« 🧹 تنظيف المهام »** (الإدارة) : effacer d'un coup les tâches **منجزة + ملغاة**, ou **toutes** les tâches (mot
  « مسح الكل » obligatoire). Sauvegarde complète AVANT (retour possible par « استرجاع ») ; pas de sauvegarde = rien
  n'est effacé.
- **مهامي plus courte** : chaque bloc montre 5 lignes, le reste derrière « عرض الكل (N) » ; une tâche déjà listée dans
  « مهام مُسندة » n'est plus répétée dans « مهام أرسلتها ».
- مسؤول مشروع / موظف : aucun changement (pas de suppression ; « إلغاء » reste comme avant).

## 1.5.2 — 2026-10-23 · base v6 — « Tout effacer (projets ET tâches) et repartir du fichier »

- Import Excel, option **« 🗑 مسح كل المشاريع والمهام الحالية ثم الاستيراد »** : efface maintenant aussi **toutes les
  tâches libres (مهام)** et les notifications 🔔 (qui pointaient vers les projets / tâches effacés). Aperçu : « سيتم حذف
  N مشروع حالي + M مهمة ». Toujours après une sauvegarde complète (retour possible par « استرجاع »), dans la même
  transaction que l'import. Gardés : listes, sociétés, comptes, réglages, journal, fichiers sur le disque / Drive.
- Aperçu sans l'option où tous les projets existent déjà : message clair (« rien n'a été importé, cochez l'option »)
  au lieu d'un bouton « استيراد » simplement grisé.
- Erreurs du serveur plus lisibles : une page d'erreur de l'hébergeur (non JSON) s'affiche « ردّ غير متوقّع من الخادم
  (HTTP 503) : … » sans HTML brut ; une coupure de connexion / délai dépassé a son propre message.
- Import Excel : jusqu'à 5 min de traitement accordées (`set_time_limit`, si l'hébergeur le permet).
- Vérifié avec le fichier du client (157 projets) : 0,4 s, 30 projets + tâches effacés, 157 importés.

## 1.5.1 — 2026-10-23 · base v6 — « Tout effacer et mettre ces projets »

- Import Excel : option **« 🗑 مسح كل المشاريع الحالية ثم الاستيراد »** (demande du client). Aperçu : « سيتم حذف N
  مشروع حالي » + projets à importer, rien n'est touché. Import : mot « مسح الكل » obligatoire, **sauvegarde complète
  avant** (retour possible par « استرجاع »), effacement et import dans la même transaction (tout ou rien).
  Effacés : projets, أصناف, intervenants, concurrents, سجل المتابعة, fiches des fichiers. Gardés : listes, sociétés,
  comptes, réglages, tâches, journal ; les fichiers sur le disque / Drive ne sont pas supprimés.
- **Le nom d'abord** : un projet sans « اسم المشروع » dans le fichier prend le nom de la société (signalé, modifiable).
- Base Projets : les noms longs passent à la ligne (plus de chevauchement).
- Testé avec le vrai fichier du 23/10 sur un site de 30 projets : 30 effacés, 157 importés.

## 1.5.0 — 2026-10-23 · base v6 — Nom du projet d'abord, fichiers joints, Google Drive

- **Nom du projet d'abord** partout où un projet est listé (Base Projets, Suivi Offres / Livraison, مهامي, fiche) :
  nom en gras, puis le numéro en petit (PRJ-… ou P029, + l'ancien numéro d'un projet importé). Recherche par le
  nom ET par le numéro (actuel et ancien). Notifications : « nom — numéro ».
- **Fichiers joints** : tout type de fichier SAUF exécutables / scripts (php, phtml, phar, exe, bat, cmd, msi, sh, js,
  vbs, ps1, html, htm, svg, .htaccess…), vérifié sur chaque partie du nom (« x.php.jpg » refusé) et sur le contenu
  (code PHP, programme Windows/Linux, « #! ») ; message clair en arabe. **Taille max 20 Mo** (réglage
  « الحجم الأقصى للملف المرفق », plafonné par la limite de l'hébergeur). **Nom d'affichage** saisi à l'ajout, date
  et auteur affichés. Nom sur le disque = identifiant aléatoire « .bin ». Jamais dans la Médiathèque.
  **Retrait** : مسؤول المشروع, الإدارة, et l'auteur pendant 24 h (corbeille, rien n'est effacé).
  **Téléchargement** uniquement par l'API, après vérification de la connexion et des droits (compte désactivé,
  compte sans accès, visiteur → refusé ; projet à la corbeille → الإدارة seulement).
- **Google Drive** (الإعدادات ← عام ← « تخزين الملفات ») : les fichiers sont rangés dans le Drive de la société
  (dossier « Protecta Suivi », un sous-dossier par projet) ; ajout et ouverture toujours par le site (aucun lien
  Drive donné). Deux liaisons : compte Gmail (client OAuth + bouton, droit limité aux fichiers du site) ou
  Workspace (compte de service + Drive partagé). Le fichier est d'abord enregistré sur le serveur, envoyé dans
  Drive, et la copie du serveur n'est effacée qu'après vérification de l'empreinte MD5 renvoyée par Google.
  Drive non relié ou en panne : rien n'est refusé, les fichiers attendent et partent ensuite (Cron, « مزامنة
  الآن », fin de requête) — anciens fichiers compris. Secrets jamais renvoyés au navigateur ni sauvegardés.
- **Base v6** : migration 6 additive (ps_files : display_name, md5, storage, drive_id, drive_error, drive_tries ;
  ps_projects : drive_folder_id). Les fichiers existants restent sur le serveur jusqu'à la liaison de Drive.
- Tests : 787 tests PHP (Google simulé : OAuth, compte de service RS256, envoi, MD5, panne, lecture) ; navigateur step11.

## 1.4.0 — 2026-10-22 · base v5 — Import du fichier Excel, comptes de l'équipe, règles موظف

- **Import du fichier Excel de l'équipe** (الإعدادات ← البيانات ← « استيراد ملف Excel ») : .xlsx (lu en PHP pur)
  ou .csv. Une ligne = un صنف ; les lignes du même « رقم » = un projet. **Numéros P029… conservés** (problème 09).
  Aperçu d'abord (rien n'est écrit) avec « خانات فارغة » (cases vides par colonne) et avertissements ligne par
  ligne ; sauvegarde automatique avant l'import ; un numéro déjà présent est ignoré (réimport sans doublon).
  Corrections : date 2005 → 2025 quand السنة et الشهر concordent ; date vide → 1er du mois ; ligne incomplète
  ignorée ; « حالة المتابعة » du fichier (تم التسليم / في انتظار معلومات) → statut de l'offre du صنف,
  « حالة العرض » du fichier (réponse du client) → حالة المتابعة du projet et مقبول / مرفوض ; الوسيط « موقع
  العطاءات » → مصدر موقع العطاءات ; valeurs de الأهمية (1, 2, 3X, M, L) ajoutées à la liste, jamais perdues ;
  correspondance tolérante des listes (« مفاتيح و برايز » = « مفاتيح وبرايز », ظ = ض). Testé avec le vrai
  fichier : 175 lignes → 157 projets importés.
- **الوسيط : « هامش الربح »** à la place de « الرتبة » (une الرتبة déjà saisie reste affichée). Base v5 :
  migration 5 additive (colonne `margin` sur ps_project_parties).
- **Un موظف ne modifie pas les أصناف ni les montants des autres employés**, même pendant ses 24 h de créateur
  (vérifié par le serveur ; dans la fiche, lignes et montants verrouillés 🔒).
- **« 📄 ورقة حسابات الفريق »** (الإعدادات ← المستخدمون) : crée en une fois les comptes des employés sans compte
  (identifiant latin proposé, rôle, e-mail facultatif) et/ou un nouveau mot de passe pour des comptes existants ;
  feuille à imprimer / PDF ou à télécharger (CSV) avec l'adresse du site et les étapes de première connexion.
  Mots de passe lisibles (sans 0/O/1/l/I), affichés une seule fois.
- **Telegram à la connexion** : tant que le compte n'est pas lié (et Telegram activé), une fenêtre « اربط حسابك
  بـ Telegram » propose « ربط الآن » (une fois par session).
- Base Projets / export : le numéro du fichier (P029…) est affiché dans la colonne « رقم ».
- Tests : 724 tests PHP ; navigateur step10.

## 1.3.5 — 2026-10-22 · base v4 — Cloche : où activer Telegram ; durée des messages vocaux

- Cloche 🔔 : tant que Telegram n'est pas activé, la الإدارة voit « Telegram غير مفعّل بعد » + lien vers
  الإعدادات ← عام ; les autres comptes voient « اطلب من الإدارة تفعيله » (avant : rien, donc pas de « ربط Telegram »).
- Message vocal : durée affichée à côté du lecteur (« 🎙 0:42 ») — un enregistrement Chrome/Android n'indique
  pas sa durée au lecteur, qui affichait « 0:00 / 0:00 » ; le lecteur charge maintenant l'en-tête du fichier
  (erreur détectée tout de suite → lecture de secours).

## 1.3.4 — 2026-10-22 · base v4 — Choix du micro (PC)

- Retour client : le son marche sur téléphone mais pas sur PC (« لم يُلتقط أي صوت »). Sur un PC il y a souvent
  plusieurs entrées audio (webcam, casque, « Stereo Mix », écran HDMI) et le navigateur peut prendre une entrée muette.
- **Liste 🎤 des micros** à côté du bouton d'enregistrement (dès qu'il y en a plusieurs) ; le choix est mémorisé
  sur l'ordinateur ; micro débranché → micro par défaut. Si l'enregistrement est muet : « جرّب ميكروفونًا آخر ».
- Tests navigateur : PC à deux entrées (muette + vraie) → alerte, choix du bon micro, envoi accepté.

## 1.3.3 — 2026-10-21 · base v4 — « 🎙 اختبار الصوت » (diagnostic du son)

- الإعدادات ← عام ← **اختبار الصوت** : en 3 étapes sur le téléphone concerné — (1) son de test pour vérifier le
  haut-parleur / volume multimédia, (2) enregistrement avec niveau du micro, (3) analyse par le serveur (format,
  durée, son présent ou non, conversion pour Telegram) + envoi d'essai sur son propre Telegram. Rapport ✔/✘ à
  envoyer en capture d'écran. Rien n'est enregistré (fichiers temporaires supprimés).
- Tests : 669 tests PHP ; navigateur : rapport complet.

## 1.3.2 — 2026-10-21 · base v4 — Messages vocaux muets détectés (Android + Telegram)

- **Enregistrement muet refusé** : le serveur vérifie le son SANS le décoder (un encodeur Opus qui ne reçoit
  que du silence produit des paquets de ≈ 8 octets, la voix des centaines). Un message muet (micro coupé,
  mauvais micro, autorisation partielle) n'est plus envoyé : message clair « التسجيل بدون صوت… » et la fenêtre
  reste ouverte pour réenregistrer. Une voix faible (−53 dB) reste acceptée.
- **Lecture dans le site (Android)** : si le lecteur du téléphone échoue, le fichier est téléchargé puis lu depuis
  la mémoire ; une erreur du serveur s'affiche au lieu d'un lecteur silencieux.
- Enregistrement : Opus d'abord (vérifiable et converti en vrai vocal Telegram) ; MP4/AAC pour l'iPhone seulement.
- Tests : 663 tests PHP (WebM et Ogg muets refusés, aucune tâche ni envoi) ; navigateur : micro muet, secours de lecture.

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
