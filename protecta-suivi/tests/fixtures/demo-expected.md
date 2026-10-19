# Jeu de démonstration — résultats attendus calculés à la main

Date du jour fixée pour les calculs : **19/10/2026** (T). Données : `tests/fixtures/demo.php`.
Les tests (`tests/test-calculs.php`) comparent CHAQUE valeur ci-dessous au résultat de `inc/calculs.php`.

Abréviations : statuts d'offre — prép = قيد الإعداد, env = مرسل, nenv = لم يُرسل, acc = مقبول, ref = مرفوض, ann = ملغى.
Types de suivi — J = جارية, M = مؤكد, R = مرفوض, S = مسلّم. Poids : VU=4, U=3, N=2, L=1 ; عالية=3, متوسطة=2, ضعيفة=1, vide=1.

## 1. Projets 2026 (24)

| Projet | Réception | Urg. | Taille | حالة المتابعة (type) | أصناف (statut, أجل) | حالة العرض | مرسل ? | Priorité | Retards d'offre |
|---|---|---|---|---|---|---|---|---|---|
| D01 | 10/01 | U | عالية | تم تأكيد الطلبية (M) | RELLI acc | acc | oui | 3+3=6 → P1 | 0 |
| D02 | 25/01 | N | متوسطة | رفض العرض (R) | CABLE ref | ref | oui | 2+2=4 → P2 | 0 |
| D03 | 03/02 | VU | — | في دراسة (J) | UPS prép, 10/02 | prép | non | 4+1=5 → P2 | **1** (10/02 < T) |
| D04 | 14/02 | L | ضعيفة | في دراسة (J) | Rack prép, 01/03 | prép | non | 1+1=2 → P3 | 0 (L exclu) |
| D05 | 02/03 | U | عالية | تم التسليم (S) | إنارة acc | acc | oui | 6 → P1 | 0 |
| D06 | 15/03 | N | متوسطة | في انتظار الدفع (M) | حساسات acc | acc | oui | 4 → P2 | 0 |
| D07 | 05/04 | U | — | في انتظار الرد (J) | CABLE env + Rack nenv | **env** (un مرسل) | oui | 4 → P2 | 0 |
| D08 | 20/04 | N | ضعيفة | في دراسة (J) | UPS nenv | nenv | non (لم تُرسل) | 3 → P3 | 0 |
| D09 | 08/05 | U | متوسطة | في دراسة (J) | Patch cord nenv + Smart home nenv | nenv | non (لم تُرسل) | 5 → P2 | 0 |
| D10 | 20/05 | VU | عالية | تم تأكيد الطلبية (M) | منظومة إطفاء acc | acc | oui | 7 → P1 | 0 |
| D11 | 02/06 | N | — | في دراسة (J) | إنارة ann | **ann** (à part) | — | 3 → P3 | 0 |
| D12 | 15/06 | U | متوسطة | في انتظار معلومات (J) | CABLE prép, 25/10 | prép | non | 5 → P2 | 0 (futur) |
| D13 | 01/07 | N | عالية | رفض العرض (R) | Rack env | env | oui | 5 → P2 | 0 |
| D14 | 10/07 | U | — | تم تأكيد الطلبية (M) | UPS prép, 20/07 | prép | **oui** (suivi M) | 4 → P2 | **1** |
| D15 | 22/07 | VU | ضعيفة | في دراسة (J) | حساسات prép, **19/10** | prép | non | 5 → P2 | 0 (le jour même) |
| D16 | 03/08 | U | عالية | في انتظار التوريد (M) | كابلات acc | acc | oui | 6 → P1 | 0 |
| D17 | 18/08 | N | متوسطة | في دراسة (J) | إنارة prép 30/08 + UPS ann | prép (ann ignoré) | non | 4 → P2 | **1** |
| D18 | 01/09 | L | عالية | في دراسة (J) | RELLI prép, 10/09 | prép | non | 1+3=4 → P2 | 0 (L) |
| D19 | 10/09 | U | متوسطة | في انتظار الرد (J) | Patch cord env + RELLI acc | acc | oui | 5 → P2 | 0 |
| D20 | 25/09 | N | — | في دراسة (J) | Rack prép, 05/10 | prép | non | 3 → P3 | **1** |
| D21 | 01/10 | VU | عالية | في دراسة (J) | UPS prép, 10/10 | prép | non | 7 → P1 | **1** |
| D22 | 05/10 | U | — | في انتظار معلومات (J) | CABLE prép, 30/10 | prép | non | 4 → P2 | 0 |
| D23 | 12/10 | N | ضعيفة | في دراسة (J) | إنارة prép, 18/10 | prép | non | 3 → P3 | **1** |
| D24 | 16/10 | U | متوسطة | تم تسليم جزء (M) | حساسات acc | acc | oui | 5 → P2 | 0 |

