/**
 * Page « الشركات والمنافسون » (#/annuaire?tab=companies|competitors).
 *
 * Annuaire unique des sociétés (مالك، شركة منفذة، وسيط) et des منافسين :
 *   - recherche (arabe normalisé), nombre de projets liés (clic → Base Projets filtrée) ;
 *   - ajout par toute l'équipe ; modification et FUSION des doublons par la الإدارة
 *     (les projets liés sont réaffectés à la fiche conservée).
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const COMPANY_FIELDS = [['name', 'اسم الشركة'], ['person', 'الشخص المسؤول'], ['phone', 'الهاتف'], ['email', 'الإيميل'], ['address', 'العنوان'], ['position', 'المنصب'], ['rank', 'الرتبة'], ['website', 'الموقع'], ['notes', 'ملاحظات']];
	const COMPETITOR_FIELDS = [['name', 'اسم المنافس'], ['notes', 'ملاحظات']];

	let st = null;

	function kind() { return st.params.tab === 'competitors' ? 'competitors' : 'companies'; }
	function fields() { return kind() === 'companies' ? COMPANY_FIELDS : COMPETITOR_FIELDS; }
	function canManage() { return PS.router.can('ps_manage_directory'); }

	function render() {
		const k = kind();
		st.main.innerHTML = html`<div class="ps-page-head"><h1>الشركات والمنافسون</h1>
			<div class="ps-page-head__actions">${PS.router.can('ps_add_directory') ? html`<button type="button" class="ps-btn ps-btn--primary" data-action="add">+ ${k === 'companies' ? 'شركة' : 'منافس'}</button>` : ''}</div></div>
			<div class="ps-tabs" role="tablist">
				<button type="button" role="tab" class="ps-tab" data-tab="companies" aria-selected="${k === 'companies' ? 'true' : 'false'}">🏢 الشركات</button>
				<button type="button" role="tab" class="ps-tab" data-tab="competitors" aria-selected="${k === 'competitors' ? 'true' : 'false'}">⚔️ المنافسون</button>
			</div>
			<div class="ps-card ps-toolbar"><div class="ps-toolbar__row">
				<input type="search" class="ps-input ps-search" placeholder="🔍 بحث بالاسم" value="${st.params.q || ''}" data-search>
				${canManage() ? html`<button type="button" class="ps-btn" data-action="merge">🔀 دمج المكررات</button>` : ''}
			</div></div>
			<div data-results><div class="ps-loading">جاري التحميل…</div></div>`.html;
		bind();
		load();
	}

	function load() {
		const k = kind();
		const qs = new URLSearchParams({ q: st.params.q || '', page: st.params.page || 1 }).toString();
		PS.api.get(k + '?' + qs).then(function (d) {
			st.rows = d.rows;
			const box = st.main.querySelector('[data-results]');
			if (!d.rows.length) { box.innerHTML = ui.empty(k === 'companies' ? '🏢' : '⚔️', st.params.q ? 'لا توجد نتائج.' : 'الدليل فارغ.').html; return; }
			const pages = Math.ceil(d.total / 50);
			box.innerHTML = html`<p class="ps-muted ps-small">${d.total} ${k === 'companies' ? 'شركة' : 'منافس'}</p>
				<div class="ps-table-wrap"><table class="ps-table">
				<thead><tr><th>الاسم</th>${k === 'companies' ? html`<th>الشخص المسؤول</th><th>الهاتف</th><th>الإيميل</th>` : html`<th>ملاحظات</th>`}<th>المشاريع</th><th></th></tr></thead>
				<tbody>${d.rows.map(function (r) {
					const link = k === 'companies' ? html`<a href="#/projets?companyId=${r.id}">${r.projectCount}</a>` : r.projectCount;
					return html`<tr><td><strong>${r.name}</strong></td>
						${k === 'companies' ? html`<td>${r.person || ''}</td><td dir="ltr">${r.phone || ''}</td><td dir="ltr">${r.email || ''}</td>` : html`<td>${r.notes || ''}</td>`}
						<td>${link}</td>
						<td>${canManage() ? html`<button type="button" class="ps-btn ps-btn--small" data-edit="${r.id}">تعديل</button>` : ''}</td></tr>`;
				})}</tbody></table></div>
				${pages > 1 ? html`<nav class="ps-pager"><button type="button" class="ps-btn ps-btn--small" data-page="${(Number(st.params.page) || 1) - 1}" ${(Number(st.params.page) || 1) <= 1 ? ui.raw('disabled') : ''}>→ السابق</button><span>${Number(st.params.page) || 1} / ${pages}</span><button type="button" class="ps-btn ps-btn--small" data-page="${(Number(st.params.page) || 1) + 1}" ${(Number(st.params.page) || 1) >= pages ? ui.raw('disabled') : ''}>التالي ←</button></nav>` : ''}`.html;
		}).catch(function (e) { ui.toast(e.message, 'error'); });
	}

	function formBody(row) {
		return html`<form class="ps-grid" data-dir-form>${fields().map(function (f) {
			const v = row ? row[f[0]] : '';
			return html`<label class="ps-field ${f[0] === 'name' ? 'ps-field--required' : ''}"><span class="ps-field__label">${f[1]}${f[0] === 'name' ? ' *' : ''}</span><input name="${f[0]}" data-key="${f[0]}" value="${v || ''}"></label>`;
		})}</form>`;
	}

	function edit(row) {
		const k = kind();
		ui.modal({
			title: row ? 'تعديل: ' + row.name : (k === 'companies' ? 'إضافة شركة' : 'إضافة منافس'),
			body: formBody(row),
			wide: k === 'companies',
			buttons: [{ id: 'ok', label: 'حفظ', kind: 'primary' }, { id: 'cancel', label: 'إلغاء' }],
			onButton: function (id, box, close) {
				if (id !== 'ok') return true;
				const form = box.querySelector('[data-dir-form]');
				const body = {};
				fields().forEach(function (f) { body[f[0]] = form.elements[f[0]].value; });
				const req = row ? PS.api.put(k + '/' + row.id, body) : PS.api.post(k, body);
				req.then(function () {
					close('ok');
					ui.toast('تم الحفظ', 'success');
					load();
				}).catch(function (err) {
					if (err.code === 'ps_validation' && err.data.data.errors) ui.showErrors(form, err.data.data.errors);
					ui.toast(err.message, 'error');
				});
				return false; // la modale se ferme après la réponse
			},
		});
	}

	function bind() {
		const m = st.main;
		const onSearch = ui.debounce(function (v) { st.params.q = v.trim(); delete st.params.page; PS.router.setParams(st.params); load(); }, 300);
		m.querySelector('[data-search]').addEventListener('input', function (e) { onSearch(e.target.value); });
		m.onclick = function (e) {
			const t = e.target.closest('[data-tab]');
			if (t) { st.params = { tab: t.dataset.tab }; PS.router.setParams(st.params); render(); return; }
			const ed = e.target.closest('[data-edit]');
			if (ed) { edit(st.rows.find(function (r) { return String(r.id) === ed.dataset.edit; })); return; }
			const pg = e.target.closest('[data-page]');
			if (pg) { st.params.page = pg.dataset.page; PS.router.setParams(st.params); load(); return; }
			const a = e.target.closest('[data-action]');
			if (!a) return;
			if (a.dataset.action === 'add') edit(null);
			if (a.dataset.action === 'merge' && PS.directoryMerge) PS.directoryMerge(kind(), load);
		};
	}

	PS.router.register({
		id: 'annuaire',
		title: 'الشركات والمنافسون',
		icon: '🏢',
		cap: 'ps_access',
		order: 70,
		group: 'analysis',
		render: function (main, params) {
			st = { main: main, params: Object.assign({}, params), rows: [] };
			render();
		},
	});
})();

/**
 * Fusion des doublons de l'annuaire (الإدارة) : PS.directoryMerge(kind, reload).
 * Propose les paires de noms proches ; on choisit la fiche à GARDER, l'autre y est fusionnée
 * (ses projets sont réaffectés, elle-même est conservée avec un lien vers la fiche gardée).
 */
