/**
 * Page « المشاريع » — Base Projets (#/projets).
 *
 * Une ligne par projet, colonnes dans l'ordre du cahier §4, + مصدر et الأولوية.
 * 1.7.0 : tableau court (colonnes importantes, les autres via « الأعمدة »), filtres rapides مشروع؟ avec compteurs,
 * clic sur une ligne → panneau latéral avec tous les détails et les actions.
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

	/**
	 * Colonnes : [clé de tri (null = non triable), titre, rendu, importante, identifiant].
	 * 1.7.0 : seules les colonnes « importantes » sont affichées par défaut ; les autres s'ajoutent par « الأعمدة ».
	 */
	const COLUMNS = [
		// 1.6.1 (demande du client) : les colonnes importantes d'abord — رقم المشروع، الاسم، مشروع؟، السبب،
		// المسؤول، المتابع، تاريخ الاستلام، الحالة — puis toutes les autres.
		['projectNo', PS.t('رقم المشروع'), function (r) { return html`<a class="ps-pno" href="#/projet?id=${r.id}" dir="ltr">${r.projectNo}</a>${r.oldNo ? html`<div class="ps-small ps-muted" dir="ltr">${r.oldNo}</div>` : ''}`; }, true, 'projectNo'],
		['name', PS.t('الاسم'), function (r) { return ui.projectTitle({ id: r.id, name: r.name, no: '' }); }, true, 'name'],
		['isProject', PS.t('مشروع؟'), function (r) { return ynCell(r); }, true, 'isProject'],
		['nonProjectReasonId', PS.t('سبب عدم الاعتبار'), function (r) { return r.isProject === false && r.nonProjectReasonId ? L.label(r.nonProjectReasonId) : ''; }, true, 'nonProjectReasonId'],
		['responsibleEmpId', PS.t('المسؤول عن المشروع'), function (r) { return L.label(r.responsibleEmpId); }, true, 'responsibleEmpId'],
		['followerEmpId', PS.t('الموظف المتابع'), function (r) { return L.label(r.followerEmpId); }, true, 'followerEmpId'],
		['receivedDate', PS.t('تاريخ الاستلام'), function (r) { return F.date(r.receivedDate); }, true, 'receivedDate'],
		['followupStatusId', PS.t('الحالة'), function (r) { return L.badge(r.followupStatusId, L.label(r.followupStatusId)); }, true, 'followupStatusId'],
		['offerStatus', PS.t('حالة العرض'), function (r) { return L.offerBadge(r.offerStatus); }, false, 'offerStatus'],
		['receivedYear', PS.t('السنة'), function (r) { return r.receivedYear; }, false, 'receivedYear'],
		['month', PS.t('الشهر'), function (r) { return F.month(r.month); }, false, 'month'],
		['placeId', PS.t('المكان'), function (r) { return html`<span title="${L.label(r.placeId)}">${L.short(r.placeId)}</span>`; }, false, 'placeId'],
		['urgencyId', PS.t('الاستعجال'), function (r) { return L.badge(r.urgencyId); }, false, 'urgencyId'],
		['importanceKindId', PS.t('الأهمية كصنف'), function (r) { return L.label(r.importanceKindId); }, false, 'importanceKindId'],
		['importanceSizeId', PS.t('الأهمية كحجم'), function (r) { return L.label(r.importanceSizeId); }, false, 'importanceSizeId'],
		['categoryId', PS.t('التصنيف'), function (r) { return L.label(r.categoryId); }, false, 'categoryId'],
		['companyName', PS.t('اسم الشركة'), function (r) { return r.companyName || ''; }, false, 'companyName'],
		['directPerson', PS.t('شخص المباشر'), function (r) { return r.directPerson || ''; }, false, 'directPerson'],
		['directPhone', PS.t('رقم شخص المباشر'), function (r) { return html`<span dir="ltr">${r.directPhone || ''}</span>`; }, false, 'directPhone'],
		[null, PS.t('الأصناف'), function (r) { return r.itemTypeIds.map(L.label).join(PS.t('، ')); }, false, 'items'],
		['sentDate', PS.t('تاريخ إرسال الطلب'), function (r) { return F.date(r.sentDate); }, false, 'sentDate'],
		['intermediaryEmpId', PS.t('الموظف الوسيط'), function (r) { return L.label(r.intermediaryEmpId); }, false, 'intermediaryEmpId'],
		['sourceId', PS.t('مصدر الطلب'), function (r) { return L.label(r.sourceId); }, false, 'sourceId'],
		['priority', PS.t('الأولوية'), function (r) { return r.priority ? html`<span class="ps-badge ps-prio ps-prio--${r.priority}">${r.priority}</span>` : ''; }, false, 'priority'],
		[null, 'NOTE', function (r) { return html`<span class="ps-clip" title="${r.note || ''}">${r.note || ''}</span>`; }, false, 'note'],
		[null, PS.t('رابط ملف المشاريع'), function (r) { return r.filesLink ? html`<a href="${r.filesLink}" target="_blank" rel="noopener noreferrer">🔗 ${PS.t('فتح')}</a>` : ''; }, false, 'link'],
	];

	/*
	 * 1.8.0 — « مشروع؟ » : interrupteur directement dans la liste (tableau, carte, panneau) pour ceux qui peuvent
	 * modifier le projet. « نعم » = le projet devient une offre (Suivi Offres) ; « لا » demande le سبب.
	 * 1.9.0 — troisième état « ⏳ لم يُحدد بعد » (valeur 2) : demande enregistrée, décision plus tard. Sans droit : badge.
	 */
	function stateOf(r) { return r.projectState || (r.isProject === false ? 'no' : 'yes'); }
	const STATE_VALUE = { yes: '1', no: '0', pending: '2' };
	function ynBadge(r) {
		const s = stateOf(r);
		if (s === 'no') return html`<span class="ps-badge ps-badge--muted" data-non-project>🚫 ${PS.t('مشروع مُلغى')}</span>`;
		if (s === 'pending') return html`<span class="ps-badge ps-badge--warning" data-pending>⏳ ${PS.t('لم يُحدد بعد')}</span>`;
		return html`<span class="ps-badge ps-badge--success">✔ ${PS.t('نعم')}</span>`;
	}
	function ynCell(r) {
		if (!r.canEdit || (st && st.params.trash)) return ynBadge(r);
		const s = stateOf(r);
		const b = function (val, cls, label, title) {
			const on = STATE_VALUE[s] === val;
			return html`<button type="button" class="ps-yn__b ${on ? 'is-on ' + cls : ''}" data-yn="${val}" data-pid="${r.id}" aria-pressed="${on ? 'true' : 'false'}" title="${title}">${label}</button>`;
		};
		return html`<span class="ps-yn" role="group" aria-label="${PS.t('مشروع؟')}" ${s === 'no' ? ui.raw('data-non-project') : (s === 'pending' ? ui.raw('data-pending') : '')}>
			${b('1', 'is-yes', PS.t('نعم'), PS.t('نعم'))}${b('0', 'is-no', PS.t('لا'), PS.t('لا'))}${b('2', 'is-pending', '⏳', PS.t('لم يُحدد بعد'))}
		</span>`;
	}

	/** Change « مشروع؟ » d'un projet de la liste (val = '1' نعم, '0' لا, '2' لم يُحدد بعد). → Promise<bool> */
	function setYesNo(id, val) {
		const r = st.data.rows.find(function (x) { return x.id === Number(id); });
		if (!r || STATE_VALUE[stateOf(r)] === val) return Promise.resolve(false);
		let ask = Promise.resolve({ isProject: Number(val) });
		if (val === '0') {
			let picked = null;
			ask = ui.modal({
				title: PS.t('ليس مشروعًا') + ' — ' + r.projectNo,
				body: html`<p class="ps-small ps-muted">${PS.t('لن يظهر في متابعة العروض ولا في المؤشرات. يبقى في «المشاريع».')}</p>
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('سبب عدم اعتباره مشروعا')} *</span><select data-q="reason">${ui.raw(L.options('non_project_reason', r.nonProjectReasonId, { emptyLabel: PS.t('اختر…') }))}</select></label>
					<label class="ps-field ps-mt"><span class="ps-field__label">${PS.t('ملاحظة (اختياري)')}</span><input data-q="note"></label>`,
				buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onButton: function (b, box) {
					if (b !== 'ok') return true;
					const reason = box.querySelector('[data-q="reason"]');
					if (!reason.value) { ui.toast(PS.t('اختر السبب'), 'error'); reason.focus(); return false; }
					picked = { isProject: 0, nonProjectReasonId: Number(reason.value), nonProjectNote: box.querySelector('[data-q="note"]').value };
					return true;
				},
			}).then(function () { return picked; });
		}
		return ask.then(function (body) {
			if (!body) return false;
			const prev = { isProject: Number(STATE_VALUE[stateOf(r)]), nonProjectReasonId: r.nonProjectReasonId || null, nonProjectNote: r.nonProjectNote || '' };
			return PS.api.post('projects/' + r.id + '/is-project', body).then(function (res) {
				// 1.12.0 : une demande saisie en « إدخال سريع » devient une offre → dire ce qu'il reste à compléter.
				const missing = res.missing || [];
				const open = function () { PS.router.go('projet', { id: r.id }); };
				if (val === '1' && !res.itemsCount) ui.toast(PS.t('أصبح مشروعًا، لكن بدون أصناف: أضف صنفًا من بطاقة المشروع ليظهر في متابعة العروض.'), 'error', { action: PS.t('فتح البطاقة'), onAction: open, ms: 10000 });
				else if (val === '1' && missing.length) ui.toast(PS.t('أصبح مشروعًا. أكمل في بطاقة المشروع:') + ' ' + missing.join(PS.t('، ')), 'error', { action: PS.t('فتح البطاقة'), onAction: open, ms: 10000 });
				else {
					// 1.12.0 : « ↩ تراجع » pendant 8 s (remet l'ancien choix, journalisé comme une modification normale).
					ui.undoToast(val === '1' ? PS.t('✔ أصبح مشروعًا — يظهر الآن في متابعة العروض') : (val === '0' ? PS.t('تم: ليس مشروعًا') : PS.t('⏳ بانتظار القرار')), function () {
						PS.api.post('projects/' + r.id + '/is-project', prev).then(function () {
							ui.toast(PS.t('تم التراجع'), 'success');
							PS.router.refreshCounts();
							load();
						}).catch(function (e) { ui.toast(e.message, 'error'); });
					});
				}
				PS.router.refreshCounts();
				return load().then(function () { return true; });
			}).catch(function (e) { ui.toast(e.message, 'error'); return false; });
		});
	}

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
		// 1.10.0 : corbeille → « استرجاع » / « حذف نهائي » des projets cochés.
		if (st.params.trash) {
			return html`<div class="ps-bulk" role="region" aria-label="${PS.t('تعديل جماعي')}">
				<strong>${st.selected.size} ${PS.t('مشروع محدد')}</strong>
				<button type="button" class="ps-btn ps-btn--small" data-action="bulk-restore">↩ ${PS.t('استرجاع')}</button>
				<button type="button" class="ps-btn ps-btn--danger ps-btn--small" data-action="bulk-purge">❌ ${PS.t('حذف نهائي')}</button>
				<button type="button" class="ps-btn ps-btn--small" data-action="bulk-clear">${PS.t('إلغاء التحديد')}</button>
			</div>`;
		}
		return html`<div class="ps-bulk" role="region" aria-label="${PS.t('تعديل جماعي')}">
			<strong>${st.selected.size} ${PS.t('مشروع محدد')}</strong>
			${PS.router.can('ps_delete') ? html`<button type="button" class="ps-btn ps-btn--danger ps-btn--small" data-action="bulk-trash">🗑 ${PS.t('حذف المحدد')}</button>` : ''}
			<label>${PS.t('تعيين مصدر الطلب:')} <select class="ps-input ps-input--sm" data-bulk-source>${ui.raw(L.options('source', '', { emptyLabel: PS.t('اختر…') }))}</select></label>
			<button type="button" class="ps-btn ps-btn--primary ps-btn--small" data-action="bulk-apply">${PS.t('تطبيق')}</button>
			<button type="button" class="ps-btn ps-btn--small" data-action="bulk-clear">${PS.t('إلغاء التحديد')}</button>
		</div>`;
	}

	/** Colonnes au format de PS.ui.visibleColumns / chooseColumns. */
	function colDefs() {
		return COLUMNS.map(function (c) { return { key: c[4], label: c[1], important: c[3], col: c }; });
	}

	function table(rows) {
		const sort = st.params.sort || 'receivedDate';
		const dir = st.params.dir || 'desc';
		const admin = isAdmin(); // cases à cocher aussi dans la corbeille (1.10.0)
		const allChecked = rows.length && rows.every(function (r) { return st.selected.has(r.id); });
		const cols = ui.visibleColumns('projets', colDefs()).map(function (d) { return d.col; });
		return html`<div class="ps-table-wrap ps-desktop-only"><table class="ps-table ps-table--list ${st.params.trash ? '' : 'ps-table--clickable'}">
			<thead><tr>
				${admin ? html`<th><input type="checkbox" data-check-all aria-label="${PS.t('تحديد الكل')}" ${allChecked ? ui.raw('checked') : ''}></th>` : ''}
				${cols.map(function (c) {
					if (!c[0]) return html`<th>${c[1]}</th>`;
					const on = sort === c[0];
					return html`<th aria-sort="${on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" class="ps-sort" data-sort="${c[0]}">${c[1]} ${on ? (dir === 'asc' ? '▲' : '▼') : ''}</button></th>`;
				})}
				${st.params.trash ? html`<th></th>` : ''}
			</tr></thead>
			<tbody>${rows.map(function (r) {
				return html`<tr ${st.params.trash ? '' : ui.raw('data-open-row="' + r.id + '" tabindex="0"')} class="${r.isProject === false ? 'is-non-project' : ''} ${stateOf(r) === 'pending' ? 'is-pending' : ''}">
					${admin ? html`<td><input type="checkbox" data-check="${r.id}" aria-label="${PS.t('تحديد')} ${r.projectNo}" ${st.selected.has(r.id) ? ui.raw('checked') : ''}></td>` : ''}
					${cols.map(function (c) { return html`<td>${c[2](r)}</td>`; })}
					${st.params.trash ? html`<td class="ps-trash-actions"><button type="button" class="ps-btn ps-btn--small" data-restore="${r.id}">↩ ${PS.t('استرجاع')}</button> ${isAdmin() ? html`<button type="button" class="ps-btn ps-btn--danger ps-btn--small" data-purge="${r.id}">❌ ${PS.t('حذف نهائي')}</button>` : ''}</td>` : ''}
				</tr>`;
			})}</tbody>
		</table></div>`;
	}

	/*
	 * Téléphone (écran < 760 px) : une carte par projet, avec les MÊMES champs importants et dans le même ordre que le
	 * tableau (1.6.2 — demande du client) : رقم المشروع، الاسم، مشروع؟، السبب، المسؤول، المتابع، تاريخ الاستلام، الحالة.
	 * 1.7.0 : un appui ouvre le même panneau de détails que le tableau.
	 */
	function cards(rows) {
		const line = ui.kv;
		return html`<div class="ps-cards ps-mobile-only">${rows.map(function (r) {
			return html`<div class="ps-pcard ${r.isProject === false ? 'is-non-project' : ''} ${stateOf(r) === 'pending' ? 'is-pending' : ''}" ${st.params.trash ? '' : ui.raw('data-open-row="' + r.id + '" role="button" tabindex="0"')}>
				<div class="ps-pcard__top">${ui.projectTitle({ name: r.name, no: r.projectNo, oldNo: r.oldNo }, { link: false, numberFirst: true })} ${L.badge(r.urgencyId)}</div>
				<div class="ps-kvs">
					${line(PS.t('مشروع؟'), ynCell(r))}
					${r.isProject === false ? line(PS.t('سبب عدم الاعتبار'), r.nonProjectReasonId ? L.label(r.nonProjectReasonId) : '—') : ''}
					${line(PS.t('المسؤول عن المشروع'), L.label(r.responsibleEmpId))}
					${line(PS.t('الموظف المتابع'), L.label(r.followerEmpId))}
					${line(PS.t('تاريخ الاستلام'), F.date(r.receivedDate))}
					${line(PS.t('الحالة'), L.badge(r.followupStatusId, L.label(r.followupStatusId)))}
				</div>
				<div class="ps-pcard__badges">${L.offerBadge(r.offerStatus)} ${r.priority ? html`<span class="ps-badge ps-prio ps-prio--${r.priority}">${r.priority}</span>` : ''} <span class="ps-muted ps-small">${r.companyName || ''}</span></div>
				${st.params.trash ? html`<div class="ps-row ps-row--tight"><button type="button" class="ps-btn ps-btn--small" data-restore="${r.id}">↩ ${PS.t('استرجاع')}</button>${isAdmin() ? html`<button type="button" class="ps-btn ps-btn--danger ps-btn--small" data-purge="${r.id}">❌ ${PS.t('حذف نهائي')}</button>` : ''}</div>` : ''}
			</div>`;
		})}</div>`;
	}

	/*
	 * 1.7.0 — panneau de détails (clic sur une ligne ou une carte) : TOUTES les informations de la ligne, sans quitter
	 * la liste, et les actions courantes (ouvrir la fiche, ses fichiers, son سجل المتابعة, nouvelle tâche).
	 */
	function openDrawer(id) {
		const r = st.data && st.data.rows.find(function (x) { return x.id === Number(id); });
		if (!r) return;
		const skip = { projectNo: 1, name: 1, isProject: 1 };
		const body = html`<div class="ps-drawer">
			<div class="ps-drawer__head">
				${ui.projectTitle({ id: r.id, name: r.name, no: r.projectNo, oldNo: r.oldNo }, { numberFirst: true })}
				<div class="ps-drawer__badges">${stateOf(r) !== 'yes' ? ynBadge(r) : ''} ${L.badge(r.followupStatusId, L.label(r.followupStatusId))} ${L.offerBadge(r.offerStatus)} ${L.badge(r.urgencyId)}</div>
			</div>
			<div class="ps-drawer__yn">${PS.t('مشروع؟')} ${ynCell(r)} ${r.isProject === false && r.nonProjectReasonId ? html`<span class="ps-small ps-muted">${L.label(r.nonProjectReasonId)}</span>` : ''}</div>
			<div class="ps-drawer__actions">
				<a class="ps-btn ps-btn--primary" href="#/projet?id=${r.id}" data-drawer-go>✏ ${PS.t('فتح بطاقة المشروع')}</a>
				<a class="ps-btn" href="#/projet?id=${r.id}&tab=files" data-drawer-go>📎 ${PS.t('الملفات')}</a>
				<a class="ps-btn" href="#/projet?id=${r.id}&tab=followups" data-drawer-go>🗒 ${PS.t('سجل المتابعة')}</a>
				${PS.router.can('ps_assign_tasks') && PS.taskModal ? html`<button type="button" class="ps-btn" data-btn="task">➕ ${PS.t('مهمة جديدة')}</button>` : ''}
				${PS.router.can('ps_delete') ? html`<button type="button" class="ps-btn ps-btn--danger" data-btn="delete">🗑 ${PS.t('حذف')}</button>` : ''}
			</div>
			<div class="ps-drawer__section"><h3>${PS.t('التفاصيل')}</h3><div class="ps-kvs">
				${COLUMNS.filter(function (c) { return !skip[c[4]]; }).map(function (c) {
					if (c[4] === 'nonProjectReasonId' && r.isProject !== false) return '';
					const v = c[2](r);
					const txt = v && v.html !== undefined ? v.html : String(v === null || v === undefined ? '' : v);
					return txt.replace(/<[^>]*>/g, '').trim() === '' ? '' : ui.kv(c[1], v);
				})}
			</div></div>
		</div>`;
		ui.modal({
			title: PS.t('تفاصيل المشروع'),
			body: body,
			drawer: true,
			buttons: [{ id: 'close', label: PS.t('إغلاق') }],
			onButton: function (b, wrap, close) {
				if (b === 'task') { close(null); PS.taskModal({ projectId: r.id, projectLabel: r.name + ' — ' + r.projectNo }); return false; }
				if (b === 'delete') { close(null); trashProjects([r.id]); return false; }
				return true;
			},
			onOpen: function (wrap) {
				// Un lien vers la fiche ferme le panneau (la page change).
				wrap.addEventListener('click', function (e) {
					if (e.target.closest('[data-drawer-go]')) wrap.remove();
					const yn = e.target.closest('[data-yn]');
					if (yn) { wrap.remove(); setYesNo(yn.dataset.pid, yn.dataset.yn).then(function () { openDrawer(r.id); }); }
				});
			},
		});
	}

	/** En-tête des résultats : nombre, filtres rapides « مشروع؟ » avec compteurs, bouton « الأعمدة ». */
	function resultsHead(d) {
		const f = Object.assign({ yes: 0, no: 0, pending: 0 }, d.facets && d.facets.isProject);
		return html`<div class="ps-list-head">
			${st.params.trash ? html`<div class="ps-row ps-row--tight"><button type="button" class="ps-btn ps-btn--small" data-action="trash-close">→ ${PS.t('رجوع إلى المشاريع')}</button><strong>🗑 ${PS.t('سلة المحذوفات')}</strong> <span class="ps-muted ps-small" role="status">${d.total} ${PS.t('مشروع')}${PS.t(' في سلة المحذوفات')}</span></div>` : ui.chips('isProject', [
				{ value: '', label: PS.t('الكل'), count: f.yes + f.no + f.pending },
				{ value: '1', label: PS.t('مشروع'), count: f.yes },
				{ value: '2', label: PS.t('⏳ بانتظار القرار'), count: f.pending, tone: f.pending ? 'warning' : '' },
				{ value: '0', label: PS.t('🚫 مشروع مُلغى'), count: f.no, tone: 'muted' },
			], st.params.isProject)}
			<span class="ps-row ps-row--tight">
				${PS.router.can('ps_delete') && !st.params.trash ? html`<button type="button" class="ps-btn ps-btn--ghost ps-btn--small" data-action="trash-open">🗑 ${PS.t('سلة المحذوفات')}</button>` : ''}
				<button type="button" class="ps-btn ps-btn--ghost ps-btn--small ps-desktop-only" data-action="columns">▦ ${PS.t('الأعمدة')}</button>
			</span>
		</div>
		${st.params.trash ? '' : html`<p class="ps-muted ps-small" role="status">${d.total} ${PS.t('مشروع')}${isAdmin() ? ' · ' + PS.t('حدّد مشاريع (☐) لحذفها أو تعديلها معًا') : ''}</p>`}`;
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
		box.innerHTML = html`${resultsHead(d)}${bulkBar()}${body}${pager(d)}`.html;
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
		// Écouteurs délégués posés UNE fois par page (un nouvel affichage de la page ne doit pas les doubler).
		if (st.bound) return;
		st.bound = true;
		m.addEventListener('change', function (e) {
			const t = e.target;
			if (t.dataset.filter) setParam(t.dataset.filter, t.type === 'checkbox' ? (t.checked ? 1 : '') : t.value);
			else if (t.dataset.check) { t.checked ? st.selected.add(Number(t.dataset.check)) : st.selected.delete(Number(t.dataset.check)); renderResults(); }
			else if (t.hasAttribute('data-check-all')) { st.data.rows.forEach(function (r) { t.checked ? st.selected.add(r.id) : st.selected.delete(r.id); }); renderResults(); }
		});
		m.addEventListener('keydown', function (e) {
			const row = e.target.closest && e.target.closest('[data-open-row]');
			if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openDrawer(row.dataset.openRow); }
		});
		m.addEventListener('click', function (e) {
			const chip = e.target.closest('[data-chip]');
			if (chip) { setParam(chip.dataset.chip, chip.dataset.value); return; }
			const yn = e.target.closest('[data-yn]');
			if (yn) { setYesNo(yn.dataset.pid, yn.dataset.yn); return; }
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
			if (rs) { restoreProjects([Number(rs.dataset.restore)]); return; }
			const pu = e.target.closest('[data-purge]');
			if (pu) { purgeProjects([Number(pu.dataset.purge)]); return; }
			const a = e.target.closest('[data-action]');
			if (!a) {
				// Clic sur une ligne (hors lien, case à cocher, bouton) → panneau de détails.
				const row = e.target.closest('[data-open-row]');
				if (row && !e.target.closest('a, button, input, select, label')) openDrawer(row.dataset.openRow);
				return;
			}
			if (a.tagName === 'SELECT') return;
			const act = a.dataset.action;
			if (act === 'toggle-filters') { st.showFilters = !st.showFilters; m.querySelector('.ps-filters').hidden = !st.showFilters; a.setAttribute('aria-expanded', String(st.showFilters)); }
			else if (act === 'reset-filters') { st.params = {}; PS.router.setParams({}); renderPage(); }
			else if (act === 'clear-company') { setParam('companyId', ''); renderPage(); }
			else if (act === 'bulk-clear') { st.selected.clear(); renderResults(); }
			else if (act === 'bulk-apply') bulkApply();
			else if (act === 'bulk-trash') trashProjects(Array.from(st.selected));
			else if (act === 'bulk-restore') restoreProjects(Array.from(st.selected));
			else if (act === 'bulk-purge') purgeProjects(Array.from(st.selected));
			else if (act === 'trash-open') { st.selected.clear(); setParam('trash', 1); }
			else if (act === 'trash-close') { st.selected.clear(); setParam('trash', ''); }
			else if (act === 'columns') ui.chooseColumns('projets', colDefs()).then(function (ch) { if (ch) renderResults(); });
			else if (act === 'export-csv') exportFile('csv');
			else if (act === 'export-xlsx') exportFile('xlsx');
		});
	}

	/*
	 * 1.10.0 — suppression (demande du client) :
	 *   « حذف » → corbeille (restaurable) ; « حذف نهائي » (الإدارة, depuis la corbeille) → sauvegarde automatique puis
	 *   effacement définitif, confirmé en tapant « حذف نهائي ».
	 */
	function label(ids) {
		const rows = st.data.rows.filter(function (x) { return ids.indexOf(x.id) !== -1; });
		return rows.length === 1 ? rows[0].projectNo + ' — ' + rows[0].name : ids.length + ' ' + PS.t('مشروع');
	}

	function trashProjects(ids) {
		if (!ids.length) return;
		ui.confirm(PS.t('نقل إلى سلة المحذوفات: ') + label(ids) + PS.t('؟ يمكن استرجاعه لاحقًا.'), PS.t('🗑 حذف'), 'danger').then(function (ok) {
			if (!ok) return;
			const req = ids.length === 1 ? PS.api.del('projects/' + ids[0]).then(function () { return { deleted: 1 }; }) : PS.api.post('projects/bulk-delete', { ids: ids });
			req.then(function (res) {
				ui.toast(PS.t('نُقل إلى سلة المحذوفات: ') + res.deleted, 'success');
				st.selected.clear();
				PS.router.refreshCounts();
				load();
			}).catch(function (e) { ui.toast(e.message, 'error'); });
		});
	}

	function restoreProjects(ids) {
		Promise.all(ids.map(function (id) { return PS.api.post('projects/' + id + '/restore'); })).then(function () {
			ui.toast(PS.t('تم الاسترجاع'), 'success');
			st.selected.clear();
			PS.router.refreshCounts();
			load();
		}).catch(function (er) { ui.toast(er.message, 'error'); });
	}

	function purgeProjects(ids) {
		if (!ids.length) return;
		const word = PS.t('حذف نهائي');
		ui.modal({
			title: '❌ ' + PS.t('حذف نهائي'),
			body: html`<p><strong>${label(ids)}</strong></p>
				<p class="ps-small">${PS.t('يُحذف المشروع وكل بياناته (الأصناف، المتابعات، الفواتير…) نهائيًا. تُحفظ نسخة احتياطية كاملة قبل الحذف (يمكن التراجع من «استرجاع» في الإعدادات).')}</p>
				<label class="ps-field ps-mt"><span class="ps-field__label">${PS.t('اكتب «حذف نهائي» للتأكيد')}</span><input data-q="word" autocomplete="off"></label>`,
			buttons: [{ id: 'ok', label: '❌ ' + PS.t('حذف نهائي'), kind: 'danger' }, { id: 'cancel', label: PS.t('إلغاء') }],
			onButton: function (b, box, close) {
				if (b !== 'ok') return true;
				const typed = box.querySelector('[data-q="word"]').value.trim();
				if (typed !== 'حذف نهائي' && typed.toLowerCase() !== word.toLowerCase()) { ui.toast(PS.t('اكتب «حذف نهائي» للتأكيد'), 'error'); return false; }
				const btn = box.querySelector('[data-btn="ok"]');
				btn.disabled = true;
				btn.textContent = PS.t('جاري الحذف…');
				PS.api.post('projects/purge', { ids: ids, confirm: 'حذف نهائي' }).then(function (res) {
					close('ok');
					ui.toast(PS.t('حُذف نهائيًا: ') + res.deleted, 'success');
					st.selected.clear();
					load();
				}).catch(function (e) { btn.disabled = false; btn.textContent = '❌ ' + word; ui.toast(e.message, 'error'); });
				return false;
			},
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
		// 1.12.0 : « ⚡ إدخال سريع » (4 خانات) à côté du formulaire complet.
		const add = PS.router.can('ps_create_project') ? html`<div class="ps-page-head__actions"><a class="ps-btn ps-btn--primary" href="#/projet?quick=1" data-quick-add>⚡ ${PS.t('إدخال سريع')}</a><a class="ps-btn" href="#/projet">＋ ${PS.t('النموذج الكامل')}</a></div>` : '';
		st.main.innerHTML = html`<div class="ps-page-head"><h1>${PS.t('المشاريع')}</h1>${add}</div>${toolbar()}<div data-results><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`.html;
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
				st = { main: main, params: Object.assign({}, params), data: null, selected: new Set(), showFilters: Object.keys(params).some(function (k) { return k !== 'q' && k !== 'isProject' && k !== 'sort' && k !== 'dir' && k !== 'page'; }) };
				return renderPage();
			});
		},
	});
})();
