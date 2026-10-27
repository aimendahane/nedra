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
			${u ? '' : html`<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('اسم المستخدم (للدخول) *')}</span><input name="login" data-key="login" dir="ltr" autocomplete="off"></label>`}
			<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('الاسم الكامل *')}</span><input name="name" data-key="name" value="${v.name || ''}"></label>
			<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('البريد الإلكتروني *')}</span><input name="email" type="email" data-key="email" dir="ltr" value="${v.email || ''}"></label>
			<label class="ps-field ${u ? '' : 'ps-field--required'}"><span class="ps-field__label">${u ? PS.t('كلمة سر جديدة (اختياري)') : PS.t('كلمة السر *')}</span><input name="password" type="text" data-key="password" dir="ltr" autocomplete="new-password" placeholder="8 ${PS.t('أحرف على الأقل')}"></label>
			${u && u.isWpAdmin ? '' : html`<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('الدور *')}</span><select name="role" data-key="role">${Object.keys(roles).map(function (r) { return html`<option value="${r}" ${v.role === r ? ui.raw('selected') : ''}>${roles[r]}</option>`; })}</select></label>`}
			<label class="ps-field"><span class="ps-field__label">${PS.t('الموظف المرتبط (من القائمة)')}</span><select name="employeeId" data-key="employeeId">${ui.raw(L.options('employee', v.employeeId, { emptyLabel: PS.t('— بدون —') }))}</select></label>
			<label class="ps-check"><input type="checkbox" name="dailyEmail" ${v.dailyEmail ? ui.raw('checked') : ''}> ${PS.t('البريد الصباحي (ملخص المهام)')}</label>
		</form>`;
	}

	function edit(u, roles, reload) {
		ui.modal({
			title: u ? PS.t('تعديل الحساب: ') + u.login : PS.t('حساب جديد'),
			body: form(u, roles),
			wide: true,
			buttons: [{ id: 'ok', label: PS.t('حفظ'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
			onButton: function (id, box, close) {
				if (id !== 'ok') return true;
				const f = box.querySelector('[data-user-form]');
				const body = { name: f.name.value, email: f.email.value, employeeId: f.employeeId.value ? Number(f.employeeId.value) : null, dailyEmail: f.dailyEmail.checked };
				if (f.login) body.login = f.login.value;
				if (f.role) body.role = f.role.value;
				if (f.password.value) body.password = f.password.value;
				(u ? PS.api.put('users/' + u.id, body) : PS.api.post('users', body)).then(function () {
					close('ok');
					ui.toast(PS.t('تم الحفظ'), 'success');
					reload();
				}).catch(function (e) {
					if (e.data && e.data.data && e.data.data.errors) ui.showErrors(f, e.data.data.errors);
					ui.toast(PS.settingsTabs.errMsg(e), 'error');
				});
				return false;
			},
		});
	}

	/* ---- « ورقة حسابات الفريق » (1.4.0) : accès à remettre au directeur ---- */

	/** Identifiant proposé à partir du nom arabe (« غفران دريدي » → « ghofran.dridi »), modifiable. */
	const TR = { 'ا': 'a', 'أ': 'a', 'إ': 'i', 'آ': 'a', 'ب': 'b', 'ت': 't', 'ث': 'th', 'ج': 'j', 'ح': 'h', 'خ': 'kh', 'د': 'd', 'ذ': 'dh', 'ر': 'r', 'ز': 'z', 'س': 's', 'ش': 'ch', 'ص': 's', 'ض': 'dh', 'ط': 't', 'ظ': 'dh', 'ع': 'a', 'غ': 'gh', 'ف': 'f', 'ق': 'k', 'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n', 'ه': 'h', 'ة': 'a', 'و': 'ou', 'ي': 'i', 'ى': 'a', 'ئ': 'i', 'ؤ': 'ou', 'ء': '' };
	function suggestLogin(name, taken) {
		let base = String(name || '').trim().split(/\s+/).slice(0, 2).map(function (w) {
			return w.split('').map(function (c) { return TR[c] !== undefined ? TR[c] : (/[a-z0-9]/i.test(c) ? c.toLowerCase() : ''); }).join('').replace(/(.)\1+/g, '$1');
		}).filter(Boolean).join('.') || 'user';
		let login = base;
		for (let n = 2; taken[login]; n++) login = base + n;
		taken[login] = true;
		return login;
	}

	function accessSheet(d, roles, reload) {
		const linked = {};
		const taken = {};
		d.users.forEach(function (u) { if (u.employeeId) linked[u.employeeId] = true; taken[u.login] = true; });
		const free = L.values('employee').filter(function (v) { return !v.hidden && !linked[v.id]; });
		const existing = d.users.filter(function (u) { return !u.isWpAdmin && !u.disabled && u.id !== PS.config.user.id; });
		const roleSel = function (cur) { return Object.keys(roles).map(function (r) { return html`<option value="${r}" ${r === cur ? ui.raw('selected') : ''}>${roles[r]}</option>`; }); };
		const body = html`<p class="ps-small">${PS.t('تُنشأ الحسابات بكلمات سر عشوائية تظهر مرة واحدة فقط في الورقة: اطبعها أو حمّلها وسلّمها للمدير.')}</p>
			<h3>${PS.t('موظفون بدون حساب')} (${free.length})</h3>
			${free.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact ps-table--form" data-free><thead><tr><th></th><th>${PS.t('الموظف')}</th><th>${PS.t('اسم الدخول')}</th><th>${PS.t('الدور')}</th><th>${PS.t('البريد (اختياري)')}</th></tr></thead><tbody>${free.map(function (v, i) {
				return html`<tr data-emp="${v.id}"><td><input type="checkbox" data-pick checked aria-label="${v.label}"></td><td><strong>${v.label}</strong></td>
					<td><div class="ps-field" data-key="create.${i}.login"><input class="ps-input ps-input--sm" name="login" dir="ltr" value="${suggestLogin(v.label, taken)}"></div></td>
					<td><select class="ps-input ps-input--sm" name="role">${roleSel('ps_employee')}</select></td>
					<td><input class="ps-input ps-input--sm" name="email" type="email" dir="ltr"></td></tr>`;
			})}</tbody></table></div>` : html`<p class="ps-muted">${PS.t('كل الموظفين لديهم حسابات.')}</p>`}
			${existing.length ? html`<h3 class="ps-mt">${PS.t('حسابات موجودة: كلمة سر جديدة (لإضافتها إلى الورقة)')}</h3><div class="ps-row ps-row--wrap">${existing.map(function (u) {
				return html`<label class="ps-check"><input type="checkbox" data-reset="${u.id}"> ${u.name} <span class="ps-muted" dir="ltr">(${u.login})</span></label>`;
			})}</div>` : ''}`;
		ui.modal({
			title: '📄 ' + PS.t('ورقة حسابات الفريق'),
			body: body,
			wide: true,
			buttons: [{ id: 'ok', label: PS.t('إنشاء الحسابات وتحضير الورقة'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
			onButton: function (id, box, close) {
				if (id !== 'ok') return true;
				const create = [];
				const rows = Array.prototype.slice.call(box.querySelectorAll('tr[data-emp]')).filter(function (tr) { return tr.querySelector('[data-pick]').checked; });
				rows.forEach(function (tr) { create.push({ employeeId: Number(tr.dataset.emp), login: tr.querySelector('[name=login]').value.trim(), role: tr.querySelector('[name=role]').value, email: tr.querySelector('[name=email]').value.trim() }); });
				const reset = Array.prototype.map.call(box.querySelectorAll('[data-reset]:checked'), function (c) { return Number(c.dataset.reset); });
				// Les erreurs « create.N » suivent l'ordre des lignes COCHÉES : on renumérote les cases pour les afficher.
				rows.forEach(function (tr, n) { tr.querySelector('[name=login]').parentNode.dataset.key = 'create.' + n + '.login'; });
				PS.api.post('users/access-sheet', { create: create, reset: reset }).then(function (r) {
					close('ok');
					reload();
					showSheet(r);
				}).catch(function (e) {
					if (e.data && e.data.data && e.data.data.errors) ui.showErrors(box, e.data.data.errors);
					ui.toast(PS.settingsTabs.errMsg(e), 'error');
				});
				return false;
			},
		});
	}

	/** Feuille des accès : à imprimer / enregistrer en PDF, ou à télécharger (CSV pour Excel). */
	function sheetHtml(r) {
		const dir = PS.i18n.dir;
		const rows = r.accounts.map(function (a) {
			return html`<tr><td>${a.name}</td><td>${PS.t(a.role)}</td><td dir="ltr"><b>${a.login}</b></td><td dir="ltr" style="font-family:monospace;font-size:15px"><b>${a.password}</b></td></tr>`;
		});
		return html`<!doctype html><html lang="${PS.i18n.lang}" dir="${dir}"><head><meta charset="utf-8"><title>${PS.t('حسابات الفريق')}</title>
			<style>body{font-family:Tahoma,Arial,sans-serif;color:#1F2733;margin:24px}h1{color:#385B9A;font-size:20px;margin:0 0 4px}table{border-collapse:collapse;width:100%;margin:14px 0}th,td{border:1px solid #c9d1dc;padding:8px;text-align:start}th{background:#eef2f8}.box{border:1px solid #E8571F;border-radius:8px;padding:10px 14px;margin-top:12px}small{color:#5D6878}</style></head><body>
			<h1>${PS.t('متابعة المشاريع والعروض')} — ${PS.t('حسابات الفريق')}</h1>
			<small>${PS.format.date(PS.config.today)} · ${PS.t('سرّي: سلّم لكل موظف سطره فقط.')}</small>
			<p>${PS.t('رابط الموقع:')} <b dir="ltr">${r.site}</b></p>
			<table><thead><tr><th>${PS.t('الاسم')}</th><th>${PS.t('الدور')}</th><th>${PS.t('اسم الدخول')}</th><th>${PS.t('كلمة السر')}</th></tr></thead><tbody>${rows}</tbody></table>
			<div class="box"><b>${PS.t('أول دخول:')}</b><ol>
				<li>${PS.t('افتح رابط الموقع واكتب اسم الدخول وكلمة السر.')}</li>
				${r.telegram ? html`<li>${PS.t('اربط حسابك بـ Telegram: تظهر رسالة عند أول دخول ← «ربط الآن» ← في Telegram اضغط Start (أو من 🔔 ← ربط Telegram). البوت:')} <b dir="ltr">@${r.telegram}</b></li>` : html`<li>${PS.t('عند تفعيل Telegram من الإدارة: 🔔 ← ربط Telegram ← Start.')}</li>`}
				<li>${PS.t('في الهاتف: افتح الرابط في Chrome ← ⋮ ← «إضافة إلى الشاشة الرئيسية» ليصبح كتطبيق.')}</li>
			</ol></div></body></html>`.html;
	}

	function showSheet(r) {
		const csv = '﻿' + [[PS.t('الاسم'), PS.t('الدور'), PS.t('اسم الدخول'), PS.t('كلمة السر'), PS.t('رابط الموقع')]].concat(r.accounts.map(function (a) { return [a.name, PS.t(a.role), a.login, a.password, r.site]; }))
			.map(function (row) { return row.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
		ui.modal({
			title: '✔ ' + PS.t('الحسابات جاهزة') + ' (' + r.accounts.length + ')',
			body: html`<div class="ps-alert ps-alert--warning">${PS.t('كلمات السر تظهر الآن فقط: اطبع الورقة أو حمّلها قبل الإغلاق.')}</div>
				<div class="ps-table-wrap"><table class="ps-table ps-table--compact" data-sheet><thead><tr><th>${PS.t('الاسم')}</th><th>${PS.t('الدور')}</th><th>${PS.t('اسم الدخول')}</th><th>${PS.t('كلمة السر')}</th></tr></thead><tbody>${r.accounts.map(function (a) {
					return html`<tr><td>${a.name}</td><td>${PS.t(a.role)}</td><td dir="ltr">${a.login}</td><td dir="ltr"><code>${a.password}</code></td></tr>`;
				})}</tbody></table></div>`,
			wide: true,
			buttons: [{ id: 'print', label: '🖨 ' + PS.t('طباعة / PDF'), kind: 'primary' }, { id: 'csv', label: '⬇ ' + PS.t('تحميل (Excel / CSV)') }, { id: 'cancel', label: PS.t('إغلاق') }],
			onButton: function (id, box, close) {
				if (id === 'print') {
					const w = window.open('', '_blank');
					if (!w) { ui.toast(PS.t('اسمح بالنوافذ المنبثقة للطباعة'), 'error'); return false; }
					w.document.write(sheetHtml(r));
					w.document.close();
					w.focus();
					w.print();
					return false;
				}
				if (id === 'csv') {
					const a = document.createElement('a');
					a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
					a.download = 'comptes-equipe.csv';
					document.body.appendChild(a);
					a.click();
					a.remove();
					return false;
				}
				// Fermeture : confirmation (les mots de passe ne seront plus jamais affichés).
				ui.confirm(PS.t('هل طبعت أو حمّلت الورقة؟ لن تظهر كلمات السر مرة أخرى.'), PS.t('إغلاق')).then(function (ok) { if (ok) close('cancel'); });
				return false;
			},
		});
	}

	function usersTab(box) {
		const reload = function () { usersTab(box); };
		return Promise.all([PS.api.get('users'), L.load()]).then(function (res) {
			const d = res[0];
			box.innerHTML = html`<section class="ps-card">
				<div class="ps-page-head"><h2 class="ps-card__title">${PS.t('حسابات الفريق (')}${d.users.length})</h2><div class="ps-page-head__actions"><button type="button" class="ps-btn" data-sheet-new>📄 ${PS.t('ورقة حسابات الفريق')}</button><button type="button" class="ps-btn ps-btn--primary" data-new>+ ${PS.t('حساب جديد')}</button></div></div>
				<p class="ps-muted ps-small">${PS.t('لا يُحذف أي حساب: الحساب المعطّل لا يستطيع الدخول ويبقى تاريخه في المشاريع.')}</p>
				<div class="ps-table-wrap"><table class="ps-table ps-table--compact">
					<thead><tr><th>${PS.t('الاسم')}</th><th>${PS.t('اسم الدخول')}</th><th>${PS.t('البريد')}</th><th>${PS.t('الدور')}</th><th>${PS.t('الموظف المرتبط')}</th><th>${PS.t('البريد الصباحي')}</th><th>Telegram</th><th>${PS.t('آخر نشاط')}</th><th>${PS.t('الحالة')}</th><th></th></tr></thead>
					<tbody>${d.users.map(function (u) {
						return html`<tr class="${u.disabled ? 'ps-row-muted' : ''}" data-id="${u.id}">
							<td><strong>${u.name}</strong></td><td dir="ltr">${u.login}</td><td dir="ltr">${u.email}</td><td>${u.roleLabel}</td>
							<td>${u.employeeId ? L.label(u.employeeId) : html`<span class="ps-text-danger ps-small">${PS.t('غير مرتبط')}</span>`}</td>
							<td>${u.dailyEmail ? '✔' : '—'}</td><td class="ps-nowrap" data-tg="${u.telegram ? 1 : 0}">${u.telegram ? html`<span class="ps-text-success">✔ ${PS.t('مربوط بتيليغرام')}</span>` : html`<span class="ps-muted">${PS.t('غير مربوط')}</span>`}</td><td class="ps-nowrap">${u.lastLogin || '—'}</td>
							<td>${u.disabled ? ui.badge(PS.t('معطّل'), '#e03131') : ui.badge(PS.t('نشط'), '#2b8a3e')}</td>
							<td class="ps-nowrap"><button type="button" class="ps-btn ps-btn--small" data-edit>${PS.t('تعديل')}</button>
								${u.isWpAdmin || u.id === PS.config.user.id ? '' : html`<button type="button" class="ps-btn ps-btn--small ${u.disabled ? '' : 'ps-btn--danger'}" data-toggle>${u.disabled ? PS.t('تفعيل') : PS.t('تعطيل')}</button>`}</td>
						</tr>`;
					})}</tbody></table></div>
			</section>`.html;
			box.onclick = function (e) {
				if (e.target.closest('[data-new]')) { edit(null, d.roles, reload); return; }
				if (e.target.closest('[data-sheet-new]')) { accessSheet(d, d.roles, reload); return; }
				const tr = e.target.closest('tr[data-id]');
				if (!tr) return;
				const u = d.users.find(function (x) { return String(x.id) === tr.dataset.id; });
				if (e.target.closest('[data-edit]')) edit(u, d.roles, reload);
				if (e.target.closest('[data-toggle]')) {
					ui.confirm(u.disabled ? PS.t('إعادة تفعيل حساب ') + u.name + PS.t('؟') : PS.t('تعطيل حساب ') + u.name + PS.t('؟ سيتم إخراجه فورًا.'), u.disabled ? PS.t('تفعيل') : PS.t('تعطيل'), u.disabled ? 'primary' : 'danger').then(function (ok) {
						if (!ok) return;
						PS.api.put('users/' + u.id, { disabled: !u.disabled }).then(function () { ui.toast(PS.t('تم'), 'success'); reload(); })
							.catch(function (er) { ui.toast(PS.settingsTabs.errMsg(er), 'error'); });
					});
				}
			};
		});
	}

	PS.settingsTabs.users = usersTab;
})();
