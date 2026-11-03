// Vidéo de formation « المسؤول » v2 : capture HD (CDP screencast 1920×1080), voix arabe synchronisée scène par scène,
// zoom / projecteur sur l'élément expliqué (appliqués au montage par tools/video/assemble.py).
// Usage : node record2.js <dossier de sortie> <scenes.json>   (site de démo : http://localhost:8081, compte masoul)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const OUT = process.argv[2] || '/tmp/psx/video2';
const SCENES = JSON.parse(fs.readFileSync(process.argv[3] || '/tmp/psx/tts/scenes.json', 'utf8'));
const BASE = 'http://localhost:8081';
const PASS = 'Test-pass-2026';
const OVERLAY = fs.readFileSync(path.join(__dirname, 'overlay.js'), 'utf8');
const W = 1440, H = 810, DPR = 1920 / 1440;

fs.mkdirSync(OUT + '/frames', { recursive: true });
const log = { frames: [], scenes: [], zooms: [], segments: [] };
const wait = (p, ms) => p.waitForTimeout(ms);
const now = () => Date.now() / 1000;

/* ---------- capture ---------- */
async function startCapture(page, seg) {
	const cdp = await page.context().newCDPSession(page);
	let n = 0;
	cdp.on('Page.screencastFrame', async (f) => {
		const file = `${seg}-${String(n++).padStart(6, '0')}.jpg`;
		fs.writeFileSync(`${OUT}/frames/${file}`, Buffer.from(f.data, 'base64'));
		log.frames.push({ seg, t: f.metadata.timestamp, file });
		cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
	});
	await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: 1920, maxHeight: 1080, everyNthFrame: 1 });
	// Petite animation invisible : Chrome n'envoie des images que quand l'écran change → horloge régulière.
	await page.evaluate(() => { if (window.__tick) return; const d = document.createElement('div'); d.style.cssText = 'position:fixed;left:0;bottom:0;width:1px;height:1px;opacity:.01;z-index:2147483647;pointer-events:none'; document.documentElement.appendChild(d); window.__tick = setInterval(() => { d.style.background = d.style.background === 'red' ? 'blue' : 'red'; }, 33); });
	return cdp;
}

/* ---------- outils ---------- */
let mouse = { x: W / 2, y: H / 2 };
async function el(p, sel) { return typeof sel === 'string' ? p.waitForSelector(sel, { state: 'visible', timeout: 15000 }) : sel; }
async function moveTo(p, sel, opts) {
	const e = await el(p, sel);
	await e.scrollIntoViewIfNeeded();
	await wait(p, 200);
	const b = await e.boundingBox();
	const x = b.x + b.width * ((opts && opts.fx) || 0.5), y = b.y + b.height * ((opts && opts.fy) || 0.5);
	await p.mouse.move(x, y, { steps: 22 });
	mouse = { x, y };
	await wait(p, 250);
	return e;
}
async function click(p, sel, opts) { const e = await moveTo(p, sel, opts); await wait(p, 150); await p.mouse.down(); await wait(p, 90); await p.mouse.up(); await wait(p, (opts && opts.after) || 900); return e; }
async function type(p, sel, text, delay) { await click(p, sel, { after: 200 }); await p.keyboard.press('Control+A'); await p.keyboard.press('Delete'); await p.keyboard.type(text, { delay: delay || 65 }); await wait(p, 350); }
async function go(p, hash) { await p.evaluate((h) => { location.hash = h; }, hash); await p.waitForLoadState('networkidle'); await wait(p, 900); }

/**
 * Zoom (au montage) sur un élément : sa position est relevée toutes les 120 ms tant que le zoom dure (elle suit le
 * défilement de la page) ; rectangle en pixels de l'image (1920×1080).
 */