Répartition des priorités 2026 : P1 = 5 (D01, D05, D10, D16, D21), P3 = 5 (D04, D08, D11, D20, D23), P2 = 14.

## 2. Indicateurs 2026 (année complète)

| Indicateur | Calcul à la main | Attendu |
|---|---|---|
| عدد المشاريع (hors ملغى) | 24 − 1 (D11) | **23** |
| ملغى (à part) | D11 | **1** |
| المؤكدة (type M ou S, hors ملغى) | D01, D05, D06, D10, D14, D16, D24 | **7** |
| المرفوضة (type R) | D02, D13 | **2** |
| قيد الدراسة (type J, hors ملغى) | 15 J − D11 | **14** |
| العروض المرسلة | statut env/acc/ref : D01, D02, D05, D06, D07, D10, D13, D16, D19, D24 (10) + suivi M/R/S : D14 | **11** |
| لم تُرسل | D08, D09 | **2** |
| نسبة النجاح | 7 ÷ 11 × 100 = 63,636… | **63.6** |
| نسبة الإرسال | 11 ÷ (11 + 2) × 100 = 84,615… | **84.6** |
| مبالغ العروض LYD | D01 10 000 + D06 8 000 + D10 50 000 + D19 7 000 | **75 000** |
| مبالغ العروض USD | D02 5 000 + D16 12 000 | **17 000** |
| مبالغ العروض EUR | D05 20 000 + D24 4 000 | **24 000** |
| مبالغ العروض TND | D07 3 000 | **3 000** |
| مبالغ المؤكدة | LYD : D01 + D06 + D10 = 68 000 ; EUR : D05 + D24 = 24 000 ; USD : D16 = 12 000 | **68 000 / 24 000 / 12 000** |
| العروض المتأخرة (أصناف) | D03, D14, D17, D20, D21, D23 | **6** |
| التسليمات المتأخرة | D06, D16, D24 | **3** |
| Alertes de suivi | voir §5 | **14** |

## 3. Marges (هامش الربح)

