/**
 * Page « متابعة العروض » — Suivi Offres (#/offres).
 *
 * Une ligne par صنف (cahier §5) : رقم المشروع، الصنف، الموظف، الأجل، تاريخ تقديم العرض، الحالة،
 * رقم فاتورة العرض، ملاحظات. Ligne ROUGE = offre en retard (قيد الإعداد, أجل dépassé, hors L),
 * VERTE = مقبول (calculé par le serveur).
 * En haut : compteur « لازم قرار » (clic = n'afficher que ces أصناف).
 *
 * Modification directe dans le tableau (si l'utilisateur en a le droit) :
 *   - « لم يُرسل » ouvre une fenêtre pour choisir « سبب عدم الإرسال » (+ note) ;
 *   - مرسل / مقبول / مرفوض sur un projet sans « تاريخ إرسال العرض » demande cette date
 *     (aujourd'hui proposé, modifiable).
 *
 * PS.items.save(row, changes) est aussi utilisé par la page Suivi Livraison.
 */
window.PS = window.PS || {};

PS.items = (function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;

	/** Demande les informations complémentaires nécessaires, puis enregistre. → Promise<ligne|null> */
	function save(row, changes) {
		const statusId = changes.offerStatusId;
		const code = statusId ? L.code(Number(statusId)) : null;
		let ask = Promise.resolve(changes);

		if (code === 'not_sent') {
			ask = ui.modal({
				title: PS.t('لم يُرسل — سبب عدم الإرسال'),
				body: html`<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('سبب عدم الإرسال *')}</span><select data-q="reason">${ui.raw(L.options('not_sent_reason', row.notSentReasonId, { emptyLabel: PS.t('اختر…') }))}</select></label>
					<label class="ps-field ps-mt"><span class="ps-field__label">${PS.t('ملاحظة (اختياري)')}</span><textarea data-q="note" rows="2">${row.notSentNote || ''}</textarea></label>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (id, box) {
					if (id !== 'ok') return true;
					const reason = box.querySelector('[data-q="reason"]').value;
					if (!reason) { box.querySelector('[data-q="reason"]').focus(); ui.toast(PS.t('اختر سبب عدم الإرسال'), 'error'); return false; }
					changes.notSentReasonId = Number(reason);
					changes.notSentNote = box.querySelector('[data-q="note"]').value;
					return true;
				},
			}).then(function (r) { return r === 'ok' ? changes : null; });
		} else if (changes.followerEmpId !== undefined || changes.responsibleEmpId !== undefined) {
			// 1.6.0 : changer المسؤول / المتابع d'une offre → justification obligatoire (journal).
			const who = changes.followerEmpId !== undefined ? PS.t('الموظف المتابع') : PS.t('المسؤول عن المشروع');
			const to = L.label(changes.followerEmpId !== undefined ? changes.followerEmpId : changes.responsibleEmpId);
			ask = ui.modal({
				title: PS.t('تغيير') + ' ' + who,
				body: html`<p>${who}: <strong>${to}</strong></p>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('سبب التغيير *')}</span><textarea data-q="reason" rows="2"></textarea></label>
					<p class="ps-small ps-muted">${PS.t('يُسجَّل في سجل التعديلات: من، متى، القيمة القديمة والجديدة والسبب.')}</p>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (id, box) {
					if (id !== 'ok') return true;
					const reason = box.querySelector('[data-q="reason"]').value.trim();
					if (!reason) { box.querySelector('[data-q="reason"]').focus(); ui.toast(PS.t('اكتب سبب التغيير (إجباري)'), 'error'); return false; }
					changes.reason = reason;
					return true;
				},
			}).then(function (r) { return r === 'ok' ? changes : null; });
		} else if ((code === 'sent' || code === 'accepted' || code === 'rejected') && !row.projectSentDate) {
			ask = ui.modal({
				title: PS.t('تاريخ إرسال العرض'),
				body: html`<p>${PS.t('المشروع')} <strong>${row.projectNo}</strong> ${PS.t('بدون تاريخ إرسال العرض. هذا التاريخ إجباري عند إرسال العرض.')}</p>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('تاريخ إرسال العرض *')}</span><input type="date" data-q="date" value="${PS.config.today}" min="${row.receivedDate || ''}"></label>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (id, box) {
					if (id !== 'ok') return true;
					const d = box.querySelector('[data-q="date"]').value;
					if (!d) return false;
					changes.projectSentDate = d;
					return true;
				},
			}).then(function (r) { return r === 'ok' ? changes : null; });
		}

		return ask.then(function (ch) {
			if (!ch) return null;
			return PS.api.put('items/' + row.id, ch).then(function (updated) {
				ui.toast(PS.t('تم الحفظ'), 'success');
				PS.router.refreshCounts();
				return updated;
			}).catch(function (e) {
				const errs = e.data && e.data.data && e.data.data.errors;
				ui.toast(errs ? Object.keys(errs).map(function (k) { return errs[k]; }).join(' — ') : e.message, 'error');
				return null;
			});
		});
	}

	return { save: save };
})();

(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;
	let st = null;

	function filters() {
		const p = st.params;
		const empOpts = ui.raw(('<option value="">' + PS.t('كل الموظفين') + '</option>') + (PS.config.user.employeeId ? '<option value="me"' + (p.employeeId === 'me' ? ' selected' : '') + ('>' + PS.t('أصنافي فقط') + '</option>') : '') + L.options('employee', p.employeeId, { noEmpty: true }));
		const stOpts = ui.raw(('<option value="">' + PS.t('كل الحالات') + '</option>') + L.values('offer_status').map(function (v) { return '<option value="' + v.code + '"' + (p.offerStatus === v.code ? ' selected' : '') + '>' + ui.esc(v.label) + '</option>'; }).join(''));
		return html`<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
			<input type="search" class="ps-input ps-search" placeholder="🔍 ${PS.t('بحث: مشروع، شركة، صنف، فاتورة')}" value="${p.q || ''}" data-search>
			<select class="ps-input ps-input--auto" data-filter="employeeId" aria-label="${PS.t('الموظف')}">${empOpts}</select>
			<select class="ps-input ps-input--auto" data-filter="offerStatus" aria-label="${PS.t('الحالة')}">${stOpts}</select>
			${p.notSentReasonId ? html`<span class="ps-chip">${PS.t('سبب:')} ${L.label(Number(p.notSentReasonId))} <button type="button" class="ps-chip__x" data-clear="notSentReasonId">×</button></span>` : ''}
			${p.itemTypeId ? html`<span class="ps-chip">${PS.t('الصنف:')} ${L.label(Number(p.itemTypeId))} <button type="button" class="ps-chip__x" data-clear="itemTypeId">×</button></span>` : ''}
			<label class="ps-check"><input type="checkbox" data-filter="late" ${p.late ? ui.raw('checked') : ''}> ⚠ ${PS.t('لازم قرار فقط')}</label>
			${PS.router.can('ps_export') ? html`<button type="button" class="ps-btn" data-export="csv">⬇ CSV</button><button type="button" class="ps-btn" data-export="xlsx">⬇ Excel</button>` : ''}
		</div></div>`;
	}

	function statusCell(r) {
		if (!r.canEdit) return html`${L.offerBadge(r.offerStatusCode)}${r.offerStatusCode === 'not_sent' && r.notSentReasonId ? html`<div class="ps-small ps-muted">${L.label(r.notSentReasonId)}</div>` : ''}`;
		return html`<select class="ps-input ps-input--sm" data-field="offerStatusId" aria-label="${PS.t('حالة العرض')}">${ui.raw(L.options('offer_status', r.offerStatusId, { noEmpty: true }))}</select>
			${r.offerStatusCode === 'not_sent' ? html`<button type="button" class="ps-link ps-small" data-reason>${r.notSentReasonId ? L.label(r.notSentReasonId) : PS.t('سبب؟')}</button>` : ''}`;
	}

	function input(r, field, type, cls) {
		if (!r.canEdit) return type === 'date' ? F.date(r[field]) : (r[field] || '');
		return html`<input type="${type}" class="ps-input ps-input--sm ${cls || ''}" data-field="${field}" value="${r[field] || ''}">`;
	}

	/** المسؤول / المتابع de l'offre (1.6.0) : liste si l'utilisateur peut les changer, sinon le nom. */
	function assignCell(r, field) {
		if (!r.canAssign) return html`${L.label(r[field])}`;
		return html`<select class="ps-input ps-input--sm" data-field="${field}" aria-label="${field === 'followerEmpId' ? PS.t('الموظف المتابع') : PS.t('المسؤول عن المشروع')}">${ui.raw(L.options('employee', r[field], { noEmpty: true }))}</select>`;
	}

	/** Factures de l'offre + fichier joint de l'offre (1.6.0). */
	/** 📎 fichiers de l'offre : icône + nombre (1.6.1 : colonne à part). */
	function filesCell(r) {
		return html`<button type="button" class="ps-btn ps-btn--small ps-nowrap" data-offer-files title="${PS.t('ملفات العرض')}" aria-label="${PS.t('ملفات العرض')}">📎 ${r.filesCount || 0}</button>`;
	}

	/** 🧾 factures de l'offre (nombre, badge rouge si retard). */
	function invoicesCell(r) {
		const inv = r.invoices || { total: 0, late: 0 };
		return html`<button type="button" class="ps-btn ps-btn--small ps-nowrap ${inv.late ? 'ps-btn--danger' : ''}" data-invoices title="${PS.t('الفواتير')}">🧾 ${inv.total}${inv.late ? html` <span class="ps-badge ps-badge--danger">${inv.late}</span>` : ''}</button>`;
	}

	/*
	 * 1.6.1 (demande du client) : colonnes importantes d'abord — رقم المشروع، رقم فاتورة العرض، المسؤول، المتابع،
	 * تاريخ الإرسال، حالة العرض، 📎، ملاحظة العرض — puis الصنف، موظف الصنف، الاستعجال، الأجل، 🧾.
	 */
	const HEADERS = [PS.t('رقم المشروع'), PS.t('رقم فاتورة العرض'), PS.t('المسؤول عن المشروع'), PS.t('الموظف المتابع'), PS.t('تاريخ الإرسال'), PS.t('حالة العرض'), '📎', PS.t('ملاحظة العرض'), PS.t('الصنف'), PS.t('موظف الصنف'), PS.t('الاستعجال'), PS.t('الأجل المطلوب'), PS.t('🧾 الفواتير')];

	function rowHtml(r) {
		const cls = r.computed.rowColor === 'late' ? 'is-late' : r.computed.rowColor === 'done' ? 'is-done' : '';
		return html`<tr class="${cls}" data-row="${r.id}">
			<td>${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })}</td>
			<td>${input(r, 'offerInvoiceNo', 'text', 'ps-input--code')}</td>
			<td>${assignCell(r, 'responsibleEmpId')}</td>
			<td>${assignCell(r, 'followerEmpId')}</td>
			<td>${input(r, 'offerDate', 'date')}</td>
			<td>${statusCell(r)}</td>
			<td>${filesCell(r)}</td>
			<td>${input(r, 'offerNotes', 'text')}</td>
			<td>${L.label(r.itemTypeId)}${r.quantity ? html` <span class="ps-muted">×${F.number(r.quantity)}</span>` : ''}</td>
			<td>${L.label(r.employeeId)}</td>
			<td>${L.badge(r.urgencyId)}</td>
			<td>${F.date(r.dueDate)}${r.computed.late ? html` <span class="ps-badge ps-badge--danger">${PS.t('لازم قرار')}</span>` : ''}</td>
			<td>${invoicesCell(r)}</td>
		</tr>`;
	}

	function cardHtml(r) {
		const cls = r.computed.rowColor === 'late' ? 'is-late' : r.computed.rowColor === 'done' ? 'is-done' : '';
		return html`<div class="ps-icard ${cls}" data-row="${r.id}">
			<div class="ps-pcard__top">${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })} ${L.badge(r.urgencyId)} ${r.computed.late ? html`<span class="ps-badge ps-badge--danger">${PS.t('لازم قرار')}</span>` : ''}</div>
			<div class="ps-pcard__name">${L.label(r.itemTypeId)}</div>
			<div class="ps-small ps-muted">${L.label(r.employeeId)} · ${PS.t('الأجل')} ${F.date(r.dueDate)}</div>
			<div class="ps-small ps-muted">${PS.t('المسؤول')}: ${L.label(r.responsibleEmpId)} · ${PS.t('المتابع')}: ${L.label(r.followerEmpId)}</div>
			<div class="ps-icard__edit">${statusCell(r)} ${filesCell(r)} ${invoicesCell(r)}</div>
		</div>`;
	}

	function renderRows() {
		const d = st.data;
		const box = st.main.querySelector('[data-results]');
		const counter = st.main.querySelector('[data-decision]');
		counter.innerHTML = html`<button type="button" class="ps-decision ${d.needDecisionAll ? 'is-on' : ''}" data-action="only-late">⚠ ${PS.t('لازم قرار:')} <strong>${d.needDecisionAll}</strong>${PS.config.user.employeeId ? html` <span class="ps-muted">(${PS.t('منها')} ${d.needDecisionMine} ${PS.t('لك')})</span>` : ''}</button>`.html;
		if (!d.rows.length) {
			box.innerHTML = ui.empty('📨', PS.t('لا توجد أصناف تطابق الفلاتر.')).html;
			return;
		}
		box.innerHTML = html`<p class="ps-muted ps-small" role="status">${d.total} ${PS.t('صنف ·')} <span class="ps-legend ps-legend--late"></span> ${PS.t('متأخر (لازم قرار) ·')} <span class="ps-legend ps-legend--done"></span> ${PS.t('مقبول')}</p>
			<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list ps-table--edit">
				<thead><tr>${HEADERS.map(function (h) { return html`<th>${h}</th>`; })}</tr></thead>
				<tbody>${d.rows.map(rowHtml)}</tbody></table></div>
			<div class="ps-cards ps-mobile-only">${d.rows.map(cardHtml)}</div>`.html;
	}

	function load() {
		const qs = new URLSearchParams(Object.assign({ view: 'offers', perPage: 300 }, st.params)).toString();
		return Promise.all([
			PS.api.get('items?' + qs),
			PS.api.get('counters', { passive: true }),
		]).then(function (res) {
			st.data = res[0];
			st.data.needDecisionAll = res[1].needDecision;
			st.data.needDecisionMine = res[1].needDecisionMine;
			renderRows();
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	function setParam(k, v) {
		if (v === '' || v === null || v === false || v === undefined) delete st.params[k]; else st.params[k] = v;
		PS.router.setParams(st.params);
		load();
	}

	function rowById(id) { return st.data.rows.find(function (r) { return String(r.id) === String(id); }); }

	function onFieldChange(el) {
		const tr = el.closest('[data-row]');
		const row = rowById(tr.dataset.row);
		const field = el.dataset.field;
		const ch = {};
		ch[field] = el.value === '' ? null : (field.slice(-2) === 'Id' ? Number(el.value) : el.value);
		PS.items.save(row, ch).then(function (updated) {
			if (updated && updated.id) Object.assign(row, updated);
			load();
		});
	}

	function bind() {
		const m = st.main;
		const onSearch = ui.debounce(function (v) { setParam('q', v.trim()); }, 300);
		m.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
		m.addEventListener('click', function (e) {
			const ex = e.target.closest('[data-export]');
			if (!ex) return;
			const qs = new URLSearchParams(Object.assign({}, st.params, { view: 'offers', format: ex.dataset.export, _wpnonce: PS.config.nonce })).toString();
			window.location.href = PS.config.restUrl + 'export/items?' + qs;
		});
		m.addEventListener('change', function (e) {
			const t = e.target;
			if (t.dataset.filter) setParam(t.dataset.filter, t.type === 'checkbox' ? (t.checked ? 1 : '') : t.value);
			else if (t.dataset.field) onFieldChange(t);
		});
		m.addEventListener('click', function (e) {
			const c = e.target.closest('[data-clear]');
			if (c) { setParam(c.dataset.clear, ''); render(); return; }
			if (e.target.closest('[data-action="only-late"]')) { setParam('late', st.params.late ? '' : 1); render(); return; }
			const invBtn = e.target.closest('[data-invoices]');
			if (invBtn) {
				PS.invoices.forOffer(rowById(invBtn.closest('[data-row]').dataset.row)).then(function () { load(); });
				return;
			}
			const fBtn = e.target.closest('[data-offer-files]');
			if (fBtn) {
				PS.invoices.offerFiles(rowById(fBtn.closest('[data-row]').dataset.row)).then(function () { load(); });
				return;
			}
			const reason = e.target.closest('[data-reason]');
			if (reason) {
				const row = rowById(reason.closest('[data-row]').dataset.row);
				PS.items.save(row, { offerStatusId: row.offerStatusId }).then(function () { load(); });
			}
		});
	}

	function render() {
		st.main.innerHTML = html`<div class="ps-page-head"><h1>${PS.t('متابعة العروض')}</h1><div data-decision class="ps-page-head__actions"></div></div>
			${filters()}<div data-results><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`.html;
		bind();
		return load();
	}

	PS.router.register({
		id: 'offres',
		title: PS.t('متابعة العروض'),
		icon: '📨',
		cap: 'ps_access',
		order: 40,
		group: 'work',
		render: function (main, params) {
			return L.load().then(function () {
				st = { main: main, params: Object.assign({}, params), data: null };
				return render();
			});
		},
	});
})();