let zoomTimer = null;
async function zoom(p, sel, scale) {
	const e = await el(p, sel);
	await e.scrollIntoViewIfNeeded();
	await wait(p, 150);
	unzoom();
	const sample = async () => {
		const b = await e.boundingBox().catch(() => null);
		if (b) log.zooms.push({ t: now(), x: b.x * DPR, y: b.y * DPR, w: b.width * DPR, h: b.height * DPR, scale: scale || 1.8 });
	};
	await sample();
	zoomTimer = setInterval(sample, 120);
}
function unzoom() {
	if (zoomTimer) { clearInterval(zoomTimer); zoomTimer = null; }
	log.zooms.push({ t: now(), scale: 1 });
}
/** Projecteur : assombrit tout sauf l'élément (dans la page, suit la mise en page). */
async function spot(p, sel) {
	await p.evaluate((s) => {
		const t = document.querySelector(s);
		let o = document.getElementById('vid-spot');
		if (!o) { o = document.createElement('div'); o.id = 'vid-spot'; document.documentElement.appendChild(o); }
		if (!t) { o.remove(); return; }
		const r = t.getBoundingClientRect();
		o.style.cssText = `position:fixed;z-index:2147483640;pointer-events:none;left:${r.left - 8}px;top:${r.top - 8}px;width:${r.width + 16}px;height:${r.height + 16}px;border-radius:12px;box-shadow:0 0 0 4px #E8571F, 0 0 0 9999px rgba(10,16,30,.45);transition:all .4s`;
	}, sel);
}
async function unspot(p) { await p.evaluate(() => { const o = document.getElementById('vid-spot'); if (o) o.remove(); }); }

/** Scène : la voix commence maintenant ; les actions se déroulent pendant ; on attend la fin de la phrase. */
async function scene(p, id, actions) {
	const s = SCENES[id];
	if (!s) throw new Error('scène inconnue ' + id);
	const t0 = now();
	log.scenes.push({ id, t: t0, dur: s.dur, cap: s.cap });
	if (actions) await actions();
	const left = t0 + s.dur + 0.5 - now();
	if (left > 0) await wait(p, left * 1000);
}
async function card(p, h, lines, ms) {
	await p.evaluate(([a, b]) => window.__card(a, b), [h, lines]);
	await wait(p, ms);
}
async function cardOff(p) { await p.evaluate(() => window.__card('')); await wait(p, 700); }

