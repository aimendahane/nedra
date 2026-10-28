/**
 * Onglets d'un projet existant (complément de fiche-projet.js) :
 *   - سجل المتابعة : chronologie (date, auteur, texte, prochaine date) + ajout / modification ;
 *   - الملفات      : fichiers joints (envoi, ouverture, retrait) ;
 *   - سجل التعديلات : journal « qui, quoi, quand ».
 *
 * PS.projectTabs.<onglet>(conteneur, projet)
 */
window.PS = window.PS || {};

PS.projectTabs = (function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const F = PS.format;

	/* ------------------------------------------------------------------ */
	/* سجل المتابعة                                                       */
	/* ------------------------------------------------------------------ */

	function followups(box, project) {
		PS.api.get('projects/' + project.id + '/followups').then(function (rows) {
			const canAdd = project.permissions.canAddFollowup && !project.deletedAt;
			box.innerHTML = html`
				${canAdd ? followupForm(null) : html`<p class="ps-alert ps-alert--info">${PS.t('إضافة متابعة متاحة للمتدخلين في المشروع.')}</p>`}
				<section class="ps-card">
					<h2 class="ps-card__title">${PS.t('سجل المتابعة')} <span class="ps-muted">(${rows.length})</span></h2>
					${rows.length ? html`<ol class="ps-timeline">${rows.map(function (f) {
						const overdue = f.nextDate && f.nextDate < PS.config.today;
						return html`<li class="ps-timeline__item" data-id="${f.id}">
							<div class="ps-timeline__meta"><strong>${F.date(f.date)}</strong> · ${f.author}
								${f.nextDate ? html` · <span class="${overdue ? 'ps-text-danger' : ''}">${PS.t('المتابعة القادمة:')} ${F.date(f.nextDate)}</span>` : ''}
								${f.canEdit ? html`<button type="button" class="ps-btn ps-btn--small ps-btn--ghost" data-edit-followup="${f.id}">${PS.t('تعديل')}</button>` : ''}</div>
							<div class="ps-timeline__text">${f.text}</div>
						</li>`;
					})}</ol>` : ui.empty('🗒️', PS.t('لا توجد متابعات بعد. NOTE يبقى لنص الطلب.'))}
				</section>`.html;
			box.onclick = function (e) {
				const b = e.target.closest('[data-edit-followup]');
				if (!b) return;
				const f = rows.find(function (x) { return String(x.id) === b.dataset.editFollowup; });
				const li = b.closest('li');
				li.innerHTML = followupForm(f).html;
				bindFollowupForm(li.querySelector('form'), project, box, f);
			};
			const form = box.querySelector('form[data-followup]');
			if (form) bindFollowupForm(form, project, box, null);
		}).catch(function (e) { box.innerHTML = html`<div class="ps-alert ps-alert--error">${e.message}</div>`.html; });
	}

	function followupForm(f) {
		return html`<form class="ps-card ps-followup-form" data-followup novalidate>
			<h2 class="ps-card__title">${f ? PS.t('تعديل المتابعة') : PS.t('إضافة متابعة')}</h2>
			<div class="ps-grid">
				<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('التاريخ *')}</span><input type="date" name="date" data-key="date" value="${f ? f.date : PS.config.today}"></label>
				<label class="ps-field"><span class="ps-field__label">${PS.t('تاريخ المتابعة القادمة')}</span><input type="date" name="nextDate" data-key="nextDate" value="${f && f.nextDate ? f.nextDate : ''}"></label>
			</div>
			<label class="ps-field ps-field--required ps-mt"><span class="ps-field__label">${PS.t('النص *')}</span><textarea name="text" rows="3" data-key="text" placeholder="${PS.t('مثال: اتصال بالحريف، طلب تعديل الكمية…')}">${f ? f.text : ''}</textarea></label>
			<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">${f ? PS.t('حفظ') : PS.t('+ إضافة')}</button>${f ? html`<button type="button" class="ps-btn" data-cancel>${PS.t('إلغاء')}</button>` : ''}</div>
		</form>`;
	}

	function bindFollowupForm(form, project, box, f) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const body = { date: form.date.value, nextDate: form.nextDate.value, text: form.text.value };
			const req = f ? PS.api.put('followups/' + f.id, body) : PS.api.post('projects/' + project.id + '/followups', body);
			form.querySelector('[type=submit]').disabled = true;
			req.then(function () {
				ui.toast(f ? PS.t('تم حفظ التعديل') : PS.t('تمت إضافة المتابعة'), 'success');
				followups(box, project);
			}).catch(function (err) {
				form.querySelector('[type=submit]').disabled = false;
				if (err.code === 'ps_validation') ui.showErrors(form, err.data.data.errors);
				ui.toast(err.message, 'error');
			});
		});
		const c = form.querySelector('[data-cancel]');
		if (c) c.addEventListener('click', function () { followups(box, project); });
	}

	/* ------------------------------------------------------------------ */
	/* الملفات                                                            */
	/* ------------------------------------------------------------------ */

	function files(box, project) {
		PS.api.get('projects/' + project.id + '/files').then(function (rows) {
			const canAdd = project.permissions.canAddFollowup && !project.deletedAt;
			box.innerHTML = html`<section class="ps-card">
				<h2 class="ps-card__title">${PS.t('ملفات المشروع')} <span class="ps-muted">(${rows.length})</span></h2>
				${project.filesLink ? html`<p>🔗 ${PS.t('رابط ملف المشاريع:')} <a href="${project.filesLink}" target="_blank" rel="noopener noreferrer" dir="ltr">${project.filesLink}</a></p>` : ''}
				${canAdd ? html`<label class="ps-dropzone" data-drop>
					<input type="file" multiple hidden data-file>
					📎 ${PS.t('اضغط أو اسحب الملفات هنا (كل الأنواع ما عدا البرامج والسكربتات — الحد الأقصى')} ${F.size(PS.config.settings.maxUpload)})
				</label><div data-progress></div>` : ''}
				${rows.length ? html`<div class="ps-table-wrap"><table class="ps-table">
					<thead><tr><th>${PS.t('الملف')}</th><th>${PS.t('الحجم')}</th><th>${PS.t('رفعه')}</th><th>${PS.t('التاريخ')}</th><th>${PS.t('المكان')}</th><th></th></tr></thead>
					<tbody>${rows.map(function (f) {
						return html`<tr><td><a href="${f.url}" target="_blank" rel="noopener"><strong>${fileIcon(f.mime)} ${f.name}</strong></a>${f.originalName && f.originalName !== f.name ? html`<div class="ps-small ps-muted" dir="auto">${f.originalName}</div>` : ''}</td><td>${F.size(f.size)}</td><td>${f.author}</td><td>${F.dateTime(f.createdAt)}</td>
							<td>${f.storage === 'drive' ? html`<span class="ps-small" title="${PS.t('محفوظ في Google Drive')}">☁ Drive</span>` : html`<span class="ps-small ps-muted" title="${PS.t('على الخادم (يُرسل إلى Google Drive عند ربطه)')}">🖥 ${PS.t('الخادم')}</span>`}</td>
							<td>${f.canDelete ? html`<button type="button" class="ps-icon-btn ps-icon-btn--sm" data-del-file="${f.id}" aria-label="${PS.t('حذف')}">🗑</button>` : ''}</td></tr>`;
					})}</tbody></table></div>` : ui.empty('📂', PS.t('لا توجد ملفات.'))}
			</section>`.html;

			const input = box.querySelector('[data-file]');
			const drop = box.querySelector('[data-drop]');
			if (input) {
				input.addEventListener('change', function () { upload(input.files, project, box); });
				['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
				['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
				drop.addEventListener('drop', function (e) { e.preventDefault(); upload(e.dataTransfer.files, project, box); });
			}
			box.onclick = function (e) {
				const b = e.target.closest('[data-del-file]');
				if (!b) return;
				ui.confirm(PS.t('حذف هذا الملف من المشروع؟'), PS.t('حذف'), 'danger').then(function (ok) {
					if (!ok) return;
					PS.api.del('files/' + b.dataset.delFile).then(function () { ui.toast(PS.t('تم حذف الملف'), 'success'); files(box, project); })
						.catch(function (err) { ui.toast(err.message, 'error'); });
				});
			};
		}).catch(function (e) { box.innerHTML = html`<div class="ps-alert ps-alert--error">${e.message}</div>`.html; });
	}

	function fileIcon(mime) {
		if (/^image\//.test(mime)) return '🖼️';
		if (/pdf/.test(mime)) return '📕';
		if (/sheet|excel|csv/.test(mime)) return '📗';
		if (/word/.test(mime)) return '📘';
		return '📄';
	}

	/** Extension refusée (même liste que le serveur ; le serveur vérifie aussi le contenu). */
	function blockedExt(name) {
		const parts = String(name).toLowerCase().replace(/^\.+/, '').split('.').slice(String(name).startsWith('.') ? 0 : 1);
		return parts.find(function (p) { return (PS.config.settings.blockedExts || []).indexOf(p.trim()) >= 0; }) || null;
	}

	/** Avant l'envoi : nom d'affichage de chaque fichier (pré-rempli avec le nom du fichier). */
	function upload(list, project, box) {
		const all = Array.prototype.slice.call(list || []);
		const ok = all.filter(function (f) {
			const bad = blockedExt(f.name);
			if (bad) { ui.toast(f.name + ': ' + PS.t('نوع الملف غير مسموح (.') + bad + PS.t('): الملفات التنفيذية والبرمجية ممنوعة لحماية الموقع.'), 'error'); return false; }
			if (f.size > PS.config.settings.maxUpload) { ui.toast(f.name + PS.t(': الملف كبير جدًا') + ' (' + F.size(PS.config.settings.maxUpload) + ')', 'error'); return false; }
			return true;
		});
		if (!ok.length) return;
		ui.modal({
			title: '📎 ' + PS.t('إضافة ملفات') + ' (' + ok.length + ')',
			body: html`<p class="ps-small ps-muted">${PS.t('اسم العرض: يظهر للفريق بدل اسم الملف (يمكن تركه كما هو).')}</p><div class="ps-grid">${ok.map(function (f, i) {
				return html`<label class="ps-field ps-span-2"><span class="ps-field__label" dir="auto">${f.name} · ${F.size(f.size)}</span><input data-dn="${i}" value="${f.name.replace(/\.[^.]+$/, '')}" maxlength="200"></label>`;
			})}</div>`,
			buttons: [{ id: 'ok', label: '⬆ ' + PS.t('رفع'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
			onButton: function (id, mbox, close) {
				if (id !== 'ok') return true;
				const names = ok.map(function (f, i) { return mbox.querySelector('[data-dn="' + i + '"]').value.trim(); });
				close('ok');
				send(ok, names, project, box);
				return false;
			},
		});
	}

	function send(filesArr, names, project, box) {
		const progress = box.querySelector('[data-progress]');
		let done = 0;
		progress.innerHTML = html`<p class="ps-muted">${PS.t('جاري الرفع… (0/')}${filesArr.length})</p>`.html;
		filesArr.reduce(function (p, file) {
			return p.then(function () {
				const fd = new FormData();
				fd.append('file', file);
				if (names[filesArr.indexOf(file)]) fd.append('displayName', names[filesArr.indexOf(file)]);
				return PS.api.post('projects/' + project.id + '/files', fd).then(function () {
					done++;
					progress.innerHTML = html`<p class="ps-muted">${PS.t('جاري الرفع… (')}${done}/${filesArr.length})</p>`.html;
				}).catch(function (err) { ui.toast(file.name + ': ' + err.message, 'error'); });
			});
		}, Promise.resolve()).then(function () {
			if (done) ui.toast(PS.t('تم رفع ') + done + PS.t(' ملف'), 'success');
			files(box, project);
		});
	}

	/* ------------------------------------------------------------------ */
	/* سجل التعديلات                                                      */
	/* ------------------------------------------------------------------ */

	const ACTIONS = { create: PS.t('إضافة'), update: PS.t('تعديل'), delete: PS.t('حذف'), restore: 'استرجاع', merge: PS.t('دمج'), import: PS.t('استيراد') };
	const ENTITIES = { project: PS.t('المشروع'), party: PS.t('طرف'), item: PS.t('صنف'), competitor: PS.t('منافس'), followup: PS.t('متابعة'), file: PS.t('ملف'), invoice: PS.t('فاتورة'), task: PS.t('مهمة') };
	const FIELDS = {
		offer_no: PS.t('رقم العرض'), name: PS.t('اسم المشروع'), received_date: PS.t('تاريخ الاستلام'), sent_date: PS.t('تاريخ الإرسال'),
		responsible_emp_id: PS.t('المسؤول'), follower_emp_id: PS.t('المتابع'), intermediary_emp_id: PS.t('الوسيط'), place_id: PS.t('المكان'),
		category_id: PS.t('التصنيف'), urgency_id: PS.t('الاستعجال'), importance_kind_id: PS.t('الأهمية كصنف'), importance_size_id: PS.t('الأهمية كحجم'),
		followup_status_id: PS.t('حالة المتابعة'), source_id: PS.t('مصدر الطلب'), loss_reason_id: PS.t('سبب الخسارة'), offer_amount: PS.t('مبلغ العرض'),
		offer_currency_id: PS.t('عملة العرض'), purchase_amount: PS.t('مبلغ الشراء'), purchase_currency_id: PS.t('عملة الشراء'), files_link: PS.t('الرابط'),
		note: 'NOTE', item_type_id: PS.t('الصنف'), quantity: PS.t('الكمية'), employee_id: PS.t('الموظف'), due_date: PS.t('الأجل'),
		offer_status_id: PS.t('حالة العرض'), offer_date: PS.t('تاريخ تقديم العرض'), not_sent_reason_id: PS.t('سبب عدم الإرسال'), not_sent_note: PS.t('ملاحظة عدم الإرسال'),
		offer_invoice_no: PS.t('فاتورة العرض'), offer_notes: PS.t('ملاحظات'), supplier_invoice_no: PS.t('فاتورة المورد'), availability_id: PS.t('المتوفر'),
		delivery_due_date: PS.t('أجل التسليم'), delivery_actual_date: PS.t('التسليم الفعلي'), delivery_status_id: PS.t('حالة التسليم'), delivery_notes: PS.t('ملاحظات التسليم'),
		date: PS.t('التاريخ'), entry_date: PS.t('التاريخ'), text: PS.t('النص'), next_date: PS.t('المتابعة القادمة'),
		// 1.6.0
		is_project: PS.t('مشروع؟'), non_project_reason_id: PS.t('سبب عدم اعتباره مشروعا'), non_project_note: PS.t('ملاحظة (مشروع مُلغى)'), project_note: PS.t('ملاحظة المشروع'),
		invoice_no: PS.t('رقم الفاتورة'), answer: PS.t('جواب الحريف'), amount: PS.t('المبلغ'), currency_id: PS.t('العملة'), sent_date_invoice: PS.t('تاريخ إرسال الفاتورة'), reply_deadline: PS.t('آخر أجل للرد'),
	};
	const ROLES = { owner: PS.t('المالك'), executor: PS.t('الشركة المنفذة'), direct: PS.t('الشخص المباشر'), broker: PS.t('الوسيط') };
	const PARTY_COLS = { company_id: PS.t('الشركة'), person: PS.t('الشخص'), email: PS.t('الإيميل'), phone: PS.t('الهاتف'), address: PS.t('العنوان'), position: PS.t('المنصب'), rank_title: PS.t('الرتبة'), margin: PS.t('هامش الربح'), location: PS.t('الموقع') };

	function fieldLabel(entity, field) {
		if (!field) return '';
		if (entity === 'party') {
			const parts = field.split('.');
			return (ROLES[parts[0]] || parts[0]) + (parts[1] ? ' — ' + (PARTY_COLS[parts[1]] || parts[1]) : '');
		}
		return FIELDS[field] || field;
	}

	function log(box, project) {
		PS.api.get('projects/' + project.id + '/changelog').then(function (rows) {
			box.innerHTML = html`<section class="ps-card">
				<h2 class="ps-card__title">${PS.t('سجل التعديلات')} <span class="ps-muted">(${PS.t('من، ماذا، متى')})</span></h2>
				${rows.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--wrap">
					<thead><tr><th>${PS.t('التاريخ')}</th><th>${PS.t('المستخدم')}</th><th>${PS.t('العملية')}</th><th>${PS.t('الخانة')}</th><th>${PS.t('القيمة القديمة')}</th><th>${PS.t('القيمة الجديدة')}</th></tr></thead>
					<tbody>${rows.map(function (r) {
						return html`<tr><td class="ps-nowrap">${F.dateTime(r.at)}</td><td>${r.user}</td><td>${ACTIONS[r.action] || r.action} ${ENTITIES[r.entity] || r.entity}</td>
							<td>${fieldLabel(r.entity, r.field)}</td><td class="ps-old">${r.old === null ? '—' : PS.t(r.old)}</td><td>${r.new === null ? '—' : PS.t(r.new)}${r.reason ? html`<div class="ps-small ps-muted">📝 ${PS.t('التبرير:')} ${r.reason}</div>` : ''}</td></tr>`;
					})}</tbody></table></div>` : ui.empty('📜', PS.t('لا توجد تعديلات.'))}
			</section>`.html;
		}).catch(function (e) { box.innerHTML = html`<div class="ps-alert ps-alert--error">${e.message}</div>`.html; });
	}

	return { followups: followups, files: files, log: log };
})();
