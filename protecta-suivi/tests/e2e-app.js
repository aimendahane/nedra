/**
 * Tests navigateur des fonctionnalités (étapes 2 à 5) sur le site de TEST isolé.
 *
 *   cd /srv/wp && sudo -u www-data env PS_TEST=1 php …/tests/e2e-setup.php   # site neuf
 *   BASE_URL=http://localhost:8081 SHOTS=/tmp/captures node tests/e2e-app.js
 *
 * Chaque bloc correspond à une étape et laisse des données utilisées par les blocs suivants.
 */
'use strict';

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost:8081';
const SHOTS = process.env.SHOTS || '.';
const PASS = 'Test-pass-2026';
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null; // ex. ONLY=step2,step3

let failures = 0;
function check(label, ok, detail) {
	console.log((ok ? '  ✔ ' : '  ✘ ') + label + (!ok && detail ? '  [' + detail + ']' : ''));
	if (!ok) failures++;
}

async function newPage(browser, user, mobile) {
	const ctx = await browser.newContext(mobile ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } : { viewport: { width: 1366, height: 900 } });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => { failures++; console.log('  ✘ erreur JavaScript : ' + e.message); });
	await page.goto(BASE + '/');
	await page.fill('input[name=log]', user);
	await page.fill('input[name=pwd]', PASS);
	await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
	await page.waitForSelector('.ps-nav__link');
	return { ctx, page };
}

/** Attend un nouveau message « تم حفظ المشروع PRJ-… » contenant ce numéro. */
async function waitSaved(page, projectNo) {
	await page.waitForFunction((no) => { const a = document.querySelector('.ps-alert--success'); return a && a.textContent.includes(no); }, projectNo, { timeout: 8000 });
}

async function go(page, hash) {
	await page.goto(BASE + '/' + hash);
	await page.waitForLoadState('networkidle');
	await page.waitForTimeout(250);
}

async function pickCompany(page, role, text, choose) {
	const sel = `[data-company="${role}"]`;
	await page.fill(sel, '');
	await page.type(sel, text, { delay: 20 });
	await page.waitForSelector(`${sel} ~ .ps-ac li`, { timeout: 4000 });
	if (choose === 'new') await page.click(`${sel} ~ .ps-ac li.ps-ac__new`);
	else await page.click(`${sel} ~ .ps-ac li >> nth=0`);
	await page.waitForTimeout(250);
}

/** Remplit un champ s'il est modifiable (1.6.0 : coordonnées venant de l'annuaire = lecture seule). */
async function fillIfEditable(page, sel, v) {
	if (await page.isEditable(sel)) await page.fill(sel, v);
}

async function fillProject(page, o) {
	await page.fill('[data-bind="name"]', o.name);
	// 1.10.0 : plus de « مشروع؟ » à la création (décision ensuite dans « المشاريع ») ; gardé pour une fiche existante.
	if (await page.$('input[name="isProject"][value="1"]')) await page.check('input[name="isProject"][value="1"]');
	await page.click('[data-action="suggest-offer"]');
	await page.waitForTimeout(400);
	await page.selectOption('[data-bind="placeId"]', { label: o.place || 'T – Tripoli' });
	await page.selectOption('[data-bind="categoryId"]', { label: 'إنارة' });
	await page.selectOption('[data-bind="urgencyId"]', { label: o.urgency || 'U – Urgent' });
	await page.selectOption('[data-bind="sourceId"]', { label: 'مباشر' });
	if (o.companyNew) await pickCompany(page, 'owner', o.company, 'new');
	else await pickCompany(page, 'owner', o.company, 'first');
	await fillIfEditable(page, '[data-bind="parties.owner.person"]', o.person || 'سالم');
	await fillIfEditable(page, '[data-bind="parties.owner.phone"]', '0912345678');
	await page.fill('[data-bind="parties.executor.companyName"]', 'شركة التنفيذ');
	await page.fill('[data-bind="parties.direct.person"]', 'خالد');
	await page.fill('[data-bind="parties.direct.phone"]', '0921112233');
	await page.selectOption('[data-bind="items.0.itemTypeId"]', { label: 'إنارة' });
	await page.fill('[data-bind="items.0.dueDate"]', o.due || '2026-10-25');
}

