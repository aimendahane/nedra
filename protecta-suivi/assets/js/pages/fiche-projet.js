/**
 * Page « Fiche Projet » : #/projet (nouveau projet) et #/projet?id=123 (projet existant).
 *
 * Nouveau projet : formulaire complet (معلومات، مبالغ، أطراف، أصناف، منافسون).
 *   - les champs obligatoires (réglés dans الإعدادات) sont en jaune ;
 *   - le brouillon est gardé dans le navigateur tant qu'il n'est pas enregistré ;
 *   - après l'enregistrement : رقم المشروع affiché et formulaire vidé (cahier §3.5).
 * Projet existant : onglets البيانات / سجل المتابعة / الملفات / سجل التعديلات.
 *   - le formulaire est en lecture seule si l'utilisateur ne peut pas modifier
 *     (le serveur décide : permissions.canEdit).
 *
 * Toutes les vérifications sont refaites par le serveur ; ici on ne fait que les
 * annoncer plus tôt.
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const PARTY_FIELDS = [
		['companyName', PS.t('اسم الشركة')], ['person', PS.t('الشخص المسؤول')], ['phone', PS.t('الهاتف'), 'tel'], ['email', PS.t('الإيميل'), 'email'],
		['address', PS.t('العنوان')], ['position', PS.t('المنصب')], ['rank', PS.t('الرتبة')], ['location', PS.t('الموقع')],
	];
	/*
	 * الوسيط (1.4.0) : « هامش الربح » à la place de « الرتبة » (demande du client). Une ancienne الرتبة déjà saisie
	 * reste affichée pour ne rien perdre.
	 */
	function partyFields(role, p) {
		if (role !== 'broker') return PARTY_FIELDS;
		return PARTY_FIELDS.reduce(function (out, f) {
			if (f[0] === 'rank') {
				out.push(['margin', PS.t('هامش الربح'), 'text', PS.t('مثلًا: 10% أو 5000')]);
				if (p.rank) out.push(f);
			} else out.push(f);
			return out;
		}, []);
	}
	/** Clés des règles « obligatoire » pour chaque champ d'intervenant. */
	const PARTY_RULE = { companyName: 'company', person: 'person', phone: 'phone', email: 'email' };

	let state = null; // { main, mode, project, draft, tab, saving }

	/* =====================================================================
	 * Brouillon
	 * ================================================================== */

	function draftKey() { return 'ps_draft_project_' + PS.config.user.id; }

	function emptyDraft() {
		const me = PS.config.user.employeeId;
		const d = {
			offerNo: '', name: '', receivedDate: PS.config.today, sentDate: '',
			responsibleEmpId: me || '', followerEmpId: me || '', intermediaryEmpId: '',
			placeId: '', categoryId: '', urgencyId: '', importanceKindId: '', importanceSizeId: '',
			followupStatusId: (L.values('followup_status').find(function (v) { return v.meta && v.meta.default; }) || {}).id || '',
			sourceId: '', lossReasonId: '', offerAmount: '', offerCurrencyId: (L.byCode('currency', 'LYD') || {}).id || '',
			purchaseAmount: '', purchaseCurrencyId: (L.byCode('currency', 'LYD') || {}).id || '',
			filesLink: '', note: '', parties: {}, items: [{ itemTypeId: '', quantity: '', employeeId: me || '', dueDate: '' }], competitors: [],
		};
		Object.keys(PS.config.partyRoles).forEach(function (r) { d.parties[r] = { companyId: null }; });
		return d;
	}

	function saveDraftLocal() {
		if (state.mode !== 'create') return;
		try { localStorage.setItem(draftKey(), JSON.stringify(state.draft)); } catch (e) { /* stockage indisponible */ }
	}
	function loadDraftLocal() {
		try { return JSON.parse(localStorage.getItem(draftKey()) || 'null'); } catch (e) { return null; }
	}
	function clearDraftLocal() {
		try { localStorage.removeItem(draftKey()); } catch (e) { /* rien */ }
	}

	/** Projet de l'API → brouillon modifiable (mêmes clés). */
	function projectToDraft(p) {
		const d = JSON.parse(JSON.stringify(p));
		['offerAmount', 'purchaseAmount'].forEach(function (k) { if (d[k] === null) d[k] = ''; });
		d.items.forEach(function (it) { if (it.quantity === null) it.quantity = ''; });
		return d;
	}

	/* =====================================================================
	 * Construction des champs
	 * ================================================================== */

	function rules() { return PS.config.settings.validation || {}; }
	function isRequired(ruleKey) { return (rules().required || []).indexOf(ruleKey) !== -1; }
	function readOnly() { return state.mode === 'edit' && !state.project.permissions.canEdit; }
	function dis(force) { return readOnly() || force ? ui.raw(' disabled') : ''; }
	/** 1.4.0 : صنف d'un autre employé, verrouillé pour un موظف créateur (le serveur vérifie aussi). */
	function otherItem(it) {
		const perm = state.mode === 'edit' && state.project.permissions;
		if (!perm || !perm.ownItemsOnly || !it.id) return false;
		const saved = (state.project.items || []).find(function (x) { return x.id === it.id; }); // employé ENREGISTRÉ
		return !!saved && String(saved.employeeId) !== String(PS.config.user.employeeId);
	}
	function amountsLocked() { return state.draft.items.some(otherItem); }

	function fieldWrap(label, ruleKey, inner, extraClass) {
		const req = ruleKey && isRequired(ruleKey);
		return html`<label class="ps-field ${req ? 'ps-field--required' : ''} ${extraClass || ''}">
			<span class="ps-field__label">${label}${req ? ui.raw(' <span class="ps-req">*</span>') : ''}</span>${inner}</label>`;
	}

	/** Champ texte / date / nombre lié à state.draft[path]. */
	function input(path, label, type, ruleKey, extra) {
		const v = ui.getPath(state.draft, path);
		return fieldWrap(label, ruleKey === undefined ? path : ruleKey,
			html`<input type="${type || 'text'}" data-bind="${path}" data-key="${extra && extra.key || path}" value="${v === null || v === undefined ? '' : v}"${type === 'number' ? ui.raw(' step="any" min="0" inputmode="decimal"') : ''}${dis(extra && extra.locked)}>`,
			extra && extra.cls);
	}

	/** Liste déroulante liée à state.draft[path]. */
	function select(path, label, listKey, ruleKey, opts) {
		const o = opts || {};
		const v = ui.getPath(state.draft, path);
		let options = L.options(listKey, v);
		if (o.addable && !readOnly()) options += '<option value="__add">' + (listKey === 'place' ? PS.t('+ إضافة مكان جديد') : PS.t('+ إضافة صنف جديد')) + '</option>';
		return fieldWrap(label, ruleKey === undefined ? path : ruleKey,
			html`<select data-bind="${path}" data-key="${o.key || path}" data-list="${listKey}"${dis(o.locked)}>${ui.raw(options)}</select>`, o.cls);
	}

	/* =====================================================================
	 * Sections du formulaire
	 * ================================================================== */

	function sectionInfo() {
		const d = state.draft;
		const isRejected = L.followupType(d.followupStatusId) === 'rejected';
		const offerNoBtn = !readOnly() ? html`<button type="button" class="ps-btn ps-btn--small" data-action="suggest-offer">${PS.t('اقتراح رقم')}</button>` : '';
		return html`<section class="ps-card">
			<h2 class="ps-card__title">${PS.t('معلومات المشروع')} <span class="ps-muted">(${PS.t('الخانات الصفراء إجبارية')})</span></h2>
			<div class="ps-grid">
				${state.mode === 'edit' ? fieldWrap(PS.t('رقم المشروع'), null, html`<input value="${state.project.projectNo}" disabled>`) : fieldWrap(PS.t('رقم المشروع'), null, html`<input value="${PS.t('يتولّد تلقائيًا عند الحفظ')}" disabled>`)}
				${fieldWrap(PS.t('رقم العرض'), 'offer_no', html`<span class="ps-inline"><input data-bind="offerNo" data-key="offer_no" value="${d.offerNo || ''}" dir="ltr"${dis()}>${offerNoBtn}</span>`)}
				${input('name', PS.t('اسم المشروع'), 'text', 'name', { key: 'name', cls: 'ps-span-2' })}
				${input('receivedDate', PS.t('تاريخ استلام الطلب'), 'date', 'received_date', { key: 'received_date' })}
				${input('sentDate', PS.t('تاريخ إرسال العرض'), 'date', 'sent_date', { key: 'sent_date' })}
				${select('responsibleEmpId', PS.t('المسؤول عن المشروع'), 'employee', 'responsible_emp_id', { key: 'responsible_emp_id' })}
				${select('followerEmpId', PS.t('الموظف المتابع'), 'employee', 'follower_emp_id', { key: 'follower_emp_id' })}
				${select('intermediaryEmpId', PS.t('الموظف الوسيط'), 'employee', 'intermediary_emp_id', { key: 'intermediary_emp_id' })}
				${select('placeId', PS.t('المكان'), 'place', 'place_id', { key: 'place_id', addable: true })}
				${select('categoryId', PS.t('التصنيف'), 'category', 'category_id', { key: 'category_id' })}
				${select('urgencyId', PS.t('درجة الاستعجال'), 'urgency', 'urgency_id', { key: 'urgency_id' })}
				${select('importanceKindId', PS.t('الأهمية كصنف'), 'importance', 'importance_kind_id', { key: 'importance_kind_id' })}
				${select('importanceSizeId', PS.t('الأهمية كحجم'), 'importance', 'importance_size_id', { key: 'importance_size_id' })}
				${select('sourceId', PS.t('مصدر الطلب'), 'source', 'source_id', { key: 'source_id' })}
				${select('followupStatusId', PS.t('حالة المتابعة'), 'followup_status', 'followup_status_id', { key: 'followup_status_id' })}
				${isRejected || d.lossReasonId ? select('lossReasonId', PS.t('سبب الرفض أو الخسارة'), 'loss_reason', 'loss_reason_id', { key: 'loss_reason_id' }) : ''}
				${input('filesLink', PS.t('رابط ملف المشاريع (Drive / OneDrive)'), 'url', 'files_link', { key: 'files_link', cls: 'ps-span-2' })}
			</div>
			${fieldWrap(PS.t('NOTE / نص الطلب'), 'note', html`<textarea rows="4" data-bind="note" data-key="note"${dis()}>${d.note || ''}</textarea>`, 'ps-mt')}
		</section>`;
	}

	function sectionAmounts() {
		let margin = PS.t('يُحسب بعد الحفظ (نفس العملة)');
		if (state.mode === 'edit' && state.project.computed.margin) {
			const m = state.project.computed.margin;
			margin = PS.format.money(m.amount, L.code(state.project.offerCurrencyId)) + (m.percent === null ? '' : ' (' + PS.format.percent(m.percent) + ')');
		} else if (state.mode === 'edit') {
			margin = PS.t('— (يلزم المبلغان بنفس العملة)');
		}
		return html`<section class="ps-card">
			<h2 class="ps-card__title">${PS.t('المبالغ')} <span class="ps-muted">(${PS.t('اختياري')})</span></h2>
			${amountsLocked() ? html`<p class="ps-small ps-muted">🔒 ${PS.t('مبالغ العرض والشراء يعدّلها المسؤول عن المشروع أو الإدارة فقط (المشروع فيه أصناف موظفين آخرين).')}</p>` : ''}
			<div class="ps-grid">
				${input('offerAmount', PS.t('مبلغ العرض'), 'number', 'offer_amount', { key: 'offer_amount', locked: amountsLocked() })}
				${select('offerCurrencyId', PS.t('عملة العرض'), 'currency', 'offer_currency_id', { key: 'offer_currency_id', locked: amountsLocked() })}
				${input('purchaseAmount', PS.t('مبلغ الشراء'), 'number', 'purchase_amount', { key: 'purchase_amount', locked: amountsLocked() })}
				${select('purchaseCurrencyId', PS.t('عملة الشراء'), 'currency', 'purchase_currency_id', { key: 'purchase_currency_id', locked: amountsLocked() })}
				${fieldWrap(PS.t('هامش الربح'), null, html`<input value="${margin}" disabled>`)}
			</div>
		</section>`;
	}

	function sectionParties() {
		return html`<section class="ps-card">
			<h2 class="ps-card__title">${PS.t('الأطراف المتدخلة')}</h2>
			<p class="ps-muted ps-small">${PS.t('اكتب اسم الشركة واختر من الدليل لتعبئة بياناتها، أو أضف شركة جديدة.')}</p>
			<div class="ps-parties">${Object.keys(PS.config.partyRoles).map(partyCard)}</div>
		</section>`;
	}

	function partyCard(role) {
		const p = state.draft.parties[role] || {};
		const linked = p.companyId ? html`<span class="ps-linked" title="${PS.t('مرتبطة بدليل الشركات')}">🔗</span>` : '';
		return html`<fieldset class="ps-party">
			<legend>${PS.config.partyRoles[role]} ${linked}</legend>
			<div class="ps-grid ps-grid--tight">${partyFields(role, p).map(function (f) {
				const rk = PARTY_RULE[f[0]] ? role + '.' + PARTY_RULE[f[0]] : null;
				const errKey = 'parties.' + role + '.' + (PARTY_RULE[f[0]] || f[0]);
				const v = p[f[0]] || '';
				return fieldWrap(f[1], rk, html`<input type="${f[2] || 'text'}" data-bind="parties.${role}.${f[0]}" data-key="${errKey}" value="${v}"${f[3] ? ui.raw(' placeholder="' + ui.esc(f[3]) + '"') : ''} ${ui.raw(f[0] === 'companyName' ? 'data-company="' + role + '"' : '')}${f[2] === 'tel' || f[2] === 'email' ? ui.raw(' dir="ltr"') : ''}${dis()}>`);
			})}</div>
		</fieldset>`;
	}

	function sectionItems() {
		const items = state.draft.items;
		const edit = state.mode === 'edit';
		const rows = items.map(function (it, i) {
			const other = otherItem(it);
			const locked = other || (it.offerStatusCode && it.offerStatusCode !== 'preparing');
			return html`<tr ${other ? ui.raw('class="is-locked" title="' + ui.esc(PS.t('لا يمكنك تعديل أو حذف صنف موظف آخر (للمسؤول عن المشروع والإدارة فقط).')) + '"') : ''}>
				<td>${selectCell('items.' + i + '.itemTypeId', 'item_type', 'items.' + i + '.item_type_id', true, other)}</td>
				<td><div class="ps-field"><input type="number" step="any" min="0" class="ps-input ps-input--num" data-bind="items.${i}.quantity" data-key="items.${i}.quantity" value="${it.quantity === null ? '' : it.quantity}"${dis(other)}></div></td>
				<td>${selectCell('items.' + i + '.employeeId', 'employee', 'items.' + i + '.employee_id', false, other)}</td>
				<td><div class="ps-field ${isRequired('item.due_date') ? 'ps-field--required' : ''}"><input type="date" class="ps-input" data-bind="items.${i}.dueDate" data-key="items.${i}.due_date" value="${it.dueDate || ''}"${dis(other)}></div></td>
				${edit ? html`<td>${it.offerStatusCode ? L.offerBadge(it.offerStatusCode) : ''}${other ? ' 🔒' : ''}</td>` : ''}
				<td>${readOnly() || locked ? '' : html`<button type="button" class="ps-icon-btn ps-icon-btn--sm" data-action="remove-item" data-i="${i}" aria-label="${PS.t('حذف الصنف')}">✕</button>`}</td>
			</tr>`;
		});
		return html`<section class="ps-card">
			<h2 class="ps-card__title">${PS.t('الأصناف المطلوبة')} ${isRequired('items.min_one') ? html`<span class="ps-muted">(${PS.t('صنف واحد على الأقل')})</span>` : ''}</h2>
			<div data-key="items"></div>
			<div class="ps-table-wrap"><table class="ps-table ps-table--form">
				<thead><tr><th>${PS.t('الصنف')} ${isRequired('item.item_type_id') ? '*' : ''}</th><th>${PS.t('الكمية')}</th><th>${PS.t('الموظف المسؤول')} ${isRequired('item.employee_id') ? '*' : ''}</th><th>${PS.t('الأجل المطلوب')} ${isRequired('item.due_date') ? '*' : ''}</th>${edit ? html`<th>${PS.t('حالة العرض')}</th>` : ''}<th></th></tr></thead>
				<tbody>${rows}</tbody>
			</table></div>
			${readOnly() ? '' : html`<div class="ps-row"><button type="button" class="ps-btn" data-action="add-item">+ ${PS.t('صنف')}</button></div>`}
		</section>`;
	}

	function selectCell(path, listKey, errKey, addable, locked) {
		const v = ui.getPath(state.draft, path);
		const rule = listKey === 'item_type' ? 'item.item_type_id' : 'item.employee_id';
		let options = L.options(listKey, v);
		if (addable && !readOnly()) options += ('<option value="__add">+ ' + PS.t('إضافة صنف جديد') + '</option>');
		return html`<div class="ps-field ${isRequired(rule) ? 'ps-field--required' : ''}"><select class="ps-input" data-bind="${path}" data-key="${errKey}" data-list="${listKey}"${dis(locked)}>${ui.raw(options)}</select></div>`;
	}

	function sectionCompetitors() {
		const rows = state.draft.competitors.map(function (c, i) {
			const chips = (c.itemTypeIds || []).map(function (id) {
				return html`<span class="ps-chip">${L.label(id)}${readOnly() ? '' : html`<button type="button" class="ps-chip__x" data-action="remove-comp-type" data-i="${i}" data-id="${id}" aria-label="${PS.t('إزالة')}">×</button>`}</span>`;
			});
			const typeOptions = L.values('item_type').filter(function (v) { return !v.hidden && (c.itemTypeIds || []).indexOf(v.id) === -1; })
				.map(function (v) { return html`<option value="${v.id}">${v.label}</option>`; });
			return html`<tr>
				<td><div class="ps-field"><input class="ps-input" data-bind="competitors.${i}.competitorName" data-key="competitors.${i}.competitor_name" data-competitor="${i}" value="${c.competitorName || ''}"${dis()}></div></td>
				<td><div class="ps-field"><input class="ps-input" data-bind="competitors.${i}.brand" value="${c.brand || ''}"${dis()}></div></td>
				<td><div class="ps-list-chips">${chips}</div>${readOnly() ? '' : html`<select class="ps-input ps-input--sm" data-action="add-comp-type" data-i="${i}"><option value="">+ ${PS.t('صنف')}</option>${typeOptions}</select>`}</td>
				<td><div class="ps-field"><input class="ps-input" data-bind="competitors.${i}.notes" value="${c.notes || ''}"${dis()}></div></td>
				<td>${readOnly() ? '' : html`<button type="button" class="ps-icon-btn ps-icon-btn--sm" data-action="remove-comp" data-i="${i}" aria-label="${PS.t('حذف المنافس')}">✕</button>`}</td>
			</tr>`;
		});
		return html`<section class="ps-card">
			<h2 class="ps-card__title">${PS.t('المنافسون')}</h2>
			${rows.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--form">
				<thead><tr><th>${PS.t('المنافس')}</th><th>${PS.t('الماركة')}</th><th>${PS.t('الأصناف')}</th><th>${PS.t('ملاحظات')}</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : html`<p class="ps-muted">${PS.t('لا يوجد منافسون مسجلون.')}</p>`}
			${readOnly() ? '' : html`<div class="ps-row"><button type="button" class="ps-btn" data-action="add-comp">+ ${PS.t('منافس')}</button></div>`}
		</section>`;
	}

	function formActions() {
		if (readOnly()) return '';
		return html`<div class="ps-form-actions">
			<button type="button" class="ps-btn ps-btn--primary" data-action="save">💾 ${state.mode === 'create' ? PS.t('حفظ المشروع') : PS.t('حفظ التعديلات')}</button>
			${state.mode === 'create' ? html`<button type="button" class="ps-btn" data-action="reset">${PS.t('تفريغ')}</button>` : html`<button type="button" class="ps-btn" data-action="cancel">${PS.t('إلغاء التعديلات')}</button>`}
		</div>`;
	}

	function formHtml() {
		return html`<form class="ps-form" novalidate data-form>
			<div data-errors></div>
			${sectionInfo()}${sectionParties()}${sectionItems()}${sectionAmounts()}${sectionCompetitors()}
			${formActions()}
		</form>`;
	}

	/* =====================================================================
	 * Page
	 * ================================================================== */

	function header() {
		if (state.mode === 'create') {
			return html`<div class="ps-page-head"><h1>${PS.t('إدخال مشروع جديد')}</h1></div>${state.lastSaved ? html`<div class="ps-alert ps-alert--success" role="status">✔ ${PS.t('تم حفظ المشروع')} <strong>${state.lastSaved.projectNo}</strong> — <a href="#/projet?id=${state.lastSaved.id}">${PS.t('عرض المشروع')}</a></div>` : ''}${state.restored ? html`<div class="ps-alert ps-alert--info">${PS.t('تم استرجاع مسودة غير محفوظة.')} <button type="button" class="ps-btn ps-btn--small" data-action="reset">${PS.t('بدء مشروع جديد')}</button></div>` : ''}`;
		}
		const p = state.project;
		const perm = p.permissions;
		let banner = '';
		if (p.deletedAt) banner = html`<div class="ps-alert ps-alert--error">${PS.t('هذا المشروع في سلة المحذوفات.')}</div>`;
		else if (!perm.canEdit) banner = html`<div class="ps-alert ps-alert--info">${PS.t('عرض فقط: التعديل للمسؤول عن المشروع والإدارة')}${PS.config.settings.creatorEditHours ? PS.t(' (أو لمن أنشأه خلال ') + PS.config.settings.creatorEditHours + PS.t(' ساعة)') : ''}.</div>`;
		else if (perm.editReason === 'creator') banner = html`<div class="ps-alert ps-alert--warning">${PS.t('يمكنك تعديل هذا المشروع حتى')} ${PS.format.dateTime(perm.editUntil)}.</div>`;
		return html`<div class="ps-page-head">
			<h1 class="ps-ptitle-h1">${p.name}<span class="ps-ptitle__no" dir="ltr">${[p.projectNo, p.oldNo].filter(Boolean).join(' · ')}</span></h1>
			<div class="ps-page-head__badges">${L.offerBadge(p.computed.offerStatus)} ${L.badge(p.followupStatusId, L.label(p.followupStatusId))} ${p.computed.priority ? html`<span class="ps-badge ps-prio ps-prio--${p.computed.priority}">${p.computed.priority}</span>` : ''}</div>
			<div class="ps-page-head__actions">
				${PS.router.can('ps_assign_tasks') && !p.deletedAt ? html`<button type="button" class="ps-btn" data-action="new-task">📌 ${PS.t('مهمة')}</button>` : ''}
				${perm.canDelete && !p.deletedAt ? html`<button type="button" class="ps-btn ps-btn--danger" data-action="delete">🗑 ${PS.t('حذف')}</button>` : ''}
				${perm.canDelete && p.deletedAt ? html`<button type="button" class="ps-btn" data-action="restore">↩ ${PS.t('استرجاع')}</button>` : ''}
				<a class="ps-btn" href="#/projets">→ ${PS.t('المشاريع')}</a>
			</div>
		</div>
		<p class="ps-muted ps-small">${PS.t('أُنشئ')} ${PS.format.dateTime(p.createdAt)} ${PS.t('من طرف')} ${p.createdBy ? p.createdBy.name : '—'} · ${PS.t('آخر تعديل')} ${PS.format.dateTime(p.updatedAt)}${p.updatedBy ? PS.t(' من طرف ') + p.updatedBy.name : ''}</p>
		${banner}`;
	}

	function tabs() {
		if (state.mode === 'create') return '';
		const t = [['data', PS.t('البيانات')], ['followups', PS.t('سجل المتابعة')], ['files', PS.t('الملفات')], ['log', PS.t('سجل التعديلات')]];
		return html`<div class="ps-tabs" role="tablist">${t.map(function (x) {
			return html`<button type="button" role="tab" class="ps-tab" aria-selected="${state.tab === x[0] ? 'true' : 'false'}" data-tab="${x[0]}">${x[1]}</button>`;
		})}</div>`;
	}

	function render() {
		let body = '';
		if (state.mode === 'create' || state.tab === 'data') body = formHtml();
		else body = html`<div data-tab-body><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`;
		state.main.innerHTML = html`${header()}${tabs()}${body}`.html;
		bindForm();
		if (state.mode === 'edit' && state.tab !== 'data') PS.projectTabs[state.tab](state.main.querySelector('[data-tab-body]'), state.project);
	}

	/* =====================================================================
	 * Interactions
	 * ================================================================== */

	function bindForm() {
		const main = state.main;
		main.onclick = onClick;
		const form = main.querySelector('[data-form]');
		if (!form) return;
		form.addEventListener('input', onInput);
		form.addEventListener('change', onChange);
		form.addEventListener('submit', function (e) { e.preventDefault(); });
		if (!readOnly()) {
			form.querySelectorAll('[data-company]').forEach(bindCompany);
			form.querySelectorAll('[data-competitor]').forEach(bindCompetitor);
		}
	}

	function onInput(e) {
		const path = e.target.dataset.bind;
		if (!path || e.target.tagName === 'SELECT') return;
		ui.setPath(state.draft, path, e.target.value);
		// Modifier le nom de la société à la main détache la fiche de l'annuaire.
		const m = /^parties\.(\w+)\.companyName$/.exec(path);
		if (m && state.draft.parties[m[1]].companyId) {
			state.draft.parties[m[1]].companyId = null;
			const lg = e.target.closest('fieldset').querySelector('.ps-linked');
			if (lg) lg.remove();
		}
		state.dirty = true;
		saveDraftLocal();
	}

	function onChange(e) {
		const el = e.target;
		if (el.dataset.action === 'add-comp-type') {
			const i = Number(el.dataset.i);
			if (el.value) {
				const c = state.draft.competitors[i];
				c.itemTypeIds = (c.itemTypeIds || []).concat([Number(el.value)]);
				rerenderKeepScroll();
			}
			return;
		}
		const path = el.dataset.bind;
		if (!path || el.tagName !== 'SELECT') return;
		if (el.value === '__add') { quickAdd(el); return; }
		const before = ui.getPath(state.draft, path);
		ui.setPath(state.draft, path, el.value ? Number(el.value) : '');
		state.dirty = true;
		saveDraftLocal();
		// Le motif de perte n'apparaît que pour une حالة de type مرفوض.
		if (path === 'followupStatusId' && (L.followupType(before) === 'rejected') !== (L.followupType(el.value) === 'rejected')) rerenderKeepScroll();
	}

	function rerenderKeepScroll() {
		const y = window.scrollY;
		render();
		window.scrollTo(0, y);
	}

	function onClick(e) {
		const tab = e.target.closest('[data-tab]');
		if (tab) {
			if (state.tab === 'data' && state.dirty && !window.confirm(PS.t('توجد تعديلات غير محفوظة. متابعة؟'))) return;
			if (state.tab === 'data') state.dirty = false;
			state.tab = tab.dataset.tab;
			if (state.tab === 'data') state.draft = projectToDraft(state.project);
			render();
			return;
		}
		const btn = e.target.closest('[data-action]');
		if (!btn || btn.tagName === 'SELECT') return;
		const a = btn.dataset.action;
		const i = Number(btn.dataset.i);
		const d = state.draft;
		if (a === 'add-item') { d.items.push({ itemTypeId: '', quantity: '', employeeId: PS.config.user.employeeId || '', dueDate: '' }); rerenderKeepScroll(); }
		else if (a === 'remove-item') { d.items.splice(i, 1); rerenderKeepScroll(); }
		else if (a === 'add-comp') { d.competitors.push({ competitorId: null, competitorName: '', brand: '', itemTypeIds: [], notes: '' }); rerenderKeepScroll(); }
		else if (a === 'remove-comp') { d.competitors.splice(i, 1); rerenderKeepScroll(); }
		else if (a === 'remove-comp-type') { d.competitors[i].itemTypeIds = d.competitors[i].itemTypeIds.filter(function (x) { return String(x) !== btn.dataset.id; }); rerenderKeepScroll(); }
		else if (a === 'suggest-offer') suggestOfferNo();
		else if (a === 'save') save(false);
		else if (a === 'reset') { clearDraftLocal(); state.draft = emptyDraft(); state.restored = false; state.lastSaved = null; render(); }
		else if (a === 'cancel') { state.draft = projectToDraft(state.project); state.dirty = false; render(); }
		else if (a === 'new-task') { PS.taskModal({ projectId: state.project.id, projectLabel: state.project.name + ' — ' + state.project.projectNo }); return; }
		else if (a === 'delete') removeProject();
		else if (a === 'restore') restoreProject();
		else return;
		saveDraftLocal();
	}

	function suggestOfferNo() {
		PS.api.get('projects/suggest-offer-no?date=' + encodeURIComponent(state.draft.receivedDate || '')).then(function (r) {
			state.draft.offerNo = r.offerNo;
			const el = state.main.querySelector('[data-bind="offerNo"]');
			if (el) el.value = r.offerNo;
			saveDraftLocal();
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	/** « + إضافة مكان جديد » / « + إضافة صنف جديد ». */
	function quickAdd(sel) {
		const key = sel.dataset.list;
		const path = sel.dataset.bind;
		const prev = ui.getPath(state.draft, path);
		const isPlace = key === 'place';
		ui.modal({
			title: isPlace ? PS.t('إضافة مكان جديد') : PS.t('إضافة صنف جديد'),
			body: html`<div class="ps-grid">
				<label class="ps-field ps-field--required"><span class="ps-field__label">${isPlace ? PS.t('اسم المكان') : PS.t('اسم الصنف')} *</span><input data-q="label"></label>
				${isPlace ? html`<label class="ps-field"><span class="ps-field__label">${PS.t('الرمز (مثال: ZW)')}</span><input data-q="code" dir="ltr" maxlength="6"></label>` : ''}
			</div><p class="ps-muted ps-small">${PS.t('تُضاف القيمة للقائمة وتصبح متاحة لكل الفريق.')}</p>`,
			buttons: [{ id: 'ok', label: PS.t('إضافة'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
			onButton: function (id, box) {
				if (id !== 'ok') return true;
				const label = box.querySelector('[data-q="label"]').value.trim();
				if (!label) { box.querySelector('[data-q="label"]').focus(); return false; }
				const codeEl = box.querySelector('[data-q="code"]');
				PS.api.post('lists/' + key + '/quick-add', { label: label, code: codeEl ? codeEl.value : '' }).then(function (r) {
					L.add(r.value);
					ui.setPath(state.draft, path, r.value.id);
					ui.toast(r.existed ? PS.t('القيمة موجودة مسبقًا وتم اختيارها') : PS.t('تمت الإضافة'), 'success');
					rerenderKeepScroll();
				}).catch(function (err) { ui.toast(err.message, 'error'); rerenderKeepScroll(); });
				return true;
			},
		}).then(function (r) { if (r !== 'ok') { ui.setPath(state.draft, path, prev); rerenderKeepScroll(); } });
	}

	/** Autocomplétion société : choisir une fiche remplit ses coordonnées. */
	function bindCompany(inputEl) {
		const role = inputEl.dataset.company;
		ui.autocomplete(inputEl, {
			source: function (q) {
				return PS.api.get('companies/search?q=' + encodeURIComponent(q)).then(function (rows) {
					return rows.map(function (c) { return { id: c.id, label: c.name, sub: [c.person, c.phone].filter(Boolean).join(' · '), data: c }; });
				});
			},
			newLabel: function (q) { return PS.t('+ إضافة شركة جديدة: «') + q + '»'; },
			onNew: function (q) {
				const p = state.draft.parties[role];
				p.companyId = null;
				p.companyName = q;
				ui.toast(PS.t('ستُضاف الشركة إلى الدليل عند حفظ المشروع'), 'info');
				saveDraftLocal();
			},
			onSelect: function (it) {
				const p = state.draft.parties[role];
				const c = it.data;
				p.companyId = c.id;
				p.companyName = c.name;
				// Remplit les champs vides avec les coordonnées de la fiche.
				[['person', 'person'], ['phone', 'phone'], ['email', 'email'], ['address', 'address'], ['position', 'position'], ['rank', 'rank'], ['location', 'website']].forEach(function (m) {
					if (!p[m[0]] && c[m[1]]) p[m[0]] = c[m[1]];
				});
				saveDraftLocal();
				rerenderKeepScroll();
			},
		});
	}

	function bindCompetitor(inputEl) {
		const i = Number(inputEl.dataset.competitor);
		ui.autocomplete(inputEl, {
			source: function (q) {
				return PS.api.get('competitors/search?q=' + encodeURIComponent(q)).then(function (rows) {
					return rows.map(function (c) { return { id: c.id, label: c.name }; });
				});
			},
			newLabel: function (q) { return PS.t('+ منافس جديد: «') + q + '»'; },
			onNew: function (q) { state.draft.competitors[i].competitorId = null; state.draft.competitors[i].competitorName = q; saveDraftLocal(); },
			onSelect: function (it) { state.draft.competitors[i].competitorId = it.id; state.draft.competitors[i].competitorName = it.label; saveDraftLocal(); },
		});
	}

	/* =====================================================================
	 * Enregistrement
	 * ================================================================== */

	/** Vérification rapide côté navigateur des champs obligatoires (le serveur revérifie). */
	function clientErrors() {
		const d = state.draft;
		const req = rules().required || [];
		const errs = {};
		const empty = function (v) { return v === null || v === undefined || String(v).trim() === ''; };
		const map = {
			offer_no: 'offerNo', name: 'name', received_date: 'receivedDate', sent_date: 'sentDate',
			responsible_emp_id: 'responsibleEmpId', follower_emp_id: 'followerEmpId', intermediary_emp_id: 'intermediaryEmpId',
			place_id: 'placeId', category_id: 'categoryId', urgency_id: 'urgencyId', importance_kind_id: 'importanceKindId',
			importance_size_id: 'importanceSizeId', followup_status_id: 'followupStatusId', source_id: 'sourceId', offer_amount: 'offerAmount',
			files_link: 'filesLink', note: 'note',
		};
		Object.keys(map).forEach(function (k) { if (req.indexOf(k) !== -1 && empty(d[map[k]])) errs[k] = PS.t('خانة إجبارية'); });
		Object.keys(PS.config.partyRoles).forEach(function (role) {
			const p = d.parties[role] || {};
			[['company', 'companyName'], ['person', 'person'], ['phone', 'phone'], ['email', 'email']].forEach(function (f) {
				if (req.indexOf(role + '.' + f[0]) !== -1 && empty(p[f[1]]) && !(f[0] === 'company' && p.companyId)) errs['parties.' + role + '.' + f[0]] = PS.t('خانة إجبارية');
			});
		});
		if (req.indexOf('items.min_one') !== -1 && !d.items.length) errs.items = PS.t('لازم صنف واحد على الأقل');
		d.items.forEach(function (it, i) {
			[['item_type_id', 'itemTypeId'], ['employee_id', 'employeeId'], ['due_date', 'dueDate'], ['quantity', 'quantity']].forEach(function (f) {
				if (req.indexOf('item.' + f[0]) !== -1 && empty(it[f[1]])) errs['items.' + i + '.' + f[0]] = PS.t('خانة إجبارية');
			});
		});
		if (rules().sent_after_received && d.sentDate && d.receivedDate && d.sentDate < d.receivedDate) errs.sent_date = PS.t('تاريخ الإرسال لازم يكون بعد تاريخ الاستلام أو نفس اليوم');
		return errs;
	}

	function save(confirmDuplicates) {
		if (state.saving) return;
		const form = state.main.querySelector('[data-form]');
		const errs = clientErrors();
		if (Object.keys(errs).length) {
			ui.showErrors(form, errs);
			ui.toast(PS.t('يرجى تعبئة الخانات الإجبارية'), 'error');
			return;
		}
		ui.showErrors(form, {});
		state.saving = true;
		const btn = form.querySelector('[data-action="save"]');
		if (btn) btn.disabled = true;
		const body = Object.assign({}, state.draft, { confirmDuplicates: !!confirmDuplicates });
		const req = state.mode === 'create' ? PS.api.post('projects', body) : PS.api.put('projects/' + state.project.id, body);
		req.then(function (p) {
			state.saving = false;
			state.dirty = false;
			if (state.mode === 'create') {
				clearDraftLocal();
				state.lastSaved = p;
				state.restored = false;
				state.draft = emptyDraft();
				render();
				window.scrollTo(0, 0);
				ui.toast(PS.t('تم حفظ المشروع ') + p.projectNo, 'success');
			} else {
				state.project = p;
				state.draft = projectToDraft(p);
				render();
				ui.toast(PS.t('تم حفظ التعديلات'), 'success');
			}
		}).catch(function (err) {
			state.saving = false;
			if (btn) btn.disabled = false;
			if (err.code === 'ps_validation') {
				ui.showErrors(form, err.data.data.errors);
				ui.toast(err.message, 'error');
			} else if (err.code === 'ps_duplicates') {
				askDuplicates(err.data.data.duplicates);
			} else {
				ui.toast(err.message, 'error');
			}
		});
	}

	function askDuplicates(list) {
		ui.modal({
			title: PS.t('⚠️ مشروع مشابه موجود'),
			body: html`<p>${PS.t('يوجد مشروع لنفس الشركة باسم قريب خلال آخر')} ${60} ${PS.t('يومًا. تأكد أنه ليس نفس الطلب:')}</p>
				<ul class="ps-dup-list">${list.map(function (d) {
					return html`<li><a href="#/projet?id=${d.id}" target="_blank" rel="noopener"><strong>${d.projectNo}</strong> — ${d.name}</a><br><span class="ps-muted">${d.companyName} · ${PS.format.date(d.receivedDate)} · ${PS.t('تشابه')} ${Math.round(d.similarity * 100)}%</span></li>`;
				})}</ul>`,
			buttons: [{ id: 'save', label: PS.t('حفظ رغم ذلك'), kind: 'accent' }, { id: 'back', label: PS.t('رجوع للتعديل') }],
		}).then(function (r) { if (r === 'save') save(true); });
	}

	function removeProject() {
		ui.confirm(PS.t('نقل المشروع ') + state.project.projectNo + PS.t(' إلى سلة المحذوفات؟ يمكن استرجاعه لاحقًا.'), PS.t('حذف'), 'danger').then(function (ok) {
			if (!ok) return;
			PS.api.del('projects/' + state.project.id).then(function () {
				ui.toast(PS.t('تم نقل المشروع إلى سلة المحذوفات'), 'success');
				PS.router.go('projets');
			}).catch(function (e) { ui.toast(e.message, 'error'); });
		});
	}

	function restoreProject() {
		PS.api.post('projects/' + state.project.id + '/restore').then(function () {
			ui.toast(PS.t('تم استرجاع المشروع'), 'success');
			PS.router.route();
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	/* =====================================================================
	 * Inscription de la page
	 * ================================================================== */

	PS.router.register({
		id: 'projet',
		title: PS.t('إدخال مشروع'),
		icon: '➕',
		cap: 'ps_create_project',
		order: 20,
		group: 'work',
		// Un projet existant s'ouvre pour tout utilisateur (lecture), même sans droit de création.
		capCheck: function (params) { return params.id ? PS.router.can('ps_access') : PS.router.can('ps_create_project'); },
		render: function (main, params) {
			return L.load().then(function () {
				if (params.id) {
					return PS.api.get('projects/' + Number(params.id)).then(function (p) {
						state = { main: main, mode: 'edit', project: p, draft: projectToDraft(p), tab: params.tab || 'data' };
						render();
					});
				}
				const saved = loadDraftLocal();
				state = { main: main, mode: 'create', draft: saved || emptyDraft(), restored: !!saved, tab: 'data' };
				render();
			});
		},
		leave: function () {
			return !(state && state.mode === 'edit' && state.dirty) || window.confirm(PS.t('توجد تعديلات غير محفوظة. مغادرة الصفحة؟'));
		},
	});
})();