/* ---------- PC ---------- */
async function desktop(browser) {
	const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: DPR });
	await ctx.addInitScript(OVERLAY);
	await ctx.addInitScript(() => { document.addEventListener('DOMContentLoaded', () => { const st = document.createElement('style'); st.textContent = '#vid-cap,#vid-step{display:none!important} .ps-toasts{top:84px!important;bottom:auto!important;left:50%!important;right:auto!important;inset-inline-start:auto!important;transform:translateX(-50%)}'; document.head.appendChild(st); }); });
	const p = await ctx.newPage();
	await p.goto(BASE + '/');
	await wait(p, 600);
	log.segments.push({ seg: 'pc', t: now() });
	await startCapture(p, 'pc');
	await card(p, 'شرح الموقع للمسؤول عن المشروع', ['متابعة المشاريع والعروض — Protecta Group', 'من الطلب إلى العرض والفاتورة والتسليم'], 600);
	await scene(p, 'intro');
	await cardOff(p);

	await scene(p, 'login', async () => {
		await zoom(p, '.ps-login__card, form.ps-login__form', 1.7);
		await type(p, 'input[name=log]', 'masoul');
		await type(p, 'input[name=pwd]', PASS, 40);
		await click(p, 'button[type=submit]', { after: 300 });
		unzoom();
		await p.waitForSelector('.ps-nav__link');
	});

	await scene(p, 'tasks1', async () => { await wait(p, 800); await spot(p, '.ps-tasks'); });
	await unspot(p);
	await scene(p, 'tasks2', async () => {
		await zoom(p, '[data-today]', 2.0);
		await spot(p, '[data-today]');
		await wait(p, 4500);
		await unspot(p);
		const chip = await p.$('[data-jump-block]');
		if (chip) await click(p, chip, { after: 1200 });
	});
	unzoom();
	await scene(p, 'tasks3', async () => {
		const t = await p.$('.ps-task__item .ps-ptitle');
		if (t) { await zoom(p, t, 2.0); await moveTo(p, t); }
	});
	unzoom();
	await p.mouse.wheel(0, -3000);
	await scene(p, 'menu', async () => { await spot(p, '.ps-nav'); await zoom(p, '.ps-nav', 1.5); });
	unzoom(); await unspot(p);
	await scene(p, 'account', async () => {
		await zoom(p, '.ps-header__user', 1.6);
		await click(p, '#ps-usermenu', { after: 900 });
		await zoom(p, '#ps-usermenu-panel', 2.0);
	});
	await p.keyboard.press('Escape'); unzoom();
	await scene(p, 'bell', async () => {
		await click(p, '#ps-bell', { after: 900 });
		await zoom(p, '#ps-bell-panel', 1.8);
	});
	unzoom(); await p.keyboard.press('Escape'); await p.mouse.click(700, 500); await wait(p, 400);

	// Saisie rapide.
	await go(p, '#/projets');
	await scene(p, 'quick1', async () => {
		await zoom(p, '[data-quick-add]', 2.2);
		await click(p, '[data-quick-add]', { after: 1200 });
		unzoom();
		await spot(p, '[data-quick] .ps-card');
	});
	await unspot(p);
	await scene(p, 'quick2', async () => {
		await click(p, '[data-action=save]', { after: 1000 });
		await zoom(p, '.ps-errsum', 1.9);
		await wait(p, 2500);
		await click(p, '.ps-errsum [data-err-jump]', { after: 600 });
	});
	unzoom();
	await scene(p, 'quick3', async () => {
		await type(p, '[data-bind=name]', 'إنارة مجمع النخيل السكني', 55);
		await zoom(p, '[data-company=owner]', 2.0);
		await type(p, '[data-company=owner]', 'شركة النخيل للاستثمار', 55);
		await p.keyboard.press('Escape');
	});
	unzoom();
	await scene(p, 'quick4', async () => {
		await click(p, '[data-action=save]', { after: 1400 });
		await zoom(p, '.ps-alert--success', 1.9);
	});
	unzoom();

	// Décision « مشروع؟ ».
	await go(p, '#/projets?isProject=2');
	const target = await p.evaluate(async () => (await PS.api.get('projects?q=' + encodeURIComponent('مجمع النخيل'))).rows[0].id);
	await scene(p, 'dec1', async () => {
		await zoom(p, `.ps-table--list [data-pid="${target}"][data-yn="1"]`, 2.4);
		await spot(p, `.ps-table--list tr:has([data-pid="${target}"]) .ps-yn`);
	});
	await unspot(p);
	await scene(p, 'dec2', async () => {
		await click(p, `.ps-table--list [data-pid="${target}"][data-yn="1"]`, { after: 1500 });
		unzoom();
	});
	await scene(p, 'dec3', async () => { const b = await p.$('.ps-toast [data-toast-action]'); if (b) await moveTo(p, b); });
	unzoom();
	const open = await p.$('.ps-toast [data-toast-action]');
	if (open) await click(p, open, { after: 1500 }); else await go(p, '#/projet?id=' + target);

	// Fiche du projet.
	await scene(p, 'card1', async () => { await zoom(p, '.ps-jump', 1.8); await spot(p, '.ps-jump'); });
	await unspot(p); unzoom();
	await scene(p, 'card2', async () => { await zoom(p, '[data-action="suggest-offer"]', 2.4); await click(p, '[data-action="suggest-offer"]', { after: 900 }); });
	unzoom();
	await scene(p, 'card3', async () => { await click(p, '[data-jump="ps-sec-items"]', { after: 1200 }); await zoom(p, '#ps-sec-items', 1.5); });
	unzoom();
	await scene(p, 'card4', async () => { await click(p, '[data-action=save]', { after: 1300 }); await zoom(p, '.ps-errsum', 1.7); });
	unzoom();
	await p.mouse.wheel(0, -4000);

	// Projet complet.
	const demo = await p.evaluate(async () => (await PS.api.get('projects?q=PRJ-2026-001')).rows[0].id);
	await go(p, '#/projet?id=' + demo);
	await scene(p, 'proj1', async () => { await zoom(p, '.ps-page-head', 1.6); await wait(p, 3000); unzoom(); await spot(p, '.ps-tabs'); });
	await unspot(p);
	await scene(p, 'proj2', async () => {
		await click(p, '.ps-tab[data-tab="followups"]', { after: 1000 });
		await zoom(p, 'form[data-followup]', 1.6);
		await type(p, 'form[data-followup] textarea[name=text]', 'اتصال بالحريف: ينتظر موافقة الإدارة على العرض', 40);
		await type(p, 'form[data-followup] input[name=nextDate]', '26/10/2026', 60);
		await click(p, 'form[data-followup] [type=submit]', { after: 1200 });
	});
	unzoom();
	await scene(p, 'proj3', async () => { await click(p, '.ps-tab[data-tab="files"]', { after: 1100 }); await zoom(p, '[data-tab-body] .ps-card', 1.5); await moveTo(p, '[data-drop], .ps-dropzone'); });
	unzoom();
	await scene(p, 'proj4', async () => { await click(p, '.ps-tab[data-tab="log"]', { after: 1100 }); await zoom(p, '[data-tab-body]', 1.4); });
	unzoom();

	// Suivi Offres.
	await go(p, '#/offres');
	await scene(p, 'off1', async () => { await spot(p, '.ps-table--list tbody'); await wait(p, 3000); await unspot(p); await zoom(p, '.ps-table--list tbody tr.is-late', 1.7); });
	unzoom();
	await scene(p, 'off2', async () => {
		await zoom(p, '[data-chips="offer"]', 1.8);
		await spot(p, '[data-chips="offer"]');
		await wait(p, 2500);
		await unspot(p);
		await click(p, '[data-chip="offer"][data-value="late"]', { after: 1200 });
	});
	unzoom();
	await scene(p, 'off3', async () => {
		const sel = await p.$('.ps-table--list tbody tr[data-row] [data-field="offerStatusId"]');
		if (!sel) return;
		await zoom(p, sel, 2.0);
		const sent = await p.evaluate(() => PS.lists.byCode('offer_status', 'sent').id);
		await moveTo(p, sel);
		await sel.selectOption(String(sent));
		await wait(p, 1300);
		unzoom();
		if (await p.$('.ps-modal [data-q="date"]')) { await zoom(p, '.ps-modal__box', 1.5); await wait(p, 1800); await click(p, '.ps-modal [data-btn=ok]', { after: 1300 }); unzoom(); }
	});
	await go(p, '#/offres');
	await scene(p, 'off4', async () => {
		await click(p, '.ps-table--list tbody tr[data-row] td:nth-child(4)', { after: 1200 });
		await zoom(p, '.ps-modal--drawer .ps-modal__box', 1.35);
	});
	unzoom();

	// Factures.
	await scene(p, 'inv1', async () => {
		await click(p, '.ps-modal [data-btn="invoices"]', { after: 1300 });
		await click(p, '.ps-modal [data-btn="new"]', { after: 1300 });
		await zoom(p, '.ps-modal .ps-inv-scope', 2.0);
		await click(p, '.ps-modal [name="scope"][value="item"]', { after: 900 });
		await click(p, '.ps-modal [name="scope"][value="offer"]', { after: 600 });
	});
	unzoom();
	await scene(p, 'inv2', async () => {
		await type(p, '.ps-modal [name=invoiceNo]', 'FA-2026-118', 70);
		await zoom(p, '.ps-modal [name=replyDeadline]', 2.0);
		await spot(p, '.ps-modal label:has([name=replyDeadline])');
		await wait(p, 6000);
		await unspot(p);
		unzoom();
		await click(p, '.ps-modal [data-btn=save]', { after: 1500 });
		const c = await p.$('.ps-modal [data-btn=close]');
		if (c) await click(p, c, { after: 700 });
	});
	await go(p, '#/factures');
	await scene(p, 'inv3', async () => { await zoom(p, '[data-chips]', 1.7); await wait(p, 3000); unzoom(); await spot(p, '.ps-table--list tbody'); });
	await unspot(p);

	// Livraison.
	await go(p, '#/livraison');
	await scene(p, 'deliv', async () => { await click(p, '[data-chip="delivery"][data-value="late"]', { after: 1200 }); await zoom(p, '.ps-table--list tbody tr.is-late', 1.7); });
	unzoom();

	// Tâche.
	await go(p, '#/taches');
	await scene(p, 'task1', async () => {
		await click(p, '[data-new-task]', { after: 1200 });
		await zoom(p, '.ps-modal__box', 1.4);
		const emp = await p.$eval('.ps-modal select[name=employeeId] option:nth-child(3)', (o) => o.value);
		await moveTo(p, '.ps-modal select[name=employeeId]');
		await p.selectOption('.ps-modal select[name=employeeId]', emp);
		await wait(p, 600);
		await type(p, '.ps-modal input[name=title]', 'جهّز عرض الأسعار لمجمع النخيل', 50);
		await moveTo(p, '.ps-modal [data-voice-rec]');
	});
	await scene(p, 'task2', async () => { unzoom(); await click(p, '.ps-modal [data-btn=save]', { after: 1500 }); });

	await wait(p, 500);
	log.segments.push({ seg: 'pc-end', t: now() });
	await ctx.close();
}