async function step2(browser) {
	console.log('Étape 2 — Fiche Projet');
	const { ctx, page } = await newPage(browser, 'mowadhaf');
	await go(page, '#/projet');
	check('formulaire affiché', await page.isVisible('[data-form]'));
	const yellow = await page.$$eval('.ps-field--required', (e) => e.length);
	check('champs obligatoires en jaune', yellow >= 15, String(yellow));
	check('employé connecté proposé comme المتابع', (await page.$eval('[data-bind="followerEmpId"]', (e) => e.selectedOptions[0].textContent)) === 'وفاء حامدي');

	await page.fill('[data-bind="name"]', '');
	await page.click('[data-action="save"]');
	await page.waitForTimeout(400);
	const errs = await page.$$eval('.ps-field--error', (e) => e.length);
	check('enregistrement vide → erreurs affichées sous les champs', errs >= 5, String(errs));
	await page.screenshot({ path: SHOTS + '/s2-01-erreurs.png', fullPage: true });

	// Ajout rapide d'un lieu.
	await page.selectOption('[data-bind="placeId"]', '__add');
	await page.waitForSelector('.ps-modal');
	await page.fill('.ps-modal [data-q="label"]', 'Zliten');
	await page.fill('.ps-modal [data-q="code"]', 'ZL');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(700);
	check('« + إضافة مكان جديد » : Zliten ajouté et choisi', (await page.$eval('[data-bind="placeId"]', (e) => e.selectedOptions[0].textContent)) === 'ZL – Zliten');

	await fillProject(page, { name: 'إنارة مدرسة الأمل', company: 'شركة النور للمقاولات', companyNew: true, place: 'ZL – Zliten' });
	await page.screenshot({ path: SHOTS + '/s2-02-fiche-remplie.png', fullPage: true });
	await page.click('[data-action="save"]');
	await page.waitForSelector('.ps-alert--success', { timeout: 6000 });
	const ok = await page.textContent('.ps-alert--success');
	check('projet enregistré : PRJ-2026-001 affiché', ok.includes('PRJ-2026-001'), ok);
	check('formulaire vidé pour un nouveau projet', (await page.inputValue('[data-bind="name"]')) === '');

	// Doublon : même société (choisie dans l'annuaire), nom proche.
	await fillProject(page, { name: 'انارة مدرسه الامل', company: 'النور' });
	check('société choisie dans l\'annuaire : liée (🔗)', await page.isVisible('legend .ps-linked'));
	await page.click('[data-action="save"]');
	await page.waitForSelector('.ps-modal', { timeout: 6000 });
	check('doublon détecté : avertissement avec le projet PRJ-2026-001', (await page.textContent('.ps-modal')).includes('PRJ-2026-001'));
	await page.screenshot({ path: SHOTS + '/s2-03-doublon.png' });
	await page.click('.ps-modal [data-btn="save"]');
	await waitSaved(page, 'PRJ-2026-002');
	check('« حفظ رغم ذلك » : PRJ-2026-002 enregistré', true);

	// Un 3e projet, autre société.
	await fillProject(page, { name: 'كاميرات مراقبة مستشفى الهدى', company: 'مستشفى الهدى', companyNew: true, urgency: 'VU – Very Urgent' });
	await page.click('[data-action="save"]');
	await waitSaved(page, 'PRJ-2026-003');

	console.log('Étape 2 — Base Projets');
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table--list tbody tr');
	check('3 projets listés', (await page.textContent('[data-results] [role=status]')).includes('3'));
	const headers = await page.$$eval('.ps-table--list thead th', (e) => e.map((x) => x.textContent.trim()));
	// 1.6.1 (demande du client) : رقم المشروع، الاسم، مشروع؟ … puis les autres colonnes du cahier §4.
	// 1.7.0 : tableau court — les 8 colonnes importantes seulement ; « الأعمدة » ajoute les autres (mémorisé).
	check('colonnes : رقم المشروع، الاسم، مشروع؟ d\'abord, 8 colonnes par défaut', headers[0].startsWith('رقم المشروع') && headers[1].startsWith('الاسم') && headers[2].startsWith('مشروع؟') && headers.length === 8 && !headers.some((h) => h.startsWith('رابط ملف المشاريع')), headers.join('|'));
	await page.click('[data-action="columns"]');
	await page.check('.ps-colpick input[value="link"]');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(300);
	const headers2 = await page.$$eval('.ps-table--list thead th', (e) => e.map((x) => x.textContent.trim()));
	check('« الأعمدة » : رابط ملف المشاريع ajoutée', headers2.length === 9 && headers2[8].startsWith('رابط ملف المشاريع'), headers2.join('|'));
	await page.click('[data-action="columns"]');
	await page.click('.ps-modal [data-btn="reset"]');
	await page.waitForTimeout(300);
	check('« الافتراضي » : retour aux 8 colonnes', (await page.$$('.ps-table--list thead th')).length === 8);
	await page.screenshot({ path: SHOTS + '/s2-04-base-projets.png', fullPage: true });
	await page.fill('[data-search]', 'مستشفي');
	await page.waitForTimeout(900);
	check('recherche tolérante (« مستشفي ») : 1 résultat', (await page.$$eval('.ps-table--list tbody tr', (e) => e.length)) === 1);
	check('filtres conservés dans l\'adresse', page.url().includes('q='));
	await page.fill('[data-search]', '');
	await page.waitForTimeout(900);
	await page.click('[data-action="toggle-filters"]');
	await page.selectOption('[data-filter="urgencyId"]', { label: 'VU – Very Urgent' });
	await page.waitForTimeout(800);
	check('filtre الاستعجال = VU : 1 projet', (await page.$$eval('.ps-table--list tbody tr', (e) => e.length)) === 1);
	await page.click('[data-action="reset-filters"]');
	await page.waitForTimeout(800);
	await page.click('.ps-sort[data-sort="name"]');
	await page.waitForTimeout(800);
	check('tri par اسم المشروع', page.url().includes('sort=name'));

	console.log('Étape 2 — Projet existant : onglets');
	await go(page, '#/projet?id=1');
	check('fiche du projet : PRJ-2026-001', (await page.textContent('.ps-page-head h1')).includes('PRJ-2026-001'));
	check('créateur : bandeau « modifiable jusqu\'à »', await page.isVisible('.ps-alert--warning'));
	await page.fill('[data-bind="note"]', 'طلب إنارة لـ 12 قسم');
	await page.click('[data-action="save"]');
	await page.waitForTimeout(800);
	await page.click('[data-tab="followups"]');
	await page.waitForSelector('form[data-followup]');
	await page.fill('form[data-followup] [name=text]', 'اتصال بالحريف لتأكيد الكميات');
	await page.fill('form[data-followup] [name=nextDate]', '2026-10-22');
	await page.click('form[data-followup] [type=submit]');
	await page.waitForSelector('.ps-timeline__item');
	check('سجل المتابعة : entrée ajoutée (auteur, date, prochaine date)', (await page.textContent('.ps-timeline')).includes('2026/10/22'.split('/').reverse().join('/')) || (await page.textContent('.ps-timeline')).includes('22/10/2026'));
	await page.screenshot({ path: SHOTS + '/s2-05-suivi.png', fullPage: true });

	await page.click('[data-tab="files"]');
	await page.waitForSelector('[data-file]', { state: 'attached' });
	// Fichier fourni en mémoire (Playwright gère mal les chemins locaux non latins).
	await page.setInputFiles('[data-file]', { name: 'عرض-السعر.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF') });
	await page.click('.ps-modal [data-btn=ok]'); // 1.5.0 : fenêtre « اسم العرض » avant l'envoi
	await page.waitForSelector('.ps-table tbody tr', { timeout: 6000 });
	check('fichier PDF envoyé et listé', (await page.textContent('.ps-table')).includes('.pdf'));
	const href = await page.getAttribute('.ps-table tbody a', 'href');
	const resp = await page.request.get(href);
	check('fichier téléchargeable par un utilisateur connecté', resp.status() === 200 && (await resp.body()).toString().startsWith('%PDF'));
	const anon = await (await browser.newContext()).request.get(href.split('?')[0]);
	check('fichier inaccessible sans connexion', anon.status() === 401 || anon.status() === 403, String(anon.status()));

	await page.click('[data-tab="log"]');
	await page.waitForSelector('.ps-table tbody tr');
	const log = await page.textContent('.ps-table');
	check('سجل التعديلات : NOTE modifiée + fichier ajouté', log.includes('NOTE') && log.includes('ملف'));
	await page.screenshot({ path: SHOTS + '/s2-06-journal.png', fullPage: true });
	await ctx.close();

	console.log('Étape 2 — Droits et affichage par rôle');
	const other = await newPage(browser, 'mowadhaf2');
	await go(other.page, '#/projet?id=1');
	check('autre موظف : fiche en lecture seule', (await other.page.isDisabled('[data-bind="name"]')) && !(await other.page.$('[data-action="save"]')));
	await other.ctx.close();

	const admin = await newPage(browser, 'idara');
	await go(admin.page, '#/projets');
	await admin.page.waitForSelector('[data-check]');
	await admin.page.check('[data-check="1"]');
	await admin.page.check('[data-check="2"]');
	await admin.page.waitForSelector('.ps-bulk');
	check('الإدارة : sélection multiple + barre « تعيين مصدر الطلب »', (await admin.page.textContent('.ps-bulk')).includes('2'));
	await admin.page.selectOption('[data-bulk-source]', { label: 'إيميل' });
	await admin.page.click('[data-action="bulk-apply"]');
	await admin.page.click('.ps-modal [data-btn="ok"]');
	await admin.page.waitForTimeout(900);
	await admin.page.click('[data-action="toggle-filters"]');
	await admin.page.selectOption('[data-filter="sourceId"]', { label: 'إيميل' });
	await admin.page.waitForTimeout(800);
	check('modification groupée du مصدر : 2 projets « إيميل »', (await admin.page.$$eval('.ps-table--list tbody tr', (e) => e.length)) === 2);
	await go(admin.page, '#/annuaire');
	await admin.page.waitForSelector('.ps-table tbody tr');
	const dir = await admin.page.textContent('.ps-table');
	check('annuaire : « شركة النور للمقاولات » une seule fois, 2 projets', (dir.match(/شركة النور للمقاولات/g) || []).length === 1);
	await admin.page.screenshot({ path: SHOTS + '/s2-07-annuaire.png', fullPage: true });
	await admin.ctx.close();

	const mob = await newPage(browser, 'mowadhaf', true);
	await go(mob.page, '#/projets');
	await mob.page.waitForSelector('.ps-pcard');
	check('téléphone : liste en cartes', await mob.page.isVisible('.ps-pcard'));
	check('téléphone : pas de défilement horizontal', !(await mob.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)));
	await mob.page.screenshot({ path: SHOTS + '/s2-08-mobile-liste.png', fullPage: true });
	await go(mob.page, '#/projet');
	check('téléphone : Fiche Projet sans défilement horizontal', !(await mob.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
	await mob.page.screenshot({ path: SHOTS + '/s2-09-mobile-fiche.png', fullPage: true });
	await mob.ctx.close();
}

async function step3(browser) {
	console.log('Étape 3 — مهامي (محمد حسين, jeu de démonstration)');
	const { ctx, page } = await newPage(browser, 'mowadhaf2');
	await go(page, '#/taches');
	await page.waitForSelector('.ps-task');
	// 1.0.1 : le 1er bloc est « مهام مُسندة » (vide ici) ; les blocs §7 suivent.
	const counts = await page.$$eval('.ps-task__count', (e) => e.map((x) => Number(x.textContent)));
	check('مهامي : 0 مهام مُسندة, 3 عروض متأخرة, 2 متابعات, 2 تسليمات (tableau §7)', counts[0] === 0 && counts[1] === 3 && counts[2] === 2 && counts[4] === 2, counts.join(','));
	await page.waitForTimeout(600);
	const menuCount = await page.$eval('a[href="#/offres"] .ps-nav__count', (e) => e.textContent).catch(() => '');
	check('compteur « لازم قرار » sur le menu : 3', menuCount === '3', menuCount);
	await page.screenshot({ path: SHOTS + '/s3-01-mes-taches.png', fullPage: true });

	console.log('Étape 3 — Suivi Offres');
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table--list tbody tr');
	// Toutes années confondues : 6 retards en 2026 + E06 (2025) = 7.
	check('lignes rouges (en retard) : 7, vertes (مقبول) ≥ 7', (await page.$$('tr.is-late')).length === 7 && (await page.$$('tr.is-done')).length >= 7);
	const decision = (await page.textContent('[data-decision]')).replace(/\s+/g, ' ');
	check('compteur en haut : « لازم قرار: 7 (منها 3 لك) »', decision.includes('7') && decision.includes('3 لك'), decision);
	await page.screenshot({ path: SHOTS + '/s3-02-suivi-offres.png', fullPage: true });
	await page.click('[data-chip="offer"][data-value="late"]');
	await page.waitForTimeout(800);
	check('filtre « لازم قرار فقط » : 7 lignes', (await page.$$('.ps-table--list tbody tr')).length === 7);
	// Mon premier صنف en retard → لم يُرسل (motif obligatoire).
	const mineSel = 'tr.is-late select[data-field="offerStatusId"]';
	await page.selectOption(mineSel, { label: 'لم يُرسل' });
	await page.waitForSelector('.ps-modal');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(300);
	check('« لم يُرسل » : motif obligatoire (fenêtre reste ouverte)', await page.isVisible('.ps-modal'));
	await page.selectOption('.ps-modal [data-q="reason"]', { label: 'السعر غير منافس' });
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(1200);
	check('après « لم يُرسل » : 6 lignes « لازم قرار »', (await page.$$('.ps-table--list tbody tr')).length === 6);
	// Suivant → مرسل : تاريخ إرسال العرض demandé (aujourd'hui proposé).
	await page.selectOption(mineSel, { label: 'مرسل' });
	await page.waitForSelector('.ps-modal [data-q="date"]');
	check('مرسل sans تاريخ الإرسال : date du jour proposée (jj/mm/aaaa)', (await page.inputValue('.ps-modal [data-q="date"]')) === '19/10/2026');
	await page.screenshot({ path: SHOTS + '/s3-03-date-envoi.png' });
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(1200);
	check('après « مرسل » : 5 lignes « لازم قرار »', (await page.$$('.ps-table--list tbody tr')).length === 5);
	const menuAfter = await page.$eval('a[href="#/offres"] .ps-nav__count', (e) => e.textContent).catch(() => '0');
	check('compteur du menu mis à jour : 1', menuAfter === '1', menuAfter);

	console.log('Étape 3 — Suivi Livraison');
	await go(page, '#/livraison');
	await page.waitForSelector('.ps-table--list tbody tr');
	check('livraisons en retard en rouge : 3', (await page.$$('tr.is-late')).length === 3);
	const lateHeader = await page.textContent('[data-results] [role=status]');
	check('« 3 تسليم متأخر »', lateHeader.includes('3 تسليم متأخر'), lateHeader);
	await page.screenshot({ path: SHOTS + '/s3-04-livraison.png', fullPage: true });
	// 1.8.0 : la حالة reste modifiable dans le tableau ; le reste dans le panneau (clic sur la ligne).
	const editable = await page.$$('tr.is-late select[data-field="deliveryStatusId"]');
	check('موظف : seules ses lignes sont modifiables (2)', editable.length === 2, String(editable.length));
	const lateId = await (await editable[0].evaluateHandle((e) => e.closest('tr'))).getAttribute('data-row');
	await page.click(`tr[data-row="${lateId}"] td:nth-child(2)`);
	await page.waitForSelector('.ps-modal--drawer [data-d="deliveryActualDate"]');
	await page.fill('.ps-modal--drawer [data-d="deliveryActualDate"]', '01/10/2026');
	await page.selectOption('.ps-modal--drawer [data-d="deliveryStatusId"]', { label: 'تم التسليم' });
	await page.click('.ps-modal--drawer [data-btn=save]');
	await page.waitForSelector('.ps-modal--drawer', { state: 'detached', timeout: 8000 });
	await page.waitForTimeout(800);
	check('panneau de livraison : date réelle + تم التسليم enregistrées → plus en retard', (await page.$$('tr.is-late')).length === 2);
	await page.click('[data-chip="delivery"][data-value="late"]');
	await page.waitForTimeout(300);
	check('filtre rapide « متأخرة » : 2 lignes', (await page.$$('.ps-table--list tbody tr')).length === 2);
	check('statut « متأخر » jamais proposé dans la liste', !(await page.$$eval('select[data-field="deliveryStatusId"] option', (o) => o.map((x) => x.textContent))).includes('متأخر'));
	await ctx.close();

	console.log('Étape 3 — الإدارة : مهامي de l\'équipe');
	const admin = await newPage(browser, 'idara');
	await go(admin.page, '#/taches');
	await admin.page.waitForSelector('[data-emp]');
	check('الإدارة : tâches de toute l\'équipe, filtre employé', (await admin.page.textContent('h1')).includes('الفريق'));
	await admin.page.selectOption('[data-emp]', { label: 'وفاء حامدي' });
	await admin.page.waitForSelector('.ps-task');
	await admin.page.waitForTimeout(500);
	check('filtre : مهام وفاء حامدي', (await admin.page.textContent('h1')).includes('وفاء حامدي'));
	await admin.page.screenshot({ path: SHOTS + '/s3-05-taches-admin.png', fullPage: true });
	await admin.ctx.close();

	const mob = await newPage(browser, 'mowadhaf2', true);
	await go(mob.page, '#/offres');
	await mob.page.waitForSelector('.ps-icard');
	check('téléphone : Suivi Offres en cartes, sans défilement horizontal', !(await mob.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
	await mob.page.screenshot({ path: SHOTS + '/s3-06-mobile-offres.png', fullPage: true });
	await mob.ctx.close();
}

async function step4(browser) {
	console.log('Étape 4 — Recap (الإدارة, jeu de démonstration)');
	const { ctx, page } = await newPage(browser, 'idara');
	await go(page, '#/recap?year=2026');
	await page.waitForSelector('#ch-month');
	await page.waitForTimeout(500);
	const kpis = await page.$$eval('.ps-kpi', (e) => e.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
	check('عدد المشاريع 23 (أصناف ملغاة: 1) avec comparaison 2025', kpis[0].includes('23') && kpis[0].includes('أصناف ملغاة: 1') && kpis[0].includes('2025'), kpis[0]);
	check('نسبة النجاح 63.6 % ↑ 13.6 نقطة', kpis.some((k) => k.includes('63.6') && k.includes('13.6')));
	check('montants séparés par devise (LYD, USD, EUR, TND)', kpis.some((k) => k.includes('75,000') && k.includes('LYD') && k.includes('TND')));
	const drawn = await page.evaluate(() => Array.from(document.querySelectorAll('.ps-chart canvas')).map((c) => {
		const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0;
		for (let i = 3; i < d.length; i += 4 * 50) if (d[i] > 0) n++; return n > 20;
	}));
	check('5 graphiques dessinés (mois, تصنيف, أصناف, مكان, أسباب…)', drawn.length >= 5 && drawn.every(Boolean), drawn.join(','));
	await page.screenshot({ path: SHOTS + '/s4-01-recap.png', fullPage: true });

	// Clic sur la barre « أكتوبر » (mois 10) → Base Projets filtrée (après la courte animation des graphiques).
	await page.$eval('#ch-month', (c) => c.scrollIntoView({ block: 'center' }));
	await page.waitForTimeout(900);
	const box = await page.$eval('#ch-month', (c) => { const ch = Chart.getChart(c); const m = ch.getDatasetMeta(0).data[9]; const r = c.getBoundingClientRect(); return { x: r.left + m.x, y: r.top + m.y + 4 }; });
	await page.mouse.click(box.x, box.y);
	await page.waitForTimeout(900);
	check('clic sur une barre du mois → Base Projets filtrée (année + mois)', page.url().includes('#/projets') && page.url().includes('month=10') && page.url().includes('year=2026'), page.url());
	const n = (await page.textContent('[data-results] [role=status]')).trim();
	check('أكتوبر 2026 / جارية : 3 projets', n.startsWith('3'), n);

	await go(page, '#/recap?year=2026');
	await page.waitForSelector('tr.ps-drill');
	await page.click('text=السعر غير منافس');
	await page.waitForTimeout(900);
	check('clic sur une raison de non-envoi → Suivi Offres filtrée', page.url().includes('#/offres') && page.url().includes('notSentReasonId'));
	check('2 أصناف « السعر غير منافس »', (await page.$$('.ps-table--list tbody tr')).length === 2);

	await go(page, '#/recap?year=2026&month=10');
	await page.waitForSelector('.ps-kpi');
	check('filtre الشهر : أكتوبر → 4 projets', (await page.textContent('.ps-kpi')).includes('4'));
	await go(page, '#/recap?year=2030');
	await page.waitForTimeout(500);
	check('année sans projet : état vide clair', (await page.textContent('.ps-empty')).includes('لا توجد مشاريع'));

	await go(page, '#/recap?year=2026');
	await page.waitForSelector('#ch-month');
	await page.click('#ps-theme-toggle'); // nuit
	await page.waitForTimeout(500);
	const tickColor = await page.$eval('#ch-month', (c) => Chart.getChart(c).options.scales.x.ticks.color);
	check('mode nuit : graphiques redessinés avec les couleurs du thème', /^#E8EDF6$/i.test(tickColor.trim()) /* --ps-text du mode nuit (variables.css) */, tickColor);
	await page.screenshot({ path: SHOTS + '/s4-02-recap-nuit.png', fullPage: true });
	await page.click('#ps-theme-toggle');
	await page.click('#ps-theme-toggle'); // retour auto
	await page.waitForTimeout(300);

	await page.emulateMedia({ media: 'print' });
	await page.screenshot({ path: SHOTS + '/s4-03-impression.png', fullPage: true });
	check('impression : menu et filtres masqués', !(await page.isVisible('.ps-nav')) && !(await page.isVisible('.ps-recap-filters')));
	await page.pdf({ path: SHOTS + '/recap-2026.pdf', format: 'A4', printBackground: true });
	await page.emulateMedia({ media: 'screen' });
	await ctx.close();

	const mob = await newPage(browser, 'idara', true);
	await go(mob.page, '#/recap?year=2026');
	await mob.page.waitForSelector('#ch-month');
	await mob.page.waitForTimeout(500);
	check('téléphone : Recap sans défilement horizontal', !(await mob.page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)));
	await mob.page.screenshot({ path: SHOTS + '/s4-04-recap-mobile.png', fullPage: true });
	await mob.ctx.close();

	const emp = await newPage(browser, 'mowadhaf2');
	await go(emp.page, '#/recap');
	check('موظف : pas d\'accès au Recap (redirigé)', !(await emp.page.$('#ch-month')) && !(await emp.page.$('a[href="#/recap"]')));
	await emp.ctx.close();
}

async function step5(browser) {
	const fs = require('fs');
	const path = require('path');
	console.log('Étape 5 — الإعدادات : listes, règles, réglages');
	const { ctx, page } = await newPage(browser, 'idara');
	await go(page, '#/parametres?tab=lists');
	await page.waitForSelector('[data-list]');
	await page.click('[data-list="category"]');
	await page.waitForTimeout(500);
	await page.fill('[data-add] [name=label]', 'طاقة شمسية');
	await page.click('[data-add] [type=submit]');
	await page.waitForTimeout(800);
	check('liste التصنيف : valeur ajoutée', (await page.textContent('.ps-table')).length > 0 && (await page.$$eval('[data-f=label]', (e) => e.map((x) => x.value))).includes('طاقة شمسية'));
	const firstLabel = await page.$('tr[data-id] [data-f=label]');
	await firstLabel.fill('كهرباء عامة');
	await firstLabel.dispatchEvent('change');
	await page.waitForTimeout(800);
	check('renommer une valeur (كهرباء → كهرباء عامة)', (await page.$$eval('[data-f=label]', (e) => e.map((x) => x.value))).includes('كهرباء عامة'));
	await page.screenshot({ path: SHOTS + '/s5-01-listes.png', fullPage: true });
	await page.click('[data-list="offer_status"]');
	await page.waitForTimeout(500);
	check('statuts de calcul : 🔒 non supprimables', (await page.$$('tr[data-id] [data-del]')).length === 0 && (await page.textContent('.ps-table')).includes('أساسية'));

	await go(page, '#/parametres?tab=rules');
	await page.waitForSelector('[data-rules]');
	await page.uncheck('[name=req][value=offer_no]');
	await page.click('[data-rules] [type=submit]');
	await page.waitForTimeout(800);
	await go(page, '#/projet');
	await page.waitForSelector('[data-form]');
	const offerReq = await page.$eval('[data-bind="offerNo"]', (e) => e.closest('.ps-field').classList.contains('ps-field--required'));
	check('règle modifiée : رقم العرض n\'est plus obligatoire (plus en jaune)', !offerReq);
	await go(page, '#/parametres?tab=rules');
	await page.waitForSelector('[data-rules]');
	await page.check('[name=req][value=offer_no]');
	await page.click('[data-rules] [type=submit]');
	await page.waitForTimeout(600);

	await go(page, '#/parametres?tab=general');
	await page.waitForSelector('[data-general]');
	const cron = await page.textContent('pre.ps-code');
	check('Cron : commande wget avec jeton de 64 caractères', /ps_cron=[0-9a-f]{64}/.test(cron));
	const bad = await page.request.get(BASE + '/?ps_cron=' + '0'.repeat(64));
	const good = await page.request.get(cron.match(/"(http[^"]+)"/)[1]);
	check('Cron : mauvais jeton → 403, bon jeton → « OK »', bad.status() === 403 && (await good.text()).startsWith('OK'));
	await page.fill('[name=inactivity_minutes]', '2');
	await page.click('[data-general] [type=submit]');
	await page.waitForTimeout(600);
	check('valeur hors limites (2 min) refusée avec message', await page.isVisible('.ps-field--error'));
	await page.fill('[name=inactivity_minutes]', '120');
	await page.click('[data-general] [type=submit]');
	await page.waitForTimeout(600);
	await page.screenshot({ path: SHOTS + '/s5-02-general.png', fullPage: true });

	console.log('Étape 5 — comptes');
	await go(page, '#/parametres?tab=users');
	await page.waitForSelector('[data-new]');
	await page.click('[data-new]');
	await page.waitForSelector('[data-user-form]');
	await page.fill('[data-user-form] [name=login]', 'amira');
	await page.fill('[data-user-form] [name=name]', 'أميرة السعيداني');
	await page.fill('[data-user-form] [name=email]', 'amira@example.com');
	await page.fill('[data-user-form] [name=password]', 'Amira-2026');
	await page.selectOption('[data-user-form] [name=role]', 'ps_manager');
	await page.selectOption('[data-user-form] [name=employeeId]', { label: 'أميرة السعيداني' });
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForTimeout(1000);
	check('compte créé (مسؤول مشروع, employé lié)', (await page.textContent('.ps-table')).includes('amira'));
	await page.screenshot({ path: SHOTS + '/s5-03-comptes.png', fullPage: true });
	{
		const actx = await browser.newContext();
		const ap = await actx.newPage();
		await ap.goto(BASE + '/');
		await ap.fill('input[name=log]', 'amira');
		await ap.fill('input[name=pwd]', 'Amira-2026');
		await Promise.all([ap.waitForLoadState('networkidle'), ap.click('button[type=submit]')]);
		const ok = await ap.isVisible('.ps-header');
		check('le nouveau compte se connecte', ok, ok ? '' : (await ap.textContent('body')).replace(/\s+/g, ' ').slice(0, 200));
		await actx.close();
	}
	await page.click('tr:has-text("amira") [data-toggle]');
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForTimeout(900);
	const dis = await browser.newContext();
	const dp = await dis.newPage();
	await dp.goto(BASE + '/');
	await dp.fill('input[name=log]', 'amira');
	await dp.fill('input[name=pwd]', 'Amira-2026');
	await Promise.all([dp.waitForLoadState('networkidle'), dp.click('button[type=submit]')]);
	check('compte désactivé : connexion refusée avec message', (await dp.textContent('.ps-alert')).includes('معطّل'));
	await dis.close();

	console.log('Étape 5 — annuaire : fusion');
	await go(page, '#/annuaire');
	await page.waitForSelector('[data-action=merge]');
	await page.click('[data-action=merge]');
	await page.waitForSelector('.ps-modal [data-keep]');
	check('doublons probables proposés (مؤسسة الأفق / …للتجارة)', (await page.textContent('.ps-modal')).includes('مؤسسة الأفق للتجارة'));
	page.once('dialog', (d) => d.accept());
	await page.click('.ps-modal [data-pair] [data-keep=a]');
	await page.waitForTimeout(1200);
	await page.click('.ps-modal [data-btn=close]');
	await page.waitForTimeout(600);
	check('fusion faite : une seule fiche « مؤسسة الأفق… »', ((await page.textContent('.ps-table')).match(/مؤسسة الأفق/g) || []).length === 1);

	console.log('Étape 5 — données : import, export, sauvegarde');
	await go(page, '#/parametres?tab=data');
	await page.waitForSelector('[data-import]');
	const sample = fs.readFileSync(path.join(__dirname, 'fixtures', 'prototype-sample.json'));
	await page.setInputFiles('[data-import] [name=file]', { name: 'backup.json', mimeType: 'application/json', buffer: sample });
	await page.click('[data-preview]');
	await page.waitForSelector('[data-import-report] .ps-alert');
	const prev = await page.textContent('[data-import-report]');
	// Le site de démonstration contient déjà PRJ-2026-001…024 : 6 projets du fichier ont ces numéros → ignorés (jamais écrasés).
	check('aperçu : 1 projet à importer, 6 déjà présents ignorés, rien d\'écrit', prev.includes('معاينة') && prev.includes('1 مشروع سيتم استيراده') && prev.includes('6 موجود مسبقًا'), prev.replace(/\s+/g, ' ').slice(0, 160));
	await page.screenshot({ path: SHOTS + '/s5-04-import-apercu.png', fullPage: true });
	await page.click('[data-run]');
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForSelector('[data-import-report] .ps-alert--success', { timeout: 15000 });
	check('import effectué (sauvegarde automatique avant)', (await page.textContent('[data-import-report]')).includes('before-import'));
	const [dl1] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="projects|xlsx"]')]);
	check('export Excel des projets téléchargé', /\.xlsx$/.test(dl1.suggestedFilename()), dl1.suggestedFilename());
	const [dl2] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="offers|csv"]')]);
	check('export CSV des offres téléchargé', /\.csv$/.test(dl2.suggestedFilename()));
	const [dl3] = await Promise.all([page.waitForEvent('download'), page.click('[data-backup]')]);
	const bpath = await dl3.path();
	const bjson = JSON.parse(fs.readFileSync(bpath, 'utf8'));
	check('sauvegarde JSON téléchargée (31 projets)', bjson.format === 'protecta-suivi-backup' && bjson.tables.projects.length === 31, String(bjson.tables && bjson.tables.projects.length));
	await go(page, '#/parametres?tab=data');
	await page.waitForSelector('[data-restore]');
	await page.setInputFiles('[data-restore] [name=file]', { name: 'b.json', mimeType: 'application/json', buffer: fs.readFileSync(bpath) });
	await page.fill('[data-restore] [name=confirm]', 'استرجاع');
	await page.click('[data-restore-run]');
	await page.waitForSelector('[data-restore-report] .ps-alert--success', { timeout: 20000 });
	check('restauration effectuée (31 projets, sauvegarde de l\'état précédent)', (await page.textContent('[data-restore-report]')).includes('31'));
	await page.screenshot({ path: SHOTS + '/s5-05-donnees.png', fullPage: true });
	await go(page, '#/projets');
	check('Base Projets après import + restauration : 31 projets', (await page.textContent('[data-results] [role=status]')).trim().startsWith('31'));
	await ctx.close();
}

/* Version 1.0.1 : tâches libres « مهمة جديدة » + réglages d'envoi des e-mails. */
async function step6(browser) {
	console.log('1.0.1 — مهمة جديدة (مسؤول مشروع → موظف) et e-mail');
	{
		const { ctx, page } = await newPage(browser, 'mowadhaf');
		await go(page, '#/taches');
		check('موظف : pas de bouton « مهمة جديدة »', !(await page.$('[data-new-task]')));
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'masoul');
		await go(page, '#/taches');
		await page.click('[data-new-task]');
		await page.waitForSelector('[data-task-form]');
		await page.click('[data-btn=save]');
		await page.waitForSelector('[data-task-form] .ps-field__error');
		check('fenêtre « مهمة جديدة » : champs obligatoires signalés', (await page.$$('[data-task-form] .ps-field__error')).length >= 2);
		await page.selectOption('[data-task-form] [name=employeeId]', { label: 'وفاء حامدي' });
		await page.fill('[data-task-form] [name=title]', 'اتصل بشركة النور لتأكيد الطلبية');
		await page.fill('[data-task-form] [name=note]', 'اسأل عن موعد الدفع');
		await page.screenshot({ path: SHOTS + '/s6-01-nouvelle-tache.png' });
		await page.click('[data-btn=save]');
		await page.waitForSelector('.ps-modal', { state: 'detached' });
		await page.waitForTimeout(500);
		check('مسؤول مشروع : tâche envoyée → visible dans « مهام أرسلتها »', (await page.textContent('.ps-main')).includes('مهام أرسلتها') && (await page.textContent('.ps-main')).includes('اتصل بشركة النور'));
		// Depuis Fiche Projet : la tâche est liée au projet.
		await go(page, '#/projet?id=1');
		await page.click('[data-action=new-task]');
		await page.waitForSelector('[data-task-form]');
		check('Fiche Projet : bouton « 📌 مهمة » avec le projet affiché', (await page.textContent('.ps-modal')).includes('المشروع'));
		await page.selectOption('[data-task-form] [name=employeeId]', { label: 'وفاء حامدي' });
		await page.fill('[data-task-form] [name=title]', 'أرسل الكتالوج');
		await page.click('[data-btn=save]');
		await page.waitForSelector('.ps-modal', { state: 'detached' });
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'mowadhaf');
		await go(page, '#/taches');
		const txt = await page.textContent('.ps-main');
		check('موظف : bloc « مهام مُسندة » avec les 2 tâches', txt.includes('مهام مُسندة') && txt.includes('اتصل بشركة النور') && txt.includes('أرسل الكتالوج'));
		check('موظف : tâche liée au projet → lien vers la fiche', !!(await page.$('[data-task] a[href^="#/projet?id=1"]')));
		await page.screenshot({ path: SHOTS + '/s6-02-mes-taches-employe.png', fullPage: true });
		await page.click('[data-task-done]');
		await page.waitForTimeout(800);
		check('« ✔ تمت » : la tâche disparaît de la liste', (await page.$$('[data-task-done]')).length === 1);
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'masoul');
		await go(page, '#/taches');
		check('مسؤول مشروع : voit que la tâche est « تمت »', !!(await page.$('.ps-task__item.is-done')));
		await page.screenshot({ path: SHOTS + '/s6-03-taches-envoyees.png', fullPage: true });
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'idara');
		await page.click('#ps-bell');
		await page.waitForTimeout(600);
		check('الإدارة, Telegram non configuré : la cloche indique où l\'activer', !!(await page.$('[data-bell-tg] a[href="#/parametres?tab=general"]')));
		await page.click('#ps-bell');
		await go(page, '#/parametres?tab=general');
		check('الإعدادات ← عام : réglages SMTP présents', !!(await page.$('[name=smtp_host]')) && !!(await page.$('[name=smtp_pass][type=password]')));
		await page.click('[data-test-mail]');
		await page.waitForSelector('[data-test-result] .ps-alert', { timeout: 30000 });
		const res = (await page.textContent('[data-test-result]')).trim();
		check('بريد تجريبي : résultat clair affiché (succès ou vraie raison de l\'échec)', res.length > 10, res);
		await page.screenshot({ path: SHOTS + '/s6-04-reglages-mail.png', fullPage: true });
		await ctx.close();
	}
}

