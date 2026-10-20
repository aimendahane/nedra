/**
 * Page d'accueil « مهامي » (#/taches) — brief §4.5.
 *
 * Pour l'utilisateur connecté (employé lié à son compte) :
 *   1. العروض المتأخرة — لازم قرار : ses أصناف « قيد الإعداد » dont le أجل est dépassé (hors L) ;
 *   2. المتابعات المبرمجة : projets dont la prochaine date de suivi est aujourd'hui ou dépassée ;
 *   3. مشاريع بدون متابعة : pas de suivi depuis plus de 7 jours ;
 *   4. تسليمات الأسبوع : livraisons non faites dont le أجل tombe dans les 7 jours (retards compris) ;
 *   0. مهام مُسندة (1.0.1) : tâches libres confiées par الإدارة / مسؤول مشروع, bouton « تمت ».
 * الإدارة : toute l'équipe, filtrable par employé.
 * Bouton « مهمة جديدة » (capacité ps_assign_tasks) + bloc « مهام أرسلتها » pour suivre ses demandes.
 * PS.taskModal({ projectId, projectLabel }) est aussi utilisé depuis Fiche Projet.
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;

	function block(icon, title, rows, emptyText, rowFn, more) {
		return html`<section class="ps-card ps-task">
			<h2 class="ps-card__title"><span aria-hidden="true">${icon}</span> ${title} <span class="ps-task__count ${rows.length ? 'is-on' : ''}">${rows.length}</span>${more || ''}</h2>
			${rows.length ? html`<ul class="ps-task__list">${rows.map(rowFn)}</ul>` : html`<p class="ps-muted">✔ ${emptyText}</p>`}
		</section>`;
	}

	function who(r, showEmp) { return showEmp && r.employeeId ? html` · <span class="ps-muted">${L.label(r.employeeId)}</span>` : ''; }

	function errMsg(er) { return (er && er.message) || 'حدث خطأ'; }

	/**
	 * Fenêtre « مهمة جديدة ». Résout avec la tâche créée (ou null si fermée).
	 * @param {Object} o { projectId, projectLabel, task (modification d'une tâche existante) }
	 */
	function taskModal(o) {
		o = o || {};
		const t = o.task || {};
		const tomorrow = new Date(Date.parse(PS.config.today + 'T12:00:00Z') + 86400000).toISOString().slice(0, 10);
		return L.load().then(function () {
			let created = null;
			return ui.modal({
				title: t.id ? 'تعديل المهمة' : '📌 مهمة جديدة',
				body: html`<form class="ps-form" data-task-form novalidate>
					${o.projectLabel ? html`<p class="ps-small">المشروع: <strong>${o.projectLabel}</strong></p>` : ''}
					<label class="ps-field" data-key="employeeId"><span class="ps-field__label">الموظف *</span>
						<select class="ps-input" name="employeeId"><option value="">—</option>${ui.raw(L.options('employee', t.employeeId, { noEmpty: true }))}</select></label>
					<label class="ps-field" data-key="title"><span class="ps-field__label">المهمة *</span>
						<input class="ps-input" name="title" maxlength="255" value="${t.title || ''}" placeholder="مثلًا: اتصل بالحريف لتأكيد الطلبية"></label>
					<label class="ps-field" data-key="dueDate"><span class="ps-field__label">الأجل</span>
						<input class="ps-input" type="date" name="dueDate" value="${t.id ? (t.dueDate || '') : tomorrow}"></label>
					<label class="ps-field" data-key="note"><span class="ps-field__label">ملاحظة</span>
						<textarea class="ps-input" name="note" rows="3">${t.note || ''}</textarea></label>
					<p class="ps-muted ps-small">تظهر المهمة في «مهامي» عند الموظف، ويصله بريد إذا كان لحسابه بريد.</p>
				</form>`,
				buttons: [{ id: 'save', label: t.id ? '💾 حفظ' : '📌 إرسال المهمة', kind: 'primary' }, { id: 'cancel', label: 'إلغاء' }],
				onOpen: function (wrap) {
					// Une erreur disparaît dès que le champ est corrigé.
					wrap.addEventListener('input', function (e) {
						const field = e.target.closest('.ps-field--error');
						if (!field) return;
						field.classList.remove('ps-field--error');
						const err = field.querySelector('.ps-field__error');
						if (err) err.remove();
					});
				},
				onButton: function (id, wrap, close) {
					if (id !== 'save') return true;
					const f = wrap.querySelector('[data-task-form]');
					const body = { employeeId: f.employeeId.value, title: f.title.value, dueDate: f.dueDate.value, note: f.note.value };
					if (o.projectId) body.projectId = o.projectId;
					const req = t.id ? PS.api.put('free-tasks/' + t.id, Object.assign({ action: 'edit' }, body)) : PS.api.post('free-tasks', body);
					req.then(function (r) {
						created = r;
						ui.toast(t.id ? 'تم حفظ المهمة' : 'أُرسلت المهمة إلى ' + L.label(r.employeeId), 'success');
						close('save');
					}).catch(function (er) {
						if (er.data && er.data.data && er.data.data.errors) ui.showErrors(f, er.data.data.errors);
						ui.toast(errMsg(er), 'error');
					});
					return false;
				},
			}).then(function () { return created; });
		});
	}
	PS.taskModal = taskModal;

	/** Change le statut d'une tâche puis recharge la page مهامي. */
	function taskAction(id, action, confirmText) {
		const go = function () {
			return PS.api.put('free-tasks/' + id, { action: action }).then(function () {
				ui.toast({ done: 'أحسنت! تم إنجاز المهمة ✔', reopen: 'أُعيد فتح المهمة', cancel: 'أُلغيت المهمة' }[action], 'success');
				PS.router.refresh();
			}).catch(function (er) { ui.toast(errMsg(er), 'error'); });
		};
		return confirmText ? ui.confirm(confirmText, 'تأكيد').then(function (ok) { if (ok) go(); }) : go();
	}

	function dueText(r, today) {
		if (!r.dueDate) return 'بدون أجل';
		if (r.late) return html`<strong>متأخرة — الأجل ${F.date(r.dueDate)}</strong>`;
		return r.dueDate === today ? html`<strong>اليوم</strong>` : 'الأجل ' + F.date(r.dueDate);
	}

	function assignedBlock(data, showEmp) {
		return block('📌', 'مهام مُسندة', data.assigned || [], 'لا توجد مهام مُسندة', function (r) {
			return html`<li class="ps-task__item ${r.late ? 'is-danger' : (r.dueDate === data.today ? 'is-warning' : '')}" data-task="${r.id}">
				<div class="ps-task__row"><span><strong>${r.title}</strong>${r.projectId ? html` · <a href="#/projet?id=${r.projectId}" class="ps-mono">${r.projectNo}</a>` : ''}</span>
				<button type="button" class="ps-btn ps-btn--small ps-btn--primary" data-task-done="${r.id}">✔ تمت</button></div>
				<span class="ps-small">${dueText(r, data.today)}${r.createdByName ? ' · من ' + r.createdByName : ''}${who(r, showEmp)}</span>
				${r.note ? html`<span class="ps-small ps-task__note">${r.note}</span>` : ''}</li>`;
		});
	}

	function sentBlock(data) {
		if (!data.sent || !data.sent.length) return '';
		return block('📤', 'مهام أرسلتها', data.sent, '', function (r) {
			const done = r.status === 'done';
			return html`<li class="ps-task__item ${done ? 'is-done' : (r.late ? 'is-danger' : '')}">
				<div class="ps-task__row"><span>${done ? '✔ ' : ''}<strong>${r.title}</strong> → ${L.label(r.employeeId)}${r.projectId ? html` · <a href="#/projet?id=${r.projectId}" class="ps-mono">${r.projectNo}</a>` : ''}</span>
				${done ? '' : html`<span class="ps-row ps-row--tight"><button type="button" class="ps-btn ps-btn--small" data-task-edit="${r.id}">✎</button><button type="button" class="ps-btn ps-btn--small ps-btn--ghost" data-task-cancel="${r.id}">إلغاء</button></span>`}</div>
				<span class="ps-small">${done ? 'تمت ' + F.date(String(r.doneAt).slice(0, 10)) + (r.doneByName ? ' · ' + r.doneByName : '') : dueText(r, data.today)}</span></li>`;
		});
	}

	function bindTaskButtons(main, data) {
		main.addEventListener('click', function (e) {
			const d = e.target.closest('[data-task-done]');
			if (d) return taskAction(d.dataset.taskDone, 'done');
			const c = e.target.closest('[data-task-cancel]');
			if (c) return taskAction(c.dataset.taskCancel, 'cancel', 'إلغاء هذه المهمة؟ تبقى محفوظة كـ«ملغاة».');
			const ed = e.target.closest('[data-task-edit]');
			if (ed) {
				const t = (data.sent || []).concat(data.assigned || []).find(function (x) { return String(x.id) === ed.dataset.taskEdit; });
				return taskModal({ task: t, projectLabel: t && t.projectNo ? t.projectNo + ' — ' + t.projectName : '' }).then(function (r) { if (r) PS.router.refresh(); });
			}
			if (e.target.closest('[data-new-task]')) taskModal().then(function (r) { if (r) PS.router.refresh(); });
		});
	}

	function render(main, params, data) {
		const admin = PS.router.can('ps_edit_all_projects');
		const showEmp = admin && !data.employeeId;
		const name = PS.config.user.name;
		const newBtn = PS.router.can('ps_assign_tasks') ? html`<button type="button" class="ps-btn ps-btn--primary" data-new-task>📌 مهمة جديدة</button>` : '';
		let head = html`<div class="ps-page-head"><h1>مهامي</h1><span class="ps-muted">${F.date(data.today)}</span><div class="ps-page-head__actions">${newBtn}</div></div>`;
		if (admin) {
			head = html`<div class="ps-page-head"><h1>مهام ${data.employeeId ? L.label(data.employeeId) : 'الفريق'}</h1>
				<div class="ps-page-head__actions">${newBtn}<label class="ps-field ps-field--compact"><span class="ps-field__label">الموظف</span>
				<select class="ps-input" data-emp><option value="">كل الفريق</option>${ui.raw(L.options('employee', data.employeeId, { noEmpty: true }))}</select></label></div></div>`;
		}
		bindTaskButtons(main, data);
		if (!data.linked) {
			main.innerHTML = html`${head}<div class="ps-alert ps-alert--info">مرحبًا ${name}. حسابك غير مرتبط بموظف من القائمة، لذلك لا تظهر مهام شخصية. اطلب من الإدارة ربط حسابك.</div>
				<div class="ps-tasks">${sentBlock(data)}</div>`.html;
			return;
		}
		main.innerHTML = html`${head}
			<div class="ps-tasks">
			${assignedBlock(data, showEmp)}
			${block('⚠️', 'عروض متأخرة — لازم قرار', data.lateOffers, 'لا توجد عروض متأخرة', function (r) {
				return html`<li class="ps-task__item is-danger"><a href="#/projet?id=${r.projectId}"><strong class="ps-mono">${r.projectNo}</strong> ${L.label(r.itemTypeId)} — ${r.projectName}</a>
					<span class="ps-small">الأجل ${F.date(r.dueDate)} · <strong>متأخر ${r.daysLate} يوم</strong>${who(r, showEmp)}</span></li>`;
			}, data.lateOffers.length ? html` <a class="ps-btn ps-btn--small ps-btn--ghost" href="#/offres?late=1${data.employeeId ? '&employeeId=' + data.employeeId : ''}">فتح في العروض ←</a>` : '')}
			${block('📞', 'متابعات مبرمجة اليوم أو فائتة', data.followupsDue, 'لا توجد متابعات مبرمجة اليوم', function (r) {
				const overdue = r.nextDate < data.today;
				return html`<li class="ps-task__item ${overdue ? 'is-danger' : 'is-warning'}"><a href="#/projet?id=${r.projectId}&tab=followups"><strong class="ps-mono">${r.projectNo}</strong> ${r.projectName}</a>
					<span class="ps-small">${overdue ? 'كانت مبرمجة' : 'اليوم'} ${F.date(r.nextDate)}${r.companyName ? ' · ' + r.companyName : ''}${who(r, showEmp)}</span></li>`;
			})}
			${block('🕒', 'مشاريع بدون متابعة منذ أكثر من 7 أيام', data.noFollowup, 'كل المشاريع متابعة', function (r) {
				return html`<li class="ps-task__item"><a href="#/projet?id=${r.projectId}&tab=followups"><strong class="ps-mono">${r.projectNo}</strong> ${r.projectName}</a>
					<span class="ps-small">${r.lastDate ? 'آخر متابعة ' + F.date(r.lastDate) : 'لا توجد متابعة مسجلة'}${who(r, showEmp)}</span></li>`;
			})}
			${block('🚚', 'تسليمات هذا الأسبوع', data.deliveries, 'لا توجد تسليمات هذا الأسبوع', function (r) {
				return html`<li class="ps-task__item ${r.late ? 'is-danger' : ''}"><a href="#/projet?id=${r.projectId}"><strong class="ps-mono">${r.projectNo}</strong> ${L.label(r.itemTypeId)} — ${r.projectName}</a>
					<span class="ps-small">أجل التسليم ${F.date(r.deliveryDueDate)}${r.late ? html` · <strong>متأخر ${r.delayDays} يوم</strong>` : ''}${who(r, showEmp)}</span></li>`;
			}, data.deliveries.length ? html` <a class="ps-btn ps-btn--small ps-btn--ghost" href="#/livraison${data.employeeId ? '?employeeId=' + data.employeeId : ''}">فتح في التسليم ←</a>` : '')}
			${sentBlock(data)}
			</div>`.html;
		const sel = main.querySelector('[data-emp]');
		if (sel) sel.addEventListener('change', function () { PS.router.go('taches', sel.value ? { employeeId: sel.value } : null); });
	}

	PS.router.register({
		id: 'taches',
		title: 'مهامي',
		icon: '✅',
		cap: 'ps_access',
		order: 10,
		group: 'work',
		render: function (main, params) {
			return L.load().then(function () {
				return PS.api.get('tasks' + (params.employeeId ? '?employeeId=' + encodeURIComponent(params.employeeId) : '')).then(function (d) {
					render(main, params, d);
				});
			});
		},
	});
})();
