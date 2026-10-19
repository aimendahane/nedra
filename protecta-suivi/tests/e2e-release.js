/**
 * Contrôles navigateur de livraison (voir tests/release-check.sh) :
 *   FRESH=http://localhost:8082 : installation vierge → connexion, saisie d'un projet, Recap ;
 *   UPGRADE=http://localhost:8083 : après mise à jour depuis 0.1.0 → les données d'avant sont là et utilisables.
 */
'use strict';
const { chromium } = require('playwright');
const SHOTS = process.env.SHOTS || '.';
let failures = 0;
function check(label, ok, detail) {
	console.log((ok ? '  ✔ ' : '  ✘ ') + label + (!ok && detail ? '  [' + detail + ']' : ''));
	if (!ok) failures++;
}
async function login(browser, base, user, pass) {
	const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } });
	const page = await ctx.newPage();
	page.on('pageerror', (e) => { failures++; console.log('  ✘ erreur JavaScript : ' + e.message); });
	await page.goto(base + '/');
	await page.fill('input[name=log]', user);
	await page.fill('input[name=pwd]', pass);
	await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
	return { ctx, page };
}
async function go(page, url) { await page.goto(url); await page.waitForLoadState('networkidle'); await page.waitForTimeout(400); }

(async () => {
	const browser = await chromium.launch();
	const F = process.env.FRESH;
	const U = process.env.UPGRADE;

	if (F) {
		console.log('Installation vierge (zip final) — ' + F);
		const r = await fetch(F + '/');
		const html = await r.text();
		check('page de connexion personnalisée (pas WordPress)', html.includes('ps-login__form') && !html.includes('wp-login'));
		const { ctx, page } = await login(browser, F, 'admin', 'Admin-pass-2026');
		check('connexion (administrateur du site) → application', await page.isVisible('.ps-header'));
		await go(page, F + '/#/projet');
		await page.waitForSelector('[data-form]');
		await page.fill('[data-bind="name"]', 'مشروع تجريبي بعد التثبيت');
		await page.click('[data-action="suggest-offer"]');
		await page.waitForTimeout(500);
		for (const [sel, label] of [['responsibleEmpId', 'وفاء حامدي'], ['followerEmpId', 'وفاء حامدي'], ['placeId', 'T – Tripoli'], ['categoryId', 'إنارة'], ['urgencyId', 'U – Urgent'], ['sourceId', 'مباشر'], ['items.0.itemTypeId', 'إنارة'], ['items.0.employeeId', 'وفاء حامدي']]) {
			await page.selectOption(`[data-bind="${sel}"]`, { label });
		}
		await page.fill('[data-bind="parties.owner.companyName"]', 'شركة الاختبار');
		await page.fill('[data-bind="parties.owner.person"]', 'سالم');
		await page.fill('[data-bind="parties.owner.phone"]', '0912345678');
		await page.fill('[data-bind="parties.executor.companyName"]', 'شركة المنفذ');
		await page.fill('[data-bind="parties.direct.person"]', 'خالد');
		await page.fill('[data-bind="parties.direct.phone"]', '0921112233');
		await page.fill('[data-bind="items.0.dueDate"]', '2026-10-30');
		await page.click('[data-action="save"]');
		await page.waitForSelector('.ps-alert--success', { timeout: 10000 }).catch(() => null);
		const ok = await page.textContent('.ps-alert--success').catch(() => '');
		check('saisie d\'un projet : PRJ-2026-001 enregistré', ok.includes('PRJ-2026-001'), ok);
		await go(page, F + '/#/recap?year=2026');
		await page.waitForSelector('.ps-kpi', { timeout: 10000 }).catch(() => null);
		const k = (await page.textContent('.ps-kpi').catch(() => '')).replace(/\s+/g, ' ');
		check('Recap : 1 projet', /عدد المشاريع 1\b/.test(k), k);
		check('Recap : graphique mensuel dessiné', !!(await page.$('#ch-month')));
		await page.screenshot({ path: SHOTS + '/r-01-installation-vierge-recap.png', fullPage: true });
		await go(page, F + '/wp-content/uploads/protecta-suivi/backups/');
		check('dossier des sauvegardes inaccessible par URL', (await page.content()).includes('Forbidden'));
		await ctx.close();
	}

	if (U) {
		console.log('Mise à jour (ancienne version → zip final) avec données — ' + U);
		const { ctx, page } = await login(browser, U, 'wafa', 'Wafa-pass-2026');
		check('compte créé en 0.1.0 : connexion OK après mise à jour', await page.isVisible('.ps-header'));
		await go(page, U + '/#/projets');
		await page.waitForSelector('[data-results] [role=status]');
		check('projet d\'avant la mise à jour présent dans Base Projets', (await page.textContent('[data-results]')).includes('إنارة مدرسة الأمل'));
		await go(page, U + '/#/projet?id=1&tab=followups');
		await page.waitForSelector('.ps-timeline__item', { timeout: 10000 }).catch(() => null);
		check('سجل المتابعة conservé', (await page.textContent('[data-tab-body]')).includes('تأكيد الطلبية هاتفيًا'));
		await go(page, U + '/#/projet?id=1&tab=files');
		await page.waitForSelector('.ps-table tbody a', { timeout: 10000 }).catch(() => null);
		const href = await page.getAttribute('.ps-table tbody a', 'href').catch(() => null);
		const f = href ? await page.request.get(href) : null;
		check('fichier joint conservé et téléchargeable', !!f && f.status() === 200 && (await f.body()).toString().startsWith('%PDF'));
		await go(page, U + '/#/livraison');
		await page.waitForSelector('[data-results]');
		check('Suivi Livraison : le صنف مقبول d\'avant est suivi', (await page.textContent('[data-results]')).includes('PRJ-2026-001'));
		await ctx.close();
		const adm = await login(browser, U, 'idara', 'Idara-pass-2026');
		await go(adm.page, U + '/#/recap?year=2026');
		await adm.page.waitForSelector('.ps-kpi', { timeout: 10000 }).catch(() => null);
		const kp = (await adm.page.$$eval('.ps-kpi', (e) => e.map((x) => x.textContent.replace(/\s+/g, ' ')))).join(' | ');
		check('Recap après mise à jour : 1 projet, 1 مؤكدة, 12 500 LYD', /عدد المشاريع 1\b/.test(kp) && /المؤكدة 1\b/.test(kp) && kp.includes('12,500'), kp.slice(0, 200));
		await go(adm.page, U + '/#/parametres?tab=lists');
		await adm.page.waitForSelector('[data-list]');
		await adm.page.click('[data-list="category"]');
		await adm.page.waitForTimeout(500);
		const labels = await adm.page.$$eval('[data-f=label]', (e) => e.map((x) => x.value));
		check('listes personnalisées conservées (« كهرباء عامة »)', labels.includes('كهرباء عامة'));
		await adm.page.screenshot({ path: SHOTS + '/r-02-apres-mise-a-jour.png', fullPage: true });
		await adm.ctx.close();
	}
	await browser.close();
	console.log(failures ? `\n${failures} échec(s)` : '\nContrôles de livraison : tout est passé.');
	process.exit(failures ? 1 : 0);
})();
