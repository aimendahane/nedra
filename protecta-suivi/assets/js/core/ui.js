/**
 * Petits outils d'interface réutilisés par toutes les pages.
 *   PS.ui.esc(texte)            → texte échappé pour du HTML (TOUJOURS l'utiliser pour les données)
 *   PS.ui.html`...${valeur}...` → gabarit qui échappe automatiquement les valeurs
 *   PS.ui.raw(html)             → marque un HTML comme sûr dans PS.ui.html
 *   PS.ui.toast(msg, type)      → notification temporaire (type : info | success | error)
 *   PS.ui.empty(icone, texte)   → bloc « état vide »
 *   PS.ui.badge(texte, couleur) → pastille colorée
 *   PS.ui.modal({...})          → fenêtre modale (Promise résolue avec l'id du bouton cliqué)
 *   PS.ui.confirm(texte)        → Promise<boolean>
 *   PS.ui.autocomplete(input, {...}) → liste de suggestions sous un champ
 *   PS.ui.showErrors(root, errors)   → affiche les erreurs { "clé": "message" } sous les champs [data-key]
 *   PS.ui.getPath / setPath     → lire / écrire obj["a.b.0.c"]
 *   PS.ui.debounce(fn, ms)
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
	Raw.prototype.toString = function () { return this.html; };
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
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');
		el.textContent = message;
		box.appendChild(el);
		while (box.children.length > 3) box.firstChild.remove(); // 3 notifications au plus
		setTimeout(function () { el.remove(); }, type === 'error' ? 7000 : 4000);
	}

	function empty(icon, text) {
		return html`<div class="ps-empty"><span class="ps-empty__icon" aria-hidden="true">${icon}</span>${text}</div>`;
	}

	function badge(text, color) {
		return html`<span class="ps-badge">${color ? raw('<span class="ps-dot" style="background:' + esc(color) + '"></span>') : ''}${text}</span>`;
	}

	/* ---------- Modale ---------- */

	/**
	 * @param {Object} o { title, body (Raw|texte), buttons: [{id, label, kind}], wide }
	 * @return Promise<string|null> id du bouton (null si fermée)
	 */
	function modal(o) {
		return new Promise(function (resolve) {
			const wrap = document.createElement('div');
			wrap.className = 'ps-modal';
			wrap.innerHTML = html`<div class="ps-modal__box ${o.wide ? 'ps-modal__box--wide' : ''}" role="dialog" aria-modal="true" aria-labelledby="ps-modal-title">
				<div class="ps-modal__head"><h2 id="ps-modal-title">${o.title || ''}</h2><button type="button" class="ps-icon-btn" data-close aria-label="${PS.t('إغلاق')}">✕</button></div>
				<div class="ps-modal__body">${o.body || ''}</div>
				<div class="ps-modal__foot">${(o.buttons || [{ id: 'ok', label: PS.t('حسنًا'), kind: 'primary' }]).map(function (b) {
					return html`<button type="button" class="ps-btn ${b.kind ? 'ps-btn--' + b.kind : ''}" data-btn="${b.id}">${b.label}</button>`;
				})}</div></div>`.html;
			const prevFocus = document.activeElement;
			function close(result) {
				wrap.remove();
				document.removeEventListener('keydown', onKey);
				if (prevFocus && prevFocus.focus) prevFocus.focus();
				resolve(result);
			}
			function onKey(e) { if (e.key === 'Escape') close(null); }
			wrap.addEventListener('click', function (e) {
				if (e.target === wrap || e.target.closest('[data-close]')) return close(null);
				const b = e.target.closest('[data-btn]');
				if (!b) return;
				if (o.onButton && o.onButton(b.dataset.btn, wrap, close) === false) return; // false = rester ouverte (close() pour fermer plus tard)
				close(b.dataset.btn);
			});
			document.addEventListener('keydown', onKey);
			document.body.appendChild(wrap);
			const first = wrap.querySelector('input, select, textarea, [data-btn]');
			if (first) first.focus();
			if (o.onOpen) o.onOpen(wrap);
		});
	}

	function confirm(text, okLabel, kind) {
		return modal({
			title: PS.t('تأكيد'),
			body: html`<p>${text}</p>`,
			buttons: [{ id: 'ok', label: okLabel || PS.t('تأكيد'), kind: kind || 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
		}).then(function (r) { return r === 'ok'; });
	}

	/* ---------- Autocomplétion ---------- */

	/**
	 * @param {HTMLInputElement} input
	 * @param {Object} o {
	 *   source(q) → Promise<[{ id, label, sub }]>,
	 *   onSelect(item),              // choix d'une suggestion
	 *   newLabel(q) → texte          // option « + إضافة … » (facultatif)
	 *   onNew(q),
	 *   minLength (défaut 1)
	 * }
	 */
	function autocomplete(input, o) {
		const list = document.createElement('ul');
		list.className = 'ps-ac';
		list.setAttribute('role', 'listbox');
		list.hidden = true;
		input.setAttribute('autocomplete', 'off');
		input.setAttribute('aria-autocomplete', 'list');
		input.parentNode.style.position = 'relative';
		input.parentNode.appendChild(list);
		let items = [];
		let active = -1;
		let seq = 0;

		function hide() { list.hidden = true; active = -1; }
		function render() {
			const q = input.value.trim();
			let h = items.map(function (it, i) {
				return html`<li role="option" data-i="${i}" class="${i === active ? 'is-active' : ''}"><strong>${it.label}</strong>${it.sub ? html`<span class="ps-muted"> · ${it.sub}</span>` : ''}</li>`.html;
			}).join('');
			if (o.newLabel && q && !items.some(function (it) { return it.label.trim() === q; })) {
				h += html`<li role="option" data-i="new" class="ps-ac__new ${active === items.length ? 'is-active' : ''}">${o.newLabel(q)}</li>`.html;
			}
			list.innerHTML = h;
			list.hidden = !h;
		}
		const search = debounce(function () {
			const q = input.value.trim();
			if (q.length < (o.minLength || 1)) { items = []; hide(); return; }
			const my = ++seq;
			o.source(q).then(function (res) {
				if (my !== seq) return;
				items = res || [];
				active = -1;
				render();
			}).catch(function () {});
		}, 200);

		function choose(i) {
			if (i === 'new' || i === items.length) { hide(); if (o.onNew) o.onNew(input.value.trim()); return; }
			const it = items[i];
			if (!it) return;
			input.value = it.label;
			hide();
			o.onSelect(it);
		}

		input.addEventListener('input', search);
		input.addEventListener('keydown', function (e) {
			if (list.hidden) return;
			const max = items.length + (o.newLabel ? 1 : 0);
			if (e.key === 'ArrowDown') { active = (active + 1) % max; render(); e.preventDefault(); }
			else if (e.key === 'ArrowUp') { active = (active - 1 + max) % max; render(); e.preventDefault(); }
			else if (e.key === 'Enter' && active >= 0) { choose(active); e.preventDefault(); }
			else if (e.key === 'Escape') hide();
		});
		input.addEventListener('blur', function () { setTimeout(hide, 150); });
		list.addEventListener('mousedown', function (e) {
			const li = e.target.closest('li');
			if (!li) return;
			e.preventDefault();
			choose(li.dataset.i === 'new' ? 'new' : Number(li.dataset.i));
		});
	}

	/* ---------- Erreurs de formulaire ---------- */

	/** Affiche les erreurs sous les éléments [data-key="clé"] ; renvoie le premier élément en erreur. */
	function showErrors(root, errors) {
		root.querySelectorAll('.ps-field__error').forEach(function (e) { e.remove(); });
		root.querySelectorAll('.ps-field--error').forEach(function (e) { e.classList.remove('ps-field--error'); });
		let first = null;
		const general = [];
		Object.keys(errors || {}).forEach(function (key) {
			const target = root.querySelector('[data-key="' + key.replace(/"/g, '') + '"]');
			if (!target) { general.push(errors[key]); return; }
			const field = target.closest('.ps-field') || target.parentNode;
			field.classList.add('ps-field--error');
			const msg = document.createElement('span');
			msg.className = 'ps-field__error';
			msg.textContent = errors[key];
			field.appendChild(msg);
			if (!first) first = target;
		});
		const box = root.querySelector('[data-errors]');
		if (box) {
			box.innerHTML = general.length ? html`<div class="ps-alert ps-alert--error">${general.map(function (g) { return html`<div>${g}</div>`; })}</div>`.html : '';
			if (general.length && !first) first = box;
		}
		if (first) {
			first.scrollIntoView({ behavior: 'smooth', block: 'center' });
			if (first.focus) first.focus({ preventScroll: true });
		}
		return first;
	}

	/* ---------- Divers ---------- */

	function getPath(obj, path) {
		return path.split('.').reduce(function (o, k) { return o === null || o === undefined ? undefined : o[k]; }, obj);
	}
	function setPath(obj, path, value) {
		const keys = path.split('.');
		let o = obj;
		for (let i = 0; i < keys.length - 1; i++) {
			if (o[keys[i]] === null || typeof o[keys[i]] !== 'object') o[keys[i]] = /^\d+$/.test(keys[i + 1]) ? [] : {};
			o = o[keys[i]];
		}
		o[keys[keys.length - 1]] = value;
	}
	function debounce(fn, ms) {
		let t;
		return function () {
			const args = arguments, self = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(self, args); }, ms);
		};
	}

	/**
	 * Projet dans une liste (1.5.0) : le NOM d'abord (en gras), puis le numéro en plus petit
	 * (PRJ-… ou P029, + l'ancien numéro d'un projet importé). Lien vers la fiche sauf link: false.
	 */
	function projectTitle(p, opts) {
		const o = opts || {};
		const no = [p.no, p.oldNo].filter(Boolean).join(' · ');
		const inner = html`<strong class="ps-ptitle__name">${p.name || PS.t('(بدون اسم)')}</strong><span class="ps-ptitle__no" dir="ltr">${no}</span>`;
		return o.link === false ? html`<span class="ps-ptitle">${inner}</span>` : html`<a class="ps-ptitle" href="#/projet?id=${p.id}${o.hash || ''}">${inner}</a>`;
	}

	return { esc, html, raw, toast, projectTitle, empty, badge, modal, confirm, autocomplete, showErrors, getPath, setPath, debounce };
})();