/* ---------- téléphone ---------- */
async function mobile(browser) {
	const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1080 / 844 * 1.0 });
	await ctx.addInitScript(OVERLAY);
	await ctx.addInitScript(() => { document.addEventListener('DOMContentLoaded', () => { const st = document.createElement('style'); st.textContent = '#vid-cap,#vid-step{display:none!important} .ps-toasts{top:84px!important;bottom:auto!important;left:50%!important;right:auto!important;inset-inline-start:auto!important;transform:translateX(-50%)}'; document.head.appendChild(st); }); });
	const p = await ctx.newPage();
	await p.goto(BASE + '/');
	await p.fill('input[name=log]', 'masoul'); await p.fill('input[name=pwd]', PASS);
	await Promise.all([p.waitForLoadState('networkidle'), p.click('button[type=submit]')]);
	await p.waitForSelector('#ps-tabbar a'); await wait(p, 800);
	log.segments.push({ seg: 'mob', t: now() });
	await startCapture(p, 'mob');
	await scene(p, 'mobile', async () => {
		await wait(p, 1500);
		await click(p, '#ps-tabbar a[href="#/projets"]', { after: 2200 });
		await click(p, '#ps-tabbar a[href="#/offres"]', { after: 2200 });
		await click(p, '#ps-tabbar .ps-tabbar__a--add', { after: 2500 });
		await click(p, '#ps-tabbar a[href="#/taches"]', { after: 1500 });
	});
	await wait(p, 800);
	log.segments.push({ seg: 'mob-end', t: now() });
	await ctx.close();
}

/* ---------- fin ---------- */
async function ending(browser) {
	const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: DPR });
	await ctx.addInitScript(OVERLAY);
	const p = await ctx.newPage();
	await p.goto(BASE + '/');
	await card(p, 'ملخّص يوم المسؤول', ['1. افتح «مهامي» وعالج سطر «اليوم»', '2. سجّل كل طلب جديد بـ«⚡ إدخال سريع» ثم قرّر «مشروع؟»', '3. تابع العروض والفواتير والتسليمات'], 900);
	log.segments.push({ seg: 'end', t: now() });
	await startCapture(p, 'end');
	await scene(p, 'outro');
	await wait(p, 1200);
	log.segments.push({ seg: 'end-end', t: now() });
	await ctx.close();
}

(async () => {
	const browser = await chromium.launch();
	await desktop(browser);
	await mobile(browser);
	await ending(browser);
	await browser.close();
	fs.writeFileSync(OUT + '/log.json', JSON.stringify(log));
	console.log('ok', log.frames.length, 'images', log.scenes.length, 'scènes', log.zooms.length, 'zooms');
})().catch((e) => { fs.writeFileSync(OUT + '/log.json', JSON.stringify(log)); console.error(e); process.exit(1); });
