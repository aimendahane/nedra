/**
 * Test de bout en bout (navigateur) de l'étape 1 — développement local uniquement.
 *
 *   BASE_URL=http://localhost:8080 SHOTS=/chemin/captures node tests/e2e-smoke.js
 *
 * Nécessite Playwright + Chromium, et les comptes de test idara / masoul / mowadhaf
 * (mot de passe Test-pass-2026) créés sur le WordPress local.
 */
'use strict';

const { chromium } = require('playwright');

const BASE = process.env.BASE_URL || 'http://localhost:8080';
const SHOTS = process.env.SHOTS || '.';
const PASS = 'Test-pass-2026';

let failures = 0;
function check(label, ok, detail) {
	console.log((ok ? '  ✔ ' : '  ✘ ') + label + (detail ? '  [' + detail + ']' : ''));
	if (!ok) failures++;
}

async function login(page, user, pass) {
	await page.goto(BASE + '/');
	await page.fill('input[name=log]', user);
	await page.fill('input[name=pwd]', pass);
	await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type=submit]')]);
}

async function navTitles(page) {
	await page.waitForSelector('.ps-nav__link');
	return page.$$eval('.ps-nav__link', (els) => els.map((e) => e.textContent.trim()));
}

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

	console.log('Page de connexion');
	{
		const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
		const page = await ctx.newPage();
		await page.goto(BASE + '/');
		check('formulaire de connexion affiché', await page.isVisible('form.ps-login__form'));
		check('logo affiché', await page.isVisible('img.ps-login__banner'));
		check('page en arabe RTL', (await page.getAttribute('html', 'dir')) === 'rtl');
		check('pas de barre admin WordPress', !(await page.$('#wpadminbar')));
		await page.screenshot({ path: SHOTS + '/01-connexion.png', fullPage: true });

		await login(page, 'mowadhaf', 'mauvais');
		check('mauvais mot de passe → message d\'erreur', (await page.textContent('.ps-alert')).includes('غير صحيحين'));
		await ctx.close();
	}

	console.log('Employé (موظف)');
	{
		const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
		const page = await ctx.newPage();
		await login(page, 'mowadhaf', PASS);
		const titles = await navTitles(page);
		check('application affichée après connexion', await page.isVisible('.ps-header'));
		check('menu sans Recap ni الإعدادات', !titles.some((t) => t.includes('Recap') || t.includes('الإعدادات')), titles.join(' | '));
		check('page d\'accueil = مهامي', (await page.textContent('.ps-main h1')).includes('مهامي'));
		await page.goto(BASE + '/#/parametres');
		await page.waitForTimeout(400);
		check('#/parametres interdit → renvoi vers مهامي', (await page.textContent('.ps-main h1')).includes('مهامي'));
		await page.goto(BASE + '/wp-admin/');
		check('wp-admin interdit → renvoi vers l\'application', !page.url().includes('wp-admin'), page.url());
		check('pas de barre admin WordPress', !(await page.$('#wpadminbar')));
		await ctx.close();
	}

	console.log('الإدارة');
	{
		const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
		const page = await ctx.newPage();
		await login(page, 'idara', PASS);
		const titles = await navTitles(page);
		check('menu complet (8 pages)', titles.length === 8, titles.join(' | '));
		await page.screenshot({ path: SHOTS + '/02-accueil-pc.png', fullPage: true });

		await page.click('a[href="#/parametres"]');
		await page.waitForSelector('.ps-card .ps-chip');
		const cards = await page.$$eval('.ps-card', (els) => els.length);
		check('الإعدادات : 14 listes affichées', cards === 14, String(cards));
		const sources = await page.$$eval('.ps-card', (els) => els.find((e) => e.textContent.includes('مصدر الطلب')).textContent);
		check('مصدر « غير محدد » présent et marqué masqué', sources.includes('غير محدد'));
		await page.screenshot({ path: SHOTS + '/03-parametres-pc.png', fullPage: true });

		await page.click('#ps-theme-toggle'); // auto → nuit
		await page.waitForTimeout(300);
		check('mode nuit activé', (await page.getAttribute('html', 'data-theme')) === 'dark');
		const bg = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
		check('fond sombre en mode nuit', bg === 'rgb(15, 20, 28)', bg);
		await page.screenshot({ path: SHOTS + '/04-parametres-nuit.png', fullPage: true });
		await page.reload();
		await page.waitForSelector('.ps-nav__link');
		check('mode nuit mémorisé après rechargement', (await page.getAttribute('html', 'data-theme')) === 'dark');
		await page.click('#ps-theme-toggle'); // nuit → jour
		await page.click('#ps-theme-toggle'); // jour → auto
		await page.waitForTimeout(300);

		await page.click('#ps-logout');
		await page.waitForSelector('form.ps-login__form');
		check('déconnexion → page de connexion', (await page.textContent('.ps-alert')).includes('تم تسجيل الخروج'));
		await ctx.close();
	}

	console.log('Mobile');
	{
		const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
		const page = await ctx.newPage();
		await page.goto(BASE + '/');
		await page.screenshot({ path: SHOTS + '/05-connexion-mobile.png', fullPage: true });
		await login(page, 'masoul', PASS);
		await page.waitForSelector('.ps-nav__link', { state: 'attached' });
		check('menu caché par défaut sur mobile', !(await page.isVisible('#ps-nav.is-open')));
		await page.screenshot({ path: SHOTS + '/06-accueil-mobile.png' });
		await page.click('#ps-menu-toggle');
		await page.waitForTimeout(350);
		check('bouton ☰ ouvre le menu', await page.isVisible('#ps-nav.is-open'));
		await page.screenshot({ path: SHOTS + '/07-menu-mobile.png' });
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
		check('pas de défilement horizontal', !overflow);
		await ctx.close();
	}

	console.log('Limitation des tentatives de connexion');
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		for (let i = 0; i < 5; i++) await login(page, 'masoul', 'faux-' + i);
		await login(page, 'masoul', PASS);
		const msg = await page.textContent('.ps-alert');
		check('après 5 échecs, même le bon mot de passe est refusé', msg.includes('محاولات كثيرة'), msg.trim());
		await ctx.close();
	}

	await browser.close();
	console.log(failures ? `\n${failures} échec(s)` : '\nTous les contrôles navigateur sont passés.');
	process.exit(failures ? 1 : 0);
})();
