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
	// 1.0.1 : le 1er bloc est « مهام مُسندة » (vide ici) ; les blocs §7 suivent.
	const counts = await page.$$eval('.ps-task__count', (e) => e.map((x) => Number(x.textContent)));
	check('مهامي : 0 مهام مُسندة, 3 عروض متأخرة, 2 متابعات, 2 تسليمات (tableau §7)', counts[0] === 0 && counts[1] === 3 && counts[2] === 2 && counts[4] === 2, counts.join(','));
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

async function step4(browser) {
	console.log('Étape 4 — Recap (الإدارة, jeu de démonstration)');
	const { ctx, page } = await newPage(browser, 'idara');
	await go(page, '#/recap?year=2026');
	await page.waitForSelector('#ch-month');
	await page.waitForTimeout(500);
	const kpis = await page.$$eval('.ps-kpi', (e) => e.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
	check('عدد المشاريع 23 (ملغى: 1) avec comparaison 2025', kpis[0].includes('23') && kpis[0].includes('ملغى: 1') && kpis[0].includes('2025'), kpis[0]);
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
	check('2 أصناف « السعر غير منافس »', (await page.$$('.ps-table--edit tbody tr')).length === 2);

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
		await page.evaluate(() => {
			navigator.mediaDevices.getUserMedia = async function () { const ac = new AudioContext(); const d = ac.createMediaStreamDestination(); const g = ac.createGain(); g.gain.value = 0; const o = ac.createOscillator(); o.connect(g); g.connect(d); o.start(); return d.stream; };
		});
		await page.click('[data-voice-rec]');
		await page.waitForTimeout(2600);
		await page.click('[data-voice-rec]');
		await page.waitForSelector('[data-voice][data-state="done"]');
		check('micro muet : alerte « لم يُلتقط أي صوت » après l\'enregistrement', await page.waitForFunction(() => /لم يُلتقط أي صوت/.test(document.body.textContent), null, { timeout: 3000 }).then(() => true, () => false));
		await page.click('[data-btn=save]');
		check('micro muet : envoi refusé « التسجيل بدون صوت » (la fenêtre reste ouverte)', await page.waitForFunction(() => /التسجيل بدون صوت/.test(document.body.textContent), null, { timeout: 5000 }).then(() => true, () => false) && await page.isVisible('.ps-modal'));
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
		check('Telegram non configuré : rien dans la cloche', !(await page.isVisible('[data-tg-link]')));
		await ctx.close();
	}
	{
		const { ctx, page } = await newPage(browser, 'idara');
		await go(page, '#/parametres?tab=general');
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

const STEPS = { step2, step3, step4, step5, step6, step7, step8, step9 };

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
