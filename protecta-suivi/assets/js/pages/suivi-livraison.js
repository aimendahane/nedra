/**
 * Page « متابعة التسليم » — Suivi Livraison (#/livraison).
 *
 * Les أصناف dont l'offre est « مقبول » (cahier §6) : فاتورة العرض، فاتورة المورد، المتوفر،
 * أجل التسليم المطلوب (*)، أجل التسليم الفعلي، تأخير (أيام) calculé, الحالة, ملاحظات.
 * La حالة passe automatiquement à « متأخر » quand la livraison est en retard (calcul serveur).
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

	function cell(r, field, type) {
		if (!r.canEdit) return type === 'date' ? F.date(r[field]) : (r[field] || '');
		const req = field === 'deliveryDueDate' && !r[field];
		return html`<div class="ps-field ${req ? 'ps-field--required' : ''}"><input type="${type}" class="ps-input ps-input--sm" data-field="${field}" value="${r[field] || ''}" aria-label="${field}"></div>`;
	}

	function statusCell(r) {
		const late = r.computed.deliveryDisplay === 'late';
		const lateBadge = late ? L.badge(L.byCode('delivery_status', 'late').id, 'متأخر') : '';
		if (!r.canEdit) return html`${late ? lateBadge : L.badge(r.deliveryStatusId, L.label(r.deliveryStatusId))}`;
		return html`${lateBadge}<select class="ps-input ps-input--sm" data-field="deliveryStatusId" aria-label="حالة التسليم">${statusOptions(r.deliveryStatusId)}</select>`;
	}

	function rowHtml(r) {
		const cls = r.computed.deliveryLate ? 'is-late' : (L.code(r.deliveryStatusId) === 'delivered' ? 'is-done' : '');
		return html`<tr class="${cls}" data-row="${r.id}">
			<td><a class="ps-mono" href="#/projet?id=${r.projectId}">${r.projectNo}</a><div class="ps-small ps-muted ps-clip">${r.projectName}</div></td>
			<td>${L.label(r.itemTypeId)}</td>
			<td>${r.offerInvoiceNo || html`<span class="ps-text-danger ps-small">—</span>`}</td>
			<td>${cell(r, 'supplierInvoiceNo', 'text')}</td>
			<td>${r.canEdit ? html`<select class="ps-input ps-input--sm" data-field="availabilityId">${ui.raw(L.options('availability', r.availabilityId))}</select>` : L.label(r.availabilityId)}</td>
			<td>${cell(r, 'deliveryDueDate', 'date')}</td>
			<td>${cell(r, 'deliveryActualDate', 'date')}</td>
			<td class="${r.computed.delayDays > 0 ? 'ps-text-danger' : ''}">${r.computed.delayDays}</td>
			<td>${statusCell(r)}</td>
			<td>${cell(r, 'deliveryNotes', 'text')}</td>
		</tr>`;
	}

	function cardHtml(r) {
		return html`<div class="ps-icard ${r.computed.deliveryLate ? 'is-late' : ''}" data-row="${r.id}">
			<div class="ps-pcard__top"><a class="ps-mono" href="#/projet?id=${r.projectId}">${r.projectNo}</a> ${statusCell(r)}</div>
			<div class="ps-pcard__name">${L.label(r.itemTypeId)} — ${r.projectName}</div>
			<div class="ps-small">المطلوب: ${F.date(r.deliveryDueDate) || '—'} · الفعلي: ${F.date(r.deliveryActualDate) || '—'} · تأخير: <strong>${r.computed.delayDays}</strong> يوم</div>
			${r.canEdit ? html`<div class="ps-icard__edit ps-grid ps-grid--tight">${cell(r, 'deliveryDueDate', 'date')}${cell(r, 'deliveryActualDate', 'date')}</div>` : ''}
		</div>`;
	}

	function load() {
		const qs = new URLSearchParams(Object.assign({ view: 'delivery', perPage: 300 }, st.params)).toString();
		return PS.api.get('items?' + qs).then(function (d) {
			st.data = d;
			const lateCount = d.rows.filter(function (r) { return r.computed.deliveryLate; }).length;
			const box = st.main.querySelector('[data-results]');
			if (!d.rows.length) {
				box.innerHTML = ui.empty('🚚', 'لا توجد أصناف مقبولة تطابق الفلاتر.').html;
				return;
			}
			box.innerHTML = html`<p class="ps-muted ps-small" role="status">${d.total} صنف مقبول · ${lateCount ? html`<strong class="ps-text-danger">${lateCount} تسليم متأخر</strong>` : 'لا توجد تسليمات متأخرة'}</p>
				<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list ps-table--edit">
				<thead><tr><th>رقم المشروع</th><th>الصنف</th><th>فاتورة العرض</th><th>فاتورة المورد</th><th>المتوفر</th><th>أجل التسليم المطلوب *</th><th>أجل التسليم الفعلي</th><th>تأخير (أيام)</th><th>الحالة</th><th>ملاحظات</th></tr></thead>
				<tbody>${d.rows.map(rowHtml)}</tbody></table></div>
				<div class="ps-cards ps-mobile-only">${d.rows.map(cardHtml)}</div>`.html;
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	function setParam(k, v) {
		if (v === '' || v === null || v === false || v === undefined) delete st.params[k]; else st.params[k] = v;
		PS.router.setParams(st.params);
		load();
	}

	function render() {
		const p = st.params;
		const empOpts = ui.raw('<option value="">كل الموظفين</option>' + (PS.config.user.employeeId ? '<option value="me"' + (p.employeeId === 'me' ? ' selected' : '') + '>أصنافي فقط</option>' : '') + L.options('employee', p.employeeId, { noEmpty: true }));
		st.main.innerHTML = html`<div class="ps-page-head"><h1>متابعة التسليم <span class="ps-muted ps-small">(الأصناف ذات العرض المقبول)</span></h1></div>
			<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
				<input type="search" class="ps-input ps-search" placeholder="🔍 بحث: مشروع، شركة، صنف، فاتورة" value="${p.q || ''}" data-search>
				<select class="ps-input ps-input--auto" data-filter="employeeId" aria-label="الموظف">${empOpts}</select>
				<label class="ps-check"><input type="checkbox" data-filter="deliveryLate" ${p.deliveryLate ? ui.raw('checked') : ''}> ⚠ المتأخرة فقط</label>
			</div></div>
			<div data-results><div class="ps-loading">جاري التحميل…</div></div>`.html;
		const m = st.main;
		const onSearch = ui.debounce(function (v) { setParam('q', v.trim()); }, 300);
		m.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
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
		title: 'متابعة التسليم',
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
