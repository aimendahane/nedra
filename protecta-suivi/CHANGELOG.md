# Journal des versions — Protecta Suivi

Format : chaque version indique la version du thème et la version de la base (`PS_DB_VERSION`).
Une mise à jour du thème conserve toujours toutes les données (voir README.md).

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
