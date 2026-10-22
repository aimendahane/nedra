/**
 * Page « المشاريع » — Base Projets (#/projets).
 *
 * Une ligne par projet, colonnes dans l'ordre du cahier §4, + مصدر et الأولوية.
 * Recherche générale, filtres (dans l'adresse : partageables et utilisés par le Recap),
 * tri par colonne, pagination. Sur téléphone : cartes au lieu du tableau.
 *
 * الإدارة : sélection multiple → attribuer un مصدر en une fois (décision n°3, projets
 * importés « غير محدد »), et corbeille (projets supprimés, restaurables).
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;

	/** Colonnes : [clé de tri, titre, rendu]. */
	const COLUMNS = [
		['receivedYear', PS.t('السنة'), function (r) { return r.receivedYear; }],
		// Numéro du fichier Excel importé (P029…) affiché tel quel (1.4.0), sinon le numéro d'ordre.
		['seq', PS.t('رقم'), function (r) { return /^P\d+$/.test(r.projectNo || '') ? r.projectNo : r.seq; }],
		['placeId', PS.t('المكان'), function (r) { return html`<span title="${L.label(r.placeId)}">${L.short(r.placeId)}</span>`; }],
		['urgencyId', PS.t('الاستعجال'), function (r) { return L.badge(r.urgencyId); }],
		['importanceKindId', PS.t('الأهمية كصنف'), function (r) { return L.label(r.importanceKindId); }],
		['importanceSizeId', PS.t('الأهمية كحجم'), function (r) { return L.label(r.importanceSizeId); }],
		['month', PS.t('الشهر'), function (r) { return F.month(r.month); }],
		['categoryId', PS.t('التصنيف'), function (r) { return L.label(r.categoryId); }],
		['companyName', PS.t('اسم الشركة'), function (r) { return r.companyName || ''; }],
		['directPerson', PS.t('شخص المباشر'), function (r) { return r.directPerson || ''; }],
		['directPhone', PS.t('رقم شخص المباشر'), function (r) { return html`<span dir="ltr">${r.directPhone || ''}</span>`; }],
		['name', PS.t('اسم المشروع'), function (r) { return html`<a href="#/projet?id=${r.id}">${r.name}</a>`; }],
		[null, PS.t('الأصناف'), function (r) { return r.itemTypeIds.map(L.label).join(PS.t('، ')); }],
		['receivedDate', PS.t('تاريخ استلام الطلب'), function (r) { return F.date(r.receivedDate); }],
		['sentDate', PS.t('تاريخ إرسال الطلب'), function (r) { return F.date(r.sentDate); }],
		['followerEmpId', PS.t('الموظف المتابع'), function (r) { return L.label(r.followerEmpId); }],
		['intermediaryEmpId', PS.t('الموظف الوسيط'), function (r) { return L.label(r.intermediaryEmpId); }],
		['projectNo', PS.t('رقم المشروع'), function (r) { return html`<a class="ps-mono" href="#/projet?id=${r.id}">${r.projectNo}</a>`; }],
		['followupStatusId', PS.t('حالة المتابعة'), function (r) { return L.badge(r.followupStatusId, L.label(r.followupStatusId)); }],
		['offerStatus', PS.t('حالة العرض'), function (r) { return L.offerBadge(r.offerStatus); }],
		[null, 'NOTE', function (r) { return html`<span class="ps-clip" title="${r.note || ''}">${r.note || ''}</span>`; }],
		[null, PS.t('رابط ملف المشاريع'), function (r) { return r.filesLink ? html`<a href="${r.filesLink}" target="_blank" rel="noopener noreferrer">🔗 ${PS.t('فتح')}</a>` : ''; }],
		['sourceId', PS.t('مصدر الطلب'), function (r) { return L.label(r.sourceId); }],
		['priority', PS.t('الأولوية'), function (r) { return r.priority ? html`<span class="ps-badge ps-prio ps-prio--${r.priority}">${r.priority}</span>` : ''; }],
	];

	/** Filtres : [paramètre, libellé, liste ou fonction d'options]. */
	const FILTERS = [
		['year', PS.t('السنة'), 'year'],
		['month', PS.t('الشهر'), 'month'],
		['placeId', PS.t('المكان'), 'place'],
		['urgencyId', PS.t('الاستعجال'), 'urgency'],
		['categoryId', PS.t('التصنيف'), 'category'],
		['followerEmpId', PS.t('الموظف المتابع'), 'employee'],
		['responsibleEmpId', PS.t('المسؤول'), 'employee'],
		['followupStatusId', PS.t('حالة المتابعة'), 'followup_status'],
		['offerStatus', PS.t('حالة العرض'), 'offerStatus'],
		['sourceId', PS.t('مصدر الطلب'), 'source'],
		['itemTypeId', PS.t('الصنف'), 'item_type'],
		['priority', PS.t('الأولوية'), 'priority'],
	];

	let st = null; // { main, params, data, selected:Set }

	function filterOptions(kind, current) {
		const opt = function (v, label) { return '<option value="' + ui.esc(v) + '"' + (String(v) === String(current) ? ' selected' : '') + '>' + ui.esc(label) + '</option>'; };
		let h = ('<option value="">' + PS.t('الكل') + '</option>');
		if (kind === 'year') {
			const y = new Date().getFullYear();
			for (let i = y + 1; i >= y - 6; i--) h += opt(i, i);
		} else if (kind === 'month') {
			F.MONTHS.forEach(function (m, i) { h += opt(i + 1, m); });
		} else if (kind === 'offerStatus') {
			L.values('offer_status').forEach(function (v) { h += opt(v.code, v.label); });
		} else if (kind === 'priority') {
			['P1', 'P2', 'P3'].forEach(function (p) { h += opt(p, p); });
		} else {
			if (kind === 'source') h += opt('unspecified', PS.t('⚠ مصدر غير محدد'));
			L.values(kind).forEach(function (v) {
				if (kind === 'source' && v.code === 'unspecified') return;
				h += opt(v.id, L.optionText(v) + (v.hidden ? PS.t(' (مخفي)') : ''));
			});
		}
		return ui.raw(h);
	}

	function isAdmin() { return PS.router.can('ps_edit_all_projects'); }

	function toolbar() {
		const p = st.params;
		const active = FILTERS.filter(function (f) { return p[f[0]]; }).length + (p.q ? 1 : 0) + (p.companyId ? 1 : 0);
		return html`<div class="ps-card ps-toolbar">
			<div class="ps-toolbar__row">
				<input type="search" class="ps-input ps-search" placeholder="🔍 ${PS.t('بحث: اسم المشروع، الشركة، الرقم، الهاتف…')}" value="${p.q || ''}" data-search>
				${PS.router.can('ps_create_project') ? html`<a class="ps-btn ps-btn--primary" href="#/projet">➕ ${PS.t('مشروع جديد')}</a>` : ''}
				${PS.router.can('ps_export') ? html`<button type="button" class="ps-btn" data-action="export-csv">⬇ CSV</button><button type="button" class="ps-btn" data-action="export-xlsx">⬇ Excel</button>` : ''}
				<button type="button" class="ps-btn ps-btn--ghost" data-action="toggle-filters" aria-expanded="${st.showFilters ? 'true' : 'false'}">⚙ ${PS.t('الفلاتر')} ${active ? html`<span class="ps-count">${active}</span>` : ''}</button>
			</div>
			<div class="ps-filters" ${st.showFilters ? '' : ui.raw('hidden')}>
				${FILTERS.map(function (f) {
					return html`<label class="ps-field ps-field--compact"><span class="ps-field__label">${f[1]}</span><select class="ps-input" data-filter="${f[0]}">${filterOptions(f[2], p[f[0]])}</select></label>`;
				})}
				<div class="ps-filters__end">
					${p.companyId ? html`<span class="ps-chip">${PS.t('الشركة #')}${p.companyId} <button type="button" class="ps-chip__x" data-action="clear-company">×</button></span>` : ''}
					${PS.router.can('ps_delete') ? html`<label class="ps-check"><input type="checkbox" data-filter="trash" ${p.trash ? ui.raw('checked') : ''}> 🗑 ${PS.t('سلة المحذوفات')}</label>` : ''}
					<button type="button" class="ps-btn ps-btn--small" data-action="reset-filters">${PS.t('مسح الفلاتر')}</button>
				</div>
			</div>
		</div>`;
	}

	function bulkBar() {
		if (!isAdmin() || !st.selected.size) return '';
		return html`<div class="ps-bulk" role="region" aria-label="${PS.t('تعديل جماعي')}">
			<strong>${st.selected.size} ${PS.t('مشروع محدد')}</strong>
			<label>${PS.t('تعيين مصدر الطلب:')} <select class="ps-input ps-input--sm" data-bulk-source>${ui.raw(L.options('source', '', { emptyLabel: PS.t('اختر…') }))}</select></label>
			<button type="button" class="ps-btn ps-btn--primary ps-btn--small" data-action="bulk-apply">${PS.t('تطبيق')}</button>
			<button type="button" class="ps-btn ps-btn--small" data-action="bulk-clear">${PS.t('إلغاء التحديد')}</button>
		</div>`;
	}

	function table(rows) {
		const sort = st.params.sort || 'receivedDate';
		const dir = st.params.dir || 'desc';
		const admin = isAdmin() && !st.params.trash;
		const allChecked = rows.length && rows.every(function (r) { return st.selected.has(r.id); });
		return html`<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list">
			<thead><tr>
				${admin ? html`<th><input type="checkbox" data-check-all aria-label="${PS.t('تحديد الكل')}" ${allChecked ? ui.raw('checked') : ''}></th>` : ''}
				${COLUMNS.map(function (c) {
					if (!c[0]) return html`<th>${c[1]}</th>`;
					const on = sort === c[0];
					return html`<th aria-sort="${on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" class="ps-sort" data-sort="${c[0]}">${c[1]} ${on ? (dir === 'asc' ? '▲' : '▼') : ''}</button></th>`;
				})}
				${st.params.trash ? html`<th></th>` : ''}
			</tr></thead>
			<tbody>${rows.map(function (r) {
				return html`<tr>
					${admin ? html`<td><input type="checkbox" data-check="${r.id}" aria-label="${PS.t('تحديد')} ${r.projectNo}" ${st.selected.has(r.id) ? ui.raw('checked') : ''}></td>` : ''}
					${COLUMNS.map(function (c) { return html`<td>${c[2](r)}</td>`; })}
					${st.params.trash ? html`<td><button type="button" class="ps-btn ps-btn--small" data-restore="${r.id}">↩ ${PS.t('استرجاع')}</button></td>` : ''}
				</tr>`;
			})}</tbody>
		</table></div>`;
	}

	function cards(rows) {
		return html`<div class="ps-cards ps-mobile-only">${rows.map(function (r) {
			return html`<a class="ps-pcard" href="#/projet?id=${r.id}">
				<div class="ps-pcard__top"><span class="ps-mono">${r.projectNo}</span> ${L.badge(r.urgencyId)} ${r.priority ? html`<span class="ps-badge ps-prio ps-prio--${r.priority}">${r.priority}</span>` : ''}</div>
				<div class="ps-pcard__name">${r.name}</div>
				<div class="ps-muted ps-small">${r.companyName || '—'} · ${L.short(r.placeId)} · ${F.date(r.receivedDate)}</div>
				<div class="ps-pcard__badges">${L.offerBadge(r.offerStatus)} ${L.badge(r.followupStatusId, L.label(r.followupStatusId))}</div>
			</a>`;
		})}</div>`;
	}

	function pager(d) {
		const pages = Math.max(1, Math.ceil(d.total / d.perPage));
		if (pages <= 1) return '';
		return html`<nav class="ps-pager" aria-label="${PS.t('الصفحات')}">
			<button type="button" class="ps-btn ps-btn--small" data-page="${d.page - 1}" ${d.page <= 1 ? ui.raw('disabled') : ''}>→ ${PS.t('السابق')}</button>
			<span>${PS.t('صفحة')} ${d.page} / ${pages}</span>
			<button type="button" class="ps-btn ps-btn--small" data-page="${d.page + 1}" ${d.page >= pages ? ui.raw('disabled') : ''}>${PS.t('التالي ←')}</button>
		</nav>`;
	}

	function renderResults() {
		const box = st.main.querySelector('[data-results]');
		const d = st.data;
		if (!d) return;
		let body;
		if (!d.rows.length) {
			body = ui.empty(st.params.trash ? '🗑' : '📁', Object.keys(st.params).length ? PS.t('لا توجد مشاريع تطابق البحث أو الفلاتر.') : PS.t('لا توجد مشاريع بعد. ابدأ بإدخال مشروع جديد.'));
		} else {
			body = html`${table(d.rows)}${cards(d.rows)}`;
		}
		box.innerHTML = html`<p class="ps-muted ps-small" role="status">${d.total} ${PS.t('مشروع')}${st.params.trash ? PS.t(' في سلة المحذوفات') : ''}</p>${bulkBar()}${body}${pager(d)}`.html;
	}

	function load() {
		const qs = new URLSearchParams(Object.assign({ perPage: 50 }, st.params)).toString();
		st.main.querySelector('[data-results]').setAttribute('aria-busy', 'true');
		return PS.api.get('projects?' + qs).then(function (d) {
			st.data = d;
			st.main.querySelector('[data-results]').removeAttribute('aria-busy');
			renderResults();
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	function setParam(k, v) {
		if (v === '' || v === null || v === undefined || v === false) delete st.params[k];
		else st.params[k] = v;
		if (k !== 'page') delete st.params.page;
		PS.router.setParams(st.params);
		st.selected.clear();
		load();
	}

	function exportFile(format) {
		const qs = new URLSearchParams(Object.assign({}, st.params, { format: format, _wpnonce: PS.config.nonce })).toString();
		window.location.href = PS.config.restUrl + 'export/projects?' + qs;
	}

	function bind() {
		const m = st.main;
		const onSearch = ui.debounce(function (v) { setParam('q', v.trim()); }, 300);
		m.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
		m.addEventListener('change', function (e) {
			const t = e.target;
			if (t.dataset.filter) setParam(t.dataset.filter, t.type === 'checkbox' ? (t.checked ? 1 : '') : t.value);
			else if (t.dataset.check) { t.checked ? st.selected.add(Number(t.dataset.check)) : st.selected.delete(Number(t.dataset.check)); renderResults(); }
			else if (t.hasAttribute('data-check-all')) { st.data.rows.forEach(function (r) { t.checked ? st.selected.add(r.id) : st.selected.delete(r.id); }); renderResults(); }
		});
		m.addEventListener('click', function (e) {
			const s = e.target.closest('[data-sort]');
			if (s) {
				const same = (st.params.sort || 'receivedDate') === s.dataset.sort;
				st.params.sort = s.dataset.sort;
				setParam('dir', same && (st.params.dir || 'desc') === 'desc' ? 'asc' : 'desc');
				return;
			}
			const pg = e.target.closest('[data-page]');
			if (pg) { setParam('page', pg.dataset.page); window.scrollTo(0, 0); return; }
			const rs = e.target.closest('[data-restore]');
			if (rs) {
				PS.api.post('projects/' + rs.dataset.restore + '/restore').then(function () { ui.toast(PS.t('تم الاسترجاع'), 'success'); load(); }).catch(function (er) { ui.toast(er.message, 'error'); });
				return;
			}
			const a = e.target.closest('[data-action]');
			if (!a || a.tagName === 'SELECT') return;
			const act = a.dataset.action;
			if (act === 'toggle-filters') { st.showFilters = !st.showFilters; m.querySelector('.ps-filters').hidden = !st.showFilters; a.setAttribute('aria-expanded', String(st.showFilters)); }
			else if (act === 'reset-filters') { st.params = {}; PS.router.setParams({}); renderPage(); }
			else if (act === 'clear-company') { setParam('companyId', ''); renderPage(); }
			else if (act === 'bulk-clear') { st.selected.clear(); renderResults(); }
			else if (act === 'bulk-apply') bulkApply();
			else if (act === 'export-csv') exportFile('csv');
			else if (act === 'export-xlsx') exportFile('xlsx');
		});
	}

	function bulkApply() {
		const sel = st.main.querySelector('[data-bulk-source]');
		if (!sel.value) { ui.toast(PS.t('اختر مصدر الطلب'), 'error'); return; }
		const n = st.selected.size;
		ui.confirm(PS.t('تعيين «') + L.label(Number(sel.value)) + PS.t('» كمصدر طلب لـ ') + n + PS.t(' مشروع؟')).then(function (ok) {
			if (!ok) return;
			PS.api.post('projects/bulk-source', { ids: Array.from(st.selected), sourceId: Number(sel.value) }).then(function (r) {
				ui.toast(PS.t('تم تحديث ') + r.updated + PS.t(' مشروع'), 'success');
				st.selected.clear();
				load();
			}).catch(function (e) { ui.toast(e.message, 'error'); });
		});
	}

	function renderPage() {
		st.main.innerHTML = html`<div class="ps-page-head"><h1>${PS.t('المشاريع')}</h1></div>${toolbar()}<div data-results><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`.html;
		bind();
		return load();
	}

	PS.router.register({
		id: 'projets',
		title: PS.t('المشاريع'),
		icon: '📁',
		cap: 'ps_access',
		order: 30,
		group: 'work',
		render: function (main, params) {
			return L.load().then(function () {
				st = { main: main, params: Object.assign({}, params), data: null, selected: new Set(), showFilters: Object.keys(params).some(function (k) { return k !== 'q' && k !== 'sort' && k !== 'dir' && k !== 'page'; }) };
				return renderPage();
			});
		},
	});
})();