/* Version 1.0.2 : notifications 🔔. */
async function step7(browser) {
	console.log('1.0.2 — notifications 🔔 (الإدارة crée un projet → المسؤول et المتابع prévenus)');
	{
		const { ctx, page } = await newPage(browser, 'idara');
		const st = await page.evaluate(async () => {
			const L = (await PS.api.get('lists')).lists;
			const id = (k, v) => (L[k].find((x) => x.label === v || x.code === v) || {}).id;
			const body = {
				offerNo: 'OFR-BELL-1', name: 'مشروع الجرس', receivedDate: '2026-10-10',
				responsibleEmpId: id('employee', 'وليد جدير'), followerEmpId: id('employee', 'وفاء حامدي'),
				placeId: id('place', 'T'), categoryId: id('category', 'إنارة'), urgencyId: id('urgency', 'U'), sourceId: id('source', 'direct'),
				parties: { owner: { companyName: 'شركة الجرس', person: 'سالم', phone: '0912345678' }, executor: { companyName: 'شركة التنفيذ' }, direct: { person: 'خالد', phone: '0921112233' } },
				items: [{ itemTypeId: id('item_type', 'UPS'), quantity: '1', employeeId: id('employee', 'محمد حسين'), dueDate: '2026-10-25' }],
			};
			try { const r = await PS.api.post('projects', body); return r.id; } catch (e) { return 'ERR ' + e.message; }
		});
		check('الإدارة : projet créé (API de la fiche)', Number.isInteger(st), String(st));
		await page.reload();
		await page.waitForSelector('#ps-bell');
		await page.waitForTimeout(800);
		check('الإدارة : pas de notification pour sa propre action', await page.$eval('#ps-bell-count', (e) => e.hidden));
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'masoul');
		await page.waitForFunction(() => !document.getElementById('ps-bell-count').hidden, null, { timeout: 8000 }).catch(() => {});
		const n = await page.$eval('#ps-bell-count', (e) => (e.hidden ? 0 : Number(e.textContent)));
		check('مسؤول المشروع : 🔔 affiche 1 nouvelle notification', n === 1, String(n));
		await page.click('#ps-bell');
		await page.waitForSelector('.ps-bell__item');
		const txt = await page.textContent('#ps-bell-panel');
		check('liste : « مشروع جديد » + nom du projet + auteur', txt.includes('مشروع الجرس') && txt.includes('مشروع جديد') && txt.includes('سارة الإدارة'));
		await page.screenshot({ path: SHOTS + '/s7-01-cloche.png' });
		await page.click('.ps-bell__item');
		await page.waitForTimeout(900);
		check('clic → la fiche du projet s\'ouvre', page.url().includes('#/projet?id=') && (await page.textContent('.ps-main h1')).includes('مشروع الجرس'));
		check('notification lue → compteur à 0', await page.$eval('#ps-bell-count', (e) => e.hidden));
		await page.click('#ps-bell');
		await page.waitForSelector('[data-bell-mail]');
		await page.check('[data-bell-mail]');
		await page.waitForTimeout(600);
		await page.reload();
		await page.waitForSelector('#ps-bell');
		check('« بريد فوري عند كل تغيير » mémorisé', await page.evaluate(() => PS.config.user.prefs.instantEmail === true));
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'mowadhaf', true);
		await page.waitForTimeout(1200);
		await page.click('#ps-bell');
		await page.waitForSelector('.ps-bell__item');
		check('mobile (المتابع) : la liste s\'affiche sans défilement horizontal', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
		await page.screenshot({ path: SHOTS + '/s7-02-cloche-mobile.png' });
		await ctx.close();
	}
}

