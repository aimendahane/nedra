/**
 * Routeur de l'application (navigation par « #/page »), menu et démarrage.
 *
 * Chaque page (assets/js/pages/*.js) s'inscrit ainsi :
 *   PS.router.register({
 *     id: 'offres',               // adresse : #/offres
 *     title: 'متابعة العروض',      // titre du menu et de la page
 *     icon: '📨',
 *     cap: 'ps_access',           // capacité nécessaire pour voir la page (affichage seulement ;
 *                                 // le serveur revérifie chaque donnée)
 *     order: 30,                  // position dans le menu
 *     render: function (main, params) { ... }   // params : ce qui suit « ? » dans l'adresse
 *   });
 *
 * Options facultatives d'une page :
 *   capCheck(params) → bool : accès selon l'adresse (ex. un projet existant est lisible par tous)
 *   leave() → bool          : false pour rester sur la page (modifications non enregistrées)
 *
 * PS.router.go('offres', { statut: 'late' }) ouvre une page avec des filtres.
 * PS.router.setParams({...}) met à jour les filtres dans l'adresse sans recharger la page.
 * PS.router.setCount('offres', 3) affiche un compteur rouge dans le menu.
 */
window.PS = window.PS || {};

PS.router = (function () {
	'use strict';

	const pages = [];
	const counts = {};
	const DEFAULT_PAGE = 'taches';
	let current = null;     // page affichée
	let currentHash = '';   // adresse affichée (pour annuler une sortie refusée)

	function register(page) { pages.push(page); pages.sort(function (a, b) { return a.order - b.order; }); }

	function can(cap) { return !cap || !!(PS.config.user.caps || {})[cap]; }

	function visiblePages() { return pages.filter(function (p) { return can(p.cap); }); }

	function parseHash() {
		const h = (window.location.hash || '').replace(/^#\/?/, '');
		const parts = h.split('?');
		const params = {};
		new URLSearchParams(parts[1] || '').forEach(function (v, k) { params[k] = v; });
		return { id: parts[0] || DEFAULT_PAGE, params: params };
	}

	function go(id, params) {
		const qs = params ? new URLSearchParams(params).toString() : '';
		window.location.hash = '#/' + id + (qs ? '?' + qs : '');
	}

	function renderNav(currentId) {
		const nav = document.getElementById('ps-nav');
		const html = PS.ui.html;
		const list = visiblePages();
		nav.innerHTML = list.map(function (p, i) {
			const sep = i > 0 && p.group !== list[i - 1].group ? '<div class="ps-nav__sep"></div>' : '';
			const count = counts[p.id] ? html`<span class="ps-nav__count">${counts[p.id]}</span>` : '';
			return sep + html`<a class="ps-nav__link" href="#/${p.id}" ${PS.ui.raw(p.id === currentId ? 'aria-current="page"' : '')}><span class="ps-nav__icon" aria-hidden="true">${p.icon}</span><span>${p.title}</span>${count}</a>`.html;
		}).join('');
	}

	function setCount(id, n) {
		counts[id] = n > 0 ? n : 0;
		renderNav(parseHash().id);
	}

	/**
	 * Compteurs du menu (« لازم قرار »…). Appel « passif » : ne prolonge pas la session.
	 * Rafraîchis au démarrage, toutes les 5 minutes et après chaque modification d'un صنف.
	 */
	function refreshCounts() {
		return PS.api.get('counters', { passive: true }).then(function (c) {
			counts.offres = c.offres || 0;
			counts.livraison = c.livraison || 0;
			counts.taches = c.taches || 0;
			renderNav(parseHash().id);
		}).catch(function () {});
	}

	function closeMobileNav() {
		document.getElementById('ps-nav').classList.remove('is-open');
		document.getElementById('ps-nav-backdrop').classList.remove('is-open');
		document.getElementById('ps-menu-toggle').setAttribute('aria-expanded', 'false');
	}

	/** Change les paramètres de l'adresse sans recharger la page (filtres). */
	function setParams(params) {
		const clean = {};
		Object.keys(params || {}).forEach(function (k) {
			if (params[k] !== '' && params[k] !== null && params[k] !== undefined && params[k] !== false) clean[k] = params[k];
		});
		const qs = new URLSearchParams(clean).toString();
		currentHash = '#/' + parseHash().id + (qs ? '?' + qs : '');
		history.replaceState(null, '', currentHash);
	}

	function route() {
		const r = parseHash();
		if (current && current.leave && window.location.hash !== currentHash && !current.leave()) {
			history.replaceState(null, '', currentHash);
			return;
		}
		let page = pages.find(function (p) { return p.id === r.id && (p.capCheck ? p.capCheck(r.params) : can(p.cap)); });
		if (!page) {
			page = visiblePages()[0];
			if (!page) return;
			if (r.id !== page.id) { go(page.id); return; }
		}
		current = page;
		currentHash = window.location.hash;
		renderNav(page.id);
		closeMobileNav();
		document.title = page.title + ' — متابعة المشاريع';
		const main = document.getElementById('ps-main');
		// Conteneur NEUF à chaque page : les écouteurs d'événements posés par la page
		// précédente disparaissent avec son conteneur (pas d'accumulation).
		const view = document.createElement('div');
		view.className = 'ps-view';
		view.innerHTML = '<div class="ps-loading" role="status">جاري التحميل…</div>';
		main.replaceChildren(view);
		try {
			const res = page.render(view, r.params);
			if (res && typeof res.catch === 'function') {
				res.catch(function (e) { view.innerHTML = PS.ui.html`<div class="ps-alert ps-alert--error">${e.message}</div>`.html; });
			}
		} catch (e) {
			view.innerHTML = PS.ui.html`<div class="ps-alert ps-alert--error">${e.message}</div>`.html;
		}
		main.focus({ preventScroll: true });
		window.scrollTo(0, 0);
	}

	/** Listes déroulantes, chargées une fois puis gardées en mémoire. */
	let listsPromise = null;
	function lists(force) {
		if (!listsPromise || force) listsPromise = PS.api.get('lists');
		return listsPromise;
	}

	function boot() {
		const toggle = document.getElementById('ps-menu-toggle');
		const nav = document.getElementById('ps-nav');
		const backdrop = document.getElementById('ps-nav-backdrop');
		toggle.addEventListener('click', function () {
			const open = !nav.classList.contains('is-open');
			nav.classList.toggle('is-open', open);
			backdrop.classList.toggle('is-open', open);
			backdrop.hidden = !open;
			toggle.setAttribute('aria-expanded', String(open));
		});
		backdrop.addEventListener('click', closeMobileNav);

		PS.session.init();
		window.addEventListener('hashchange', route);
		route();
		refreshCounts();
		setInterval(refreshCounts, 5 * 60 * 1000);
	}

	document.addEventListener('DOMContentLoaded', boot);

	/** Recharge la page courante et les compteurs du menu (après une action). */
	function refresh() {
		route();
		refreshCounts();
	}

	return { register, go, setParams, setCount, refreshCounts, refresh, can, lists, route, params: function () { return parseHash().params; } };
})();
