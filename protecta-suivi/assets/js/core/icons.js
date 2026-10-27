/**
 * Icônes de l'interface (design 1.1.0) — SVG dessinés pour le thème, aucun fichier externe.
 * Trait 2 px, 24×24, couleur = celle du texte (currentColor).
 *
 *   PS.icons.svg('folder')        → chaîne HTML <svg>…</svg> (sûre : aucune donnée utilisateur)
 *   PS.icons.page(id)             → icône d'une page du menu (null si inconnue)
 */
window.PS = window.PS || {};

PS.icons = (function () {
	'use strict';

	const P = {
		check: '<path d="M9 11l3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>',
		plus: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
		folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
		send: '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
		truck: '<path d="M2 6h11v10H2z"/><path d="M13 9h4l4 4v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
		chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		building: '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10"/><path d="M8 7h4M8 11h4M8 15h4M2 21h20"/>',
		settings: '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
		bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
		sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
		auto: '<circle cx="12" cy="12" r="9"/><path d="M12 3v18" /><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/>',
		menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
		invoice: '<path d="M6 2h9l5 5v15l-3-2-3 2-3-2-3 2-2-2V2z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
		logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
	};

	const PAGES = { taches: 'check', projet: 'plus', projets: 'folder', offres: 'send', livraison: 'truck', factures: 'invoice', recap: 'chart', annuaire: 'building', parametres: 'settings' };

	function svg(name, cls) {
		if (!P[name]) return '';
		return '<svg class="ps-svg ' + (cls || '') + '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + P[name] + '</svg>';
	}

	function page(id) { return PAGES[id] ? svg(PAGES[id]) : null; }

	return { svg, page };
})();