/* Version 1.2.0 : message vocal (microphone simulé par Chromium) + réglages Telegram. */
async function step8() {
	console.log('1.2.0 — message vocal (مسؤول → موظف) et Telegram');
	const browser = await chromium.launch({ args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required'] });
	{
		const { ctx, page } = await newPage(browser, 'masoul');
		await go(page, '#/taches');
		await page.click('[data-new-task]');
		await page.waitForSelector('[data-voice-rec]');
		check('fenêtre « مهمة جديدة » : bouton « 🎙 تسجيل رسالة صوتية »', (await page.textContent('[data-voice-rec]')).includes('تسجيل'));
		await page.selectOption('[data-task-form] [name=employeeId]', { label: 'وفاء حامدي' });
		// Micro simulé par un son continu (le micro factice de Chromium ne bipe que par moments).
		await page.evaluate(() => {
			navigator.mediaDevices.getUserMedia = async function () {
				const ac = new AudioContext();
				const osc = ac.createOscillator();
				const dest = ac.createMediaStreamDestination();
				osc.frequency.value = 440;
				osc.connect(dest);
				osc.start();
				return dest.stream;
			};
		});
		await page.click('[data-voice-rec]');
		await page.waitForTimeout(2600);
		check('enregistrement en cours : chronomètre affiché', (await page.textContent('[data-voice-time]')).includes('0:0'));
		check('niveau du micro affiché et en mouvement (le son arrive)', await page.waitForFunction(() => { const e = document.querySelector('[data-voice-level] i'); return e && !e.closest('[hidden]') && parseFloat(e.style.width) > 0; }, null, { timeout: 4000 }).then(() => true, () => false));
		await page.screenshot({ path: SHOTS + '/s8-01-enregistrement.png' });
		await page.click('[data-voice-rec]');
		await page.waitForSelector('[data-voice][data-state="done"]');
		check('après « إيقاف » : lecteur de vérification + « حذف »', await page.isVisible('[data-voice-audio]') && await page.isVisible('[data-voice-del]'));
		await page.click('[data-btn=save]');
		await page.waitForSelector('.ps-modal', { state: 'detached' });
		await page.waitForTimeout(600);
		check('tâche vocale envoyée (titre par défaut « 🎙 رسالة صوتية »)', (await page.textContent('.ps-main')).includes('رسالة صوتية'));
		await ctx.close();
	}
	{
		// Micro muet : alerte pendant l'enregistrement, puis refus clair au moment d'envoyer (rien n'est envoyé).
		const { ctx, page } = await newPage(browser, 'masoul');
		await go(page, '#/taches');
		await page.click('[data-new-task]');
		await page.waitForSelector('[data-voice-rec]');
		await page.selectOption('[data-task-form] [name=employeeId]', { label: 'وفاء حامدي' });
		// PC avec deux entrées : « Stereo Mix » (muette, par défaut) et le vrai micro.
		await page.evaluate(() => {
			window.__micAsked = [];
			navigator.mediaDevices.enumerateDevices = async () => [
				{ kind: 'audioinput', deviceId: 'mix', label: 'Stereo Mix (Realtek)' },
				{ kind: 'audioinput', deviceId: 'usb', label: 'Microphone USB' },
			];
			navigator.mediaDevices.getUserMedia = async function (c) {
				const id = c.audio && c.audio.deviceId ? c.audio.deviceId.exact : 'mix';
				window.__micAsked.push(id);
				const ac = new AudioContext(); const d = ac.createMediaStreamDestination(); const g = ac.createGain(); g.gain.value = id === 'usb' ? 0.5 : 0; const o = ac.createOscillator(); o.connect(g); g.connect(d); o.start(); return d.stream;
			};
		});
		await page.click('[data-voice-rec]');
		await page.waitForTimeout(2600);
		await page.click('[data-voice-rec]');
		await page.waitForSelector('[data-voice][data-state="done"]');
		check('micro muet : alerte « لم يُلتقط أي صوت » + « جرّب ميكروفونًا آخر »', await page.waitForFunction(() => /لم يُلتقط أي صوت/.test(document.body.textContent) && /ميكروفونًا آخر/.test(document.body.textContent), null, { timeout: 3000 }).then(() => true, () => false));
		const mics = await page.$$eval('[data-voice-mic] option', (o) => o.map((x) => x.textContent.trim()));
		check('liste des micros affichée (plusieurs entrées sur le PC)', mics.length === 2 && mics[1].includes('Microphone USB') && await page.isVisible('[data-voice-mic]'), mics.join(' | '));
		await page.click('[data-btn=save]');
		check('micro muet : envoi refusé « التسجيل بدون صوت » (la fenêtre reste ouverte)', await page.waitForFunction(() => /التسجيل بدون صوت/.test(document.body.textContent), null, { timeout: 5000 }).then(() => true, () => false) && await page.isVisible('.ps-modal'));
		await page.selectOption('[data-voice-mic]', 'usb');
		await page.click('[data-voice-rec]');
		await page.waitForTimeout(2600);
		await page.click('[data-voice-rec]');
		await page.waitForSelector('[data-voice][data-state="done"]');
		check('autre micro choisi → utilisé pour l\'enregistrement et mémorisé', await page.evaluate(() => window.__micAsked[window.__micAsked.length - 1] === 'usb' && localStorage.getItem('ps_mic') === 'usb'));
		await page.click('[data-btn=save]');
		await page.waitForSelector('.ps-modal', { state: 'detached', timeout: 8000 }).catch(() => {});
		check('avec le bon micro : message vocal accepté et envoyé', !(await page.isVisible('.ps-modal')));
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'mowadhaf');
		await go(page, '#/taches');
		await page.waitForSelector('.ps-voice__player');
		const res = await page.evaluate(async () => { const a = document.querySelector('.ps-voice__player'); const r = await fetch(a.src, { credentials: 'same-origin' }); const b = await r.arrayBuffer(); return { status: r.status, type: r.headers.get('content-type'), size: b.byteLength }; });
		check('موظف : lecteur audio dans « مهام مُسندة », fichier servi après contrôle des droits', res.status === 200 && /^audio\//.test(res.type) && res.size > 1000, JSON.stringify(res));
		const ranged = await page.evaluate(async () => { const a = document.querySelector('.ps-voice__player'); const r = await fetch(a.src, { headers: { Range: 'bytes=0-99' }, credentials: 'same-origin' }); return { status: r.status, len: (await r.arrayBuffer()).byteLength }; });
		check('lecture par morceaux (Range) pour avancer dans l\'audio', ranged.status === 206 && ranged.len === 100, JSON.stringify(ranged));
		const sound = await page.evaluate(async () => {
			const a = document.querySelector('.ps-voice__player');
			const buf = await (await fetch(a.src, { credentials: 'same-origin' })).arrayBuffer();
			const audio = await new (window.AudioContext || window.webkitAudioContext)().decodeAudioData(buf);
			const d = audio.getChannelData(0); let s = 0; for (let i = 0; i < d.length; i++) s += d[i] * d[i];
			return { seconds: audio.duration, rms: Math.sqrt(s / d.length) };
		}).catch((e) => ({ error: String(e) }));
		check('message vocal servi : décodé par le navigateur, avec du son (pas muet)', sound.seconds > 1.5 && sound.rms > 0.01, JSON.stringify(sound));
		// Secours de lecture (téléphones dont le lecteur échoue) : téléchargement puis lecture depuis la mémoire.
		await page.$eval('.ps-voice__player', (a) => a.dispatchEvent(new Event('error')));
		check('lecteur en échec → fichier téléchargé et relu (secours Android)', await page.waitForFunction(() => document.querySelector('.ps-voice__player').src.startsWith('blob:'), null, { timeout: 5000 }).then(() => true, () => false));
		await page.evaluate(() => { const a = document.createElement('audio'); a.className = 'ps-voice__player'; a.src = PS.config.restUrl + 'free-tasks/999999/voice?_wpnonce=' + PS.config.nonce; document.querySelector('.ps-main').appendChild(a); a.dispatchEvent(new Event('error')); });
		check('message introuvable → erreur du serveur affichée', await page.waitForFunction(() => /غير موجودة/.test(document.body.textContent), null, { timeout: 5000 }).then(() => true, () => false));
		await page.screenshot({ path: SHOTS + '/s8-02-tache-vocale.png', fullPage: true });
		await page.click('#ps-bell');
		await page.waitForTimeout(600);
		check('Telegram non configuré : pas de bouton « ربط » mais une explication (موظف → demander à la الإدارة)', !(await page.isVisible('[data-tg-link]')) && /اطلب من الإدارة/.test(await page.textContent('[data-bell-tg]')));
		check('durée du message vocal affichée à côté du lecteur', /🎙 0:0\d/.test(await page.textContent('.ps-voice__dur')));
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'idara');
		await go(page, '#/parametres?tab=general');
		// « 🎙 اختبار الصوت » : nغمة, micro (son continu simulé), analyse serveur.
		await page.evaluate(() => {
			navigator.mediaDevices.getUserMedia = async function () { const ac = new AudioContext(); const o = ac.createOscillator(); const d = ac.createMediaStreamDestination(); o.connect(d); o.start(); return d.stream; };
		});
		await page.click('[data-tone]');
		await page.waitForSelector('[data-tone-ask]:not([hidden])', { timeout: 4000 }).catch(() => {});
		await page.click('[data-heard="1"]').catch(() => {});
		await page.click('[data-sound-test] [data-voice-rec]');
		await page.waitForTimeout(2500);
		await page.click('[data-sound-test] [data-voice-rec]');
		await page.waitForSelector('[data-sound-test] [data-voice][data-state="done"]');
		await page.click('[data-sound-run]');
		await page.waitForSelector('.ps-sound-report', { timeout: 8000 }).catch(() => {});
		const rep = await page.$$eval('.ps-sound-report tr', (t) => t.map((r) => r.textContent.replace(/\s+/g, ' ').trim()));
		check('اختبار الصوت : rapport complet (haut-parleur ✔, micro ✔, son présent ✔)', rep.length === 7 && rep[0].includes('✔') && rep[1].includes('✔') && rep[4].includes('✔'), rep.join(' | ') || (await page.textContent('[data-sound-test]')).replace(/\s+/g, ' '));
		await page.screenshot({ path: SHOTS + '/s8-03-test-son.png', fullPage: false, clip: await page.$eval('[data-sound-test]', (e) => { e.scrollIntoView(); const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; }) });
		check('الإعدادات ← عام : section Telegram (token + activation + essai)', !!(await page.$('[name=telegram_token][type=password]')) && !!(await page.$('[name=telegram_enabled]')) && !!(await page.$('[data-tg-test]')));
		await ctx.close();
	}
	await browser.close();
}

/* Version 1.3.0 : langues العربية / English / Français (page de connexion, menu de l'en-tête, sens d'écriture). */
async function step9(browser) {
	console.log('1.3.0 — langues : English et Français');
	const AR = /[؀-ۿ]/;
	{
		const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
		const page = await ctx.newPage();
		page.on('pageerror', (e) => { failures++; console.log('  ✘ erreur JavaScript : ' + e.message); });
		await page.goto(BASE + '/');
		check('connexion : choix العربية / English / Français', (await page.textContent('.ps-langs')).includes('English') && (await page.textContent('.ps-langs')).includes('Français'));
		await Promise.all([page.waitForLoadState('networkidle'), page.click('.ps-langs a[lang=en]')]);
		check('connexion en English : <html dir="ltr" lang="en">', await page.evaluate(() => document.documentElement.dir === 'ltr' && document.documentElement.lang === 'en'));
		check('bouton « Log in »', (await page.textContent('button[type=submit]')).includes('Log in'));
		await page.screenshot({ path: SHOTS + '/s9-01-connexion-en.png' });
		await page.fill('input[name=log]', 'idara');
		await page.fill('input[name=pwd]', PASS);
		await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
		await page.waitForSelector('.ps-nav__link');
		const nav = await page.$$eval('.ps-nav__link', (l) => l.map((a) => a.textContent.trim()));
		check('après connexion : application en anglais (menu)', nav.some((t) => t.includes('My tasks')) && nav.some((t) => t.includes('Settings')), nav.join(' | '));
		check('menu latéral sans texte arabe (hors noms saisis)', !nav.some((t) => AR.test(t)), nav.join(' | '));
		for (const [hash, shot] of [['#/projets', 's9-02-projets-en'], ['#/offres', 's9-03-offres-en'], ['#/recap', 's9-04-recap-en'], ['#/taches', 's9-05-taches-en'], ['#/parametres', 's9-06-parametres-en']]) {
			await go(page, hash);
			await page.waitForTimeout(hash === '#/recap' ? 1500 : 400);
			const h1 = (await page.textContent('.ps-main h1').catch(() => '')) || '';
			check(hash + ' : titre traduit « ' + h1.trim() + ' »', h1.trim() !== '' && !AR.test(h1), h1);
			check(hash + ' : pas de défilement horizontal', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
			await page.screenshot({ path: SHOTS + '/' + shot + '.png', fullPage: hash === '#/recap' });
		}
		await go(page, '#/projet');
		await page.waitForSelector('[data-bind="name"]');
		const labels = await page.$$eval('.ps-main label', (l) => l.map((x) => { const c = x.cloneNode(true); c.querySelectorAll('select, input, textarea, .ps-ac').forEach((e) => e.remove()); return c.textContent.trim(); }).filter(Boolean));
		const arabic = labels.filter((t) => AR.test(t));
		check('fiche « New project » : libellés des champs traduits', arabic.length === 0, arabic.slice(0, 5).join(' | '));
		const opts = await page.$$eval('[data-bind="sourceId"] option', (o) => o.map((x) => x.textContent.trim()));
		check('listes par défaut traduites (مباشر → Direct)', opts.includes('Direct'), opts.join(' | '));
		await page.screenshot({ path: SHOTS + '/s9-07-fiche-en.png', fullPage: true });

		// Passage en français depuis le menu de l'en-tête.
		await page.click('#ps-lang');
		await Promise.all([page.waitForNavigation(), page.click('#ps-lang-panel [data-lang=fr]')]);
		await page.waitForSelector('.ps-nav__link');
		check('menu de l\'en-tête → Français : <html lang="fr" dir="ltr">', await page.evaluate(() => document.documentElement.lang === 'fr' && document.documentElement.dir === 'ltr'));
		const navFr = await page.$$eval('.ps-nav__link', (l) => l.map((a) => a.textContent.trim()));
		check('menu en français (Mes tâches, Paramètres)', navFr.some((t) => t.includes('Mes tâches')) && navFr.some((t) => t.includes('Paramètres')), navFr.join(' | '));
		await go(page, '#/recap');
		await page.waitForTimeout(1500);
		await page.screenshot({ path: SHOTS + '/s9-08-recap-fr.png', fullPage: true });
		await page.click('#ps-lang');
		await Promise.all([page.waitForNavigation(), page.click('#ps-lang-panel [data-lang=ar]')]);
		await page.waitForSelector('.ps-nav__link');
		check('retour à العربية : dir="rtl"', await page.evaluate(() => document.documentElement.dir === 'rtl' && document.documentElement.lang === 'ar'));
		await ctx.close();
	}
	{
		// La langue est celle du COMPTE : un autre compte reste en arabe.
		const { ctx, page } = await newPage(browser, 'mowadhaf', true);
		check('autre compte : toujours en العربية', await page.evaluate(() => document.documentElement.dir === 'rtl'));
		await page.evaluate(() => PS.api.post('me/prefs', { lang: 'en' }));
		await page.reload();
		await page.waitForSelector('.ps-nav__link');
		await page.click('#ps-menu-toggle').catch(() => {});
		await page.waitForTimeout(500);
		check('mobile en English : pas de défilement horizontal', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
		await page.screenshot({ path: SHOTS + '/s9-09-mobile-en.png' });
		await ctx.close();
	}
}

/* Version 1.4.0 : import Excel, numéros P…, هامش الربح du الوسيط, ورقة حسابات الفريق, invitation Telegram. */
async function step10(browser) {
	console.log('1.4.0 — import Excel, numéros P…, هامش الربح, ورقة الحسابات, Telegram à la connexion');
	{
		const { ctx, page } = await newPage(browser, 'idara');
		// Invitation Telegram à la connexion (Telegram activé, compte non lié).
		check('connexion : fenêtre « اربط حسابك بـ Telegram » (compte non lié)', await page.waitForSelector('.ps-modal', { timeout: 5000 }).then(async () => /Telegram/.test(await page.textContent('.ps-modal h2')), () => false));
		await page.screenshot({ path: SHOTS + '/s10-01-telegram-connexion.png' });
		await page.click('.ps-modal [data-btn=later]');
		await page.reload();
		await page.waitForSelector('.ps-nav__link');
		await page.waitForTimeout(1200);
		check('« لاحقًا » : plus de fenêtre pendant cette session', !(await page.$('.ps-modal')));

		// Import Excel par l'écran.
		await go(page, '#/parametres?tab=data');
		await page.setInputFiles('[data-xl] input[type=file]', __dirname + '/fixtures/excel-equipe.xlsx');
		await page.click('[data-xl-preview]');
		await page.waitForSelector('[data-xl-report] .ps-alert', { timeout: 15000 });
		const prev = (await page.textContent('[data-xl-report]')).replace(/\s+/g, ' ');
		check('aperçu Excel : 4 projets, cases vides comptées, avertissements', /4 مشروع سيتم استيراده/.test(prev) && /خانات فارغة/.test(prev) && /صُحّح/.test(prev), prev.slice(0, 200));
		await page.screenshot({ path: SHOTS + '/s10-02-apercu-excel.png', fullPage: true });
		await page.click('[data-xl-run]');
		await page.click('.ps-modal [data-btn=ok]');
		await page.waitForFunction(() => /تم الاستيراد/.test(document.querySelector('[data-xl-report]').textContent), null, { timeout: 15000 });
		await go(page, '#/projets');
		await page.waitForTimeout(800);
		const nos = await page.$$eval('.ps-table tbody tr', (t) => t.map((r) => r.textContent));
		check('Base Projets : numéros du fichier affichés (P901…)', nos.some((t) => t.includes('P901')) && nos.some((t) => t.includes('P902')), nos.slice(0, 3).join(' | ').slice(0, 200));

		// هامش الربح du الوسيط dans la fiche.
		await go(page, '#/projet');
		await page.waitForSelector('[data-bind="parties.broker.margin"]');
		check('fiche : الوسيط → champ « هامش الربح » (à la place de « الرتبة »)', !(await page.$('[data-bind="parties.broker.rank"]')) && !!(await page.$('[data-bind="parties.owner.rank"]')));

		// ورقة حسابات الفريق.
		await go(page, '#/parametres?tab=users');
		await page.click('[data-sheet-new]');
		await page.waitForSelector('tr[data-emp]');
		const logins = await page.$$eval('tr[data-emp] [name=login]', (i) => i.map((x) => x.value));
		check('ورقة الحسابات : employés sans compte proposés avec un identifiant latin', logins.length > 0 && logins.every((l) => /^[a-z0-9.]+$/.test(l)), logins.join(', '));
		await page.$$eval('tr[data-emp] [data-pick]', (c) => c.forEach((x, i) => { x.checked = i < 2; }));
		await page.click('.ps-modal [data-btn=ok]');
		await page.waitForSelector('[data-sheet]', { timeout: 8000 });
		const sheet = await page.$$eval('[data-sheet] tbody tr', (t) => t.map((r) => r.textContent));
		check('ورقة الحسابات : 2 comptes créés, mots de passe affichés une seule fois', sheet.length === 2 && sheet.every((t) => /[A-Za-z2-9]{10}/.test(t)), sheet.join(' | '));
		await page.screenshot({ path: SHOTS + '/s10-03-feuille-comptes.png' });
		const [popup] = await Promise.all([page.waitForEvent('popup'), page.evaluate(() => { window.print = function () {}; }).then(() => page.click('.ps-modal [data-btn=print]'))]);
		await popup.waitForLoadState();
		check('feuille imprimable : adresse du site + accès + étapes (Telegram)', /ProtectaTestBot/.test(await popup.content()) && /localhost:8081/.test(await popup.content()));
		await popup.screenshot({ path: SHOTS + '/s10-04-feuille-imprimable.png', fullPage: true });
		await popup.close();
		await ctx.close();
	}
}

/* Version 1.5.0 : nom du projet d'abord, recherche par numéro, fichiers (types, nom d'affichage, téléchargement), Drive. */
async function step11(browser) {
	console.log('1.5.0 — nom d\'abord, recherche par numéro, fichiers joints, carte Google Drive');
	const { ctx, page } = await newPage(browser, 'idara');
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table tbody tr');
	const first = await page.$eval('.ps-table tbody tr', (tr) => ({ name: tr.querySelector('.ps-ptitle__name').textContent, no: tr.querySelector('.ps-pno').textContent, bold: getComputedStyle(tr.querySelector('.ps-ptitle__name')).fontWeight }));
	check('Base Projets : رقم المشروع (1re colonne) puis le nom en gras (1.6.1)', first.name.length > 2 && /PRJ-\d{4}-\d+|P\d+/.test(first.no) && Number(first.bold) >= 700, JSON.stringify(first));
	await page.fill('[data-search]', first.no.trim());
	await page.waitForTimeout(900);
	const found = await page.$$eval('.ps-table tbody .ps-pno', (n) => n.map((x) => x.textContent.trim()));
	check('recherche par numéro : le projet est trouvé', found.length >= 1 && found.includes(first.no.trim()), found.join(','));
	await page.click('.ps-table tbody .ps-ptitle');
	await page.waitForSelector('.ps-ptitle-h1');
	check('fiche : titre = nom puis numéro', (await page.textContent('.ps-ptitle-h1')).startsWith(first.name.trim()));
	const pid = page.url().match(/id=(\d+)/)[1];
	await go(page, '#/projet?id=' + pid + '&tab=files');
	await page.waitForSelector('[data-file]', { state: 'attached' });
	const fs = require('fs');
	const dir = require('os').tmpdir();
	fs.writeFileSync(dir + '/hack.php', '<?php echo 1;');
	await page.setInputFiles('[data-file]', dir + '/hack.php');
	check('fichier .php : refusé avec un message en arabe', await page.waitForFunction(() => /غير مسموح/.test(document.body.textContent), null, { timeout: 4000 }).then(() => true, () => false));
	fs.writeFileSync(dir + '/offre-fournisseur.pdf', '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF');
	await page.setInputFiles('[data-file]', dir + '/offre-fournisseur.pdf');
	await page.waitForSelector('.ps-modal [data-dn="0"]');
	await page.fill('.ps-modal [data-dn="0"]', 'عرض المورد النهائي');
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForFunction(() => /عرض المورد النهائي/.test(document.querySelector('.ps-main').textContent), null, { timeout: 8000 });
	const row = (await page.textContent('.ps-table tbody tr')).replace(/\s+/g, ' ');
	check('fichier ajouté : nom d\'affichage, nom d\'origine, auteur, date, emplacement « الخادم »', /عرض المورد النهائي/.test(row) && /offre-fournisseur\.pdf/.test(row) && /الخادم/.test(row), row.slice(0, 200));
	const dl = await page.evaluate(async () => { const a = document.querySelector('.ps-table tbody a[href*="/files/"]'); const r = await fetch(a.href, { credentials: 'same-origin' }); return { status: r.status, type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), start: (await r.text()).slice(0, 8) }; });
	check('téléchargement par le site (compte connecté) : contenu et nom d\'affichage', dl.status === 200 && dl.start === '%PDF-1.4' && /%D8%B9%D8%B1%D8%B6/.test(dl.disp || ''), JSON.stringify(dl));
	const href = await page.$eval('.ps-table tbody a[href*="/files/"]', (a) => a.href);
	await page.screenshot({ path: SHOTS + '/s11-01-fichiers.png', fullPage: true });
	await go(page, '#/parametres?tab=general');
	await page.waitForSelector('[data-gdrive] [data-gd-save]');
	check('الإعدادات : carte « تخزين الملفات — Google Drive » (2 méthodes, adresse de retour à copier)', !!(await page.$('[name=gd-method][value=oauth]')) && !!(await page.$('[name=gd-method][value=service]')) && /ps_gdrive=callback/.test(await page.textContent('[data-gdrive]')));
	await (await page.$('[data-gdrive]')).screenshot({ path: SHOTS + '/s11-02-drive.png' });
	await ctx.close();
	// Sans connexion : le lien du fichier ne donne rien.
	const anon = await browser.newContext();
	const res = await anon.request.get(href.replace(/_wpnonce=[^&]+/, ''));
	check('lien du fichier sans connexion → refusé', res.status() === 401 || res.status() === 403, String(res.status()));
	await anon.close();
}

/* Version 1.5.2 : « tout effacer (projets ET tâches) puis importer le fichier Excel » par l'écran. */
async function step12(browser) {
	console.log('1.5.2 — مسح كل المشاريع والمهام ثم الاستيراد');
	const { ctx, page } = await newPage(browser, 'idara');
	// Une tâche libre existante (doit disparaître avec les projets).
	const made = await page.evaluate(async () => {
		const id = (await PS.api.get('lists')).lists.employee[0].id;
		await PS.api.post('free-tasks', { employeeId: id, title: 'مهمة قديمة للمسح', dueDate: '2026-12-01' });
		return (await PS.api.get('free-tasks?status=all')).tasks.length;
	}).catch((e) => 'erreur: ' + e.message);
	check('préparation : une tâche libre créée', made >= 1, String(made));
	await go(page, '#/parametres?tab=data');
	await page.setInputFiles('[data-xl] input[type=file]', __dirname + '/fixtures/excel-equipe.xlsx');
	await page.click('[data-xl-preview]');
	await page.waitForSelector('[data-xl-report] .ps-alert', { timeout: 15000 });
	await page.click('[data-xl] [name=replaceAll]');
	check('option renommée : « مسح كل المشاريع والمهام الحالية… » + aide visible', /والمهام/.test(await page.textContent('[data-xl]')) && await page.isVisible('[data-xl] [name=confirm]'));
	await page.click('[data-xl-preview]');
	await page.waitForFunction(() => /سيتم حذف/.test(document.querySelector('[data-xl-report]').textContent), null, { timeout: 15000 });
	const prev = (await page.textContent('[data-xl-report]')).replace(/\s+/g, ' ');
	check('aperçu : « سيتم حذف 30 مشروع حالي + N مهمة » puis 4 projets importés', /سيتم حذف 30 مشروع حالي \+ \d+ مهمة/.test(prev) && /4 مشروع سيتم استيراده/.test(prev), prev.slice(0, 220));
	await page.fill('[data-xl] [name=confirm]', 'مسح الكل');
	await page.click('[data-xl-run]');
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForFunction(() => /تم الاستيراد/.test(document.querySelector('[data-xl-report]').textContent), null, { timeout: 15000 });
	await page.screenshot({ path: SHOTS + '/s12-01-tout-effacer.png', fullPage: true });
	const after = await page.evaluate(async () => ({ projects: await PS.api.get('projects?perPage=200'), tasks: (await PS.api.get('free-tasks?status=all')).tasks }));
	check('après : 4 projets (ceux du fichier), aucune tâche', after.projects.total === 4 && after.tasks.length === 0, after.projects.total + ' / ' + after.tasks.length);
	// Erreur non JSON du serveur (page d'erreur de l'hébergeur) : message lisible, pas de HTML brut.
	await page.route('**/import/excel*', (route) => route.fulfill({ status: 503, contentType: 'text/html', body: '<html><head><style>b{}</style></head><body><h1>Service Unavailable</h1></body></html>' }));
	await page.click('[data-xl-preview]');
	const toast = await page.waitForFunction(() => [...document.querySelectorAll('.ps-toast')].map((t) => t.textContent).find((t) => /HTTP/.test(t)), null, { timeout: 5000 }).then((h) => h.jsonValue(), () => '');
	check('erreur de l\'hébergeur : « ردّ غير متوقّع من الخادم (HTTP 503) » sans balises', /HTTP 503/.test(toast) && /Service Unavailable/.test(toast) && !/</.test(toast), toast);
	await ctx.close();
}

/* Version 1.5.3 : la الإدارة efface des tâches dans مهامي (🗑 par tâche, « 🧹 تنظيف المهام »), pas de doublon affiché. */
async function step13(browser) {
	console.log('1.5.3 — حذف المهام (الإدارة)');
	const { ctx, page } = await newPage(browser, 'idara');
	await page.evaluate(async () => {
		const id = (await PS.api.get('lists')).lists.employee[0].id;
		for (const t of ['مهمة للحذف', 'مهمة باقية', 'مهمة منجزة']) await PS.api.post('free-tasks', { employeeId: id, title: t });
		const all = (await PS.api.get('free-tasks?status=all')).tasks;
		await PS.api.put('free-tasks/' + all.find((x) => x.title === 'مهمة منجزة').id, { action: 'done' });
	});
	await go(page, '#/taches');
	await page.waitForSelector('[data-task-purge]');
	const titles = await page.$$eval('.ps-task__item strong', (n) => n.map((x) => x.textContent));
	check('مهامي (الإدارة) : chaque tâche une seule fois (pas répétée dans « مهام أرسلتها »)', titles.filter((t) => t === 'مهمة باقية').length === 1, titles.join(' | '));
	check('bouton 🗑 sur les tâches + « 🧹 تنظيف المهام »', (await page.$$('[data-task-del]')).length >= 3);
	const more = await page.$('[data-task-more]');
	const visible = async () => page.$$eval('.ps-task__item', (n) => n.filter((x) => x.offsetParent).length);
	const before = await visible();
	if (more) await more.click();
	check('bloc long : 5 lignes puis « عرض الكل (N) » qui déplie le reste', !!more && (await visible()) > before && /عرض أقل/.test(await more.textContent()), before + ' → ' + (await visible()));
	await page.screenshot({ path: SHOTS + '/s13-01-taches.png', fullPage: true });
	const li = await page.$('.ps-task__item:has-text("مهمة للحذف")');
	await (await li.$('[data-task-del]')).click();
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForFunction(() => !/مهمة للحذف/.test(document.querySelector('.ps-main').textContent), null, { timeout: 8000 });
	check('🗑 : la tâche disparaît', true);
	await page.click('[data-task-purge]');
	await page.waitForSelector('.ps-modal [name=scope]');
	await page.screenshot({ path: SHOTS + '/s13-02-nettoyer.png' });
	await page.click('.ps-modal [data-btn=ok]');
	// La sauvegarde avant suppression peut être lente : attendre la confirmation, puis la page rechargée.
	await page.waitForSelector('.ps-toast--success:has-text("حُذفت المهام")', { timeout: 30000 }).catch(() => {});
	await page.waitForFunction(() => !/مهمة منجزة/.test(document.querySelector('.ps-main').textContent) && /مهمة باقية/.test(document.querySelector('.ps-main').textContent), null, { timeout: 15000 }).catch(() => {});
	const txt = await page.textContent('.ps-main');
	check('« حذف المهام المنجزة والملغاة » : la tâche تمت disparaît, la tâche ouverte reste', !/مهمة منجزة/.test(txt) && /مهمة باقية/.test(txt));
	await page.click('[data-task-purge]');
	await page.click('.ps-modal [name=scope][value=all]');
	await page.click('.ps-modal [data-btn=ok]');
	check('« حذف كل المهام » sans le mot → refusé', await page.waitForFunction(() => /مسح الكل/.test([...document.querySelectorAll('.ps-toast')].map((t) => t.textContent).join(' ')), null, { timeout: 4000 }).then(() => true, () => false) && !!(await page.$('.ps-modal')));
	await page.fill('.ps-modal [name=confirm]', 'مسح الكل');
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForFunction(() => !/مهمة باقية/.test(document.querySelector('.ps-main').textContent), null, { timeout: 8000 }).catch(() => {});
	check('« حذف كل المهام » confirmé : plus aucune tâche', (await page.evaluate(async () => (await PS.api.get('free-tasks?status=all')).tasks.length)) === 0);
	await ctx.close();
	// Un موظف ne voit pas les boutons de suppression.
	const e = await newPage(browser, 'mowadhaf');
	await go(e.page, '#/taches');
	await e.page.waitForSelector('.ps-tasks');
	check('موظف : ni 🗑 ni « تنظيف المهام »', !(await e.page.$('[data-task-del]')) && !(await e.page.$('[data-task-purge]')));
	await e.ctx.close();
}

/* Version 1.6.0 : مشروع؟, dates jj/mm/aaaa, fichier à la création, المتابع de l'offre (justification), factures, Recap. */
async function step14(browser) {
	console.log('1.6.0 — مشروع؟، التواريخ، الفواتير، المتابع، Recap');
	const fs = require('fs');
	const dir = require('os').tmpdir();
	const { ctx, page } = await newPage(browser, 'idara');
	// Fiche Projet (nouveau) : dates jj/mm/aaaa, « مشروع؟ » obligatoire.
	await go(page, '#/projet');
	await page.waitForSelector('[data-bind="receivedDate"]');
	const rd = await page.$eval('[data-bind="receivedDate"]', (e) => ({ type: e.type, shown: e.getAttribute('value') === null ? '' : HTMLInputElement.prototype.__lookupGetter__('value').call(e), api: e.value }));
	check('date : champ texte jj/mm/aaaa (19/10/2026), valeur API AAAA-MM-JJ', rd.type === 'text' && rd.shown === '19/10/2026' && rd.api === '2026-10-19', JSON.stringify(rd));
	check('date : bouton calendrier 📅', !!(await page.$('[data-bind="receivedDate"] ~ .ps-date__btn')));
	// 1.10.0 : pas de question « مشروع؟ » à la création — message « قرّر لاحقًا من المشاريع ».
	check('création : pas de « مشروع؟ », message « بعد الحفظ، قرّر… »', !(await page.$('input[name="isProject"]')) && /بعد الحفظ، قرّر/.test(await page.textContent('[data-section="is-project"]')));
	await fillProject(page, { name: 'طلب أسعار — غير مشروع', company: 'شركة غير مشروع', companyNew: true });
	await page.click('[data-more="info"] > summary'); // 1.7.0 : ملاحظة المشروع est dans « المزيد »
	await page.fill('[data-bind="projectNote"]', 'ملاحظة داخلية 1.6.0');
	await page.fill('[data-bind="receivedDate"]', '05/10/2026');
	await page.selectOption('[data-bind="responsibleEmpId"]', { label: 'وليد جدير' });
	await page.selectOption('[data-bind="followerEmpId"]', { label: 'محمد حسين' });
	await page.selectOption('[data-bind="items.0.employeeId"]', { label: 'محمد حسين' });
	fs.writeFileSync(dir + '/cahier-160.pdf', '%PDF-1.4\n%%EOF');
	await page.setInputFiles('[data-new-files]', dir + '/cahier-160.pdf');
	await page.waitForSelector('[data-action="remove-new-file"]');
	await page.screenshot({ path: SHOTS + '/s14-01-fiche.png', fullPage: true });
	await page.click('[data-action="save"]');
	await page.waitForTimeout(600);
	if (await page.$('.ps-modal [data-btn=save]')) await page.click('.ps-modal [data-btn=save]');
	await page.waitForSelector('.ps-alert--success a[href*="projet?id="]', { timeout: 8000 });
	const pid = (await page.getAttribute('.ps-alert--success a[href*="projet?id="]', 'href')).match(/id=(\d+)/)[1];
	await page.waitForTimeout(1500);
	check('nouveau projet : « ⏳ لم يُحدد بعد »', (await page.evaluate(async (id) => (await PS.api.get('projects/' + id)).projectState, pid)) === 'pending');
	// Décision depuis la colonne « مشروع؟ » de « المشاريع » : « لا » → سبب.
	await go(page, '#/projets?isProject=2');
	await page.waitForSelector('.ps-table--list tbody tr [data-yn="0"]');
	await page.click(`.ps-table--list tbody tr:has-text("طلب أسعار — غير مشروع") [data-yn="0"]`);
	await page.waitForSelector('.ps-modal [data-q="reason"]');
	check('« لا » → liste « سبب عدم اعتباره مشروعا »', (await page.$$eval('.ps-modal [data-q="reason"] option', (o) => o.map((x) => x.textContent))).includes('خارج الاختصاص'));
	await page.selectOption('.ps-modal [data-q="reason"]', { label: 'خارج الاختصاص' });
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForTimeout(1200);
	const saved = await page.evaluate(async (id) => ({ p: await PS.api.get('projects/' + id), files: await PS.api.get('projects/' + id + '/files') }), pid);
	check('enregistré « غير مشروع » + سبب + ملاحظة المشروع, date saisie 05/10/2026', saved.p.isProject === false && saved.p.nonProjectReasonId && saved.p.projectNote === 'ملاحظة داخلية 1.6.0' && saved.p.receivedDate === '2026-10-05', JSON.stringify({ i: saved.p.isProject, n: saved.p.projectNote, d: saved.p.receivedDate }));
	check('fichier choisi à la création → joint au projet', saved.files.length === 1 && saved.files[0].name === 'cahier-160.pdf');
	await go(page, '#/projets?isProject=0');
	await page.waitForSelector('.ps-table tbody tr');
	const rows = await page.$$eval('.ps-table tbody tr', (t) => t.map((r) => ({ no: !!r.querySelector('[data-non-project]'), txt: r.textContent })));
	check('Base Projets : filtre « غير مشروع » + colonne مشروع؟ = لا', rows.length === 1 && rows[0].no, rows.map((x) => x.txt).join(' | ').slice(0, 200));
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table tbody tr');
	const offerText = await page.textContent('.ps-table');
	check('Suivi Offres : « غير مشروع » absent ; colonnes المسؤول / المتابع / ملاحظة العرض / الفواتير', !/طلب أسعار — غير مشروع/.test(offerText) && /الموظف المتابع/.test(await page.textContent('.ps-table thead')) && /ملاحظة العرض/.test(await page.textContent('.ps-table thead')));
	// Changer le المتابع d'une offre : justification obligatoire.
	// 1.7.0 : la modification se fait dans le panneau de l'offre (clic sur la ligne).
	const tr = await page.$('.ps-table tbody tr[data-row]:has-text("PRJ-2026")'); // projet de l'année du Recap
	const rowId = await tr.getAttribute('data-row');
	await page.click(`tr[data-row="${rowId}"] td:nth-child(3)`);
	const sel = await page.waitForSelector('.ps-modal--drawer [data-d="followerEmpId"]');
	const cur = await sel.inputValue();
	const other = await sel.$$eval('option', (o, c) => o.map((x) => x.value).find((v) => v && v !== c), cur);
	await sel.selectOption(other);
	check('panneau : « سبب التغيير » apparaît dès que المتابع change', await page.isVisible('.ps-modal--drawer [data-d="reason"]'));
	await page.click('.ps-modal--drawer [data-btn=save]');
	await page.waitForTimeout(400);
	check('المتابع : justification vide → refusée', !!(await page.$('.ps-modal--drawer [data-d="reason"]')));
	await page.fill('.ps-modal--drawer [data-d="reason"]', 'إعادة توزيع العمل');
	await page.click('.ps-modal--drawer [data-btn=save]');
	await page.waitForSelector('.ps-modal--drawer', { state: 'detached', timeout: 8000 });
	await page.waitForTimeout(800);
	const log = await page.evaluate(async (id) => { const r = (await PS.api.get('items?view=offers&perPage=500')).rows.find((x) => String(x.id) === id); const l = await PS.api.get('projects/' + r.projectId + '/changelog'); return { r: r, l: l.find((x) => x.field === 'follower_emp_id') }; }, rowId);
	check('المتابع de l\'offre changé + journal avec justification', String(log.r.followerEmpId) === other && log.l && log.l.reason === 'إعادة توزيع العمل', JSON.stringify(log.l));
	// Factures de l'offre (bouton du panneau).
	await page.click(`tr[data-row="${rowId}"] td:nth-child(3)`);
	await page.click('.ps-modal--drawer [data-btn=invoices]');
	await page.waitForSelector('.ps-modal [data-btn=new]');
	await page.click('.ps-modal [data-btn=new]');
	await page.waitForSelector('.ps-modal [name=invoiceNo]');
	await page.fill('.ps-modal [name=invoiceNo]', 'FAC-E2E-001');
	await page.fill('.ps-modal [name=sentDate]', '01/10/2026');
	await page.fill('.ps-modal [name=amount]', '1500');
	await page.screenshot({ path: SHOTS + '/s14-02-facture.png' });
	await page.click('.ps-modal [data-btn=save]');
	await page.waitForSelector('.ps-modal [data-btn="new"]', { timeout: 8000 });
	const invTable = await page.textContent('.ps-modal');
	check('facture enregistrée : آخر أجل للرد calculé (16/10/2026) et en retard', /FAC-E2E-001/.test(invTable) && /16\/10\/2026/.test(invTable) && /متأخرة/.test(invTable), invTable.slice(0, 300));
	await page.screenshot({ path: SHOTS + '/s14-03-factures-offre.png' });
	await page.click('.ps-modal [data-btn=close]');
	await go(page, '#/factures?status=late');
	await page.waitForSelector('[data-results] tr[data-inv]');
	check('page « الفواتير » : facture en retard listée', /FAC-E2E-001/.test(await page.textContent('[data-results]')));
	await page.screenshot({ path: SHOTS + '/s14-04-page-factures.png', fullPage: true });
	const invId = await page.getAttribute('[data-results] tr[data-inv]', 'data-inv');
	await go(page, '#/factures/' + invId);
	await page.waitForSelector('.ps-modal [name=answer]');
	check('lien des alertes #/factures/ID → fiche de la facture', (await page.inputValue('.ps-modal [name=invoiceNo]')) === 'FAC-E2E-001');
	await page.selectOption('.ps-modal [name=answer]', 'yes');
	await page.click('.ps-modal [data-btn=save]');
	await page.waitForTimeout(1000);
	check('جواب الحريف = نعم → plus en retard', (await page.evaluate(async (id) => (await PS.api.get('invoices/' + id)).computed.late, invId)) === false);
	// Recap : compteur « غير مشروع » + bloc factures.
	await go(page, '#/recap');
	await page.waitForSelector('[data-recap-invoices]');
	const rec = await page.textContent('.ps-main');
	check('Recap : « غير مشروع » compté à part + bloc factures (taux نعم)', /مشروع مُلغى/.test(rec) && /جواب «نعم»/.test(rec) && /100\.0 %/.test(await page.textContent('[data-recap-invoices]')));
	await page.screenshot({ path: SHOTS + '/s14-05-recap.png', fullPage: true });
	// Réglages : délai de réponse, interrupteur des alertes, token via wp-config ; utilisateurs : colonne Telegram.
	await go(page, '#/parametres?tab=general');
	await page.waitForSelector('[name=invoice_reply_days]');
	check('الإعدادات : « آخر أجل للرد » (15) + « تفعيل التنبيهات » + explication wp-config.php', (await page.inputValue('[name=invoice_reply_days]')) === '15' && !!(await page.$('[name=alerts_enabled]')) && /PS_TELEGRAM_TOKEN/.test(await page.textContent('.ps-main')));
	await go(page, '#/parametres?tab=users');
	await page.waitForSelector('[data-tg]');
	check('المستخدمون : colonne Telegram (مربوط / غير مربوط)', /غير مربوط/.test(await page.textContent('.ps-table')));
	await ctx.close();
	// Téléphone : la page des factures ne déborde pas.
	const m = await newPage(browser, 'idara', true);
	await go(m.page, '#/factures?status=all');
	await m.page.waitForSelector('[data-results]');
	check('téléphone : page « الفواتير » sans défilement horizontal', await m.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
	await m.ctx.close();
}

/* Version 1.6.1 : ordre des champs (fiche) et des colonnes (Base Projets, Suivi Offres, Factures) demandé par le client. */
async function step15(browser) {
	console.log('1.6.1 — ترتيب الخانات والأعمدة');
	const { ctx, page } = await newPage(browser, 'idara');
	const heads = async () => (await page.$$eval('.ps-table thead th', (e) => e.map((x) => x.textContent.replace(/[▲▼]/g, '').trim()))).filter((x) => x);
	// Données : un projet « لا » + une facture avec fichier.
	await page.evaluate(async () => {
		const L = (await PS.api.get('lists')).lists, id = (k, l) => L[k].find((v) => v.label === l || v.code === l).id;
		await PS.api.post('projects', { isProject: 0, nonProjectReasonId: id('non_project_reason', 'خارج الاختصاص'), offerNo: 'OFR-NP-15', name: 'طلب غير مشروع 15', receivedDate: '2026-10-05',
			responsibleEmpId: id('employee', 'وليد جدير'), followerEmpId: id('employee', 'محمد حسين'), placeId: id('place', 'T'), categoryId: id('category', 'إنارة'), urgencyId: id('urgency', 'U'), sourceId: id('source', 'direct'),
			parties: { owner: { companyName: 'شركة المثال', person: 'سالم', phone: '0912345678' }, executor: { companyName: 'شركة التنفيذ' }, direct: { person: 'خالد', phone: '0921112233' } },
			items: [{ itemTypeId: id('item_type', 'UPS'), employeeId: id('employee', 'محمد حسين'), dueDate: '2026-10-25' }], confirmDuplicates: true });
		const row = (await PS.api.get('items?view=offers&perPage=500')).rows.find((r) => /2026/.test(r.projectNo));
		const inv = await PS.api.post('items/' + row.id + '/invoices', { invoiceNo: 'FAC-15-001', sentDate: '2026-10-01', amount: '1500', note: 'ملاحظة الفاتورة 15' });
		const fd = new FormData();
		fd.append('file', new Blob(['%PDF-1.4'], { type: 'application/pdf' }), 'facture.pdf');
		fd.append('invoiceId', inv.id);
		await PS.api.post('projects/' + row.projectId + '/files', fd);
	});

	// Fiche projet : 📎 الملفات المرفقة tout en haut, puis « مشروع؟ », motif juste dessous si « لا ».
	await go(page, '#/projet');
	await page.waitForSelector('[data-section="files"]');
	const order = await page.$$eval('[data-section="files"], [data-section="is-project"], [data-bind="name"], [data-bind="offerNo"]', (e) => e.map((x) => x.dataset.section || x.dataset.bind));
	check('fiche : 📎 الملفات ثم مشروع؟ ثم رقم العرض / اسم المشروع', order.join(',') === 'files,is-project,offerNo,name', order.join(','));
	await page.screenshot({ path: SHOTS + '/s15-01-fiche.png', fullPage: true });
	// Projet existant (« لا ») : fichiers en haut de l'onglet البيانات ; motif juste sous « مشروع؟ ».
	const pid = await page.evaluate(async () => (await PS.api.get('projects?q=' + encodeURIComponent('طلب غير مشروع 15'))).rows[0].id);
	await go(page, '#/projet?id=' + pid);
	await page.waitForSelector('[data-top-files] [data-file]', { state: 'attached', timeout: 8000 });
	check('« لا » : السبب affiché immédiatement sous la case (même bloc)', !!(await page.$('[data-section="is-project"] [data-bind="nonProjectReasonId"]')) && !!(await page.$('[data-section="is-project"] [data-bind="nonProjectNote"]')));
	const topOrder = await page.$$eval('[data-top-files], [data-section="is-project"], [data-bind="name"]', (e) => e.map((x) => x.hasAttribute('data-top-files') ? 'files' : (x.dataset.section || x.dataset.bind)));
	check('projet existant : الملفات en haut de البيانات, puis مشروع؟, puis le reste', topOrder.join(',') === 'files,is-project,name', topOrder.join(','));

	// Base Projets.
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table--list tbody tr');
	const bp = await heads();
	check('Base Projets : رقم المشروع | الاسم | مشروع؟ | سبب عدم الاعتبار | المسؤول | المتابع | تاريخ الاستلام | الحالة', bp.slice(0, 8).join('|') === 'رقم المشروع|الاسم|مشروع؟|سبب عدم الاعتبار|المسؤول عن المشروع|الموظف المتابع|تاريخ الاستلام|الحالة', bp.slice(0, 9).join('|'));
	const chipTxt = await page.textContent('[data-chips="isProject"]').catch(() => '');
	check('Base Projets : filtres rapides « مشروع؟ » avec compteurs, sans ouvrir les filtres', await page.isVisible('[data-chip="isProject"][data-value="0"]') && /مشروع مُلغى\s*1/.test(chipTxt), chipTxt.replace(/\s+/g, ' '));
	await page.click('.ps-sort[data-sort="isProject"]');
	await page.waitForTimeout(800);
	await page.click('.ps-sort[data-sort="isProject"]');
	await page.waitForTimeout(800);
	const firstCells = await page.$$eval('.ps-table--list tbody tr', (t) => t.slice(0, 1).map((r) => [r.children[3].textContent.trim(), r.children[4].textContent.trim()]));
	check('« مشروع؟ » triable : le projet « لا » en tête, avec son motif', /لا/.test(firstCells[0][0]) && /خارج الاختصاص/.test(firstCells[0][1]), JSON.stringify(firstCells));
	const yesRow = await page.$$eval('.ps-table--list tbody tr', (t) => t.filter((r) => !r.querySelector('[data-non-project]')).map((r) => [r.children[3].textContent.trim(), r.children[4].textContent.trim()])[0]);
	check('projet « نعم » : colonne سبب vide', yesRow && yesRow[1] === '', JSON.stringify(yesRow));
	await page.click('[data-chip="isProject"][data-value="0"]');
	await page.waitForTimeout(800);
	check('filtre « مشروع؟ = لا » → seulement les « غير مشروع »', (await page.$$eval('.ps-table--list tbody tr', (t) => t.every((r) => !!r.querySelector('[data-non-project]')))));
	await page.screenshot({ path: SHOTS + '/s15-02-base-projets.png' });

	// Suivi Offres.
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table tbody tr');
	const so = await heads();
	check('Suivi Offres : رقم المشروع | رقم فاتورة العرض | المسؤول | المتابع | تاريخ الإرسال | حالة العرض | 📎 | ملاحظة العرض', so.slice(0, 8).join('|') === 'رقم المشروع|رقم فاتورة العرض|المسؤول عن المشروع|الموظف المتابع|تاريخ الإرسال|حالة العرض|📎|ملاحظة العرض', so.join('|'));
	const firstCell = await page.$eval('.ps-table tbody tr td .ps-ptitle', (a) => a.firstElementChild.className);
	check('Suivi Offres : le NUMÉRO du projet d\'abord (nom en petit)', firstCell === 'ps-ptitle__num', firstCell);
	check('Suivi Offres : 📎 avec le nombre de fichiers ; « غير مشروع » absent', /📎 \d+/.test(await page.textContent('.ps-table tbody tr td:nth-child(7)')) && !/طلب غير مشروع 15/.test(await page.textContent('.ps-table')));
	await page.screenshot({ path: SHOTS + '/s15-03-offres.png' });

	// Factures.
	await go(page, '#/factures?status=all');
	await page.waitForSelector('[data-results] tr[data-inv]');
	const fa = await heads();
	check('Factures : رقم الفاتورة | رقم المشروع | تاريخ الإرسال | آخر أجل | جواب الحريف | المبلغ والعملة | 📎 | ملاحظة الفاتورة', fa.slice(0, 8).join('|') === 'رقم الفاتورة|رقم المشروع|تاريخ الإرسال|آخر أجل|جواب الحريف|المبلغ والعملة|📎|ملاحظة الفاتورة', fa.join('|'));
	const r1 = await page.textContent('[data-results] tr[data-inv]');
	check('Factures : 📎 1 + ملاحظة الفاتورة affichés', /📎 1/.test(r1) && /ملاحظة الفاتورة 15/.test(r1), r1.replace(/\s+/g, ' ').slice(0, 200));
	await page.screenshot({ path: SHOTS + '/s15-04-factures.png' });
	await page.click('[data-results] tr[data-inv] button.ps-btn');
	await page.waitForSelector('.ps-modal [name=invoiceNo]');
	check('📎 de la facture → ouvre la facture (ses fichiers)', /facture\.pdf/.test(await page.textContent('.ps-modal')));
	await page.click('.ps-modal [data-btn=close]');
	// Fenêtre « 🧾 » d'une offre : même ordre.
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table tbody tr');
	await page.click('.ps-table tbody tr:has-text("PRJ-2026") td:nth-child(3)');
	await page.click('.ps-modal--drawer [data-btn=invoices]');
	await page.waitForSelector('.ps-modal table');
	const mh = (await page.$$eval('.ps-modal thead th', (e) => e.map((x) => x.textContent.trim()))).filter((x) => x);
	check('fenêtre « 🧾 فواتير العرض » : même ordre de colonnes', mh.slice(0, 8).join('|') === fa.slice(0, 8).join('|'), mh.join('|'));
	await ctx.close();

	// 1.6.2 — téléphone : les cartes reprennent les champs importants dans le même ordre.
	const m = await newPage(browser, 'idara', true);
	await go(m.page, '#/projets?isProject=0');
	await m.page.waitForSelector('.ps-pcard');
	const card = await m.page.$eval('.ps-pcard', (c) => ({ no: c.querySelector('.ps-ptitle__num').textContent.trim(), keys: [...c.querySelectorAll('.ps-kv__k')].map((k) => k.textContent.trim()), txt: c.textContent, non: !!c.querySelector('[data-non-project]') }));
	check('téléphone (المشاريع) : numéro d\'abord, puis مشروع؟ = لا, السبب, المسؤول, المتابع, تاريخ الاستلام, الحالة', /PRJ-2026/.test(card.no) && card.keys.join('|') === 'مشروع؟|سبب عدم الاعتبار|المسؤول عن المشروع|الموظف المتابع|تاريخ الاستلام|الحالة' && card.non && /خارج الاختصاص/.test(card.txt), JSON.stringify(card.keys));
	await m.page.screenshot({ path: SHOTS + '/s15-05-mobile-projets.png', fullPage: true });
	await go(m.page, '#/projets');
	await m.page.waitForSelector('.ps-pcard');
	const yes = await m.page.$$eval('.ps-pcard', (cs) => cs.map((c) => [...c.querySelectorAll('.ps-kv__k')].map((k) => k.textContent.trim()).join('|')).find((k) => !k.includes('سبب')));
	check('téléphone : projet « نعم » sans ligne السبب', yes === 'مشروع؟|المسؤول عن المشروع|الموظف المتابع|تاريخ الاستلام|الحالة', yes);
	check('téléphone (المشاريع) : pas de défilement horizontal', await m.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
	await go(m.page, '#/offres');
	await m.page.waitForSelector('.ps-icard');
	const ok = await m.page.$eval('.ps-icard', (c) => [...c.querySelectorAll('.ps-kv__k')].map((k) => k.textContent.trim()).join('|'));
	check('téléphone (العروض) : رقم فاتورة العرض، المسؤول، المتابع، تاريخ الإرسال puis حالة العرض / 📎', ok === 'رقم فاتورة العرض|المسؤول عن المشروع|الموظف المتابع|تاريخ الإرسال' && !!(await m.page.$('.ps-icard [data-offer-files]')), ok);
	await m.page.screenshot({ path: SHOTS + '/s15-06-mobile-offres.png', fullPage: true });
	await m.ctx.close();
}

/* Version 1.7.0 : listes courtes (colonnes au choix), filtres rapides avec compteurs, panneau de détails, fiche allégée. */
async function step16(browser) {
	console.log('1.7.0 — تجربة الاستخدام : جداول قصيرة، لوحة التفاصيل، بطاقة أخف');
	const { ctx, page } = await newPage(browser, 'idara');
	const cols = async () => page.$$eval('.ps-table--list thead th', (e) => e.filter((x) => x.textContent.trim()).length);

	// Base Projets : 8 colonnes, panneau au clic.
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table--clickable tbody tr[data-open-row]');
	check('Base Projets : 8 colonnes importantes par défaut', (await cols()) === 8, String(await cols()));
	const chips = await page.$$eval('[data-chips="isProject"] .ps-qchip', (c) => c.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
	check('Base Projets : filtres rapides الكل / مشروع / بانتظار القرار / غير مشروع avec compteurs', chips.length === 4 && chips.every((c) => /\d/.test(c)), chips.join(' | '));
	await page.click('.ps-table--list tbody tr[data-open-row] >> nth=0 >> td:nth-child(5)');
	await page.waitForSelector('.ps-modal--drawer');
	const dr = await page.$eval('.ps-modal--drawer', (d) => ({ kv: d.querySelectorAll('.ps-kv').length, txt: d.textContent, fiche: !!d.querySelector('a[href*="#/projet?id="]'), files: !!d.querySelector('a[href*="tab=files"]') }));
	check('clic sur une ligne → panneau : tous les détails + actions (البطاقة، الملفات، سجل المتابعة)', dr.kv >= 10 && dr.fiche && dr.files && /المكان/.test(dr.txt), String(dr.kv));
	await page.screenshot({ path: SHOTS + '/s16-01-projets-panneau.png' });
	await page.keyboard.press('Escape');
	check('Échap ferme le panneau', !(await page.$('.ps-modal--drawer')));
	await page.click('.ps-table--list tbody tr[data-open-row] >> nth=0 >> .ps-pno');
	await page.waitForSelector('[data-form]');
	check('le lien du numéro ouvre toujours la fiche (pas le panneau)', page.url().includes('#/projet?id=') && !(await page.$('.ps-modal--drawer')));
	// Colonnes mémorisées.
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table--list tbody tr');
	await page.click('[data-action="columns"]');
	await page.check('.ps-colpick input[value="placeId"]');
	await page.check('.ps-colpick input[value="urgencyId"]');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.reload();
	await page.waitForSelector('.ps-table--list tbody tr');
	check('« الأعمدة » : choix gardé après rechargement (10 colonnes)', (await cols()) === 10, String(await cols()));
	await page.click('[data-action="columns"]');
	await page.click('.ps-modal [data-btn="reset"]');
	await page.waitForTimeout(300);
	check('« الافتراضي » : 8 colonnes', (await cols()) === 8);
	await page.screenshot({ path: SHOTS + '/s16-02-projets.png' });

	// Suivi Offres : chips + panneau de modification.
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table--clickable tbody tr[data-open-row]');
	check('Suivi Offres : 8 colonnes par défaut', (await cols()) === 8, String(await cols()));
	const lateN = Number(await page.textContent('[data-chip="offer"][data-value="late"] .ps-qchip__n'));
	await page.click('[data-chip="offer"][data-value="late"]');
	await page.waitForTimeout(800);
	check('filtre rapide « لازم قرار » : nombre de lignes = compteur', lateN > 0 && (await page.$$('.ps-table--list tbody tr')).length === lateN, String(lateN));
	await page.click('[data-chip="offer"][data-value=""]');
	await page.waitForTimeout(800);
	await page.screenshot({ path: SHOTS + '/s16-03-offres.png' });
	const rid = await page.getAttribute('.ps-table--list tbody tr[data-open-row] >> nth=0', 'data-open-row');
	await page.click(`tr[data-open-row="${rid}"] td:nth-child(3)`);
	await page.waitForSelector('.ps-modal--drawer [data-d="offerNotes"]');
	await page.screenshot({ path: SHOTS + '/s16-04-offre-panneau.png' });
	await page.fill('.ps-modal--drawer [data-d="offerNotes"]', 'ملاحظة من اللوحة 1.7.0');
	await page.click('.ps-modal--drawer [data-btn=save]');
	await page.waitForSelector('.ps-modal--drawer', { state: 'detached', timeout: 8000 });
	const note = await page.evaluate(async (id) => (await PS.api.get('items?view=offers&perPage=500')).rows.find((r) => String(r.id) === id).offerNotes, rid);
	check('panneau de l\'offre : ملاحظة العرض enregistrée', note === 'ملاحظة من اللوحة 1.7.0', note);
	check('ملاحظة العرض visible dans le tableau', /ملاحظة من اللوحة 1\.7\.0/.test(await page.textContent(`tr[data-open-row="${rid}"]`)));

	// Factures : chips + panneau.
	await page.evaluate(async (id) => { await PS.api.post('items/' + id + '/invoices', { invoiceNo: 'FAC-170-1', sentDate: '2026-09-01' }); }, rid);
	await go(page, '#/factures');
	await page.waitForSelector('[data-chips="status"]');
	const lateInv = await page.textContent('[data-chip="status"][data-value="late"]');
	check('Factures : filtres rapides avec compteurs (متأخرة 1)', /1/.test(lateInv), lateInv.replace(/\s+/g, ' '));
	await page.click('[data-results] tr[data-open-row] td:nth-child(3)');
	await page.waitForSelector('.ps-modal--drawer [name=invoiceNo]');
	check('clic sur une facture → panneau latéral (formulaire)', (await page.inputValue('.ps-modal--drawer [name=invoiceNo]')) === 'FAC-170-1');
	await page.screenshot({ path: SHOTS + '/s16-05-facture-panneau.png' });
	await page.click('.ps-modal--drawer [data-btn=close]');

	// Fiche projet : raccourcis, « المزيد ».
	await go(page, '#/projet');
	await page.waitForSelector('.ps-jump');
	check('fiche (création) : 6 raccourcis de sections (« مشروع؟ » se décide ensuite dans « المشاريع »)', (await page.$$('.ps-jump__a')).length === 6);
	check('fiche : champs secondaires repliés (« المزيد »), الوسيط caché', !(await page.isVisible('[data-bind="intermediaryEmpId"]')) && await page.isVisible('[data-more="info"] > summary'));
	check('fiche : carte d\'intervenant allégée (العنوان dans « المزيد »)', await page.isVisible('[data-bind="parties.owner.phone"]') && !(await page.isVisible('[data-bind="parties.owner.address"]')));
	await page.click('[data-more="info"] > summary');
	await page.selectOption('[data-bind="placeId"]', '__add').catch(() => null); // provoque un nouvel affichage (ajout rapide)
	if (await page.$('.ps-modal [data-btn=cancel]')) await page.click('.ps-modal [data-btn=cancel]');
	await page.waitForTimeout(300);
	check('« المزيد » reste ouvert après un nouvel affichage', await page.isVisible('[data-bind="intermediaryEmpId"]'));
	await page.click('[data-jump="ps-sec-items"]');
	await page.waitForTimeout(900);
	check('raccourci « الأصناف » : la section est à l\'écran', await page.evaluate(() => { const r = document.getElementById('ps-sec-items').getBoundingClientRect(); return r.top >= 0 && r.top < window.innerHeight; }));
	await go(page, '#/projet');
	await page.waitForSelector('.ps-jump');
	await page.screenshot({ path: SHOTS + '/s16-06-fiche.png', fullPage: true });
	await ctx.close();

	// Téléphone : appui sur une carte → panneau plein écran.
	const m = await newPage(browser, 'idara', true);
	await go(m.page, '#/projets');
	await m.page.waitForSelector('.ps-pcard[data-open-row]');
	await m.page.click('.ps-pcard[data-open-row] >> nth=0 >> .ps-kvs');
	await m.page.waitForSelector('.ps-modal--drawer');
	const w = await m.page.$eval('.ps-modal--drawer .ps-modal__box', (b) => b.getBoundingClientRect().width);
	check('téléphone : carte → panneau plein écran', w >= 370, String(w));
	await m.page.screenshot({ path: SHOTS + '/s16-07-mobile-panneau.png' });
	await m.page.keyboard.press('Escape');
	await go(m.page, '#/factures?status=all');
	await m.page.waitForSelector('.ps-icard[data-open-row]');
	check('téléphone (الفواتير) : cartes, sans défilement horizontal', await m.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
	await m.page.screenshot({ path: SHOTS + '/s16-08-mobile-factures.png', fullPage: true });
	await m.ctx.close();
}

/* Version 1.8.0 : « مشروع؟ » نعم / لا directement dans la liste des projets ; Suivi Livraison en liste courte. */
async function step17(browser) {
	console.log('1.8.0 — «مشروع؟» من الجدول، متابعة التسليم');
	const { ctx, page } = await newPage(browser, 'idara');
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table--list tbody tr .ps-yn');
	const row = '.ps-table--list tbody tr >> nth=0';
	const no = await page.textContent(row + ' >> .ps-pno');
	check('interrupteur نعم / لا dans la colonne « مشروع؟ »', (await page.$$('.ps-table--list tbody tr .ps-yn')).length >= 20);
	await page.click(row + ' >> [data-yn="0"]');
	await page.waitForSelector('.ps-modal [data-q="reason"]');
	check('« لا » depuis la liste : pas de panneau, fenêtre du سبب', !(await page.$('.ps-modal--drawer')));
	await page.click('.ps-modal [data-btn=ok]');
	check('« لا » sans سبب → refusé (la fenêtre reste)', !!(await page.$('.ps-modal [data-q="reason"]')));
	await page.selectOption('.ps-modal [data-q="reason"]', { label: 'غير مؤكد' });
	await page.screenshot({ path: SHOTS + '/s17-01-non-projet.png' });
	await page.click('.ps-modal [data-btn=ok]');
	await page.waitForTimeout(1200);
	const after = await page.$eval(`.ps-table--list tbody tr:has(.ps-pno:text-is("${no}"))`, (tr) => ({ no: tr.querySelector('[data-yn="0"]').getAttribute('aria-pressed'), txt: tr.textContent }));
	check('ligne : « لا » actif + سبب affiché', after.no === 'true' && /غير مؤكد/.test(after.txt), after.txt.replace(/\s+/g, ' ').slice(0, 160));
	check('filtre rapide « غير مشروع » : 1', /1/.test(await page.textContent('[data-chip="isProject"][data-value="0"]')));
	const inOffers = async () => page.evaluate(async (n) => (await PS.api.get('items?view=offers&perPage=500&q=' + encodeURIComponent(n))).rows.length, no);
	check('« لا » → absent de Suivi Offres', (await inOffers()) === 0);
	await page.click(`.ps-table--list tbody tr:has(.ps-pno:text-is("${no}")) [data-yn="1"]`);
	await page.waitForTimeout(1200);
	check('« نعم » en un clic → de nouveau une offre (Suivi Offres)', (await inOffers()) > 0);
	const toast = await page.textContent('.ps-toast-wrap, .ps-toasts, body');
	check('message clair « يظهر الآن في متابعة العروض »', /يظهر الآن في متابعة العروض/.test(toast));
	// Panneau : même interrupteur.
	await page.click(`.ps-table--list tbody tr:has(.ps-pno:text-is("${no}")) td:nth-child(6)`);
	await page.waitForSelector('.ps-modal--drawer .ps-drawer__yn .ps-yn');
	check('panneau : interrupteur « مشروع؟ » en haut', true);
	await page.keyboard.press('Escape');
	// Suivi Livraison : 8 colonnes, panneau.
	await go(page, '#/livraison');
	await page.waitForSelector('.ps-table--clickable tbody tr');
	const n = await page.$$eval('.ps-table--list thead th', (e) => e.length);
	check('Suivi Livraison : 8 colonnes importantes', n === 8, String(n));
	check('Suivi Livraison : tient dans l\'écran (1366 px)', await page.$eval('.ps-table-wrap', (w) => w.scrollWidth <= w.clientWidth + 1));
	await page.click('.ps-table--list tbody tr >> nth=0 >> td:nth-child(2)');
	await page.waitForSelector('.ps-modal--drawer [data-d="deliveryDueDate"]');
	await page.screenshot({ path: SHOTS + '/s17-02-livraison-panneau.png' });
	await page.keyboard.press('Escape');
	for (const pg of ['projets', 'offres', 'factures?status=all']) {
		await go(page, '#/' + pg);
		await page.waitForSelector('[data-results] .ps-list-head');
		check('#/' + pg + ' : tableau sans défilement horizontal (1366 px)', await page.evaluate(() => [...document.querySelectorAll('.ps-table-wrap')].every((w) => w.scrollWidth <= w.clientWidth + 1)));
	}
	await ctx.close();
	// موظف : interrupteur seulement là où il peut modifier.
	const e = await newPage(browser, 'mowadhaf');
	await go(e.page, '#/projets');
	await e.page.waitForSelector('.ps-table--list tbody tr');
	const counts = await e.page.evaluate(() => ({ rows: document.querySelectorAll('.ps-table--list tbody tr').length, yn: document.querySelectorAll('.ps-table--list tbody tr .ps-yn').length }));
	check('موظف : badge (pas d\'interrupteur) sur les projets qu\'il ne peut pas modifier', counts.yn < counts.rows, JSON.stringify(counts));
	await e.ctx.close();
	// Téléphone : interrupteur sur la carte, filtres rapides sur une ligne.
	const m = await newPage(browser, 'idara', true);
	await go(m.page, '#/projets');
	await m.page.waitForSelector('.ps-pcard .ps-yn');
	check('téléphone : interrupteur « مشروع؟ » sur la carte', true);
	await go(m.page, '#/offres');
	await m.page.waitForSelector('[data-chips="offer"]');
	check('téléphone : filtres rapides sur une seule ligne', await m.page.$eval('[data-chips="offer"]', (c) => c.getBoundingClientRect().height < 60));
	await m.page.screenshot({ path: SHOTS + '/s17-03-mobile-offres.png' });
	await m.ctx.close();
}

/* Version 1.9.0 : « مشروع؟ » à trois états — « ⏳ لم يُحدد بعد » à la création, décision plus tard depuis la liste. */
async function step18(browser) {
	console.log('1.9.0 / 1.10.0 — «مشروع؟»: لم يُحدد بعد ثم القرار لاحقًا من الجدول');
	const { ctx, page } = await newPage(browser, 'idara');
	await go(page, '#/projet');
	await page.waitForSelector('[data-section="is-project"]');
	check('fiche (création) : aucune question « مشروع؟ » + message « قرّر لاحقًا »', !(await page.$('input[name="isProject"]')) && /بعد الحفظ، قرّر/.test(await page.textContent('[data-section="is-project"]')));
	await fillProject(page, { name: 'طلب جديد — القرار لاحقًا', company: 'شركة القرار', companyNew: true });
	await page.selectOption('[data-bind="responsibleEmpId"]', { label: 'وليد جدير' });
	await page.selectOption('[data-bind="followerEmpId"]', { label: 'محمد حسين' });
	await page.selectOption('[data-bind="items.0.employeeId"]', { label: 'محمد حسين' });
	await page.screenshot({ path: SHOTS + '/s18-01-fiche-pending.png' });
	await page.click('[data-action="save"]');
	await page.waitForTimeout(600);
	if (await page.$('.ps-modal [data-btn=save]')) await page.click('.ps-modal [data-btn=save]');
	await page.waitForSelector('.ps-alert--success a[href*="projet?id="]', { timeout: 8000 });
	const pid = (await page.getAttribute('.ps-alert--success a[href*="projet?id="]', 'href')).match(/id=(\d+)/)[1];
	const saved = await page.evaluate(async (id) => (await PS.api.get('projects/' + id)).projectState, pid);
	check('enregistré « لم يُحدد بعد »', saved === 'pending', saved);
	const inOffers = async () => page.evaluate(async (id) => (await PS.api.get('items?view=offers&perPage=500')).rows.some((r) => String(r.projectId) === id), pid);
	check('pas encore dans Suivi Offres', !(await inOffers()));
	// Base Projets : filtre « بانتظار القرار », ligne marquée, décision d'un clic.
	await go(page, '#/projets');
	await page.waitForSelector('[data-chip="isProject"][data-value="2"]');
	check('filtre rapide « ⏳ بانتظار القرار » : 1', /1/.test(await page.textContent('[data-chip="isProject"][data-value="2"]')));
	await page.click('[data-chip="isProject"][data-value="2"]');
	await page.waitForTimeout(800);
	const rows = await page.$$eval('.ps-table--list tbody tr', (t) => t.map((r) => ({ p: r.classList.contains('is-pending'), on: (r.querySelector('[data-yn="2"]') || {}).ariaPressed || r.querySelector('[data-yn="2"]')?.getAttribute('aria-pressed') })));
	check('ligne « بانتظار القرار » : ⏳ actif', rows.length === 1 && rows[0].p && rows[0].on === 'true', JSON.stringify(rows));
	await page.screenshot({ path: SHOTS + '/s18-02-projets-pending.png' });
	// مهامي : bloc « طلبات بانتظار القرار ».
	await go(page, '#/taches');
	await page.waitForSelector('.ps-tasks');
	check('مهامي : bloc « طلبات بانتظار القرار »', /طلبات بانتظار القرار/.test(await page.textContent('.ps-tasks')) && /طلب جديد — القرار لاحقًا/.test(await page.textContent('.ps-tasks')));
	await page.screenshot({ path: SHOTS + '/s18-03-taches.png' });
	await page.click('a[href="#/projets?isProject=2"]');
	await page.waitForSelector('.ps-table--list tbody tr [data-yn="1"]');
	await page.click('.ps-table--list tbody tr [data-yn="1"]');
	await page.waitForTimeout(1200);
	check('« نعم » depuis la liste → devient une offre', await inOffers());
	check('plus aucune demande en attente', /0/.test(await page.textContent('[data-chip="isProject"][data-value="2"]')));
	// Régression : après « مسح الفلاتر » (nouvel affichage de la page), un clic n'ouvre qu'UNE fenêtre.
	await page.click('[data-action="toggle-filters"]');
	await page.click('[data-action="reset-filters"]');
	await page.waitForSelector('.ps-table--list tbody tr [data-yn="0"]');
	await page.click('.ps-table--list tbody tr >> nth=0 >> [data-yn="0"]');
	await page.waitForSelector('.ps-modal');
	await page.waitForTimeout(400);
	check('un clic = une seule fenêtre (pas d\'écouteurs en double)', (await page.$$('.ps-modal')).length === 1, String((await page.$$('.ps-modal')).length));
	await page.click('.ps-modal [data-btn=cancel]');
	await go(page, '#/recap');
	await page.waitForSelector('[data-f]');
	const yr = await page.$eval('select[data-f="year"]', (s) => s.value).catch(() => null);
	if (yr) {
		await page.selectOption('select[data-f="year"]', String(Number(yr) - 1));
		await page.waitForTimeout(1200);
		await page.selectOption('select[data-f="year"]', yr);
		await page.waitForTimeout(1200);
		const calls = await page.evaluate(() => { let n = 0; const o = window.print; window.print = () => { n++; }; document.querySelector('[data-action="print"]').click(); window.print = o; return n; });
		check('Recap : après 2 changements de filtre, « طباعة » ne s\'exécute qu\'une fois', calls === 1, String(calls));
	}
	await ctx.close();
}

/* Version 1.10.0 : suppression de projets par الإدارة (panneau, sélection, corbeille, suppression définitive). */
async function step19(browser) {
	console.log('1.10.0 — حذف المشاريع (الإدارة)');
	const { ctx, page } = await newPage(browser, 'idara');
	const total = async () => page.evaluate(async () => ({ list: (await PS.api.get('projects?perPage=1')).total, trash: (await PS.api.get('projects?perPage=1&trash=1')).total }));
	const t0 = await total();
	await go(page, '#/projets');
	await page.waitForSelector('.ps-table--list tbody tr[data-open-row]');
	check('bouton « 🗑 سلة المحذوفات » visible au-dessus de la liste', await page.isVisible('[data-action="trash-open"]'));
	// 1) Panneau → 🗑 حذف.
	const no1 = await page.textContent('.ps-table--list tbody tr >> nth=0 >> .ps-pno');
	await page.click('.ps-table--list tbody tr >> nth=0 >> td:nth-child(6)');
	await page.waitForSelector('.ps-modal--drawer [data-btn="delete"]');
	await page.click('.ps-modal--drawer [data-btn="delete"]');
	await page.waitForSelector('.ps-modal [data-btn="ok"]');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(1200);
	let t = await total();
	check('panneau → 🗑 حذف : projet mis à la corbeille', t.list === t0.list - 1 && t.trash === t0.trash + 1, JSON.stringify(t));
	// 2) Sélection de 2 projets → « حذف المحدد ».
	await page.check('.ps-table--list tbody tr >> nth=0 >> [data-check]');
	await page.check('.ps-table--list tbody tr >> nth=1 >> [data-check]');
	await page.waitForSelector('[data-action="bulk-trash"]');
	await page.screenshot({ path: SHOTS + '/s19-01-selection.png' });
	await page.click('[data-action="bulk-trash"]');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(1500);
	t = await total();
	check('sélection (2) → « حذف المحدد » : 2 de plus dans la corbeille', t.list === t0.list - 3 && t.trash === t0.trash + 3, JSON.stringify(t));
	// 3) Corbeille : استرجاع / حذف نهائي.
	await page.click('[data-action="trash-open"]');
	await page.waitForSelector('[data-purge]');
	check('corbeille : « ↩ استرجاع » et « ❌ حذف نهائي » sur chaque ligne', (await page.$$('.ps-table--list [data-purge]')).length === 3 && (await page.$$('.ps-table--list [data-restore]')).length === 3);
	await page.screenshot({ path: SHOTS + '/s19-02-corbeille.png' });
	await page.click(`.ps-table--list tbody tr:has(.ps-pno:text-is("${no1.trim()}")) [data-purge]`);
	await page.waitForSelector('.ps-modal [data-q="word"]');
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(300);
	check('« حذف نهائي » sans taper le mot → refusé (fenêtre ouverte)', !!(await page.$('.ps-modal [data-q="word"]')));
	await page.fill('.ps-modal [data-q="word"]', 'حذف نهائي');
	await page.screenshot({ path: SHOTS + '/s19-03-definitif.png' });
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForSelector('.ps-modal [data-q="word"]', { state: 'detached', timeout: 30000 });
	await page.waitForTimeout(800);
	t = await total();
	check('« حذف نهائي » : projet effacé (corbeille 3 → 2)', t.trash === t0.trash + 2 && t.list === t0.list - 3, JSON.stringify(t));
	const gone = await page.evaluate(async (n) => (await PS.api.get('projects?trash=1&q=' + encodeURIComponent(n))).total + (await PS.api.get('projects?q=' + encodeURIComponent(n))).total, no1.trim());
	check('le projet n\'existe plus nulle part', gone === 0);
	// 4) Restaurer les 2 autres (sélection).
	await page.check('[data-check-all]');
	await page.click('[data-action="bulk-restore"]');
	await page.waitForTimeout(1500);
	t = await total();
	check('corbeille : « ↩ استرجاع » de la sélection → projets revenus', t.trash === t0.trash && t.list === t0.list - 1, JSON.stringify(t));
	await page.click('[data-action="trash-close"]');
	await page.waitForSelector('[data-action="trash-open"]');
	await ctx.close();
	// موظف : aucun bouton de suppression.
	const e = await newPage(browser, 'mowadhaf');
	await go(e.page, '#/projets');
	await e.page.waitForSelector('.ps-table--list tbody tr[data-open-row]');
	await e.page.click('.ps-table--list tbody tr >> nth=0 >> td:nth-child(5)');
	await e.page.waitForSelector('.ps-modal--drawer');
	check('موظف : ni 🗑 dans le panneau, ni corbeille, ni cases à cocher', !(await e.page.$('.ps-modal--drawer [data-btn="delete"]')) && !(await e.page.$('[data-action="trash-open"]')) && !(await e.page.$('[data-check]')));
	await e.ctx.close();
}

/* Version 1.11.0 : factures par offre entière, آخر أجل saisi, tableau des factures complet, « مشروع مُلغى », vitesse. */
async function step20(browser) {
	console.log('1.11.0 — الفواتير حسب العرض، آخر أجل يدوي، السرعة');
	const { ctx, page } = await newPage(browser, 'idara');
	// Une offre à 2 أصناف (projet de démo de 2026 avec plusieurs lignes dans Suivi Offres).
	const target = await page.evaluate(async () => {
		const rows = (await PS.api.get('items?view=offers&perPage=500')).rows;
		const by = {};
		rows.forEach((r) => { (by[r.projectId] = by[r.projectId] || []).push(r); });
		const pid = Object.keys(by).find((k) => by[k].length >= 2 && /2026/.test(by[k][0].projectNo));
		return { pid: Number(pid), items: by[pid].map((r) => r.id), no: by[pid][0].projectNo };
	});
	check('offre de démonstration avec ≥ 2 أصناف', target.items.length >= 2, JSON.stringify(target));
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table--clickable tbody tr');
	await page.click(`tr[data-row="${target.items[0]}"] td:nth-child(3)`);
	await page.click('.ps-modal--drawer [data-btn=invoices]');
	await page.waitForSelector('.ps-modal [data-btn=new]');
	await page.click('.ps-modal [data-btn=new]');
	await page.waitForSelector('.ps-modal [name=replyDeadline]');
	check('formulaire : « آخر أجل للرد » est un champ à saisir (proposé +15 j)', (await page.inputValue('.ps-modal [name=replyDeadline]')) === '03/11/2026', await page.inputValue('.ps-modal [name=replyDeadline]'));
	check('formulaire : « الفاتورة تخص العرض كاملًا »', /تخص العرض كاملًا/.test(await page.textContent('.ps-modal')));
	await page.fill('.ps-modal [name=invoiceNo]', 'FAC-1110');
	await page.fill('.ps-modal [name=sentDate]', '01/10/2026');
	await page.waitForTimeout(200);
	check('la date proposée suit la date d\'envoi (16/10/2026)', (await page.inputValue('.ps-modal [name=replyDeadline]')) === '16/10/2026', await page.inputValue('.ps-modal [name=replyDeadline]'));
	await page.fill('.ps-modal [name=replyDeadline]', '20/10/2026');
	await page.screenshot({ path: SHOTS + '/s20-01-facture-form.png' });
	await page.click('.ps-modal [data-btn=save]');
	await page.waitForSelector('.ps-modal [data-btn="new"]', { timeout: 8000 });
	await page.click('.ps-modal [data-btn=close]');
	const inv = await page.evaluate(async (t) => ({ a: (await PS.api.get('invoices?status=all&itemId=' + t.items[0])).rows, b: (await PS.api.get('invoices?status=all&itemId=' + t.items[1])).rows }), target);
	check('facture enregistrée pour l\'offre entière (visible depuis chaque صنف), آخر أجل = 20/10', inv.a.length === 1 && inv.b.length === 1 && inv.a[0].itemId === 0 && inv.a[0].replyDeadline === '2026-10-20' && inv.a[0].itemTypeIds.length >= 2, JSON.stringify(inv.a[0] && { i: inv.a[0].itemId, d: inv.a[0].replyDeadline, t: inv.a[0].itemTypeIds }));
	// Page « الفواتير » : toutes les colonnes, comme demandé.
	await go(page, '#/factures?status=all');
	await page.waitForSelector('[data-results] tr[data-inv]');
	const heads = (await page.$$eval('.ps-table thead th', (e) => e.map((x) => x.textContent.trim()))).filter((x) => x);
	check('الفواتير : les 10 colonnes visibles (… الأصناف، الموظف المتابع)', heads.length === 10 && heads[8] === 'الأصناف' && heads[9] === 'الموظف المتابع', heads.join('|'));
	check('الفواتير : آخر أجل saisi affiché (20/10/2026)', /20\/10\/2026/.test(await page.textContent('[data-results] tr[data-inv]')));
	await page.screenshot({ path: SHOTS + '/s20-02-factures.png' });
	// « مشروع مُلغى ».
	await go(page, '#/projets');
	await page.waitForSelector('[data-chip="isProject"][data-value="0"]');
	check('filtre « 🚫 مشروع مُلغى » (au lieu de « غير مشروع »)', /مشروع مُلغى/.test(await page.textContent('[data-chip="isProject"][data-value="0"]')) && !/غير مشروع/.test(await page.textContent('[data-chips="isProject"]')));
	// Vitesse : listes incluses dans la page, JSON compressé.
	const enc = await page.evaluate(async () => (await fetch(PS.config.restUrl + 'items?view=offers&perPage=300', { headers: { 'X-WP-Nonce': PS.config.nonce } })).headers.get('content-encoding'));
	check('vitesse : réponses JSON compressées (gzip)', enc === 'gzip', String(enc));
	await ctx.close();
	const p2 = await newPage(browser, 'idara');
	const calls = [];
	p2.page.on('request', (r) => { if (r.url().includes('/wp-json/')) calls.push(r.url()); });
	await go(p2.page, '#/projets');
	await p2.page.waitForSelector('.ps-table--list tbody tr');
	check('vitesse : les listes viennent de la page (pas d\'appel /lists)', !calls.some((u) => /ps\/v1\/lists(\?|$)/.test(u)), calls.join(' '));
	await p2.ctx.close();
}

const STEPS = { step2, step3, step4, step5, step6, step7, step8, step9, step10, step11, step12, step13, step14, step15, step16, step17, step18, step19, step20 };

(async () => {
	const browser = await chromium.launch();
	for (const name of Object.keys(STEPS)) {
		if (ONLY && ONLY.indexOf(name) === -1) continue;
		try { await STEPS[name](browser); } catch (e) { failures++; console.log('  ✘ ' + name + ' interrompu : ' + e.message.split('\n')[0]); }
	}
	await browser.close();
	console.log(failures ? `\n${failures} échec(s)` : '\nTous les contrôles navigateur sont passés.');
	process.exit(failures ? 1 : 0);
})();