| Projet | Offre | Achat | Marge | % |
|---|---|---|---|---|
| D01 | 10 000 LYD | 7 500 LYD | 2 500 | 25.0 |
| D05 | 20 000 EUR | 15 000 EUR | 5 000 | 25.0 |
| D06 | 8 000 LYD | 8 500 LYD | −500 | −500 ÷ 8 000 = −6,25 → **−6.3** |
| D10 | 50 000 LYD | 41 000 LYD | 9 000 | 18.0 |
| D16 | 12 000 USD | 9 000 USD | 3 000 | 25.0 |
| D02 | 5 000 USD | 4 000 **LYD** | non calculée (devises différentes) | — |
| D24 | 4 000 EUR | — | non calculée (pas d'achat) | — |

## 4. Livraisons (أصناف مقبولة)

| Projet | Demandée | Réelle | حالة choisie | En retard ? | تأخير (أيام) | حالة affichée |
|---|---|---|---|---|---|---|
| D01 | 15/02 | 10/02 | تم التسليم | non | max(0, −5) = **0** | تم التسليم |
| D05 | 01/04 | 06/04 | تم التسليم | non (livré) | 06/04 − 01/04 = **5** | تم التسليم |
| D06 | 30/09 | — | في الانتظار | **oui** (30/09 < T) | 19/10 − 30/09 = **19** | **متأخر** |
| D10 | 25/10 | — | جاهز | non (futur) | **0** | جاهز |
| D16 | 01/09 | 11/09 | جاهز | **oui** (réelle > demandée) | **10** | **متأخر** |
| D19 | 15/11 | — | في الانتظار | non | **0** | في الانتظار |
| D24 | 18/10 | — | في الانتظار | **oui** | **1** | **متأخر** |

## 5. Alertes de suivi (projet non مغلق, urgence ≠ L, plus de 7 jours ou prochaine date dépassée)

| Projet | Référence | Prochaine date | Alerte ? |
|---|---|---|---|
| D01 | suivi 15/10 (4 j) | — | non |
| D06 | suivi 10/10 (9 j) | 17/10 dépassée | **oui** |
| D12 | suivi 14/10 (5 j) | 25/10 | non |
| D15 | suivi 11/10 (**8 j** > 7) | 19/10 (aujourd'hui, pas dépassée) | **oui** |
| D19 | suivi 18/10 (1 j) | 18/10 dépassée | **oui** |
| D21 | suivi 12/10 (**7 j**, pas > 7) | — | non |
| D22 | pas de suivi ; modifié 06/10 (13 j) | — | **oui** |
| D23 | pas de suivi ; modifié 13/10 (6 j) | — | non |
| D24 | suivi 19/10 | 26/10 | non |
| D02, D05, D13 | — | — | non (مغلق) |
| D04, D18 | — | — | non (urgence L) |
| D03, D07, D08, D09, D10, D11, D14, D16, D17, D20 | pas de suivi ; modifié le lendemain de la réception (> 7 j) | — | **oui** |

Total 2026 : D03, D06, D07, D08, D09, D10, D11, D14, D15, D16, D17, D19, D20, D22 = **14**.

## 6. Projets 2025 (6) et indicateurs 2025

| Projet | Statuts | حالة العرض | Suivi | Envoyé | Remarque |
|---|---|---|---|---|---|
| E01 | RELLI acc | acc | تم تأكيد الطلبية (M) | oui | 9 000 LYD |
| E02 | CABLE ref | ref | رفض العرض (R) | oui | |
| E03 | UPS nenv | nenv | في دراسة (J) | non (لم تُرسل) | |
| E04 | إنارة acc, livré | acc | تم التسليم (S) | oui | 15 000 USD |
| E05 | Rack env | env | في انتظار الرد (J) | oui | |
| E06 | Rack prép, 20/11/2025 | prép | في دراسة (J) | non | offre en retard |

2025 : projets **6**, ملغى 0, المؤكدة **2**, المرفوضة **1**, قيد الدراسة **3**, المرسلة **4**, لم تُرسل **1**,
نسبة النجاح 2 ÷ 4 = **50.0**, نسبة الإرسال 4 ÷ 5 = **80.0**, montants LYD **9 000** / USD **15 000**,
العروض المتأخرة **1**, التسليمات المتأخرة **0**.

## 7. Page مهامي (employés)

| Employé | عروض متأخرة | متابعات مبرمجة اليوم أو فائتة | تسليمات الأسبوع (≤ 26/10) |
|---|---|---|---|
| محمد حسين | D03, D17, D21 → **3** | D06 (17/10), D15 (19/10) → **2** | D06, D16 → **2** |
| وفاء حامدي | D14, D23 → **2** | D19 (18/10) → **1** | D10 → **1** |
| غفران دريدي | D20 → **1** | **0** | D24 → **1** |
| E06 (2025, وليد جدير) | E06 → 1 pour وليد جدير | | |

## 8. Doublons de sociétés prévus dans les données

« شركة النور » (D01, D10) et « شركه النور » (D21) → **une seule fiche** (normalisation).
« مؤسسة الأفق » et « مؤسسة الأفق للتجارة » → deux fiches (noms différents) : cas de fusion manuelle par la الإدارة.
