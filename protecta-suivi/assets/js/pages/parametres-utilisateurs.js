/**
 * الإعدادات › المستخدمون : comptes de l'équipe (الإدارة).
 *   - créer un compte (identifiant, nom, e-mail, mot de passe, rôle, employé lié, e-mail du matin) ;
 *   - modifier (rôle, employé, e-mail du matin, nouveau mot de passe) ;
 *   - désactiver / réactiver (un compte n'est jamais supprimé : son historique reste).
 * Le lien compte ↔ employé permet مهامي, « mes أصناف » et les droits du مسؤول مشروع.
 */
window.PS = window.PS || {};
PS.settingsTabs = PS.settingsTabs || {};

(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;

	function form(u, roles) {
		const v = u || { role: 'ps_employee', dailyEmail: true };
		return html`<form class="ps-grid" data-user-form>
			${u ? '' : html`<label class="ps-field ps-field--required"><span class="ps-field__label">اسم المستخدم (للدخول) *</span><input name="login" data-key="login" dir="ltr" autocomplete="off"></label>`}
			<label class="ps-field ps-field--required"><span class="ps-field__label">الاسم الكامل *</span><input name="name" data-key="name" value="${v.name || ''}"></label>
			<label class="ps-field ps-field--required"><span class="ps-field__label">البريد الإلكتروني *</span><input name="email" type="email" data-key="email" dir="ltr" value="${v.email || ''}"></label>
			<label class="ps-field ${u ? '' : 'ps-field--required'}"><span class="ps-field__label">${u ? 'كلمة سر جديدة (اختياري)' : 'كلمة السر *'}</span><input name="password" type="text" data-key="password" dir="ltr" autocomplete="new-password" placeholder="8 أحرف على الأقل"></label>
			${u && u.isWpAdmin ? '' : html`<label class="ps-field ps-field--required"><span class="ps-field__label">الدور *</span><select name="role" data-key="role">${Object.keys(roles).map(function (r) { return html`<option value="${r}" ${v.role === r ? ui.raw('selected') : ''}>${roles[r]}</option>`; })}</select></label>`}
			<label class="ps-field"><span class="ps-field__label">الموظف المرتبط (من القائمة)</span><select name="employeeId" data-key="employeeId">${ui.raw(L.options('employee', v.employeeId, { emptyLabel: '— بدون —' }))}</select></label>
			<label class="ps-check"><input type="checkbox" name="dailyEmail" ${v.dailyEmail ? ui.raw('checked') : ''}> البريد الصباحي (ملخص المهام)</label>
		</form>`;
	}

	function edit(u, roles, reload) {
		ui.modal({
			title: u ? 'تعديل الحساب: ' + u.login : 'حساب جديد',
			body: form(u, roles),
			wide: true,
			buttons: [{ id: 'ok', label: 'حفظ', kind: 'primary' }, { id: 'cancel', label: 'إلغاء' }],
			onButton: function (id, box, close) {
				if (id !== 'ok') return true;
				const f = box.querySelector('[data-user-form]');
				const body = { name: f.name.value, email: f.email.value, employeeId: f.employeeId.value ? Number(f.employeeId.value) : null, dailyEmail: f.dailyEmail.checked };
				if (f.login) body.login = f.login.value;
				if (f.role) body.role = f.role.value;
				if (f.password.value) body.password = f.password.value;
				(u ? PS.api.put('users/' + u.id, body) : PS.api.post('users', body)).then(function () {
					close('ok');
					ui.toast('تم الحفظ', 'success');
					reload();
				}).catch(function (e) {
					if (e.data && e.data.data && e.data.data.errors) ui.showErrors(f, e.data.data.errors);
					ui.toast(PS.settingsTabs.errMsg(e), 'error');
				});
				return false;
			},
		});
	}

	function usersTab(box) {
		const reload = function () { usersTab(box); };
		return Promise.all([PS.api.get('users'), L.load()]).then(function (res) {
			const d = res[0];
			box.innerHTML = html`<section class="ps-card">
				<div class="ps-page-head"><h2 class="ps-card__title">حسابات الفريق (${d.users.length})</h2><div class="ps-page-head__actions"><button type="button" class="ps-btn ps-btn--primary" data-new>+ حساب جديد</button></div></div>
				<p class="ps-muted ps-small">لا يُحذف أي حساب: الحساب المعطّل لا يستطيع الدخول ويبقى تاريخه في المشاريع.</p>
				<div class="ps-table-wrap"><table class="ps-table ps-table--compact">
					<thead><tr><th>الاسم</th><th>اسم الدخول</th><th>البريد</th><th>الدور</th><th>الموظف المرتبط</th><th>البريد الصباحي</th><th>آخر نشاط</th><th>الحالة</th><th></th></tr></thead>
					<tbody>${d.users.map(function (u) {
						return html`<tr class="${u.disabled ? 'ps-row-muted' : ''}" data-id="${u.id}">
							<td><strong>${u.name}</strong></td><td dir="ltr">${u.login}</td><td dir="ltr">${u.email}</td><td>${u.roleLabel}</td>
							<td>${u.employeeId ? L.label(u.employeeId) : html`<span class="ps-text-danger ps-small">غير مرتبط</span>`}</td>
							<td>${u.dailyEmail ? '✔' : '—'}</td><td class="ps-nowrap">${u.lastLogin || '—'}</td>
							<td>${u.disabled ? ui.badge('معطّل', '#e03131') : ui.badge('نشط', '#2b8a3e')}</td>
							<td class="ps-nowrap"><button type="button" class="ps-btn ps-btn--small" data-edit>تعديل</button>
								${u.isWpAdmin || u.id === PS.config.user.id ? '' : html`<button type="button" class="ps-btn ps-btn--small ${u.disabled ? '' : 'ps-btn--danger'}" data-toggle>${u.disabled ? 'تفعيل' : 'تعطيل'}</button>`}</td>
						</tr>`;
					})}</tbody></table></div>
			</section>`.html;
			box.onclick = function (e) {
				if (e.target.closest('[data-new]')) { edit(null, d.roles, reload); return; }
				const tr = e.target.closest('tr[data-id]');
				if (!tr) return;
				const u = d.users.find(function (x) { return String(x.id) === tr.dataset.id; });
				if (e.target.closest('[data-edit]')) edit(u, d.roles, reload);
				if (e.target.closest('[data-toggle]')) {
					ui.confirm(u.disabled ? 'إعادة تفعيل حساب ' + u.name + '؟' : 'تعطيل حساب ' + u.name + '؟ سيتم إخراجه فورًا.', u.disabled ? 'تفعيل' : 'تعطيل', u.disabled ? 'primary' : 'danger').then(function (ok) {
						if (!ok) return;
						PS.api.put('users/' + u.id, { disabled: !u.disabled }).then(function () { ui.toast('تم', 'success'); reload(); })
							.catch(function (er) { ui.toast(PS.settingsTabs.errMsg(er), 'error'); });
					});
				}
			};
		});
	}

	PS.settingsTabs.users = usersTab;
})();
