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

	/**
	 * Notification temporaire. 1.12.0 : opts.action + opts.onAction → bouton dans la notification (ex. « تراجع » après
	 * un changement rapide, 8 s) ; opts.ms = durée d'affichage.
	 */
	function toast(message, type, opts) {
		const box = document.getElementById('ps-toasts');
		if (!box) return;
		const o = opts || {};
		const el = document.createElement('div');
		el.className = 'ps-toast ps-toast--' + (type || 'info');
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');
		const txt = document.createElement('span');
		txt.textContent = message;
		el.appendChild(txt);
		if (o.action && o.onAction) {
			el.classList.add('ps-toast--action');
			const b = document.createElement('button');
			b.type = 'button';
			b.className = 'ps-toast__btn';
			b.setAttribute('data-toast-action', '');
			b.textContent = o.action;
			b.addEventListener('click', function () { el.remove(); o.onAction(); });
			el.appendChild(b);
		}
		box.appendChild(el);
		while (box.children.length > 3) box.firstChild.remove(); // 3 notifications au plus
		setTimeout(function () { el.remove(); }, o.ms || (o.action ? 8000 : (type === 'error' ? 7000 : 4000)));
	}

	/** 1.12.0 : en changeant de page, les messages d'ERREUR de la page précédente disparaissent (ils prêtaient à confusion). */
	function clearErrorToasts() {
		document.querySelectorAll('#ps-toasts .ps-toast--error').forEach(function (t) { t.remove(); });
	}

	/** 1.12.0 : notification « تم ✔ — تراجع » après un changement rapide (undo() remet l'ancienne valeur). */
	function undoToast(message, undo) {
		toast(message, 'success', { action: PS.t('↩ تراجع'), onAction: undo });
	}

	function empty(icon, text) {
		return html`<div class="ps-empty"><span class="ps-empty__icon" aria-hidden="true">${icon}</span>${text}</div>`;
	}

	function badge(text, color) {
		return html`<span class="ps-badge">${color ? raw('<span class="ps-dot" style="background:' + esc(color) + '"></span>') : ''}${text}</span>`;
	}

	/* ---------- Modale ---------- */

	/**
	 * @param {Object} o { title, body (Raw|texte), buttons: [{id, label, kind}], wide, drawer }
	 *   drawer : true → panneau latéral (1.7.0 : détails d'une ligne de liste, plein écran sur téléphone).
	 * @return Promise<string|null> id du bouton (null si fermée)
	 */
	function modal(o) {
		return new Promise(function (resolve) {
			const wrap = document.createElement('div');
			wrap.className = 'ps-modal' + (o.drawer ? ' ps-modal--drawer' : '');
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

	/**
	 * Affiche les erreurs sous les éléments [data-key="clé"] ; renvoie le premier élément en erreur.
	 * 1.12.0 : si le formulaire a une zone [data-errors], elle montre un RÉSUMÉ « ينقص N خانات » dont chaque nom est un
	 * lien qui amène directement au champ (même s'il est plus bas ou dans « المزيد »).
	 */
	function showErrors(root, errors) {
		root.querySelectorAll('.ps-field__error').forEach(function (e) { e.remove(); });
		root.querySelectorAll('.ps-field--error').forEach(function (e) { e.classList.remove('ps-field--error'); });
		let first = null;
		const general = [];
		const list = [];
		Object.keys(errors || {}).forEach(function (key) {
			const target = root.querySelector('[data-key="' + key.replace(/"/g, '') + '"]');
			if (!target) { general.push(errors[key]); return; }
			const field = target.closest('.ps-field') || target.parentNode;
			field.classList.add('ps-field--error');
			// 1.7.0 : un champ en erreur dans un bloc « المزيد » replié → ouvrir le bloc.
			for (let det = field.closest('details'); det; det = det.parentElement && det.parentElement.closest('details')) det.open = true;
			const msg = document.createElement('span');
			msg.className = 'ps-field__error';
			msg.textContent = errors[key];
			field.appendChild(msg);
			const lab = field.querySelector('.ps-field__label');
			const th = !lab && field.closest('td') ? fieldColumnTitle(field) : '';
			// Champ d'un groupe (ex. « المالك » / « الشركة المنفذة ») : le nom du groupe d'abord.
			const legend = field.closest('fieldset') && field.closest('fieldset').querySelector('legend');
			const group = legend ? legend.textContent.replace(/🔗/g, '').trim() + ': ' : '';
			list.push({ key: key, label: lab ? group + lab.textContent.replace(/[*🔗]/gu, '').trim() : (th || ''), msg: errors[key] });
			if (!first) first = target;
		});
		const box = root.querySelector('[data-errors]');
		if (box) {
			const n = list.length + general.length;
			box.innerHTML = n ? html`<div class="ps-alert ps-alert--error ps-errsum" role="alert">
				<strong>${n === 1 ? PS.t('خانة واحدة تحتاج تصحيحًا:') : PS.t('خانات تحتاج تصحيحًا:') + ' ' + n}</strong>
				<ul>${general.map(function (g) { return html`<li>${g}</li>`; })}${list.map(function (x) {
					const text = String(x.msg).indexOf(x.label) === -1 && x.label ? x.label + ' — ' + x.msg : x.msg;
					return html`<li><button type="button" class="ps-link" data-err-jump="${x.key}">${text}</button></li>`;
				})}</ul></div>`.html : '';
			if (!box.dataset.jumpBound) {
				box.dataset.jumpBound = '1';
				box.addEventListener('click', function (e) {
					const b = e.target.closest('[data-err-jump]');
					if (!b) return;
					const t = root.querySelector('[data-key="' + b.dataset.errJump.replace(/"/g, '') + '"]');
					if (!t) return;
					for (let det = t.closest('details'); det; det = det.parentElement && det.parentElement.closest('details')) det.open = true;
					t.scrollIntoView({ behavior: 'smooth', block: 'center' });
					if (t.focus) t.focus({ preventScroll: true });
				});
			}
			if (n && list.length + general.length > 1) first = box; // plusieurs erreurs : on montre d'abord la liste
			else if (general.length && !first) first = box;
		}
		if (first) {
			first.scrollIntoView({ behavior: 'smooth', block: 'center' });
			if (first.focus && first !== box) first.focus({ preventScroll: true });
		}
		return first;
	}

	/** Titre de colonne d'un champ placé dans un tableau de saisie (ex. « الأجل المطلوب » d'un صنف). */
	function fieldColumnTitle(field) {
		const td = field.closest('td');
		const table = td && td.closest('table');
		if (!table) return '';
		const th = table.querySelectorAll('thead th')[Array.prototype.indexOf.call(td.parentNode.children, td)];
		const row = Array.prototype.indexOf.call(td.parentNode.parentNode.children, td.parentNode) + 1;
		return th ? th.textContent.replace(/\*/g, '').trim() + ' (' + row + ')' : '';
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
		// 1.6.1 (demande du client) : numberFirst → le NUMÉRO en premier (gras), le nom en petit dessous.
		const inner = o.numberFirst
			? html`<strong class="ps-ptitle__num" dir="ltr">${no}</strong><span class="ps-ptitle__sub">${p.name || PS.t('(بدون اسم)')}</span>`
			: html`<strong class="ps-ptitle__name">${p.name || PS.t('(بدون اسم)')}</strong><span class="ps-ptitle__no" dir="ltr">${no}</span>`;
		return o.link === false ? html`<span class="ps-ptitle">${inner}</span>` : html`<a class="ps-ptitle" href="#/projet?id=${p.id}${o.hash || ''}">${inner}</a>`;
	}

	/* ---------- Dates jj/mm/aaaa (1.6.0) ---------- */

	/*
	 * Les champs <input type="date"> affichent la date au format du navigateur (souvent mm/dd/yyyy).
	 * Demande du client : saisie et affichage TOUJOURS en jj/mm/aaaa. Chaque champ date de l'application est donc
	 * transformé automatiquement (MutationObserver) en champ texte jj/mm/aaaa + bouton 📅 (calendrier du navigateur).
	 * Le code des pages ne change pas : la propriété « value » du champ renvoie toujours AAAA-MM-JJ (format de l'API)
	 * et accepte AAAA-MM-JJ quand on la remplit ; seul l'affichage est en jj/mm/aaaa.
	 */
	const NATIVE_VALUE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

	/** « 5/3/2026 », « 05-03-2026 », « 2026-03-05 » → « 2026-03-05 » ; '' si vide ; null si invalide. */
	function parseDate(text) {
		const t = String(text || '').trim();
		if (!t) return '';
		let y, m, d, r;
		if ((r = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/.exec(t))) { d = +r[1]; m = +r[2]; y = +r[3]; }
		else if ((r = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t))) { y = +r[1]; m = +r[2]; d = +r[3]; }
		else if ((r = /^(\d{2})(\d{2})(\d{4})$/.exec(t))) { d = +r[1]; m = +r[2]; y = +r[3]; }
		else return null;
		const dt = new Date(Date.UTC(y, m - 1, d));
		if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
		return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
	}

	function enhanceDate(el) {
		if (el.dataset.psDate) return;
		el.dataset.psDate = '1';
		const iso = NATIVE_VALUE.get.call(el);
		el.type = 'text';
		el.setAttribute('dir', 'ltr');
		el.setAttribute('inputmode', 'numeric');
		el.setAttribute('maxlength', '10');
		el.setAttribute('autocomplete', 'off');
		el.setAttribute('placeholder', PS.t('يوم/شهر/سنة'));
		el.classList.add('ps-date');
		Object.defineProperty(el, 'value', {
			configurable: true,
			get: function () { const raw = NATIVE_VALUE.get.call(this); const v = parseDate(raw); return v === null ? raw : v; },
			set: function (v) { const x = parseDate(v); NATIVE_VALUE.set.call(this, x ? PS.format.date(x) : (v || '')); },
		});
		el.value = iso;
		el.addEventListener('blur', function () {
			const v = parseDate(NATIVE_VALUE.get.call(el));
			el.classList.toggle('is-invalid', v === null);
			if (v) NATIVE_VALUE.set.call(el, PS.format.date(v));
		});
		// Calendrier : champ date natif invisible, ouvert par le bouton 📅.
		const pick = document.createElement('input');
		pick.type = 'date';
		pick.className = 'ps-date__native';
		pick.tabIndex = -1;
		pick.setAttribute('aria-hidden', 'true');
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'ps-date__btn';
		btn.textContent = '📅';
		btn.setAttribute('aria-label', PS.t('اختيار من التقويم'));
		btn.hidden = el.disabled || el.readOnly;
		btn.addEventListener('click', function () {
			pick.value = el.value && parseDate(el.value) ? el.value : '';
			if (pick.showPicker) { try { pick.showPicker(); return; } catch (e) { /* navigateur ancien */ } }
			pick.focus();
			pick.click();
		});
		pick.addEventListener('change', function () {
			el.value = pick.value;
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
		});
		const wrap = document.createElement('span');
		wrap.className = 'ps-date__wrap';
		el.parentNode.insertBefore(wrap, el);
		wrap.appendChild(el);
		wrap.appendChild(btn);
		wrap.appendChild(pick);
	}

	function enhanceDates(root) {
		(root || document).querySelectorAll('input[type="date"]:not(.ps-date__native)').forEach(enhanceDate);
	}

	/* ---------- Zone d'envoi de fichiers en arabe (1.12.0) ---------- */

	/*
	 * Le bouton du navigateur affiche « Choose Files / No file chosen » (langue du navigateur). Chaque champ fichier
	 * visible devient une zone « 📎 اسحب الملف هنا أو اضغط للاختيار » qui accepte aussi le glisser-déposer et liste les
	 * fichiers choisis. Le champ reste le même élément (invisible, posé sur la zone) : le code des pages ne change pas.
	 */
	function enhanceFile(el) {
		if (el.dataset.psFile || el.hidden) return;
		el.dataset.psFile = '1';
		const zone = document.createElement('div');
		zone.className = 'ps-drop';
		const text = document.createElement('span');
		text.className = 'ps-drop__text';
		text.textContent = '📎 ' + (el.multiple ? PS.t('اسحب الملفات هنا أو اضغط للاختيار') : PS.t('اسحب الملف هنا أو اضغط للاختيار'));
		const names = document.createElement('span');
		names.className = 'ps-drop__names';
		names.setAttribute('aria-live', 'polite');
		el.parentNode.insertBefore(zone, el);
		zone.appendChild(el);
		zone.appendChild(text);
		zone.appendChild(names);
		el.classList.add('ps-drop__input');
		el.setAttribute('aria-label', text.textContent);
		const show = function () {
			const files = Array.prototype.map.call(el.files || [], function (f) { return f.name; });
			names.textContent = files.length ? '✔ ' + files.join(PS.t('، ')) : '';
			zone.classList.toggle('has-files', !!files.length);
		};
		el.addEventListener('change', show);
		['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
		['dragleave', 'dragend'].forEach(function (ev) { zone.addEventListener(ev, function () { zone.classList.remove('is-over'); }); });
		zone.addEventListener('drop', function (e) {
			e.preventDefault();
			zone.classList.remove('is-over');
			if (!e.dataTransfer || !e.dataTransfer.files.length) return;
			try { el.files = e.dataTransfer.files; } catch (er) { return; }
			el.dispatchEvent(new Event('change', { bubbles: true }));
		});
	}

	function enhanceAll(n) {
		if (n.matches && n.matches('input[type="date"]:not(.ps-date__native)')) enhanceDate(n);
		else if (n.matches && n.matches('input[type="file"]')) enhanceFile(n);
		else if (n.querySelectorAll) {
			enhanceDates(n);
			n.querySelectorAll('input[type="file"]').forEach(enhanceFile);
		}
	}
	new MutationObserver(function (list) {
		list.forEach(function (m) {
			m.addedNodes.forEach(function (n) { if (n.nodeType === 1) enhanceAll(n); });
		});
	}).observe(document.documentElement, { childList: true, subtree: true });

	/* ---------- Listes courtes (1.7.0) : colonnes au choix + filtres rapides ---------- */

	/**
	 * Colonnes visibles d'une liste : les colonnes « importantes » par défaut, les autres au choix de l'utilisateur
	 * (mémorisé dans ce navigateur). cols = [{ key, label, important }] ; la 1re colonne est toujours visible.
	 */
	function visibleColumns(page, cols) {
		let saved = null;
		try { saved = JSON.parse(localStorage.getItem('ps_cols_' + page) || 'null'); } catch (e) { saved = null; }
		return cols.filter(function (c, i) { return i === 0 || (saved ? saved.indexOf(c.key) !== -1 : c.important); });
	}

	/** Fenêtre « الأعمدة » : cocher les colonnes à afficher. → Promise<bool> (true si changé). */
	function chooseColumns(page, cols) {
		const shown = visibleColumns(page, cols).map(function (c) { return c.key; });
		return modal({
			title: PS.t('الأعمدة الظاهرة'),
			body: html`<p class="ps-small ps-muted">${PS.t('الأعمدة المهمة ظاهرة دائمًا افتراضيًا. اختر ما تريد إضافته (يُحفظ في هذا المتصفح).')}</p>
				<div class="ps-colpick">${cols.map(function (c, i) {
					return html`<label class="ps-check"><input type="checkbox" value="${c.key}" ${shown.indexOf(c.key) !== -1 ? raw('checked') : ''} ${i === 0 ? raw('disabled') : ''}> ${c.label}${c.important ? html` <span class="ps-muted ps-small">★</span>` : ''}</label>`;
				})}</div>`,
			buttons: [{ id: 'ok', label: PS.t('تطبيق'), kind: 'primary' }, { id: 'reset', label: PS.t('الافتراضي') }, { id: 'cancel', label: PS.t('إلغاء') }],
			onButton: function (id, wrap) {
				try {
					if (id === 'reset') localStorage.removeItem('ps_cols_' + page);
					if (id === 'ok') localStorage.setItem('ps_cols_' + page, JSON.stringify(Array.prototype.map.call(wrap.querySelectorAll('.ps-colpick input:checked'), function (x) { return x.value; })));
				} catch (e) { /* stockage indisponible : colonnes par défaut */ }
				return true;
			},
		}).then(function (r) { return r === 'ok' || r === 'reset'; });
	}

	/**
	 * Filtres rapides avec compteur : [{ value, label, count, tone }] ; la valeur active est mise en avant.
	 * tone : '' | 'danger' | 'muted' | 'success'.
	 */
	function chips(name, items, current) {
		return html`<div class="ps-chips" role="group" data-chips="${name}">${items.map(function (it) {
			const on = String(current || '') === String(it.value || '');
			return html`<button type="button" class="ps-qchip ${it.tone ? 'ps-qchip--' + it.tone : ''} ${on ? 'is-on' : ''}" data-chip="${name}" data-value="${it.value || ''}" aria-pressed="${on ? 'true' : 'false'}">${it.label}${it.count === undefined || it.count === null ? '' : html` <span class="ps-qchip__n">${it.count}</span>`}</button>`;
		})}</div>`;
	}

	/** Ligne « libellé : valeur » (panneaux de détails, cartes téléphone). Rien si la valeur est vide. */
	function kv(label, value) {
		return value === '' || value === null || value === undefined ? '' : html`<div class="ps-kv"><span class="ps-kv__k">${label}</span><span class="ps-kv__v">${value}</span></div>`;
	}

	return { esc, html, raw, toast, undoToast, clearErrorToasts, projectTitle, empty, badge, modal, confirm, autocomplete, showErrors, getPath, setPath, debounce, parseDate, enhanceDates, visibleColumns, chooseColumns, chips, kv };
})();
