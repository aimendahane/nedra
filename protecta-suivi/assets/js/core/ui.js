/**
 * Petits outils d'interface réutilisés par toutes les pages.
 *   PS.ui.esc(texte)            → texte échappé pour du HTML (TOUJOURS l'utiliser pour les données)
 *   PS.ui.html`...${valeur}...` → gabarit qui échappe automatiquement les valeurs
 *   PS.ui.raw(html)             → marque un HTML comme sûr dans PS.ui.html
 *   PS.ui.toast(msg, type)      → notification temporaire (type : info | success | error)
 *   PS.ui.empty(icone, texte)   → bloc « état vide »
 *   PS.ui.badge(texte, couleur) → pastille colorée
 */
window.PS = window.PS || {};

PS.ui = (function () {
	'use strict';

	function esc(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function Raw(html) { this.html = html; }
	function raw(html) { return new Raw(html); }

	function val(v) {
		if (v instanceof Raw) return v.html;
		if (Array.isArray(v)) return v.map(val).join('');
		return esc(v);
	}

	/** Gabarit HTML : les ${valeurs} sont échappées sauf si raw()/tableaux de raw. */
	function html(strings) {
		let out = strings[0];
		for (let i = 1; i < strings.length; i++) {
			out += val(arguments[i]) + strings[i];
		}
		return raw(out);
	}

	function toast(message, type) {
		const box = document.getElementById('ps-toasts');
		if (!box) return;
		const el = document.createElement('div');
		el.className = 'ps-toast ps-toast--' + (type || 'info');
		el.textContent = message;
		box.appendChild(el);
		setTimeout(function () { el.remove(); }, type === 'error' ? 7000 : 4000);
	}

	function empty(icon, text) {
		return html`<div class="ps-empty"><span class="ps-empty__icon" aria-hidden="true">${icon}</span>${text}</div>`;
	}

	function badge(text, color) {
		return html`<span class="ps-badge">${color ? raw('<span class="ps-dot" style="background:' + esc(color) + '"></span>') : ''}${text}</span>`;
	}

	return { esc, html, raw, toast, empty, badge };
})();