PS.directoryMerge = function (kind, reload) {
	'use strict';
	const ui = PS.ui;
	const html = ui.html;
	PS.api.get(kind + '/duplicates').then(function (pairs) {
		const body = pairs.length ? html`<p class="ps-muted ps-small">اختر الاسم الذي تريد الاحتفاظ به؛ الآخر يُدمج فيه وتنتقل كل مشاريعه.</p>
			<ul class="ps-dup-list">${pairs.map(function (p, i) {
				return html`<li data-pair="${i}"><span class="ps-muted">تشابه ${Math.round(p.similarity * 100)}%</span><br>
					<button type="button" class="ps-btn ps-btn--small" data-keep="a">الاحتفاظ بـ «${p.a.name}»</button>
					<button type="button" class="ps-btn ps-btn--small" data-keep="b">الاحتفاظ بـ «${p.b.name}»</button></li>`;
			})}</ul>` : html`<p>✔ لا توجد أسماء متشابهة في الدليل.</p>`;
		ui.modal({
			title: '🔀 دمج المكررات',
			body: body,
			wide: true,
			buttons: [{ id: 'close', label: 'إغلاق' }],
			onButton: function () { return true; },
			onOpen: function (box) {
				box.addEventListener('click', function (e) {
					const b = e.target.closest('[data-keep]');
					if (!b) return;
					const p = pairs[Number(b.closest('[data-pair]').dataset.pair)];
					const keep = b.dataset.keep === 'a' ? p.a : p.b;
					const drop = b.dataset.keep === 'a' ? p.b : p.a;
					if (!window.confirm('دمج «' + drop.name + '» في «' + keep.name + '»؟')) return;
					PS.api.post(kind + '/' + drop.id + '/merge', { intoId: keep.id }).then(function (r) {
						ui.toast('تم الدمج: ' + r.moved + ' ارتباط مشروع نُقل', 'success');
						b.closest('[data-pair]').remove();
						reload();
					}).catch(function (er) { ui.toast(er.message, 'error'); });
				});
			},
		});
	}).catch(function (e) { ui.toast(e.message, 'error'); });
};
