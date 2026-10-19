/**
 * Listes déroulantes (chargées une fois depuis GET /lists) et aides d'affichage.
 *
 *   PS.lists.load()                 → Promise (à attendre avant d'afficher une page)
 *   PS.lists.row(id) / label(id)    → valeur de liste par identifiant
 *   PS.lists.short(id)              → code pour lieux/urgences (T, BG, VU…), sinon libellé
 *   PS.lists.values(key)            → valeurs d'une liste (masquées comprises)
 *   PS.lists.byCode(key, code)      → valeur par code (statuts d'offre, livraison…)
 *   PS.lists.options(key, current)  → <option> pour un <select> : valeurs visibles + la valeur
 *                                     actuelle même si elle a été masquée depuis
 *   PS.lists.badge(id)              → pastille colorée
 */
window.PS = window.PS || {};

PS.lists = (function () {
	'use strict';

	let data = null;
	let byId = {};

	function index(d) {
		data = d;
		byId = {};
		Object.keys(d.lists).forEach(function (k) {
			d.lists[k].forEach(function (v) { byId[v.id] = v; });
		});
		return d;
	}

	function load(force) {
		if (data && !force) return Promise.resolve(data);
		return PS.router.lists(force).then(index);
	}

	function row(id) { return id ? byId[id] || null : null; }
	function label(id) { const r = row(id); return r ? r.label : ''; }
	function code(id) { const r = row(id); return r ? r.code : null; }
	function short(id) {
		const r = row(id);
		if (!r) return '';
		return (r.listKey === 'place' || r.listKey === 'urgency') && r.code ? r.code : r.label;
	}
	function values(key) { return (data && data.lists[key]) || []; }
	function byCode(key, c) { return values(key).find(function (v) { return v.code === c; }) || null; }
	function title(key) { return (data && data.labels[key]) || key; }
	function followupType(id) { const r = row(id); return r ? r.type : null; }

	/** Texte d'une option : lieux « T – Tripoli », autres : libellé. */
	function optionText(v) {
		return v.listKey === 'place' && v.code ? v.code + ' – ' + v.label : v.label;
	}

	function options(key, current, opts) {
		const o = opts || {};
		const esc = PS.ui.esc;
		let html = o.noEmpty ? '' : '<option value="">' + esc(o.emptyLabel || '—') + '</option>';
		values(key).forEach(function (v) {
			const isCur = String(v.id) === String(current);
			if (v.hidden && !isCur) return;
			html += '<option value="' + v.id + '"' + (isCur ? ' selected' : '') + '>' + esc(optionText(v)) + (v.hidden ? ' (مخفي)' : '') + '</option>';
		});
		return html;
	}

	function badge(id, text) {
		const r = row(id);
		if (!r) return PS.ui.raw('');
		return PS.ui.badge(text || short(id), r.color);
	}

	/** Pastille d'un statut d'offre par code. */
	function offerBadge(c) {
		const r = byCode('offer_status', c);
		return r ? PS.ui.badge(r.label, r.color) : PS.ui.raw('');
	}

	/** Ajoute une valeur reçue du serveur (ajout rapide) sans tout recharger. */
	function add(value) {
		if (!data) return;
		const list = data.lists[value.listKey] || (data.lists[value.listKey] = []);
		if (!byId[value.id]) list.push(value);
		byId[value.id] = value;
	}

	return { load, row, label, code, short, values, byCode, title, followupType, options, optionText, badge, offerBadge, add };
})();
