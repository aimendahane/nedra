/**
 * Page « متابعة التسليم » — Suivi Livraison (#/livraison).
 *
 * Les أصناف dont l'offre est « مقبول » (cahier §6) : فاتورة العرض، فاتورة المورد، المتوفر،
 * أجل التسليم المطلوب (*)، أجل التسليم الفعلي، تأخير (أيام) calculé, الحالة, ملاحظات.
 * La حالة passe automatiquement à « متأخر » quand la livraison est en retard (calcul serveur).
 * 1.8.0 : tableau court + filtres rapides (الكل / متأخرة / par statut) + panneau latéral de modification.
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;
	let st = null;

	/** Statuts proposés : tous sauf « متأخر » (calculé). */
	function statusOptions(current) {
		return ui.raw(L.values('delivery_status').filter(function (v) { return v.code !== 'late' && (!v.hidden || v.id === current); })
			.map(function (v) { return '<option value="' + v.id + '"' + (v.id === current ? ' selected' : '') + '>' + ui.esc(v.label) + '</option>'; }).join(''));
	}

	/** Badge de la حالة affichée (« متأخر » calculé par le serveur, sinon le statut choisi). */
	function statusBadge(r) {
		if (r.computed.deliveryDisplay === 'late') return L.badge(L.byCode('delivery_status', 'late').id, PS.t('متأخر'));
		return L.badge(r.deliveryStatusId, L.label(r.deliveryStatusId));
	}

	/** حالة : changement rapide gardé dans le tableau (comme حالة العرض dans Suivi Offres). */
	function statusCell(r) {
		const late = r.computed.deliveryDisplay === 'late';
		const lateBadge = late ? L.badge(L.byCode('delivery_status', 'late').id, PS.t('متأخر')) : '';
		if (!r.canEdit) return statusBadge(r);
		return html`${lateBadge}<select class="ps-input ps-input--sm" data-field="deliveryStatusId" aria-label="${PS.t('حالة التسليم')}">${statusOptions(r.deliveryStatusId)}</select>`;
	}

	/*
	 * 1.8.0 — même principe que les autres listes : colonnes importantes d'abord (8 par défaut, « الأعمدة » pour les
	 * autres), cellules en texte simple (sauf la حالة), clic sur la ligne → panneau de modification.
	 * Colonnes : [identifiant, titre, rendu, importante].
	 */
	const COLS = [
		['projectNo', PS.t('رقم المشروع'), function (r) { return html`${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })}`; }, true],
		['itemTypeId', PS.t('الصنف'), function (r) { return L.label(r.itemTypeId); }, true],
		['deliveryDueDate', PS.t('أجل التسليم المطلوب'), function (r) { return r.deliveryDueDate ? F.date(r.deliveryDueDate) : (r.canEdit ? html`<span class="ps-badge ps-badge--danger">${PS.t('إجباري')}</span>` : '—'); }, true],
		['deliveryActualDate', PS.t('أجل التسليم الفعلي'), function (r) { return F.date(r.deliveryActualDate); }, true],
		['delay', PS.t('تأخير (أيام)'), function (r) { return r.computed.delayDays > 0 ? html`<strong class="ps-text-danger">${r.computed.delayDays}</strong>` : String(r.computed.delayDays); }, true],
		['deliveryStatusId', PS.t('الحالة'), statusCell, true],
		['offerInvoiceNo', PS.t('فاتورة العرض'), function (r) { return r.offerInvoiceNo ? html`<span dir="ltr">${r.offerInvoiceNo}</span>` : html`<span class="ps-text-danger ps-small">—</span>`; }, true],
		['deliveryNotes', PS.t('ملاحظات'), function (r) { return html`<span class="ps-clip" title="${r.deliveryNotes || ''}">${r.deliveryNotes || ''}</span>`; }, true],
		['supplierInvoiceNo', PS.t('فاتورة المورد'), function (r) { return html`<span dir="ltr">${r.supplierInvoiceNo || ''}</span>`; }, false],
		['availabilityId', PS.t('المتوفر'), function (r) { return L.label(r.availabilityId); }, false],
		['employeeId', PS.t('موظف الصنف'), function (r) { return L.label(r.employeeId); }, false],
	];
	function colDefs() { return COLS.map(function (c) { return { key: c[0], label: c[1], important: c[3], col: c }; }); }

	function rowClass(r) { return r.computed.deliveryLate ? 'is-late' : (L.code(r.deliveryStatusId) === 'delivered' ? 'is-done' : ''); }

	function cardHtml(r) {
		return html`<div class="ps-icard ${rowClass(r)}" data-row="${r.id}" data-open-row="${r.id}" tabindex="0">
			<div class="ps-pcard__top">${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })} ${statusBadge(r)}</div>
			<div class="ps-kvs">
				${ui.kv(PS.t('الصنف'), L.label(r.itemTypeId))}
				${ui.kv(PS.t('أجل التسليم المطلوب'), F.date(r.deliveryDueDate) || '—')}
				${ui.kv(PS.t('أجل التسليم الفعلي'), F.date(r.deliveryActualDate) || '—')}
				${ui.kv(PS.t('تأخير (أيام)'), String(r.computed.delayDays))}
				${ui.kv(PS.t('فاتورة العرض'), r.offerInvoiceNo || '—')}
			</div>
		</div>`;
	}

	/** Filtre rapide (calculé dans le navigateur sur la liste chargée) : '' | 'late' | code de statut. */
	function chipValue() { return st.params.f || (st.params.deliveryLate ? 'late' : ''); }
	function matches(r, f) {
		if (!f) return true;
		if (f === 'late') return !!r.computed.deliveryLate;
		return r.computed.deliveryDisplay !== 'late' && L.code(r.deliveryStatusId) === f;
	}

	function renderRows() {
		const d = st.data;
		const box = st.main.querySelector('[data-results]');
		const f = chipValue();
		const items = [{ value: '', label: PS.t('الكل'), count: d.rows.length }, { value: 'late', label: '⚠ ' + PS.t('متأخرة'), count: d.rows.filter(function (r) { return matches(r, 'late'); }).length, tone: 'danger' }]
			.concat(L.values('delivery_status').filter(function (v) { return v.code !== 'late' && !v.hidden; }).map(function (v) { return { value: v.code, label: v.label, count: d.rows.filter(function (r) { return matches(r, v.code); }).length }; }));
		const head = html`<div class="ps-list-head">${ui.chips('delivery', items, f)}<button type="button" class="ps-btn ps-btn--ghost ps-btn--small ps-desktop-only" data-action="columns">▦ ${PS.t('الأعمدة')}</button></div>`;
		const rows = d.rows.filter(function (r) { return matches(r, f); });
		const lateCount = items[1].count;
		if (!rows.length) {
			box.innerHTML = html`${head}${ui.empty('🚚', PS.t('لا توجد أصناف مقبولة تطابق الفلاتر.'))}`.html;
			return;
		}
		const cols = ui.visibleColumns('livraison', colDefs()).map(function (x) { return x.col; });
		box.innerHTML = html`${head}<p class="ps-muted ps-small" role="status">${rows.length} ${PS.t('صنف مقبول ·')} ${lateCount ? html`<strong class="ps-text-danger">${lateCount} ${PS.t('تسليم متأخر')}</strong>` : PS.t('لا توجد تسليمات متأخرة')}</p>
			<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list ps-table--clickable">
			<thead><tr>${cols.map(function (c) { return html`<th>${c[1]}</th>`; })}</tr></thead>
			<tbody>${rows.map(function (r) { return html`<tr class="${rowClass(r)}" data-row="${r.id}" data-open-row="${r.id}" tabindex="0">${cols.map(function (c) { return html`<td>${c[2](r)}</td>`; })}</tr>`; })}</tbody></table></div>
			<div class="ps-cards ps-mobile-only">${rows.map(cardHtml)}</div>`.html;
	}

	function load() {
		const params = Object.assign({ view: 'delivery', perPage: 300 }, st.params);
		delete params.f; delete params.deliveryLate; // filtres rapides appliqués dans le navigateur (compteurs exacts)
		return PS.api.get('items?' + new URLSearchParams(params).toString()).then(function (d) {
			st.data = d;
			renderRows();
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	function rowById(id) { return st.data.rows.find(function (r) { return String(r.id) === String(id); }); }

	/** Panneau de la livraison : tous les champs modifiables au même endroit. */
	function openDrawer(id) {
		const r = rowById(id);
		if (!r) return;
		const ro = !r.canEdit;
		const field = function (label, inner, req) { return html`<label class="ps-field ${req ? 'ps-field--required' : ''}"><span class="ps-field__label">${label}</span>${inner}</label>`; };
		const txt = function (f, ltr) { return ro ? html`<div class="ps-readonly">${r[f] || '—'}</div>` : html`<input class="ps-input" data-d="${f}" value="${r[f] || ''}" ${ltr ? ui.raw('dir="ltr"') : ''}>`; };
		const date = function (f) { return ro ? html`<div class="ps-readonly">${F.date(r[f]) || '—'}</div>` : html`<input type="date" class="ps-input" data-d="${f}" value="${r[f] || ''}">`; };
		const body = html`<div class="ps-drawer">
			<div class="ps-drawer__head">
				${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })}
				<div class="ps-drawer__badges">${statusBadge(r)} ${r.computed.delayDays > 0 ? html`<span class="ps-badge ps-badge--danger">${PS.t('تأخير (أيام)')}: ${r.computed.delayDays}</span>` : ''}</div>
			</div>
			<div class="ps-kvs">
				${ui.kv(PS.t('الصنف'), L.label(r.itemTypeId))}
				${ui.kv(PS.t('موظف الصنف'), L.label(r.employeeId))}
				${ui.kv(PS.t('فاتورة العرض'), r.offerInvoiceNo || '—')}
			</div>
			<div class="ps-drawer__section"><h3>${PS.t('التسليم')}</h3><div class="ps-drawer__form">
				${field(PS.t('أجل التسليم المطلوب *'), date('deliveryDueDate'), true)}
				${field(PS.t('أجل التسليم الفعلي'), date('deliveryActualDate'))}
				${field(PS.t('الحالة'), ro ? html`<div class="ps-readonly">${statusBadge(r)}</div>` : html`<select class="ps-input" data-d="deliveryStatusId">${statusOptions(r.deliveryStatusId)}</select>`)}
				${field(PS.t('فاتورة المورد'), txt('supplierInvoiceNo', true))}
				${field(PS.t('المتوفر'), ro ? html`<div class="ps-readonly">${L.label(r.availabilityId) || '—'}</div>` : html`<select class="ps-input" data-d="availabilityId">${ui.raw(L.options('availability', r.availabilityId))}</select>`)}
				${field(PS.t('ملاحظات'), ro ? html`<div class="ps-readonly">${r.deliveryNotes || '—'}</div>` : html`<textarea class="ps-input" data-d="deliveryNotes" rows="3">${r.deliveryNotes || ''}</textarea>`)}
			</div></div>
			<div class="ps-drawer__section ps-drawer__actions"><a class="ps-btn ps-btn--ghost" href="#/projet?id=${r.projectId}" data-drawer-go>📁 ${PS.t('فتح بطاقة المشروع')}</a></div>
		</div>`;
		ui.modal({
			title: PS.t('تفاصيل التسليم'),
			body: body,
			drawer: true,
			buttons: ro ? [{ id: 'close', label: PS.t('إغلاق') }] : [{ id: 'save', label: PS.t('حفظ'), kind: 'primary' }, { id: 'close', label: PS.t('إغلاق') }],
			onOpen: function (wrap) { wrap.addEventListener('click', function (e) { if (e.target.closest('[data-drawer-go]')) wrap.remove(); }); },
			onButton: function (b, wrap, close) {
				if (b !== 'save') return true;
				const ch = {};
				wrap.querySelectorAll('[data-d]').forEach(function (el) {
					const k = el.dataset.d;
					const v = el.value === '' ? null : (k.slice(-2) === 'Id' ? Number(el.value) : el.value);
					if (String(v === null ? '' : v) !== String(r[k] === null || r[k] === undefined ? '' : r[k])) ch[k] = v;
				});
				if (!Object.keys(ch).length) { close('close'); return false; }
				PS.items.save(r, ch).then(function (u) { if (u) { close('save'); load(); } });
				return false;
			},
		});
	}

	function setParam(k, v) {
		if (v === '' || v === null || v === false || v === undefined) delete st.params[k]; else st.params[k] = v;
		PS.router.setParams(st.params);
		load();
	}

	function render() {
		const p = st.params;
		const empOpts = ui.raw(('<option value="">' + PS.t('كل الموظفين') + '</option>') + (PS.config.user.employeeId ? '<option value="me"' + (p.employeeId === 'me' ? ' selected' : '') + ('>' + PS.t('أصنافي فقط') + '</option>') : '') + L.options('employee', p.employeeId, { noEmpty: true }));
		st.main.innerHTML = html`<div class="ps-page-head"><h1>${PS.t('متابعة التسليم')} <span class="ps-muted ps-small">(${PS.t('الأصناف ذات العرض المقبول')})</span></h1></div>
			<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
				<input type="search" class="ps-input ps-search" placeholder="🔍 ${PS.t('بحث: مشروع، شركة، صنف، فاتورة')}" value="${p.q || ''}" data-search>
				<select class="ps-input ps-input--auto" data-filter="employeeId" aria-label="${PS.t('الموظف')}">${empOpts}</select>
				${PS.router.can('ps_export') ? html`<button type="button" class="ps-btn" data-export="csv">⬇ CSV</button><button type="button" class="ps-btn" data-export="xlsx">⬇ Excel</button>` : ''}
			</div></div>
			<div data-results><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`.html;
		const m = st.main;
		const onSearch = ui.debounce(function (v) { setParam('q', v.trim()); }, 300);
		m.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
		m.addEventListener('keydown', function (e) {
			const row = e.target.closest && e.target.closest('[data-open-row]');
			if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openDrawer(row.dataset.openRow); }
		});
		m.addEventListener('click', function (e) {
			const ex = e.target.closest('[data-export]');
			if (ex) {
				const qs = new URLSearchParams(Object.assign({}, st.params, { view: 'delivery', format: ex.dataset.export, _wpnonce: PS.config.nonce })).toString();
				window.location.href = PS.config.restUrl + 'export/items?' + qs;
				return;
			}
			const chip = e.target.closest('[data-chip]');
			if (chip) {
				delete st.params.deliveryLate;
				if (chip.dataset.value) st.params.f = chip.dataset.value; else delete st.params.f;
				PS.router.setParams(st.params);
				renderRows();
				return;
			}
			if (e.target.closest('[data-action="columns"]')) { ui.chooseColumns('livraison', colDefs()).then(function (c) { if (c) renderRows(); }); return; }
			const row = e.target.closest('[data-open-row]');
			if (row && !e.target.closest('a, button, input, select, textarea, label')) openDrawer(row.dataset.openRow);
		});
		m.addEventListener('change', function (e) {
			const t = e.target;
			if (t.dataset.filter) { setParam(t.dataset.filter, t.type === 'checkbox' ? (t.checked ? 1 : '') : t.value); return; }
			if (!t.dataset.field) return;
			const row = st.data.rows.find(function (r) { return String(r.id) === t.closest('[data-row]').dataset.row; });
			const ch = {};
			ch[t.dataset.field] = t.value === '' ? null : (t.dataset.field.slice(-2) === 'Id' ? Number(t.value) : t.value);
			PS.items.save(row, ch).then(load);
		});
		return load();
	}

	PS.router.register({
		id: 'livraison',
		title: PS.t('متابعة التسليم'),
		icon: '🚚',
		cap: 'ps_access',
		order: 50,
		group: 'work',
		render: function (main, params) {
			return L.load().then(function () {
				st = { main: main, params: Object.assign({}, params), data: null };
				return render();
			});
		},
	});
})();
