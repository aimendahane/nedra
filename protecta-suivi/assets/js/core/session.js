/**
 * Session côté navigateur :
 *  - déconnexion après inactivité (durée réglée dans الإعدادات, 2 h par défaut) ;
 *    le serveur applique aussi la même règle, ceci ne fait qu'anticiper l'affichage ;
 *  - « ping » au serveur au plus toutes les 5 min SI l'utilisateur a été actif ;
 *  - bouton « خروج » et bascule mode nuit (auto → nuit → jour).
 */
window.PS = window.PS || {};

PS.session = (function () {
	'use strict';

	const PING_EVERY_MS = 5 * 60 * 1000;
	let lastActivity = Date.now();
	let lastPing = Date.now();
	let redirecting = false;

	function limitMs() {
		return Math.max(1, Number(PS.config.settings.inactivityMinutes) || 120) * 60 * 1000;
	}

	function goLogin(msg) {
		if (redirecting) return;
		redirecting = true;
		window.location.href = PS.config.homeUrl + (msg ? '?ps_msg=' + encodeURIComponent(msg) : '');
	}

	function expired(msg) { goLogin(msg || 'expired'); }

	function logout() {
		PS.api.post('session/logout').catch(function () {}).then(function () { goLogin('loggedout'); });
	}

	function onActivity() {
		lastActivity = Date.now();
		if (Date.now() - lastPing > PING_EVERY_MS) {
			lastPing = Date.now();
			PS.api.post('session/ping').catch(function () {});
		}
	}

	function watch() {
		['click', 'keydown', 'touchstart', 'scroll', 'mousemove'].forEach(function (ev) {
			window.addEventListener(ev, onActivity, { passive: true });
		});
		setInterval(function () {
			if (Date.now() - lastActivity > limitMs()) logout();
		}, 30 * 1000);
	}

	/* ---- Mode nuit ---- */
	const THEMES = ['auto', 'dark', 'light'];
	const THEME_LABELS = { auto: PS.t('تلقائي (حسب الجهاز)'), dark: PS.t('الوضع الليلي'), light: PS.t('الوضع النهاري') };
	const THEME_ICONS = { auto: 'auto', dark: 'moon', light: 'sun' };

	function showThemeIcon(theme) {
		const icon = document.getElementById('ps-theme-icon');
		if (icon) icon.innerHTML = PS.icons.svg(THEME_ICONS[theme] || THEME_ICONS.auto);
		const btn = document.getElementById('ps-theme-toggle');
		if (btn) btn.title = THEME_LABELS[theme] + PS.t(' — اضغط للتغيير');
	}

	function setTheme(theme) {
		document.documentElement.setAttribute('data-theme', theme);
		PS.config.user.prefs.theme = theme;
		showThemeIcon(theme);
		window.dispatchEvent(new CustomEvent('ps:theme', { detail: theme })); // ex. Recap : redessiner les graphiques
		PS.api.post('me/prefs', { theme: theme }).catch(function () {});
		PS.ui.toast(THEME_LABELS[theme]);
	}

	/* ---- Langue de l'interface (1.3.0) ---- */
	function initLang() {
		const btn = document.getElementById('ps-lang');
		const panel = document.getElementById('ps-lang-panel');
		if (!btn || !panel) return;
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			panel.hidden = !panel.hidden;
			btn.setAttribute('aria-expanded', String(!panel.hidden));
		});
		panel.addEventListener('click', function (e) {
			const b = e.target.closest('[data-lang]');
			if (!b || b.dataset.lang === PS.i18n.lang) { panel.hidden = true; return; }
			// Enregistré sur le compte, puis rechargement : textes, sens d'écriture et graphiques changent ensemble.
			PS.api.post('me/prefs', { lang: b.dataset.lang }).then(function () { window.location.reload(); })
				.catch(function (er) { PS.ui.toast(er.message, 'error'); });
		});
		document.addEventListener('click', function () { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); });
	}

	function init() {
		watch();
		initLang();
		const btnLogout = document.getElementById('ps-logout');
		if (btnLogout) btnLogout.addEventListener('click', logout);
		const btnTheme = document.getElementById('ps-theme-toggle');
		showThemeIcon(PS.config.user.prefs.theme || 'auto');
		if (btnTheme) {
			btnTheme.addEventListener('click', function () {
				const cur = PS.config.user.prefs.theme || 'auto';
				setTheme(THEMES[(THEMES.indexOf(cur) + 1) % THEMES.length]);
			});
		}
	}

	return { init, logout, expired, setTheme };
})();
