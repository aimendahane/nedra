/**
 * Page « متابعة العروض » — Suivi Offres (#/offres).
 *
 * Une ligne par صنف (cahier §5) : رقم المشروع، الصنف، الموظف، الأجل، تاريخ تقديم العرض، الحالة،
 * رقم فاتورة العرض، ملاحظات. Ligne ROUGE = offre en retard (قيد الإعداد, أجل dépassé, hors L),
 * VERTE = مقبول (calculé par le serveur).
 * « لازم قرار » : filtre rapide (1.12.0 : plus de deuxième compteur en haut de page, avec « منها X لك »).
 * 1.7.0 : tableau court (8 colonnes importantes, « الأعمدة » pour les autres), filtres rapides par حالة العرض avec
 * compteurs, clic sur une ligne → panneau latéral où l'on modifie toute l'offre (fichiers et factures compris).
 *
 * Modification directe dans le tableau (حالة العرض seulement depuis 1.7.0, si l'utilisateur en a le droit) :
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

	/**
	 * Demande les informations complémentaires nécessaires, puis enregistre. → Promise<ligne|null>
	 * 1.7.0 : les questions s'enchaînent (le panneau de détails peut changer plusieurs champs à la fois) ; une
	 * information déjà fournie dans `changes` (motif, justification, date) n'est pas redemandée.
	 */
	function save(row, changes, opts) {
		const statusId = changes.offerStatusId;
		const code = statusId ? L.code(Number(statusId)) : null;
		const steps = [];

		if (code === 'not_sent' && !changes.notSentReasonId) steps.push(function (ch) {
			return ui.modal({
				title: PS.t('لم يُرسل — سبب عدم الإرسال'),
				body: html`<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('سبب عدم الإرسال *')}</span><select data-q="reason">${ui.raw(L.options('not_sent_reason', row.notSentReasonId, { emptyLabel: PS.t('اختر…') }))}</select></label>
					<label class="ps-field ps-mt"><span class="ps-field__label">${PS.t('ملاحظة (اختياري)')}</span><textarea data-q="note" rows="2">${row.notSentNote || ''}</textarea></label>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (id, box) {
					if (id !== 'ok') return true;
					const reason = box.querySelector('[data-q="reason"]').value;
					if (!reason) { box.querySelector('[data-q="reason"]').focus(); ui.toast(PS.t('اختر سبب عدم الإرسال'), 'error'); return false; }
					ch.notSentReasonId = Number(reason);
					ch.notSentNote = box.querySelector('[data-q="note"]').value;
					return true;
				},
			}).then(function (r) { return r === 'ok' ? ch : null; });
		});
		if ((changes.followerEmpId !== undefined || changes.responsibleEmpId !== undefined) && !changes.reason) steps.push(function (ch) {
			// 1.6.0 : changer المسؤول / المتابع d'une offre → justification obligatoire (journal).
			const who = ch.followerEmpId !== undefined ? PS.t('الموظف المتابع') : PS.t('المسؤول عن المشروع');
			const to = L.label(ch.followerEmpId !== undefined ? ch.followerEmpId : ch.responsibleEmpId);
			return ui.modal({
				title: PS.t('تغيير') + ' ' + who,
				body: html`<p>${who}: <strong>${to}</strong></p>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('سبب التغيير *')}</span><textarea data-q="reason" rows="2"></textarea></label>
					<p class="ps-small ps-muted">${PS.t('يُسجَّل في سجل التعديلات: من، متى، القيمة القديمة والجديدة والسبب.')}</p>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (id, box) {
					if (id !== 'ok') return true;
					const reason = box.querySelector('[data-q="reason"]').value.trim();
					if (!reason) { box.querySelector('[data-q="reason"]').focus(); ui.toast(PS.t('اكتب سبب التغيير (إجباري)'), 'error'); return false; }
					ch.reason = reason;
					return true;
				},
			}).then(function (r) { return r === 'ok' ? ch : null; });
		});
		if ((code === 'sent' || code === 'accepted' || code === 'rejected') && !row.projectSentDate && !changes.projectSentDate) steps.push(function (ch) {
			return ui.modal({
				title: PS.t('تاريخ إرسال العرض'),
				body: html`<p>${PS.t('المشروع')} <strong>${row.projectNo}</strong> ${PS.t('بدون تاريخ إرسال العرض. هذا التاريخ إجباري عند إرسال العرض.')}</p>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('تاريخ إرسال العرض *')}</span><input type="date" data-q="date" value="${PS.config.today}" min="${row.receivedDate || ''}"></label>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (id, box) {
					if (id !== 'ok') return true;
					const d = box.querySelector('[data-q="date"]').value;
					if (!d) return false;
					ch.projectSentDate = d;
					return true;
				},
			}).then(function (r) { return r === 'ok' ? ch : null; });
		});

		const ask = steps.reduce(function (pr, step) { return pr.then(function (ch) { return ch ? step(ch) : null; }); }, Promise.resolve(changes));
		return ask.then(function (ch) {
			if (!ch) return null;
			return PS.api.put('items/' + row.id, ch).then(function (updated) {
				// 1.12.0 : changement rapide depuis un tableau → « تم ✔ — ↩ تراجع » pendant 8 s.
				if (opts && opts.undo) ui.undoToast(PS.t('تم الحفظ ✔'), opts.undo);
				else ui.toast(PS.t('تم الحفظ'), 'success');
				PS.router.refreshCounts();
				return updated;
			}).catch(function (e) {
				const errs = e.data && e.data.data && e.data.data.errors;
				ui.toast(errs ? Object.keys(errs).map(function (k) { return errs[k]; }).join(' — ') : e.message, 'error');
				return null;
			});
		});
	}

	/**
	 * 1.12.0 — « تراجع » après un changement rapide de حالة (Suivi Offres / Livraison) : remet l'ancienne valeur
	 * (et l'ancien motif de « لم يُرسل », gardé par le serveur) ; c'est une modification normale, journalisée.
	 */
	function undoFor(row, field, after) {
		const prev = row[field];
		return function () {
			const ch = {};
			ch[field] = prev;
			if (field === 'offerStatusId' && row.notSentReasonId) ch.notSentReasonId = row.notSentReasonId;
			const back = Object.assign({}, row, field === 'offerStatusId' ? { projectSentDate: row.projectSentDate || PS.config.today } : {});
			save(back, ch).then(function () { if (after) after(); });
		};
	}

	/**
	 * 1.12.0 — note « 🔒 للعرض فقط » d'un panneau : POURQUOI on ne peut pas modifier et QUI peut le faire.
	 * who = [noms] (vides ignorés).
	 */
	function readOnlyNote(who) {
		const names = (who || []).filter(Boolean);
		return html`<div class="ps-ro-note" data-ro-note><span aria-hidden="true">🔒</span><span><strong>${PS.t('للعرض فقط.')}</strong> ${PS.t('يعدّله:')} ${names.concat([PS.t('الإدارة')]).join(PS.t('، '))}.</span></div>`;
	}

	return { save: save, undoFor: undoFor, readOnlyNote: readOnlyNote };
})();

(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;
	let st = null;

	/** Filtres rapides : الكل · ⚠ لازم قرار · un par حالة العرض (avec compteurs). */
	function chipValue() { return st.params.late ? 'late' : (st.params.offerStatus || ''); }

	function filters() {
		const p = st.params;
		const empOpts = ui.raw(('<option value="">' + PS.t('كل الموظفين') + '</option>') + (PS.config.user.employeeId ? '<option value="me"' + (p.employeeId === 'me' ? ' selected' : '') + ('>' + PS.t('أصنافي فقط') + '</option>') : '') + L.options('employee', p.employeeId, { noEmpty: true }));
		return html`<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
			<input type="search" class="ps-input ps-search" placeholder="🔍 ${PS.t('بحث: مشروع، شركة، صنف، فاتورة')}" value="${p.q || ''}" data-search>
			<select class="ps-input ps-input--auto" data-filter="employeeId" aria-label="${PS.t('الموظف')}">${empOpts}</select>
			${p.notSentReasonId ? html`<span class="ps-chip">${PS.t('سبب:')} ${L.label(Number(p.notSentReasonId))} <button type="button" class="ps-chip__x" data-clear="notSentReasonId">×</button></span>` : ''}
			${p.itemTypeId ? html`<span class="ps-chip">${PS.t('الصنف:')} ${L.label(Number(p.itemTypeId))} <button type="button" class="ps-chip__x" data-clear="itemTypeId">×</button></span>` : ''}
			${PS.router.can('ps_export') ? html`<button type="button" class="ps-btn" data-export="csv">⬇ CSV</button><button type="button" class="ps-btn" data-export="xlsx">⬇ Excel</button>` : ''}
		</div></div>`;
	}

	/** حالة العرض : la seule modification gardée DANS le tableau (changement rapide) ; le reste se modifie dans le panneau. */
	function statusCell(r) {
		if (!r.canEdit) return html`${L.offerBadge(r.offerStatusCode)}${r.offerStatusCode === 'not_sent' && r.notSentReasonId ? html`<div class="ps-small ps-muted">${L.label(r.notSentReasonId)}</div>` : ''}`;
		return html`<select class="ps-input ps-input--sm" data-field="offerStatusId" aria-label="${PS.t('حالة العرض')}">${ui.raw(L.options('offer_status', r.offerStatusId, { noEmpty: true }))}</select>
			${r.offerStatusCode === 'not_sent' ? html`<button type="button" class="ps-link ps-small" data-reason>${r.notSentReasonId ? L.label(r.notSentReasonId) : PS.t('سبب؟')}</button>` : ''}`;
	}

	/** 📎 fichiers de l'offre : icône + nombre (1.6.1 : colonne à part). */
	function filesCell(r) {
		// 1.12.0 : rien quand il n'y a pas de fichier (moins de « 📎 0 ») ; on ajoute un fichier depuis le panneau.
		if (!r.filesCount) return '';
		return html`<button type="button" class="ps-btn ps-btn--small ps-nowrap" data-offer-files title="${PS.t('ملفات العرض')}" aria-label="${PS.t('ملفات العرض')}">📎 ${r.filesCount || 0}</button>`;
	}

	/** 🧾 factures de l'offre (nombre, badge rouge si retard). */
	function invoicesCell(r) {
		const inv = r.invoices || { total: 0, late: 0 };
		return html`<button type="button" class="ps-btn ps-btn--small ps-nowrap ${inv.late ? 'ps-btn--danger' : ''}" data-invoices title="${PS.t('الفواتير')}">🧾 ${inv.total}${inv.late ? html` <span class="ps-badge ps-badge--danger">${inv.late}</span>` : ''}</button>`;
	}

	/*
	 * Colonnes : [identifiant, titre, rendu, importante].
	 * 1.6.1 (demande du client) : colonnes importantes d'abord — رقم المشروع، رقم فاتورة العرض، المسؤول، المتابع،
	 * تاريخ الإرسال، حالة العرض، 📎، ملاحظة العرض — puis الصنف، موظف الصنف، الاستعجال، الأجل، 🧾.
	 * 1.7.0 : seules les 8 importantes sont affichées par défaut (« الأعمدة » pour les autres) ; les cellules sont du
	 * texte simple (sauf حالة العرض) et un clic sur la ligne ouvre le panneau de modification.
	 */
	const COLS = [
		['projectNo', PS.t('رقم المشروع'), function (r) { return html`${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })}${r.computed.late ? html` <span class="ps-badge ps-badge--danger">${PS.t('لازم قرار')}</span>` : ''}`; }, true],
		['offerInvoiceNo', PS.t('رقم فاتورة العرض'), function (r) { return html`<span dir="ltr">${r.offerInvoiceNo || ''}</span>`; }, true],
		['responsibleEmpId', PS.t('المسؤول عن المشروع'), function (r) { return L.label(r.responsibleEmpId); }, true],
		['followerEmpId', PS.t('الموظف المتابع'), function (r) { return L.label(r.followerEmpId); }, true],
		['offerDate', PS.t('تاريخ الإرسال'), function (r) { return F.date(r.offerDate); }, true],
		['offerStatusId', PS.t('حالة العرض'), statusCell, true],
		['files', '📎', filesCell, true],
		['offerNotes', PS.t('ملاحظة العرض'), function (r) { return html`<span class="ps-clip" title="${r.offerNotes || ''}">${r.offerNotes || ''}</span>`; }, true],
		['itemTypeId', PS.t('الصنف'), function (r) { return html`${L.label(r.itemTypeId)}${r.quantity ? html` <span class="ps-muted">×${F.number(r.quantity)}</span>` : ''}`; }, false],
		['employeeId', PS.t('موظف الصنف'), function (r) { return L.label(r.employeeId); }, false],
		['urgencyId', PS.t('الاستعجال'), function (r) { return L.badge(r.urgencyId); }, false],
		['dueDate', PS.t('الأجل المطلوب'), function (r) { return F.date(r.dueDate); }, false],
		['invoices', PS.t('🧾 الفواتير'), invoicesCell, false],
	];

	function colDefs() { return COLS.map(function (c) { return { key: c[0], label: c[1], important: c[3], col: c }; }); }

	function rowClass(r) { return r.computed.rowColor === 'late' ? 'is-late' : r.computed.rowColor === 'done' ? 'is-done' : ''; }

	function rowHtml(cols) {
		return function (r) {
			return html`<tr class="${rowClass(r)}" data-row="${r.id}" data-open-row="${r.id}" tabindex="0">${cols.map(function (c) { return html`<td>${c[2](r)}</td>`; })}</tr>`;
		};
	}

	/* Téléphone : mêmes champs importants que le tableau, dans le même ordre (1.6.2) ; appui = panneau (1.7.0). */
	function cardHtml(r) {
		const line = function (label, value) { return html`<div class="ps-kv"><span class="ps-kv__k">${label}</span><span class="ps-kv__v">${value}</span></div>`; };
		return html`<div class="ps-icard ${rowClass(r)}" data-row="${r.id}" data-open-row="${r.id}" tabindex="0">
			<div class="ps-pcard__top">${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })} ${r.computed.late ? html`<span class="ps-badge ps-badge--danger">${PS.t('لازم قرار')}</span>` : ''}</div>
			<div class="ps-kvs">
				${line(PS.t('رقم فاتورة العرض'), r.offerInvoiceNo || '—')}
				${line(PS.t('المسؤول عن المشروع'), L.label(r.responsibleEmpId))}
				${line(PS.t('الموظف المتابع'), L.label(r.followerEmpId))}
				${line(PS.t('تاريخ الإرسال'), F.date(r.offerDate) || '—')}
			</div>
			<div class="ps-icard__edit">${statusCell(r)} ${filesCell(r)} ${invoicesCell(r)}</div>
			${r.offerNotes ? html`<div class="ps-small">${PS.t('ملاحظة العرض')}: ${r.offerNotes}</div>` : ''}
			<div class="ps-small ps-muted">${L.label(r.itemTypeId)} · ${L.label(r.employeeId)} · ${PS.t('الأجل')} ${F.date(r.dueDate)} ${L.badge(r.urgencyId)}</div>
		</div>`;
	}

	function chipsHtml() {
		const f = (st.data && st.data.facets) || { all: 0, late: 0 };
		const items = [
			{ value: '', label: PS.t('الكل'), count: f.all },
			{ value: 'late', label: '⚠ ' + PS.t('لازم قرار') + (PS.config.user.employeeId && st.data && st.data.needDecisionMine ? ' (' + PS.t('منها') + ' ' + st.data.needDecisionMine + ' ' + PS.t('لك') + ')' : ''), count: f.late, tone: f.late ? 'danger' : '' },
		].concat(L.values('offer_status').map(function (v) { return { value: v.code, label: v.label, count: f[v.code] || 0 }; }));
		return ui.chips('offer', items, chipValue());
	}

	function renderRows() {
		const d = st.data;
		const box = st.main.querySelector('[data-results]');
		const head = html`<div class="ps-list-head">${chipsHtml()}<button type="button" class="ps-btn ps-btn--ghost ps-btn--small ps-desktop-only" data-action="columns">▦ ${PS.t('الأعمدة')}</button></div>`;
		if (!d.rows.length) {
			box.innerHTML = html`${head}${ui.empty('📨', PS.t('لا توجد أصناف تطابق الفلاتر.'))}`.html;
			return;
		}
		const cols = ui.visibleColumns('offres', colDefs()).map(function (x) { return x.col; });
		box.innerHTML = html`${head}<p class="ps-muted ps-small" role="status">${d.total} ${PS.t('صنف ·')} <span class="ps-legend ps-legend--late"></span> ${PS.t('متأخر (لازم قرار) ·')} <span class="ps-legend ps-legend--done"></span> ${PS.t('مقبول')}</p>
			<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list ps-table--clickable">
				<thead><tr>${cols.map(function (c) { return html`<th>${c[1]}</th>`; })}</tr></thead>
				<tbody>${d.rows.map(rowHtml(cols))}</tbody></table></div>
			<div class="ps-cards ps-mobile-only">${d.rows.map(cardHtml)}</div>`.html;
	}

	/*
	 * 1.7.0 — panneau de l'offre : tout voir et tout modifier au même endroit (رقم فاتورة العرض، المسؤول / المتابع avec
	 * justification, تاريخ الإرسال، حالة العرض + motif, ملاحظة العرض), plus les fichiers et les factures de l'offre.
	 */
	function openDrawer(id) {
		const r = rowById(id);
		if (!r) return;
		const ro = !r.canEdit;
		const field = function (label, inner) { return html`<label class="ps-field"><span class="ps-field__label">${label}</span>${inner}</label>`; };
		const emp = function (f) { return r.canAssign ? html`<select class="ps-input" data-d="${f}">${ui.raw(L.options('employee', r[f], { noEmpty: true }))}</select>` : html`<div class="ps-readonly">${L.label(r[f]) || '—'}</div>`; };
		const inv = r.invoices || { total: 0, late: 0 };
		const body = html`<div class="ps-drawer">
			<div class="ps-drawer__head">
				${ui.projectTitle({ id: r.projectId, name: r.projectName, no: r.projectNo }, { numberFirst: true })}
				<div class="ps-drawer__badges">${L.offerBadge(r.offerStatusCode)} ${L.badge(r.urgencyId)} ${r.computed.late ? html`<span class="ps-badge ps-badge--danger">${PS.t('لازم قرار')}</span>` : ''}</div>
			</div>
			${ro && !r.canAssign ? PS.items.readOnlyNote([L.label(r.employeeId) && PS.t('موظف الصنف') + ' (' + L.label(r.employeeId) + ')', L.label(r.responsibleEmpId) && PS.t('المسؤول عن المشروع') + ' (' + L.label(r.responsibleEmpId) + ')']) : ''}
			<div class="ps-kvs">
				${ui.kv(PS.t('الصنف'), html`${L.label(r.itemTypeId)}${r.quantity ? html` ×${F.number(r.quantity)}` : ''}`)}
				${ui.kv(PS.t('موظف الصنف'), L.label(r.employeeId))}
				${ui.kv(PS.t('الأجل المطلوب'), F.date(r.dueDate))}
			</div>
			<div class="ps-drawer__section"><h3>${PS.t('العرض')}</h3><div class="ps-drawer__form">
				${field(PS.t('رقم فاتورة العرض'), ro ? html`<div class="ps-readonly" dir="ltr">${r.offerInvoiceNo || '—'}</div>` : html`<input class="ps-input ps-input--code" data-d="offerInvoiceNo" value="${r.offerInvoiceNo || ''}" dir="ltr">`)}
				${field(PS.t('المسؤول عن المشروع'), emp('responsibleEmpId'))}
				${field(PS.t('الموظف المتابع'), emp('followerEmpId'))}
				<label class="ps-field ps-field--required" data-d-reason-box hidden><span class="ps-field__label">${PS.t('سبب التغيير *')}</span><textarea class="ps-input" data-d="reason" rows="2"></textarea></label>
				${field(PS.t('تاريخ الإرسال'), ro ? html`<div class="ps-readonly">${F.date(r.offerDate) || '—'}</div>` : html`<input type="date" class="ps-input" data-d="offerDate" value="${r.offerDate || ''}">`)}
				${field(PS.t('حالة العرض'), ro ? html`<div class="ps-readonly">${L.offerBadge(r.offerStatusCode)}</div>` : html`<select class="ps-input" data-d="offerStatusId">${ui.raw(L.options('offer_status', r.offerStatusId, { noEmpty: true }))}</select>`)}
				<div data-d-notsent ${r.offerStatusCode === 'not_sent' ? '' : ui.raw('hidden')}>
					${field(PS.t('سبب عدم الإرسال *'), ro ? html`<div class="ps-readonly">${L.label(r.notSentReasonId) || '—'}</div>` : html`<select class="ps-input" data-d="notSentReasonId">${ui.raw(L.options('not_sent_reason', r.notSentReasonId, { emptyLabel: PS.t('اختر…') }))}</select>`)}
				</div>
				${field(PS.t('ملاحظة العرض'), ro ? html`<div class="ps-readonly">${r.offerNotes || '—'}</div>` : html`<textarea class="ps-input" data-d="offerNotes" rows="3">${r.offerNotes || ''}</textarea>`)}
			</div></div>
			<div class="ps-drawer__section ps-drawer__actions">
				<button type="button" class="ps-btn" data-btn="files">📎 ${PS.t('ملفات العرض')} (${r.filesCount || 0})</button>
				<button type="button" class="ps-btn ${inv.late ? 'ps-btn--danger' : ''}" data-btn="invoices">🧾 ${PS.t('الفواتير')} (${inv.total})</button>
				<a class="ps-btn ps-btn--ghost" href="#/projet?id=${r.projectId}" data-drawer-go>📁 ${PS.t('فتح بطاقة المشروع')}</a>
			</div>
		</div>`;
		const buttons = (ro && !r.canAssign) ? [{ id: 'close', label: PS.t('إغلاق') }] : [{ id: 'save', label: PS.t('حفظ'), kind: 'primary' }, { id: 'close', label: PS.t('إغلاق') }];
		ui.modal({
			title: PS.t('تفاصيل العرض'),
			body: body,
			drawer: true,
			buttons: buttons,
			onOpen: function (wrap) {
				const reasonBox = wrap.querySelector('[data-d-reason-box]');
				wrap.addEventListener('change', function (e) {
					const d = e.target.dataset.d;
					if (d === 'responsibleEmpId' || d === 'followerEmpId') {
						reasonBox.hidden = ['responsibleEmpId', 'followerEmpId'].every(function (f) { const el = wrap.querySelector('[data-d="' + f + '"]'); return !el || String(el.value) === String(r[f] || ''); });
					}
					if (d === 'offerStatusId') wrap.querySelector('[data-d-notsent]').hidden = L.code(Number(e.target.value)) !== 'not_sent';
				});
				wrap.addEventListener('click', function (e) { if (e.target.closest('[data-drawer-go]')) wrap.remove(); });
			},
			onButton: function (b, wrap, close) {
				if (b === 'files' || b === 'invoices') {
					close(null);
					(b === 'files' ? PS.invoices.offerFiles(r) : PS.invoices.forOffer(r)).then(function () { load(); });
					return false;
				}
				if (b !== 'save') return true;
				const ch = {};
				wrap.querySelectorAll('[data-d]').forEach(function (el) {
					const f = el.dataset.d;
					if (f === 'reason') return;
					let v = el.value === '' ? null : (f.slice(-2) === 'Id' ? Number(el.value) : el.value);
					if (String(v === null ? '' : v) !== String(r[f] === null || r[f] === undefined ? '' : r[f])) ch[f] = v;
				});
				if (ch.notSentReasonId !== undefined && L.code(Number(ch.offerStatusId || r.offerStatusId)) !== 'not_sent') delete ch.notSentReasonId;
				if (ch.notSentReasonId !== undefined && !ch.offerStatusId) ch.offerStatusId = r.offerStatusId;
				if (ch.responsibleEmpId !== undefined || ch.followerEmpId !== undefined) {
					const reason = wrap.querySelector('[data-d="reason"]').value.trim();
					if (!reason) { ui.toast(PS.t('اكتب سبب التغيير (إجباري)'), 'error'); wrap.querySelector('[data-d="reason"]').focus(); return false; }
					ch.reason = reason;
				}
				if (!Object.keys(ch).length) { close('close'); return false; }
				PS.items.save(r, ch).then(function (updated) {
					if (!updated) return;
					close('save');
					load();
				});
				return false;
			},
		});
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
		const undo = PS.items.undoFor(Object.assign({}, row), field, load);
		PS.items.save(row, ch, { undo: undo }).then(function (updated) {
			if (updated && updated.id) Object.assign(row, updated);
			load();
		});
	}

	function bind() {
		const m = st.main;
		const onSearch = ui.debounce(function (v) { setParam('q', v.trim()); }, 300);
		m.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
		// Écouteurs délégués posés UNE fois par page (un nouvel affichage de la page ne doit pas les doubler).
		if (st.bound) return;
		st.bound = true;
		m.addEventListener('change', function (e) {
			const t = e.target;
			if (t.dataset.filter) setParam(t.dataset.filter, t.type === 'checkbox' ? (t.checked ? 1 : '') : t.value);
			else if (t.dataset.field) onFieldChange(t);
		});
		m.addEventListener('keydown', function (e) {
			const row = e.target.closest && e.target.closest('[data-open-row]');
			if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openDrawer(row.dataset.openRow); }
		});
		m.addEventListener('click', function (e) {
			const ex = e.target.closest('[data-export]');
			if (ex) {
				const qs = new URLSearchParams(Object.assign({}, st.params, { view: 'offers', format: ex.dataset.export, _wpnonce: PS.config.nonce })).toString();
				window.location.href = PS.config.restUrl + 'export/items?' + qs;
				return;
			}
			const chip = e.target.closest('[data-chip]');
			if (chip) {
				const v = chip.dataset.value;
				delete st.params.late; delete st.params.offerStatus;
				if (v === 'late') st.params.late = 1; else if (v) st.params.offerStatus = v;
				setParam('page', '');
				return;
			}
			const c = e.target.closest('[data-clear]');
			if (c) { setParam(c.dataset.clear, ''); render(); return; }
			if (e.target.closest('[data-action="only-late"]')) { delete st.params.offerStatus; setParam('late', st.params.late ? '' : 1); return; }
			if (e.target.closest('[data-action="columns"]')) { ui.chooseColumns('offres', colDefs()).then(function (ch) { if (ch) renderRows(); }); return; }
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
				return;
			}
			// Clic ailleurs sur la ligne / la carte (hors lien, bouton, liste) → panneau de l'offre.
			const open = e.target.closest('[data-open-row]');
			if (open && !e.target.closest('a, button, input, select, textarea, label')) openDrawer(open.dataset.openRow);
		});
	}

	function render() {
		// 1.12.0 : « لازم قرار » n'apparaît plus deux fois (seulement dans les filtres rapides, avec « منها X لك »).
		st.main.innerHTML = html`<div class="ps-page-head"><h1>${PS.t('متابعة العروض')}</h1></div>
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
