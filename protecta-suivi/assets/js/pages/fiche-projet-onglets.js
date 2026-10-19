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
				${canAdd ? followupForm(null) : html`<p class="ps-alert ps-alert--info">إضافة متابعة متاحة للمتدخلين في المشروع.</p>`}
				<section class="ps-card">
					<h2 class="ps-card__title">سجل المتابعة <span class="ps-muted">(${rows.length})</span></h2>
					${rows.length ? html`<ol class="ps-timeline">${rows.map(function (f) {
						const overdue = f.nextDate && f.nextDate < PS.config.today;
						return html`<li class="ps-timeline__item" data-id="${f.id}">
							<div class="ps-timeline__meta"><strong>${F.date(f.date)}</strong> · ${f.author}
								${f.nextDate ? html` · <span class="${overdue ? 'ps-text-danger' : ''}">المتابعة القادمة: ${F.date(f.nextDate)}</span>` : ''}
								${f.canEdit ? html`<button type="button" class="ps-btn ps-btn--small ps-btn--ghost" data-edit-followup="${f.id}">تعديل</button>` : ''}</div>
							<div class="ps-timeline__text">${f.text}</div>
						</li>`;
					})}</ol>` : ui.empty('🗒️', 'لا توجد متابعات بعد. NOTE يبقى لنص الطلب.')}
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
			<h2 class="ps-card__title">${f ? 'تعديل المتابعة' : 'إضافة متابعة'}</h2>
			<div class="ps-grid">
				<label class="ps-field ps-field--required"><span class="ps-field__label">التاريخ *</span><input type="date" name="date" data-key="date" value="${f ? f.date : PS.config.today}"></label>
				<label class="ps-field"><span class="ps-field__label">تاريخ المتابعة القادمة</span><input type="date" name="nextDate" data-key="nextDate" value="${f && f.nextDate ? f.nextDate : ''}"></label>
			</div>
			<label class="ps-field ps-field--required ps-mt"><span class="ps-field__label">النص *</span><textarea name="text" rows="3" data-key="text" placeholder="مثال: اتصال بالحريف، طلب تعديل الكمية…">${f ? f.text : ''}</textarea></label>
			<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">${f ? 'حفظ' : '+ إضافة'}</button>${f ? html`<button type="button" class="ps-btn" data-cancel>إلغاء</button>` : ''}</div>
		</form>`;
	}

	function bindFollowupForm(form, project, box, f) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			const body = { date: form.date.value, nextDate: form.nextDate.value, text: form.text.value };
			const req = f ? PS.api.put('followups/' + f.id, body) : PS.api.post('projects/' + project.id + '/followups', body);
			form.querySelector('[type=submit]').disabled = true;
			req.then(function () {
				ui.toast(f ? 'تم حفظ التعديل' : 'تمت إضافة المتابعة', 'success');
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
				<h2 class="ps-card__title">ملفات المشروع <span class="ps-muted">(${rows.length})</span></h2>
				${project.filesLink ? html`<p>🔗 رابط ملف المشاريع: <a href="${project.filesLink}" target="_blank" rel="noopener noreferrer" dir="ltr">${project.filesLink}</a></p>` : ''}
				${canAdd ? html`<label class="ps-dropzone" data-drop>
					<input type="file" multiple hidden data-file accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp">
					📎 اضغط أو اسحب الملفات هنا (PDF، Excel، Word، صور — الحد الأقصى ${F.size(PS.config.settings.maxUpload)})
				</label><div data-progress></div>` : ''}
				${rows.length ? html`<div class="ps-table-wrap"><table class="ps-table">
					<thead><tr><th>الملف</th><th>الحجم</th><th>رفعه</th><th>التاريخ</th><th></th></tr></thead>
					<tbody>${rows.map(function (f) {
						return html`<tr><td><a href="${f.url}" target="_blank" rel="noopener">${fileIcon(f.mime)} ${f.name}</a></td><td>${F.size(f.size)}</td><td>${f.author}</td><td>${F.dateTime(f.createdAt)}</td>
							<td>${f.canDelete ? html`<button type="button" class="ps-icon-btn ps-icon-btn--sm" data-del-file="${f.id}" aria-label="حذف">🗑</button>` : ''}</td></tr>`;
					})}</tbody></table></div>` : ui.empty('📂', 'لا توجد ملفات.')}
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
				ui.confirm('حذف هذا الملف من المشروع؟', 'حذف', 'danger').then(function (ok) {
					if (!ok) return;
					PS.api.del('files/' + b.dataset.delFile).then(function () { ui.toast('تم حذف الملف', 'success'); files(box, project); })
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

	function upload(list, project, box) {
		const filesArr = Array.prototype.slice.call(list || []);
		if (!filesArr.length) return;
		const progress = box.querySelector('[data-progress]');
		let done = 0;
		progress.innerHTML = html`<p class="ps-muted">جاري الرفع… (0/${filesArr.length})</p>`.html;
		filesArr.reduce(function (p, file) {
			return p.then(function () {
				if (file.size > PS.config.settings.maxUpload) {
					ui.toast(file.name + ': الملف كبير جدًا', 'error');
					return null;
				}
				const fd = new FormData();
				fd.append('file', file);
				return PS.api.post('projects/' + project.id + '/files', fd).then(function () {
					done++;
					progress.innerHTML = html`<p class="ps-muted">جاري الرفع… (${done}/${filesArr.length})</p>`.html;
				}).catch(function (err) { ui.toast(file.name + ': ' + err.message, 'error'); });
			});
		}, Promise.resolve()).then(function () {
			if (done) ui.toast('تم رفع ' + done + ' ملف', 'success');
			files(box, project);
		});
	}

	/* ------------------------------------------------------------------ */
	/* سجل التعديلات                                                      */
	/* ------------------------------------------------------------------ */

	const ACTIONS = { create: 'إضافة', update: 'تعديل', delete: 'حذف', restore: 'استرجاع', merge: 'دمج', import: 'استيراد' };
	const ENTITIES = { project: 'المشروع', party: 'طرف', item: 'صنف', competitor: 'منافس', followup: 'متابعة', file: 'ملف' };
	const FIELDS = {
		offer_no: 'رقم العرض', name: 'اسم المشروع', received_date: 'تاريخ الاستلام', sent_date: 'تاريخ الإرسال',
		responsible_emp_id: 'المسؤول', follower_emp_id: 'المتابع', intermediary_emp_id: 'الوسيط', place_id: 'المكان',
		category_id: 'التصنيف', urgency_id: 'الاستعجال', importance_kind_id: 'الأهمية كصنف', importance_size_id: 'الأهمية كحجم',
		followup_status_id: 'حالة المتابعة', source_id: 'مصدر الطلب', loss_reason_id: 'سبب الخسارة', offer_amount: 'مبلغ العرض',
		offer_currency_id: 'عملة العرض', purchase_amount: 'مبلغ الشراء', purchase_currency_id: 'عملة الشراء', files_link: 'الرابط',
		note: 'NOTE', item_type_id: 'الصنف', quantity: 'الكمية', employee_id: 'الموظف', due_date: 'الأجل',
		offer_status_id: 'حالة العرض', offer_date: 'تاريخ تقديم العرض', not_sent_reason_id: 'سبب عدم الإرسال', not_sent_note: 'ملاحظة عدم الإرسال',
		offer_invoice_no: 'فاتورة العرض', offer_notes: 'ملاحظات', supplier_invoice_no: 'فاتورة المورد', availability_id: 'المتوفر',
		delivery_due_date: 'أجل التسليم', delivery_actual_date: 'التسليم الفعلي', delivery_status_id: 'حالة التسليم', delivery_notes: 'ملاحظات التسليم',
		date: 'التاريخ', entry_date: 'التاريخ', text: 'النص', next_date: 'المتابعة القادمة',
	};
	const ROLES = { owner: 'المالك', executor: 'الشركة المنفذة', direct: 'الشخص المباشر', broker: 'الوسيط' };
	const PARTY_COLS = { company_id: 'الشركة', person: 'الشخص', email: 'الإيميل', phone: 'الهاتف', address: 'العنوان', position: 'المنصب', rank_title: 'الرتبة', location: 'الموقع' };

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
				<h2 class="ps-card__title">سجل التعديلات <span class="ps-muted">(من، ماذا، متى)</span></h2>
				${rows.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--wrap">
					<thead><tr><th>التاريخ</th><th>المستخدم</th><th>العملية</th><th>الخانة</th><th>القيمة القديمة</th><th>القيمة الجديدة</th></tr></thead>
					<tbody>${rows.map(function (r) {
						return html`<tr><td class="ps-nowrap">${F.dateTime(r.at)}</td><td>${r.user}</td><td>${ACTIONS[r.action] || r.action} ${ENTITIES[r.entity] || r.entity}</td>
							<td>${fieldLabel(r.entity, r.field)}</td><td class="ps-old">${r.old === null ? '—' : r.old}</td><td>${r.new === null ? '—' : r.new}</td></tr>`;
					})}</tbody></table></div>` : ui.empty('📜', 'لا توجد تعديلات.')}
			</section>`.html;
		}).catch(function (e) { box.innerHTML = html`<div class="ps-alert ps-alert--error">${e.message}</div>`.html; });
	}

	return { followups: followups, files: files, log: log };
})();
