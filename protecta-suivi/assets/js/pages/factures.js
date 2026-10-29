/**
 * Factures (1.6.0) — page « الفواتير » (#/factures, #/factures/ID) + fenêtres partagées avec Suivi Offres.
 *
 * Une facture est liée à une offre (صنف). Plusieurs factures par offre (paiements en tranches).
 * « آخر أجل للرد » est CALCULÉ par le serveur (date d'envoi + réglage « آخر أجل للرد على الفاتورة »), jamais saisi.
 * Une facture « في الانتظار » dont l'échéance est dépassée est en retard (jours affichés).
 *
 * PS.invoices.forOffer(row)   : factures d'une offre (liste + « فاتورة جديدة ») — depuis Suivi Offres ;
 * PS.invoices.open(id, opts)  : fiche d'une facture (modification, fichiers) ; opts.drawer = panneau latéral ;
 * 1.7.0 — page : tableau court (colonnes au choix), filtres rapides avec compteurs, clic sur la ligne → panneau.
 * PS.invoices.offerFiles(row) : fichiers joints de l'offre.
 */
window.PS = window.PS || {};

PS.invoices = (function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;

	function answerLabel(a) { return (PS.config.invoiceAnswers || {})[a] || a; }

	function answerBadge(inv) {
		const color = inv.answer === 'yes' ? '#2b8a3e' : inv.answer === 'no' ? '#e03131' : (inv.computed.late ? '#e03131' : '#f59f00');
		return ui.badge(answerLabel(inv.answer), color);
	}

	/** Échéance + retard / jours restants. */
	function deadlineHtml(inv) {
		const c = inv.computed;
		if (c.late) return html`${F.date(c.deadline)} <span class="ps-badge ps-badge--danger" data-late>${PS.t('متأخرة')} ${c.daysLate} ${PS.t('يوم')}</span>`;
		if (inv.answer === 'pending' && c.daysLeft === 0) return html`${F.date(c.deadline)} <strong>${PS.t('(اليوم)')}</strong>`;
		if (inv.answer === 'pending') return html`${F.date(c.deadline)} <span class="ps-muted ps-small">(${PS.t('بعد')} ${c.daysLeft} ${PS.t('يوم')})</span>`;
		return F.date(c.deadline);
	}

	function errText(e) {
		const errs = e.data && e.data.data && e.data.data.errors;
		return errs ? Object.keys(errs).map(function (k) { return errs[k]; }).join(' — ') : e.message;
	}

	/** Envoi de fichiers (un par un) vers le projet, liés à l'offre ou à la facture. */
	function upload(projectId, fileList, links) {
		let done = 0;
		return Array.prototype.reduce.call(fileList || [], function (chain, f) {
			return chain.then(function () {
				const fd = new FormData();
				fd.append('file', f);
				fd.append('displayName', f.name);
				Object.keys(links).forEach(function (k) { if (links[k]) fd.append(k, links[k]); });
				return PS.api.post('projects/' + projectId + '/files', fd).then(function () { done++; }).catch(function (e) { ui.toast(f.name + ': ' + e.message, 'error'); });
			});
		}, Promise.resolve()).then(function () {
			if (done) ui.toast(PS.t('تم رفع ') + done + PS.t(' ملف'), 'success');
			return done;
		});
	}

	function filesList(files) {
		if (!files || !files.length) return html`<p class="ps-muted ps-small">${PS.t('لا توجد ملفات.')}</p>`;
		return html`<ul class="ps-report">${files.map(function (f) {
			return html`<li><a href="${f.url}" target="_blank" rel="noopener">📄 ${f.name}</a> <span class="ps-muted ps-small">${F.size(f.size)} · ${f.author} · ${F.dateTime(f.createdAt)}</span></li>`;
		})}</ul>`;
	}

	/**
	 * Formulaire d'une facture (nouvelle : inv = null). → Promise<facture enregistrée | null>
	 */
	function form(ctx, inv, opts) {
		const days = PS.config.settings.invoiceReplyDays;
		const plus = function (iso, n) { const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
		const v = inv || { invoiceNo: '', sentDate: PS.config.today, answer: 'pending', amount: null, currencyId: (L.byCode('currency', 'LYD') || {}).id, note: '' };
		// 1.11.0 : « آخر أجل للرد » saisi (proposé : date d'envoi + N jours, suit la date d'envoi tant qu'on ne l'a pas changé).
		const deadline = (inv && (inv.replyDeadline || inv.computed.deadline)) || plus(v.sentDate, days);
		const ro = inv && !inv.canEdit;
		const dis = ro ? ui.raw(' disabled') : '';
		let saved = null;
		return ui.modal({
			title: inv ? PS.t('الفاتورة') + ' ' + inv.invoiceNo : PS.t('فاتورة جديدة'),
			wide: !(opts && opts.drawer),
			drawer: !!(opts && opts.drawer), // 1.7.0 : panneau latéral depuis la page « الفواتير »
			body: html`<p class="ps-small ps-muted">${PS.t('العرض')}: <strong>${ctx.projectName || ''}</strong> <span dir="ltr">${ctx.projectNo || ''}</span>${(ctx.itemTypeIds || []).length ? html` · ${PS.t('الأصناف')}: ${ctx.itemTypeIds.map(L.label).join(PS.t('، '))}` : ''}</p>
				<p class="ps-small ps-muted">${PS.t('الفاتورة تخص العرض كاملًا (كل أصناف المشروع).')}</p>
				${ro ? PS.items.readOnlyNote([PS.t('المسؤول عن المشروع') + (ctx.responsibleEmpId ? ' (' + L.label(ctx.responsibleEmpId) + ')' : ''), PS.t('الموظف المتابع') + (ctx.followerEmpId ? ' (' + L.label(ctx.followerEmpId) + ')' : ''), PS.t('موظفو أصناف العرض')]) : ''}
				<div class="ps-grid" data-inv-form>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('رقم الفاتورة *')}</span><input name="invoiceNo" data-key="invoiceNo" dir="ltr" value="${v.invoiceNo}"${dis}></label>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('تاريخ إرسال الفاتورة *')}</span><input type="date" name="sentDate" data-key="sentDate" value="${v.sentDate || ''}"${dis}></label>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('آخر أجل للرد *')}</span><input type="date" name="replyDeadline" data-key="replyDeadline" value="${deadline}"${dis}>
						<span class="ps-small ps-muted">${PS.t('مقترح: تاريخ الإرسال +')} ${days} ${PS.t('يوم')} · ${PS.t('تذكير كل')} ${PS.config.settings.invoiceRemindDays || 15} ${PS.t('يوم بعده حتى الرد')}</span>${inv ? html`<span class="ps-small">${deadlineHtml(inv)}</span>` : ''}</label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('جواب الحريف')}</span><select name="answer" data-key="answer"${dis}>${ui.raw(Object.keys(PS.config.invoiceAnswers).map(function (k) { return '<option value="' + k + '"' + (k === v.answer ? ' selected' : '') + '>' + ui.esc(answerLabel(k)) + '</option>'; }).join(''))}</select></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('المبلغ (اختياري)')}</span><input type="number" step="any" min="0" name="amount" data-key="amount" value="${v.amount === null || v.amount === undefined ? '' : v.amount}"${dis}></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('العملة')}</span><select name="currencyId" data-key="currencyId"${dis}>${ui.raw(L.options('currency', v.currencyId, { noEmpty: true }))}</select></label>
					<label class="ps-field ps-span-2"><span class="ps-field__label">${PS.t('ملاحظة الفاتورة')}</span><textarea name="note" rows="2" data-key="note"${dis}>${v.note || ''}</textarea></label>
				</div>
				<h3 class="ps-mt">📎 ${PS.t('ملفات الفاتورة')}</h3>
				<div data-inv-files>${inv ? filesList(inv.files) : ''}</div>
				${ro ? '' : html`<input type="file" multiple data-inv-upload>`}
				${inv ? html`<p class="ps-small ps-muted ps-mt">${PS.t('أُنشئت')} ${F.dateTime(inv.createdAt)} ${inv.createdBy ? PS.t('من طرف') + ' ' + inv.createdBy : ''}</p>` : ''}`,
			buttons: (ro ? [] : [{ id: 'save', label: PS.t('💾 حفظ'), kind: 'primary' }])
				.concat(inv && inv.canDelete ? [{ id: 'delete', label: PS.t('حذف'), kind: 'danger' }] : [])
				.concat([{ id: 'close', label: PS.t('إغلاق') }]),
			onOpen: function (wrap) {
				const sent = wrap.querySelector('[name="sentDate"]');
				const dl = wrap.querySelector('[name="replyDeadline"]');
				if (!sent || !dl) return;
				let touched = !!(inv && inv.replyDeadline);
				dl.addEventListener('change', function () { touched = true; });
				const follow = function () { if (!touched && sent.value) dl.value = plus(sent.value, days); };
				sent.addEventListener('change', follow);
				sent.addEventListener('input', follow);
			},
			onButton: function (id, wrap, close) {
				const f = function (n) { return wrap.querySelector('[name="' + n + '"]'); };
				if (id === 'delete') {
					ui.confirm(PS.t('نقل الفاتورة إلى سلة المحذوفات؟'), PS.t('حذف'), 'danger').then(function (ok) {
						if (!ok) return;
						PS.api.del('invoices/' + inv.id).then(function () { ui.toast(PS.t('تم الحذف'), 'success'); saved = { deleted: true }; close('ok'); }).catch(function (e) { ui.toast(errText(e), 'error'); });
					});
					return false;
				}
				if (id !== 'save') return true;
				const body = { invoiceNo: f('invoiceNo').value, sentDate: f('sentDate').value, replyDeadline: f('replyDeadline').value, answer: f('answer').value, amount: f('amount').value, currencyId: f('currencyId').value ? Number(f('currencyId').value) : null, note: f('note').value };
				if (!body.invoiceNo.trim() || !body.sentDate || !body.replyDeadline) {
					const e = {};
					if (!body.invoiceNo.trim()) e.invoiceNo = PS.t('خانة إجبارية');
					if (!body.sentDate) e.sentDate = PS.t('خانة إجبارية');
					if (!body.replyDeadline) e.replyDeadline = PS.t('خانة إجبارية');
					ui.showErrors(wrap, e);
					ui.toast(PS.t('يرجى تعبئة الخانات الإجبارية'), 'error');
					return false;
				}
				const req = inv ? PS.api.put('invoices/' + inv.id, body) : PS.api.post('items/' + ctx.itemId + '/invoices', body);
				const files = wrap.querySelector('[data-inv-upload]');
				req.then(function (r) {
					saved = r;
					return upload(r.projectId, files && files.files, { invoiceId: r.id });
				}).then(function () {
					ui.toast(PS.t('تم الحفظ'), 'success');
					close('ok');
				}).catch(function (e) {
					if (e.data && e.data.data && e.data.data.errors) ui.showErrors(wrap, e.data.data.errors);
					ui.toast(errText(e), 'error');
				});
				return false;
			},
		}).then(function () { return saved; });
	}

	/*
	 * 1.6.1 (demande du client) : colonnes importantes d'abord — رقم الفاتورة، رقم المشروع، تاريخ الإرسال، آخر أجل،
	 * جواب الحريف، المبلغ والعملة، 📎، ملاحظة الفاتورة — puis الصنف et الموظف المتابع. Même ordre sur la page « الفواتير »
	 * et dans la fenêtre « 🧾 فواتير العرض ». openAttr = attribut du bouton qui ouvre la facture (data-open / data-btn).
	 */
	/*
	 * Colonnes : [identifiant, titre, rendu(facture, open), importante]. 1.7.0 : la page « الفواتير » n'affiche par
	 * défaut que les 8 importantes (« الأعمدة » pour الصنف et الموظف المتابع) ; la fenêtre de l'offre les montre toutes.
	 */
	function btnOpen(v, open, content, cls, title) { return ui.raw('<button type="button" class="' + cls + '"' + (title ? ' title="' + ui.esc(title) + '"' : '') + ' ' + open(v.id) + '>' + content + '</button>'); }
	const COLS = [
		['invoiceNo', PS.t('رقم الفاتورة'), function (v, open) { return html`<span dir="ltr">${btnOpen(v, open, '<strong>' + ui.esc(v.invoiceNo) + '</strong>', 'ps-link')}</span>`; }, true],
		['projectNo', PS.t('رقم المشروع'), function (v) { return ui.projectTitle({ id: v.projectId, name: v.projectName, no: v.projectNo, oldNo: v.oldNo }, { numberFirst: true }); }, true],
		['sentDate', PS.t('تاريخ الإرسال'), function (v) { return F.date(v.sentDate); }, true],
		['deadline', PS.t('آخر أجل'), function (v) { return deadlineHtml(v); }, true],
		['answer', PS.t('جواب الحريف'), function (v) { return answerBadge(v); }, true],
		['amount', PS.t('المبلغ والعملة'), function (v) { return html`<span dir="ltr">${v.amount !== null ? F.money(v.amount, L.code(v.currencyId)) : ''}</span>`; }, true],
		['files', '📎', function (v, open) { return btnOpen(v, open, '📎 ' + (v.filesCount || 0), 'ps-btn ps-btn--small ps-nowrap', PS.t('ملفات الفاتورة')); }, true],
		['note', PS.t('ملاحظة الفاتورة'), function (v) { return html`<span class="ps-clip" title="${v.note || ''}">${v.note || ''}</span>`; }, true],
		// 1.11.0 : la facture couvre toute l'offre → toutes ses أصناف ; toutes les colonnes visibles (demande du client).
		['itemTypeId', PS.t('الأصناف'), function (v) { return (v.itemTypeIds || []).map(L.label).join(PS.t('، ')); }, true],
		['followerEmpId', PS.t('الموظف المتابع'), function (v) { return L.label(v.followerEmpId); }, true],
	];
	const NOWRAP = { sentDate: 1, deadline: 1, amount: 1 };

	function headRow(extra, cols) {
		return html`<tr>${(cols || COLS).map(function (c) { return html`<th>${c[1]}</th>`; })}${extra ? html`<th></th>` : ''}</tr>`;
	}

	function rowCells(v, open, cols) {
		return html`${(cols || COLS).map(function (c) { return html`<td class="${NOWRAP[c[0]] ? 'ps-nowrap' : ''}">${c[2](v, open)}</td>`; })}`;
	}

	function colDefs() { return COLS.map(function (c) { return { key: c[0], label: c[1], important: c[3], col: c }; }); }

	/** Fiche d'une facture (lien des alertes : #/factures/ID). */
	function open(id, opts) {
		return PS.api.get('invoices/' + id).then(function (inv) {
			return form({ projectName: inv.projectName, projectNo: inv.projectNo, itemTypeIds: inv.itemTypeIds, itemId: inv.itemId, responsibleEmpId: inv.responsibleEmpId, followerEmpId: inv.followerEmpId }, inv, opts);
		}).catch(function (e) { ui.toast(e.message, 'error'); return null; });
	}

	/** Factures d'une offre (Suivi Offres). */
	function forOffer(row) {
		const ctx = { projectName: row.projectName, projectNo: row.projectNo, itemTypeId: row.itemTypeId, itemId: row.id };
		const canAdd = row.canEdit || (PS.config.user.employeeId && row.followerEmpId === PS.config.user.employeeId);
		return PS.api.get('invoices?itemId=' + row.id).then(function (d) {
			// 1.11.0 : les factures de l'offre ENTIÈRE (toutes ses أصناف).
			if (d.rows[0]) ctx.itemTypeIds = d.rows[0].itemTypeIds;
			return ui.modal({
				title: PS.t('🧾 فواتير العرض'),
				wide: true,
				body: html`<p class="ps-small ps-muted">${PS.t('المشروع')}: <strong>${row.projectName}</strong> <span dir="ltr">${row.projectNo}</span> · ${L.label(row.itemTypeId)}</p>
					${d.rows.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact">
						<thead>${headRow(true)}</thead>
						<tbody>${d.rows.map(function (v) {
							return html`<tr class="${v.computed.late ? 'is-late' : ''}">${rowCells(v, function (id) { return 'data-btn="inv-' + id + '"'; })}
								<td><button type="button" class="ps-btn ps-btn--small" data-btn="inv-${v.id}">${v.canEdit ? PS.t('تعديل') : PS.t('عرض')}</button></td></tr>`;
						})}</tbody></table></div>` : html`<p class="ps-muted">${PS.t('لا توجد فواتير لهذا العرض.')}</p>`}`,
				buttons: (canAdd ? [{ id: 'new', label: PS.t('+ فاتورة جديدة'), kind: 'primary' }] : []).concat([{ id: 'close', label: PS.t('إغلاق') }]),
			}).then(function (r) {
				if (r === 'new') return form(ctx, null).then(function (saved) { return saved ? forOffer(row) : null; });
				if (r && r.indexOf('inv-') === 0) return open(Number(r.slice(4))).then(function () { return forOffer(row); });
				return null;
			});
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	/** Fichiers joints de l'offre (pas ceux des factures). */
	function offerFiles(row) {
		return PS.api.get('projects/' + row.projectId + '/files').then(function (all) {
			const files = all.filter(function (f) { return f.itemId === row.id && !f.invoiceId; });
			return ui.modal({
				title: PS.t('📎 ملفات العرض'),
				body: html`<p class="ps-small ps-muted"><strong>${row.projectName}</strong> <span dir="ltr">${row.projectNo}</span> · ${L.label(row.itemTypeId)}</p>${filesList(files)}
					${row.canEdit ? html`<label class="ps-field ps-mt"><span class="ps-field__label">${PS.t('إضافة ملف')}</span><input type="file" multiple data-offer-upload></label>` : ''}`,
				buttons: (row.canEdit ? [{ id: 'up', label: PS.t('رفع'), kind: 'primary' }] : []).concat([{ id: 'close', label: PS.t('إغلاق') }]),
				onButton: function (id, wrap, close) {
					if (id !== 'up') return true;
					const inp = wrap.querySelector('[data-offer-upload]');
					if (!inp.files.length) { ui.toast(PS.t('لم يتم اختيار ملف'), 'error'); return false; }
					upload(row.projectId, inp.files, { itemId: row.id }).then(function () { close('ok'); });
					return false;
				},
			});
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	return { forOffer: forOffer, open: open, offerFiles: offerFiles, deadlineHtml: deadlineHtml, answerBadge: answerBadge, headRow: headRow, rowCells: rowCells, colDefs: colDefs };
})();

/* ---------------------------------------------------------------------- */
/* Page « الفواتير »                                                       */
/* ---------------------------------------------------------------------- */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;
	let st = null;

	function toolbar() {
		const p = st.params;
		return html`<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
			<input type="search" class="ps-input ps-search" placeholder="🔍 ${PS.t('بحث: رقم الفاتورة، المشروع')}" value="${p.q || ''}" data-search>
			<select class="ps-input ps-input--auto" data-filter="employeeId" aria-label="${PS.t('الموظف المتابع')}">${ui.raw('<option value="">' + PS.t('كل الموظفين') + '</option>' + (PS.config.user.employeeId ? '<option value="me"' + (p.employeeId === 'me' ? ' selected' : '') + '>' + PS.t('فواتيري فقط') + '</option>' : '') + L.options('employee', p.employeeId, { noEmpty: true }))}</select>
		</div></div>`;
	}

	/** 1.7.0 : filtres rapides avec compteurs (remplacent la liste « الحالة »). */
	function chipsHtml() {
		const f = st.data.facets || {};
		return ui.chips('status', [
			{ value: 'pending', label: PS.t('في الانتظار'), count: f.pending },
			{ value: 'late', label: '⚠ ' + PS.t('متأخرة'), count: f.late, tone: f.late ? 'danger' : '' },
			{ value: 'answered', label: PS.t('تم الرد'), count: f.answered },
			{ value: 'yes', label: PS.t('نعم'), count: f.yes },
			{ value: 'no', label: PS.t('لا'), count: f.no },
			{ value: 'all', label: PS.t('الكل'), count: f.all },
		], st.params.status || 'pending');
	}

	/* Téléphone : une carte par facture, mêmes champs importants dans le même ordre. */
	function cardHtml(v) {
		return html`<div class="ps-icard ${v.computed.late ? 'is-late' : ''}" data-open-row="${v.id}" role="button" tabindex="0">
			<div class="ps-pcard__top"><strong dir="ltr">${v.invoiceNo}</strong> ${PS.invoices.answerBadge(v)}</div>
			<div class="ps-kvs">
				${ui.kv(PS.t('رقم المشروع'), ui.projectTitle({ name: v.projectName, no: v.projectNo, oldNo: v.oldNo }, { link: false, numberFirst: true }))}
				${ui.kv(PS.t('تاريخ الإرسال'), F.date(v.sentDate))}
				${ui.kv(PS.t('آخر أجل'), PS.invoices.deadlineHtml(v))}
				${ui.kv(PS.t('المبلغ والعملة'), v.amount !== null ? html`<span dir="ltr">${F.money(v.amount, L.code(v.currencyId))}</span>` : '')}
				${ui.kv('📎', String(v.filesCount || 0))}
				${ui.kv(PS.t('ملاحظة الفاتورة'), v.note || '')}
			</div>
		</div>`;
	}

	function renderRows() {
		const d = st.data;
		const box = st.main.querySelector('[data-results]');
		st.main.querySelector('[data-inv-counts]').innerHTML = html`<span class="ps-badge">${PS.t('في الانتظار')}: <strong>${d.counts.pending}</strong></span> <span class="ps-badge ps-badge--danger">${PS.t('متأخرة')}: <strong>${d.counts.late}</strong></span>`.html;
		const head = html`<div class="ps-list-head">${chipsHtml()}<button type="button" class="ps-btn ps-btn--ghost ps-btn--small ps-desktop-only" data-action="columns">▦ ${PS.t('الأعمدة')}</button></div>`;
		if (!d.rows.length) {
			box.innerHTML = html`${head}${ui.empty('🧾', PS.t('لا توجد فواتير تطابق الفلاتر. تُضاف الفواتير من صفحة «متابعة العروض» (زر 🧾).'))}`.html;
			return;
		}
		const cols = ui.visibleColumns('factures', PS.invoices.colDefs()).map(function (c) { return c.col; });
		const open = function (id) { return 'data-open="' + id + '"'; };
		box.innerHTML = html`${head}<p class="ps-muted ps-small" role="status">${d.total} ${PS.t('فاتورة')} · ${PS.t('آخر أجل يُكتب في كل فاتورة (مقترح: الإرسال +')} ${d.replyDays} ${PS.t('يوم)')} · ${PS.t('تذكير كل')} ${PS.config.settings.invoiceRemindDays || 15} ${PS.t('يوم بعده حتى الرد')}</p>
			<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list ps-table--clickable">
				<thead>${PS.invoices.headRow(false, cols)}</thead>
				<tbody>${d.rows.map(function (v) {
					return html`<tr class="${v.computed.late ? 'is-late' : ''}" data-inv="${v.id}" data-open-row="${v.id}" tabindex="0">${PS.invoices.rowCells(v, open, cols)}</tr>`;
				})}</tbody></table></div>
			<div class="ps-cards ps-mobile-only">${d.rows.map(cardHtml)}</div>`.html;
	}

	function load() {
		const qs = new URLSearchParams(Object.assign({ status: 'pending' }, st.params));
		qs.delete('id');
		return PS.api.get('invoices?' + qs.toString()).then(function (d) { st.data = d; renderRows(); }).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	function setParam(k, v) {
		if (v === '' || v === null || v === undefined) delete st.params[k]; else st.params[k] = v;
		delete st.params.id;
		PS.router.setParams(st.params);
		load();
	}

	function render() {
		st.main.innerHTML = html`<div class="ps-page-head"><h1>🧾 ${PS.t('الفواتير')}</h1><div class="ps-page-head__actions" data-inv-counts></div></div>
			${toolbar()}<div data-results><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`.html;
		const onSearch = ui.debounce(function (v) { setParam('q', v.trim()); }, 300);
		st.main.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
		st.main.addEventListener('change', function (e) { if (e.target.dataset.filter) setParam(e.target.dataset.filter, e.target.value); });
		const openInv = function (id) { PS.invoices.open(Number(id), { drawer: true }).then(load); };
		st.main.addEventListener('keydown', function (e) {
			const row = e.target.closest && e.target.closest('[data-open-row]');
			if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openInv(row.dataset.openRow); }
		});
		st.main.addEventListener('click', function (e) {
			const chip = e.target.closest('[data-chip]');
			if (chip) { setParam('status', chip.dataset.value); return; }
			if (e.target.closest('[data-action="columns"]')) { ui.chooseColumns('factures', PS.invoices.colDefs()).then(function (ch) { if (ch) renderRows(); }); return; }
			const b = e.target.closest('[data-open]');
			if (b) { openInv(b.dataset.open); return; }
			// Clic sur la ligne / la carte (hors lien du projet) → la facture dans le panneau latéral.
			const row = e.target.closest('[data-open-row]');
			if (row && !e.target.closest('a, button, input, select')) openInv(row.dataset.openRow);
		});
		return load().then(function () {
			if (st.params.id) PS.invoices.open(Number(st.params.id), { drawer: true }).then(load);
		});
	}

	PS.router.register({
		id: 'factures',
		title: PS.t('الفواتير'),
		icon: '🧾',
		cap: 'ps_access',
		order: 55,
		group: 'work',
		render: function (main, params) {
			return L.load().then(function () {
				st = { main: main, params: Object.assign({}, params), data: null };
				return render();
			});
		},
	});
})();
