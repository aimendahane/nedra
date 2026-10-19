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

async function fillProject(page, o) {
	await page.fill('[data-bind="name"]', o.name);
	await page.click('[data-action="suggest-offer"]');
	await page.waitForTimeout(400);
	await page.selectOption('[data-bind="placeId"]', { label: o.place || 'T – Tripoli' });
	await page.selectOption('[data-bind="categoryId"]', { label: 'إنارة' });
	await page.selectOption('[data-bind="urgencyId"]', { label: o.urgency || 'U – Urgent' });
	await page.selectOption('[data-bind="sourceId"]', { label: 'مباشر' });
	if (o.companyNew) await pickCompany(page, 'owner', o.company, 'new');
	else await pickCompany(page, 'owner', o.company, 'first');
	await page.fill('[data-bind="parties.owner.person"]', o.person || 'سالم');
	await page.fill('[data-bind="parties.owner.phone"]', '0912345678');
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
	check('colonnes du cahier §4 dans l\'ordre (السنة … رابط ملف المشاريع)', headers[0].startsWith('السنة') && headers[21].startsWith('رابط ملف المشاريع'), headers.slice(0, 3).join('|'));
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
	const counts = await page.$$eval('.ps-task__count', (e) => e.map((x) => Number(x.textContent)));
	check('مهامي : 3 عروض متأخرة, 2 متابعات, 2 تسليمات (tableau §7)', counts[0] === 3 && counts[1] === 2 && counts[3] === 2, counts.join(','));
	await page.waitForTimeout(600);
	const menuCount = await page.$eval('a[href="#/offres"] .ps-nav__count', (e) => e.textContent).catch(() => '');
	check('compteur « لازم قرار » sur le menu : 3', menuCount === '3', menuCount);
	await page.screenshot({ path: SHOTS + '/s3-01-mes-taches.png', fullPage: true });

	console.log('Étape 3 — Suivi Offres');
	await go(page, '#/offres');
	await page.waitForSelector('.ps-table--edit tbody tr');
	// Toutes années confondues : 6 retards en 2026 + E06 (2025) = 7.
	check('lignes rouges (en retard) : 7, vertes (مقبول) ≥ 7', (await page.$$('tr.is-late')).length === 7 && (await page.$$('tr.is-done')).length >= 7);
	const decision = (await page.textContent('[data-decision]')).replace(/\s+/g, ' ');
	check('compteur en haut : « لازم قرار: 7 (منها 3 لك) »', decision.includes('7') && decision.includes('3 لك'), decision);
	await page.screenshot({ path: SHOTS + '/s3-02-suivi-offres.png', fullPage: true });
	await page.check('[data-filter="late"]');
	await page.waitForTimeout(800);
	check('filtre « لازم قرار فقط » : 7 lignes', (await page.$$('.ps-table--edit tbody tr')).length === 7);
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
	check('après « لم يُرسل » : 6 lignes « لازم قرار »', (await page.$$('.ps-table--edit tbody tr')).length === 6);
	// Suivant → مرسل : تاريخ إرسال العرض demandé (aujourd'hui proposé).
	await page.selectOption(mineSel, { label: 'مرسل' });
	await page.waitForSelector('.ps-modal [data-q="date"]');
	check('مرسل sans تاريخ الإرسال : date du jour proposée', (await page.inputValue('.ps-modal [data-q="date"]')) === '2026-10-19');
	await page.screenshot({ path: SHOTS + '/s3-03-date-envoi.png' });
	await page.click('.ps-modal [data-btn="ok"]');
	await page.waitForTimeout(1200);
	check('après « مرسل » : 5 lignes « لازم قرار »', (await page.$$('.ps-table--edit tbody tr')).length === 5);
	const menuAfter = await page.$eval('a[href="#/offres"] .ps-nav__count', (e) => e.textContent).catch(() => '0');
	check('compteur du menu mis à jour : 1', menuAfter === '1', menuAfter);

	console.log('Étape 3 — Suivi Livraison');
	await go(page, '#/livraison');
	await page.waitForSelector('.ps-table--edit tbody tr');
	check('livraisons en retard en rouge : 3', (await page.$$('tr.is-late')).length === 3);
	const lateHeader = await page.textContent('[data-results] [role=status]');
	check('« 3 تسليم متأخر »', lateHeader.includes('3 تسليم متأخر'), lateHeader);
	await page.screenshot({ path: SHOTS + '/s3-04-livraison.png', fullPage: true });
	const editable = await page.$$('tr.is-late input[data-field="deliveryActualDate"]');
	check('موظف : seules ses lignes sont modifiables (2)', editable.length === 2, String(editable.length));
	await editable[0].fill('2026-10-01');
	await editable[0].dispatchEvent('change');
	await page.waitForTimeout(1200);
	await page.selectOption('tr[data-row] >> nth=0 >> select[data-field="deliveryStatusId"]', { label: 'تم التسليم' }).catch(() => null);
	await page.waitForTimeout(1200);
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

const STEPS = { step2, step3 };

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
