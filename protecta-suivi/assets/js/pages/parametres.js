/**
 * Page « الإعدادات » (#/parametres?tab=…) — الإدارة seulement.
 *
 * Onglets :
 *   lists   القوائم المنسدلة : ajouter, renommer, couleur, type, masquer, réordonner, supprimer (si jamais utilisée)
 *   rules   قواعد التحقق : champs obligatoires + règles (§11)
 *   general عام : délais, sécurité, e-mail du matin, Cron (jeton régénérable), e-mail d'essai
 *   users   المستخدمون (fichier parametres-utilisateurs.js)
 *   data    البيانات : import du prototype, export, sauvegarde / restauration (fichier parametres-donnees.js)
 *
 * Chaque onglet est une fonction PS.settingsTabs.<nom>(conteneur).
 * Toutes les règles sont revérifiées par le serveur (inc/rest/settings.php).
 */
window.PS = window.PS || {};
PS.settingsTabs = PS.settingsTabs || {};

(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const TABS = [['lists', '📋 القوائم'], ['rules', '✔ قواعد التحقق'], ['general', '⚙ عام'], ['users', '👤 المستخدمون'], ['data', '💾 البيانات']];
	/** Listes dont la couleur est utilisée (pastilles, graphiques). */
	const COLORED = ['urgency', 'followup_status', 'offer_status', 'delivery_status'];
	/** Listes avec un code (affiché dans les tableaux). */
	const CODED = ['place', 'currency'];

	function errMsg(e) {
		const errs = e.data && e.data.data && e.data.data.errors;
		return errs ? Object.keys(errs).map(function (k) { return errs[k]; }).join(' — ') : e.message;
	}

	/* ------------------------------------------------------------------ */
	/* Listes                                                              */
	/* ------------------------------------------------------------------ */

	let currentList = 'urgency';

	function listsTab(box) {
		return L.load(true).then(function (data) {
			const keys = Object.keys(data.labels);
			const values = data.lists[currentList] || [];
			const types = data.followupTypes;
			const rows = values.map(function (v, i) {
				const sys = v.system;
				const importOnly = v.meta && v.meta.import_only;
				return html`<tr data-id="${v.id}" class="${v.hidden ? 'ps-row-muted' : ''}">
					<td class="ps-nowrap"><button type="button" class="ps-icon-btn ps-icon-btn--sm" data-move="-1" ${i === 0 ? ui.raw('disabled') : ''} aria-label="أعلى">▲</button><button type="button" class="ps-icon-btn ps-icon-btn--sm" data-move="1" ${i === values.length - 1 ? ui.raw('disabled') : ''} aria-label="أسفل">▼</button></td>
					<td><input class="ps-input ps-input--sm" data-f="label" value="${v.label}" aria-label="الاسم"></td>
					${CODED.indexOf(currentList) !== -1 || currentList === 'urgency' ? html`<td><input class="ps-input ps-input--sm ps-input--code" data-f="code" value="${v.code || ''}" ${sys ? ui.raw('disabled') : ''} aria-label="الرمز"></td>` : ''}
					${COLORED.indexOf(currentList) !== -1 ? html`<td><input type="color" data-f="color" value="${v.color || '#888888'}" aria-label="اللون"></td>` : ''}
					${currentList === 'followup_status' ? html`<td><select class="ps-input ps-input--sm" data-f="type" aria-label="النوع">${Object.keys(types).map(function (t) { return html`<option value="${t}" ${v.type === t ? ui.raw('selected') : ''}>${types[t]}</option>`; })}</select></td>` : ''}
					<td>${sys ? html`<span class="ps-badge" title="قيمة تستعملها الحسابات: يمكن تغيير اسمها فقط">🔒 أساسية</span>` : html`<label class="ps-check"><input type="checkbox" data-f="hidden" ${v.hidden ? ui.raw('checked') : ''}> مخفية</label>`}${importOnly ? html` <span class="ps-small ps-muted">(للاستيراد فقط)</span>` : ''}</td>
					<td>${sys ? '' : html`<button type="button" class="ps-icon-btn ps-icon-btn--sm" data-del aria-label="حذف">🗑</button>`}</td>
				</tr>`;
			});
			box.innerHTML = html`<div class="ps-settings-lists">
				<nav class="ps-card ps-sidelist" aria-label="القوائم">${keys.map(function (k) {
					return html`<button type="button" class="ps-sidelist__btn" data-list="${k}" aria-current="${k === currentList ? 'true' : 'false'}">${data.labels[k]} <span class="ps-muted">(${data.lists[k].length})</span></button>`;
				})}</nav>
				<section class="ps-card">
					<h2 class="ps-card__title">${data.labels[currentList]}</h2>
					<p class="ps-muted ps-small">تغيير الاسم يظهر في كل المشاريع؛ القيمة المخفية لا تُقترح عند الإدخال لكن المشاريع القديمة تحتفظ بها؛ الحذف ممكن فقط لقيمة لم تُستعمل أبدًا.</p>
					<div class="ps-table-wrap"><table class="ps-table ps-table--compact ps-table--edit">
						<thead><tr><th>الترتيب</th><th>الاسم</th>${CODED.indexOf(currentList) !== -1 || currentList === 'urgency' ? html`<th>الرمز</th>` : ''}${COLORED.indexOf(currentList) !== -1 ? html`<th>اللون</th>` : ''}${currentList === 'followup_status' ? html`<th>النوع (للحسابات)</th>` : ''}<th>الحالة</th><th></th></tr></thead>
						<tbody>${rows}</tbody></table></div>
					<form class="ps-row ps-add-value" data-add>
						<input class="ps-input ps-input--auto" name="label" placeholder="قيمة جديدة" required aria-label="قيمة جديدة">
						${CODED.indexOf(currentList) !== -1 ? html`<input class="ps-input ps-input--code" name="code" placeholder="الرمز" aria-label="الرمز">` : ''}
						${COLORED.indexOf(currentList) !== -1 ? html`<input type="color" name="color" value="#1c7ed6" aria-label="اللون">` : ''}
						${currentList === 'followup_status' ? html`<select class="ps-input ps-input--auto" name="type" aria-label="النوع">${Object.keys(types).map(function (t) { return html`<option value="${t}">${types[t]}</option>`; })}</select>` : ''}
						<button type="submit" class="ps-btn ps-btn--primary">+ إضافة</button>
					</form>
				</section></div>`.html;

			box.onclick = function (e) {
				const lb = e.target.closest('[data-list]');
				if (lb) { currentList = lb.dataset.list; listsTab(box); return; }
				const tr = e.target.closest('tr[data-id]');
				if (!tr) return;
				const id = Number(tr.dataset.id);
				const mv = e.target.closest('[data-move]');
				if (mv) {
					const ids = values.map(function (v) { return v.id; });
					const i = ids.indexOf(id);
					const j = i + Number(mv.dataset.move);
					ids.splice(j, 0, ids.splice(i, 1)[0]);
					PS.api.post('lists/' + currentList + '/reorder', { ids: ids }).then(function () { listsTab(box); }).catch(function (er) { ui.toast(errMsg(er), 'error'); });
					return;
				}
				if (e.target.closest('[data-del]')) {
					ui.confirm('حذف هذه القيمة نهائيًا؟', 'حذف', 'danger').then(function (ok) {
						if (!ok) return;
						PS.api.del('lists/item/' + id).then(function () { ui.toast('تم الحذف', 'success'); listsTab(box); })
							.catch(function (er) { ui.toast(errMsg(er), 'error'); });
					});
				}
			};
			box.onchange = function (e) {
				const f = e.target.dataset.f;
				const tr = e.target.closest('tr[data-id]');
				if (!f || !tr) return;
				const body = {};
				body[f] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
				PS.api.put('lists/item/' + tr.dataset.id, body).then(function () { ui.toast('تم الحفظ', 'success'); if (f === 'hidden') listsTab(box); })
					.catch(function (er) { ui.toast(errMsg(er), 'error'); listsTab(box); });
			};
			box.querySelector('[data-add]').onsubmit = function (e) {
				e.preventDefault();
				const fd = new FormData(e.target);
				const body = {};
				fd.forEach(function (v, k) { body[k] = v; });
				PS.api.post('lists/' + currentList, body).then(function () { ui.toast('تمت الإضافة', 'success'); listsTab(box); })
					.catch(function (er) { ui.toast(errMsg(er), 'error'); });
			};
		});
	}

	/* ------------------------------------------------------------------ */
	/* Règles de validation                                                */
	/* ------------------------------------------------------------------ */

	function rulesTab(box) {
		return PS.api.get('settings').then(function (d) {
			const v = d.settings.validation;
			const req = v.required || [];
			const groups = [['المشروع', function (k) { return k.indexOf('.') === -1; }], ['الأطراف', function (k) { return /^(owner|executor|direct|broker)\./.test(k); }], ['الأصناف', function (k) { return /^items?\./.test(k); }]];
			box.innerHTML = html`<form class="ps-card" data-rules>
				<h2 class="ps-card__title">الخانات الإجبارية</h2>
				<p class="ps-muted ps-small">الخانات المختارة تظهر بالأصفر وتمنع الحفظ إذا كانت فارغة.</p>
				${groups.map(function (g) {
					return html`<fieldset class="ps-party ps-mt"><legend>${g[0]}</legend><div class="ps-checks">${Object.keys(d.fields).filter(g[1]).map(function (k) {
						return html`<label class="ps-check"><input type="checkbox" name="req" value="${k}" ${req.indexOf(k) !== -1 ? ui.raw('checked') : ''}> ${d.fields[k]}</label>`;
					})}</div></fieldset>`;
				})}
				<h2 class="ps-card__title ps-mt">قواعد أخرى</h2>
				<div class="ps-checks">
					<label class="ps-check"><input type="checkbox" name="sent_after_received" ${v.sent_after_received ? ui.raw('checked') : ''}> تاريخ الإرسال ≥ تاريخ الاستلام</label>
					<label class="ps-check"><input type="checkbox" name="email_format" ${v.email_format ? ui.raw('checked') : ''}> الإيميل بصيغة صحيحة</label>
					<label class="ps-check"><input type="checkbox" name="phone_format" ${v.phone_format ? ui.raw('checked') : ''}> الهاتف أرقام فقط</label>
				</div>
				<p class="ps-muted ps-small ps-mt">قواعد ثابتة: رقم المشروع ورقم العرض لا يتكرران، القيم من القوائم فقط، تاريخ إرسال العرض إجباري عند إرسال صنف.</p>
				<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">💾 حفظ القواعد</button></div>
			</form>`.html;
			box.querySelector('[data-rules]').onsubmit = function (e) {
				e.preventDefault();
				const f = e.target;
				const body = { validation: { required: Array.from(f.querySelectorAll('[name=req]:checked')).map(function (c) { return c.value; }), sent_after_received: f.sent_after_received.checked, email_format: f.email_format.checked, phone_format: f.phone_format.checked } };
				PS.api.put('settings', body).then(function (r) {
					PS.config.settings.validation = r.settings.validation;
					ui.toast('تم حفظ القواعد', 'success');
				}).catch(function (er) { ui.toast(errMsg(er), 'error'); });
			};
		});
	}

	/* ------------------------------------------------------------------ */
	/* Réglages généraux, e-mail du matin, Cron                            */
	/* ------------------------------------------------------------------ */

	function generalTab(box) {
		return PS.api.get('settings').then(function (d) {
			const s = d.settings;
			box.innerHTML = html`<form class="ps-card" data-general novalidate>
				<h2 class="ps-card__title">الأمان والتنبيهات</h2>
				<div class="ps-grid">${Object.keys(d.numeric).map(function (k) {
					const n = d.numeric[k];
					return html`<label class="ps-field"><span class="ps-field__label">${n[2]}</span><input type="number" name="${k}" data-key="${k}" min="${n[0]}" max="${n[1]}" value="${s[k]}"></label>`;
				})}</div>
				<h2 class="ps-card__title ps-mt">البريد الصباحي</h2>
				<label class="ps-check"><input type="checkbox" name="digest_enabled" ${s.digest_enabled ? ui.raw('checked') : ''}> تفعيل إرسال ملخص المهام كل صباح (للحسابات التي فعّلت «البريد الصباحي»)</label>
				<p class="ps-muted ps-small">آخر إرسال: ${d.digestLastRun || '—'}</p>
				<h2 class="ps-card__title ps-mt">إعدادات إرسال البريد</h2>
				<p class="ps-small">إذا لم يصل البريد التجريبي: أنشئ حساب بريد في cPanel ← <strong>Email Accounts</strong> (مثلًا <span dir="ltr">app@موقعك.com</span>)، ثم فعّل SMTP بهذه المعطيات: الخادم <span dir="ltr">mail.موقعك.com</span>، المنفذ 465، التشفير SSL، اسم المستخدم = البريد كاملًا، وكلمة سره.</p>
				<div class="ps-grid">
					<label class="ps-field" data-key="mail_from_email"><span class="ps-field__label">بريد المرسل</span><input class="ps-input" type="email" dir="ltr" name="mail_from_email" value="${s.mail_from_email}" placeholder="${d.mailFrom}"></label>
					<label class="ps-field"><span class="ps-field__label">اسم المرسل</span><input class="ps-input" name="mail_from_name" value="${s.mail_from_name}"></label>
				</div>
				<label class="ps-check"><input type="checkbox" name="smtp_enabled" ${s.smtp_enabled ? ui.raw('checked') : ''}> الإرسال عبر SMTP (حساب بريد حقيقي — أنصح به على HostGator)</label>
				<div class="ps-grid">
					<label class="ps-field" data-key="smtp_host"><span class="ps-field__label">خادم SMTP</span><input class="ps-input" dir="ltr" name="smtp_host" value="${s.smtp_host}" placeholder="mail.example.com"></label>
					<label class="ps-field" data-key="smtp_port"><span class="ps-field__label">المنفذ</span><input class="ps-input" type="number" dir="ltr" name="smtp_port" value="${s.smtp_port}"></label>
					<label class="ps-field" data-key="smtp_secure"><span class="ps-field__label">التشفير</span><select class="ps-input" name="smtp_secure">${[['ssl', 'SSL (465)'], ['tls', 'TLS (587)'], ['none', 'بدون']].map(function (o) {
						return html`<option value="${o[0]}" ${s.smtp_secure === o[0] ? ui.raw('selected') : ''}>${o[1]}</option>`;
					})}</select></label>
					<label class="ps-field"><span class="ps-field__label">اسم المستخدم (البريد كاملًا)</span><input class="ps-input" dir="ltr" name="smtp_user" value="${s.smtp_user}" autocomplete="off"></label>
					<label class="ps-field"><span class="ps-field__label">كلمة السر</span><input class="ps-input" type="password" dir="ltr" name="smtp_pass" autocomplete="new-password" placeholder="${d.smtpPassSet ? 'محفوظة — اتركها فارغة للإبقاء عليها' : ''}"></label>
				</div>
				<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">💾 حفظ</button></div>
				<h2 class="ps-card__title ps-mt">إشعارات Telegram</h2>
				<ol class="ps-small ps-steps">
					<li>في Telegram افتح <a href="https://t.me/BotFather" target="_blank" rel="noopener" dir="ltr">@BotFather</a> ← <span dir="ltr">/newbot</span> ← اختر اسمًا (مثلًا «Protecta Suivi») ← انسخ <strong>الرمز (token)</strong>.</li>
					<li>الصقه هنا، فعّل الإشعارات ثم <strong>حفظ</strong>.</li>
					<li>كل موظف يربط حسابه من 🔔 ← «ربط Telegram» (ضغطة واحدة).</li>
				</ol>
				${d.telegramBot ? html`<p class="ps-alert ps-alert--success">✔ متصل بالبوت <a href="https://t.me/${d.telegramBot}" target="_blank" rel="noopener" dir="ltr">@${d.telegramBot}</a></p>` : ''}
				<div class="ps-grid">
					<label class="ps-field" data-key="telegram_token"><span class="ps-field__label">رمز البوت (token)</span><input class="ps-input" type="password" dir="ltr" name="telegram_token" autocomplete="off" placeholder="${d.telegramTokenSet ? 'محفوظ — اتركه فارغًا للإبقاء عليه' : '123456789:AA…'}"></label>
				</div>
				<label class="ps-check ps-mt"><input type="checkbox" name="telegram_enabled" ${s.telegram_enabled ? ui.raw('checked') : ''}> تفعيل إشعارات Telegram (الإشعارات 🔔، المهام والرسائل الصوتية، الملخص الصباحي)</label>
				<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">💾 حفظ</button><button type="button" class="ps-btn" data-tg-test>✈ رسالة تجريبية لي على Telegram</button></div>
				<h2 class="ps-card__title ps-mt">تجربة الإرسال</h2>
				<div class="ps-row">
					<input class="ps-input" type="email" dir="ltr" data-test-to value="${d.myEmail || ''}" placeholder="البريد الذي يصله الاختبار" style="max-width:320px">
					<button type="button" class="ps-btn" data-test-mail>✉ إرسال بريد تجريبي</button>
				</div>
				<p class="ps-muted ps-small">احفظ أولًا ثم جرّب. إذا وصل البريد إلى «Spam»، استعمل SMTP.</p>
				<div data-test-result></div>
			</form>
			<section class="ps-card">
				<h2 class="ps-card__title">المهمة اليومية (Cron Job في cPanel)</h2>
				<p>منبّه في الاستضافة يفتح هذا الرابط السري مرة في اليوم، فيُرسل البريد الصباحي حتى لو لم يدخل أحد. في cPanel ← <strong>Cron Jobs</strong> ← Once Per Day ← اختر الساعة ← الصق الأمر في «Command» (التفاصيل في README):</p>
				<pre class="ps-code" dir="ltr">wget -q -O /dev/null "${d.cronUrl}"</pre>
				<p class="ps-small"><a href="${d.cronUrl}" target="_blank" rel="noopener">▶ تجربة الرابط الآن</a> — يجب أن تظهر كلمة «OK».</p>
				<p class="ps-muted ps-small">أو (بديل): <code dir="ltr">${d.cronCli}</code></p>
				<p class="ps-small">الرمز السري (64 حرفًا) جزء من الرابط. إذا تسرّب، جدّده ثم حدّث أمر الـ Cron.</p>
				<button type="button" class="ps-btn ps-btn--danger" data-regen>🔄 تجديد الرمز السري</button>
			</section>`.html;
			const form = box.querySelector('[data-general]');
			form.onsubmit = function (e) {
				e.preventDefault();
				const body = { digest_enabled: form.digest_enabled.checked, smtp_enabled: form.smtp_enabled.checked, smtp_secure: form.smtp_secure.value, smtp_pass: form.smtp_pass.value, telegram_enabled: form.telegram_enabled.checked, telegram_token: form.telegram_token.value };
				['mail_from_email', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_user'].forEach(function (k) { body[k] = form[k].value; });
				Object.keys(d.numeric).forEach(function (k) { body[k] = Number(form[k].value); });
				PS.api.put('settings', body).then(function (r) {
					PS.config.settings.inactivityMinutes = r.settings.inactivity_minutes;
					PS.config.settings.creatorEditHours = r.settings.creator_edit_hours;
					ui.showErrors(form, {});
					ui.toast('تم الحفظ', 'success');
					if (body.telegram_token) generalTab(box); // affiche le nom du bot vérifié
				}).catch(function (er) {
					if (er.data && er.data.data && er.data.data.errors) ui.showErrors(form, er.data.data.errors);
					ui.toast(errMsg(er), 'error');
				});
			};
			box.querySelector('[data-test-mail]').onclick = function (e) {
				const out = box.querySelector('[data-test-result]');
				const btn = e.currentTarget;
				btn.disabled = true;
				out.innerHTML = html`<p class="ps-muted">جاري الإرسال…</p>`.html;
				PS.api.post('settings/test-email', { to: box.querySelector('[data-test-to]').value }).then(function (r) {
					out.innerHTML = html`<div class="ps-alert ps-alert--success">✔ قَبِل الخادم الرسالة وأُرسلت إلى <span dir="ltr">${r.to}</span>${r.smtp ? ' (عبر SMTP)' : ''}. إذا لم تجدها خلال دقائق، انظر في «Spam».</div>`.html;
				}).catch(function (er) {
					out.innerHTML = html`<div class="ps-alert ps-alert--error">${errMsg(er)}</div>`.html;
				}).then(function () { btn.disabled = false; });
			};
			box.querySelector('[data-tg-test]').onclick = function () {
				PS.api.post('settings/telegram-test').then(function () { ui.toast('✔ أُرسلت الرسالة إلى Telegram', 'success'); })
					.catch(function (er) { ui.toast(errMsg(er), 'error'); });
			};
			box.querySelector('[data-regen]').onclick = function () {
				ui.confirm('تجديد الرمز؟ الرابط القديم يتوقف عن العمل، ويجب تحديث أمر الـ Cron في cPanel.', 'تجديد', 'danger').then(function (ok) {
					if (!ok) return;
					PS.api.post('settings/cron-token').then(function () { ui.toast('تم تجديد الرمز', 'success'); generalTab(box); })
						.catch(function (er) { ui.toast(errMsg(er), 'error'); });
				});
			};
		});
	}

	PS.settingsTabs.lists = listsTab;
	PS.settingsTabs.rules = rulesTab;
	PS.settingsTabs.general = generalTab;
	PS.settingsTabs.errMsg = errMsg;

	PS.router.register({
		id: 'parametres',
		title: 'الإعدادات',
		icon: '⚙️',
		cap: 'ps_manage_settings',
		order: 90,
		group: 'admin',
		render: function (main, params) {
			const tab = TABS.some(function (t) { return t[0] === params.tab; }) ? params.tab : 'lists';
			main.innerHTML = html`<div class="ps-page-head"><h1>الإعدادات</h1></div>
				<div class="ps-tabs" role="tablist">${TABS.map(function (t) {
					return html`<button type="button" role="tab" class="ps-tab" data-stab="${t[0]}" aria-selected="${t[0] === tab ? 'true' : 'false'}">${t[1]}</button>`;
				})}</div><div data-stab-body><div class="ps-loading">جاري التحميل…</div></div>`.html;
			main.querySelector('.ps-tabs').onclick = function (e) {
				const b = e.target.closest('[data-stab]');
				if (b) PS.router.go('parametres', { tab: b.dataset.stab });
			};
			return PS.settingsTabs[tab](main.querySelector('[data-stab-body]'));
		},
	});
})();
