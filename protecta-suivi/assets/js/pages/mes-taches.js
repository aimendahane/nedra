/**
 * Page d'accueil « مهامي » (#/taches) — brief §4.5.
 *
 * Pour l'utilisateur connecté (employé lié à son compte) :
 *   1. العروض المتأخرة — لازم قرار : ses أصناف « قيد الإعداد » dont le أجل est dépassé (hors L) ;
 *   2. المتابعات المبرمجة : projets dont la prochaine date de suivi est aujourd'hui ou dépassée ;
 *   3. مشاريع بدون متابعة : pas de suivi depuis plus de 7 jours ;
 *   4. تسليمات الأسبوع : livraisons non faites dont le أجل tombe dans les 7 jours (retards compris).
 * الإدارة : toute l'équipe, filtrable par employé.
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

	function render(main, params, data) {
		const admin = PS.router.can('ps_edit_all_projects');
		const showEmp = admin && !data.employeeId;
		const name = PS.config.user.name;
		let head = html`<div class="ps-page-head"><h1>مهامي</h1><span class="ps-muted">${F.date(data.today)}</span></div>`;
		if (admin) {
			head = html`<div class="ps-page-head"><h1>مهام ${data.employeeId ? L.label(data.employeeId) : 'الفريق'}</h1>
				<div class="ps-page-head__actions"><label class="ps-field ps-field--compact"><span class="ps-field__label">الموظف</span>
				<select class="ps-input" data-emp><option value="">كل الفريق</option>${ui.raw(L.options('employee', data.employeeId, { noEmpty: true }))}</select></label></div></div>`;
		}
		if (!data.linked) {
			main.innerHTML = html`${head}<div class="ps-alert ps-alert--info">مرحبًا ${name}. حسابك غير مرتبط بموظف من القائمة، لذلك لا تظهر مهام شخصية. اطلب من الإدارة ربط حسابك.</div>`.html;
			return;
		}
		main.innerHTML = html`${head}
			<div class="ps-tasks">
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
