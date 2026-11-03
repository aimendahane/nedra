// Sous-titres de la vidéo v2 rendus en PNG transparents (le navigateur gère bien l'arabe), un par scène.
// Usage : node captions.js <dossier de sortie> <scenes.json>
const { chromium } = require('playwright');
const fs = require('fs');
const OUT = process.argv[2];
const SCENES = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const FONT = '/home/user/nedra/protecta-suivi/assets/fonts/cairo-arabic-700-normal.woff2';
(async () => {
	fs.mkdirSync(OUT + '/caps', { recursive: true });
	const b = await chromium.launch();
	const p = await (await b.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 })).newPage();
	const font = fs.readFileSync(FONT).toString('base64');
	for (const [id, s] of Object.entries(SCENES)) {
		await p.setContent(`<!doctype html><html dir="rtl"><head><style>
			@font-face{font-family:Cairo;src:url(data:font/woff2;base64,${font}) format('woff2');font-weight:700}
			html,body{margin:0;background:transparent}
			#c{position:absolute;left:50%;bottom:44px;transform:translateX(-50%);max-width:1500px;width:max-content;
			  background:rgba(14,22,38,.90);color:#fff;font:700 38px/1.5 Cairo,sans-serif;text-align:center;
			  padding:16px 40px 18px;border-radius:18px;border-bottom:6px solid #E8571F;box-shadow:0 12px 40px rgba(0,0,0,.35)}
		</style></head><body><div id="c"></div></body></html>`);
		await p.$eval('#c', (e, t) => { e.textContent = t; }, s.cap);
		await p.evaluate(() => document.fonts.ready);
		await p.screenshot({ path: `${OUT}/caps/${id}.png`, omitBackground: true });
	}
	await b.close();
	console.log('captions ok');
})();
