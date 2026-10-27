/**
 * Factures (1.6.0) — page « الفواتير » (#/factures, #/factures/ID) + fenêtres partagées avec Suivi Offres.
 *
 * Une facture est liée à une offre (صنف). Plusieurs factures par offre (paiements en tranches).
 * « آخر أجل للرد » est CALCULÉ par le serveur (date d'envoi + réglage « آخر أجل للرد على الفاتورة »), jamais saisi.
 * Une facture « في الانتظار » dont l'échéance est dépassée est en retard (jours affichés).
 *
 * PS.invoices.forOffer(row)   : factures d'une offre (liste + « فاتورة جديدة ») — depuis Suivi Offres ;
 * PS.invoices.open(id)        : fiche d'une facture (modification, fichiers) ;
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
	function form(ctx, inv) {
		const v = inv || { invoiceNo: '', sentDate: PS.config.today, answer: 'pending', amount: null, currencyId: (L.byCode('currency', 'LYD') || {}).id, note: '' };
		const ro = inv && !inv.canEdit;
		const dis = ro ? ui.raw(' disabled') : '';
		const days = PS.config.settings.invoiceReplyDays;
		let saved = null;
		return ui.modal({
			title: inv ? PS.t('الفاتورة') + ' ' + inv.invoiceNo : PS.t('فاتورة جديدة'),
			wide: true,
			body: html`<p class="ps-small ps-muted">${PS.t('المشروع')}: <strong>${ctx.projectName || ''}</strong> <span dir="ltr">${ctx.projectNo || ''}</span> · ${PS.t('الصنف')}: ${L.label(ctx.itemTypeId)}</p>
				<div class="ps-grid" data-inv-form>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('رقم الفاتورة *')}</span><input name="invoiceNo" data-key="invoiceNo" dir="ltr" value="${v.invoiceNo}"${dis}></label>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('تاريخ إرسال الفاتورة *')}</span><input type="date" name="sentDate" data-key="sentDate" value="${v.sentDate || ''}"${dis}></label>
					<div class="ps-field"><span class="ps-field__label">${PS.t('آخر أجل للرد')}</span><output data-deadline class="ps-input" style="display:block">${inv ? deadlineHtml(inv) : ''}</output>
						<span class="ps-small ps-muted">${PS.t('محسوب تلقائيًا: تاريخ الإرسال +')} ${days} ${PS.t('يوم')}</span></div>
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
				const body = { invoiceNo: f('invoiceNo').value, sentDate: f('sentDate').value, answer: f('answer').value, amount: f('amount').value, currencyId: f('currencyId').value ? Number(f('currencyId').value) : null, note: f('note').value };
				if (!body.invoiceNo.trim() || !body.sentDate) { ui.showErrors(wrap, { invoiceNo: body.invoiceNo.trim() ? '' : PS.t('خانة إجبارية'), sentDate: body.sentDate ? '' : PS.t('خانة إجبارية') }); ui.toast(PS.t('يرجى تعبئة الخانات الإجبارية'), 'error'); return false; }
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

	/** Fiche d'une facture (lien des alertes : #/factures/ID). */
	function open(id) {
		return PS.api.get('invoices/' + id).then(function (inv) {
			return form({ projectName: inv.projectName, projectNo: inv.projectNo, itemTypeId: inv.itemTypeId, itemId: inv.itemId }, inv);
		}).catch(function (e) { ui.toast(e.message, 'error'); return null; });
	}

	/** Factures d'une offre (Suivi Offres). */
	function forOffer(row) {
		const ctx = { projectName: row.projectName, projectNo: row.projectNo, itemTypeId: row.itemTypeId, itemId: row.id };
		const canAdd = row.canEdit || (PS.config.user.employeeId && row.followerEmpId === PS.config.user.employeeId);
		return PS.api.get('invoices?itemId=' + row.id).then(function (d) {
			return ui.modal({
				title: PS.t('🧾 فواتير العرض'),
				wide: true,
				body: html`<p class="ps-small ps-muted">${PS.t('المشروع')}: <strong>${row.projectName}</strong> <span dir="ltr">${row.projectNo}</span> · ${L.label(row.itemTypeId)}</p>
					${d.rows.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact">
						<thead><tr><th>${PS.t('رقم الفاتورة')}</th><th>${PS.t('تاريخ الإرسال')}</th><th>${PS.t('آخر أجل للرد')}</th><th>${PS.t('جواب الحريف')}</th><th>${PS.t('المبلغ')}</th><th></th></tr></thead>
						<tbody>${d.rows.map(function (v) {
							return html`<tr class="${v.computed.late ? 'is-late' : ''}"><td dir="ltr"><strong>${v.invoiceNo}</strong></td><td>${F.date(v.sentDate)}</td><td>${deadlineHtml(v)}</td><td>${answerBadge(v)}</td><td>${v.amount !== null ? F.money(v.amount, L.code(v.currencyId)) : ''}</td>
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

	return { forOffer: forOffer, open: open, offerFiles: offerFiles, deadlineHtml: deadlineHtml, answerBadge: answerBadge };
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
		const opt = function (v, label) { return '<option value="' + v + '"' + ((p.status || 'pending') === v ? ' selected' : '') + '>' + ui.esc(label) + '</option>'; };
		return html`<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
			<input type="search" class="ps-input ps-search" placeholder="🔍 ${PS.t('بحث: رقم الفاتورة، المشروع')}" value="${p.q || ''}" data-search>
			<select class="ps-input ps-input--auto" data-filter="status" aria-label="${PS.t('الحالة')}">${ui.raw(opt('pending', PS.t('في الانتظار')) + opt('late', PS.t('متأخرة')) + opt('answered', PS.t('تم الرد')) + opt('yes', PS.t('نعم')) + opt('no', PS.t('لا')) + opt('all', PS.t('الكل')))}</select>
			<select class="ps-input ps-input--auto" data-filter="employeeId" aria-label="${PS.t('الموظف المتابع')}">${ui.raw('<option value="">' + PS.t('كل الموظفين') + '</option>' + (PS.config.user.employeeId ? '<option value="me"' + (p.employeeId === 'me' ? ' selected' : '') + '>' + PS.t('فواتيري فقط') + '</option>' : '') + L.options('employee', p.employeeId, { noEmpty: true }))}</select>
		</div></div>`;
	}

	function renderRows() {
		const d = st.data;
		const box = st.main.querySelector('[data-results]');
		st.main.querySelector('[data-inv-counts]').innerHTML = html`<span class="ps-badge">${PS.t('في الانتظار')}: <strong>${d.counts.pending}</strong></span> <span class="ps-badge ps-badge--danger">${PS.t('متأخرة')}: <strong>${d.counts.late}</strong></span>`.html;
		if (!d.rows.length) {
			box.innerHTML = ui.empty('🧾', PS.t('لا توجد فواتير تطابق الفلاتر. تُضاف الفواتير من صفحة «متابعة العروض» (زر 🧾).')).html;
			return;
		}
		box.innerHTML = html`<p class="ps-muted ps-small" role="status">${d.total} ${PS.t('فاتورة')} · ${PS.t('آخر أجل للرد = تاريخ الإرسال +')} ${d.replyDays} ${PS.t('يوم')}</p>
			<div class="ps-table-wrap"><table class="ps-table ps-table--list">
				<thead><tr><th>${PS.t('رقم الفاتورة')}</th><th>${PS.t('المشروع')}</th><th>${PS.t('الصنف')}</th><th>${PS.t('تاريخ إرسال الفاتورة')}</th><th>${PS.t('آخر أجل للرد')}</th><th>${PS.t('جواب الحريف')}</th><th>${PS.t('المبلغ')}</th><th>${PS.t('الموظف المتابع')}</th></tr></thead>
				<tbody>${d.rows.map(function (v) {
					return html`<tr class="${v.computed.late ? 'is-late' : ''}" data-inv="${v.id}">
						<td dir="ltr"><button type="button" class="ps-link" data-open="${v.id}"><strong>${v.invoiceNo}</strong></button></td>
						<td>${ui.projectTitle({ id: v.projectId, name: v.projectName, no: v.projectNo, oldNo: v.oldNo })}</td>
						<td>${L.label(v.itemTypeId)}</td><td>${F.date(v.sentDate)}</td><td>${PS.invoices.deadlineHtml(v)}</td><td>${PS.invoices.answerBadge(v)}</td>
						<td>${v.amount !== null ? F.money(v.amount, L.code(v.currencyId)) : ''}</td><td>${L.label(v.followerEmpId)}</td>
					</tr>`;
				})}</tbody></table></div>`.html;
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
		st.main.addEventListener('click', function (e) {
			const b = e.target.closest('[data-open]');
			if (b) PS.invoices.open(Number(b.dataset.open)).then(load);
		});
		return load().then(function () {
			if (st.params.id) PS.invoices.open(Number(st.params.id)).then(load);
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
