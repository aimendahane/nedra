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
	const F = PS.format;
	const TABS = [['lists', PS.t('📋 القوائم')], ['rules', PS.t('✔ قواعد التحقق')], ['general', PS.t('⚙ عام')], ['users', PS.t('👤 المستخدمون')], ['data', PS.t('💾 البيانات')]];
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
					<td class="ps-nowrap"><button type="button" class="ps-icon-btn ps-icon-btn--sm" data-move="-1" ${i === 0 ? ui.raw('disabled') : ''} aria-label="${PS.t('أعلى')}">▲</button><button type="button" class="ps-icon-btn ps-icon-btn--sm" data-move="1" ${i === values.length - 1 ? ui.raw('disabled') : ''} aria-label="${PS.t('أسفل')}">▼</button></td>
					<td><input class="ps-input ps-input--sm" data-f="label" value="${v.labelRaw}" aria-label="${PS.t('الاسم')}">${v.label !== v.labelRaw ? html`<span class="ps-muted ps-small"> → ${v.label}</span>` : ''}</td>
					${CODED.indexOf(currentList) !== -1 || currentList === 'urgency' ? html`<td><input class="ps-input ps-input--sm ps-input--code" data-f="code" value="${v.code || ''}" ${sys ? ui.raw('disabled') : ''} aria-label="${PS.t('الرمز')}"></td>` : ''}
					${COLORED.indexOf(currentList) !== -1 ? html`<td><input type="color" data-f="color" value="${v.color || '#888888'}" aria-label="${PS.t('اللون')}"></td>` : ''}
					${currentList === 'followup_status' ? html`<td><select class="ps-input ps-input--sm" data-f="type" aria-label="${PS.t('النوع')}">${Object.keys(types).map(function (t) { return html`<option value="${t}" ${v.type === t ? ui.raw('selected') : ''}>${types[t]}</option>`; })}</select></td>` : ''}
					<td>${sys ? html`<span class="ps-badge" title="${PS.t('قيمة تستعملها الحسابات: يمكن تغيير اسمها فقط')}">🔒 ${PS.t('أساسية')}</span>` : html`<label class="ps-check"><input type="checkbox" data-f="hidden" ${v.hidden ? ui.raw('checked') : ''}> ${PS.t('مخفية')}</label>`}${importOnly ? html` <span class="ps-small ps-muted">(${PS.t('للاستيراد فقط')})</span>` : ''}</td>
					<td>${sys ? '' : html`<button type="button" class="ps-icon-btn ps-icon-btn--sm" data-del aria-label="${PS.t('حذف')}">🗑</button>`}</td>
				</tr>`;
			});
			box.innerHTML = html`<div class="ps-settings-lists">
				<nav class="ps-card ps-sidelist" aria-label="${PS.t('القوائم')}">${keys.map(function (k) {
					return html`<button type="button" class="ps-sidelist__btn" data-list="${k}" aria-current="${k === currentList ? 'true' : 'false'}">${data.labels[k]} <span class="ps-muted">(${data.lists[k].length})</span></button>`;
				})}</nav>
				<section class="ps-card">
					<h2 class="ps-card__title">${data.labels[currentList]}</h2>
					<p class="ps-muted ps-small">${PS.t('تغيير الاسم يظهر في كل المشاريع؛ القيمة المخفية لا تُقترح عند الإدخال لكن المشاريع القديمة تحتفظ بها؛ الحذف ممكن فقط لقيمة لم تُستعمل أبدًا.')}</p>
					<div class="ps-table-wrap"><table class="ps-table ps-table--compact ps-table--edit">
						<thead><tr><th>${PS.t('الترتيب')}</th><th>${PS.t('الاسم')}</th>${CODED.indexOf(currentList) !== -1 || currentList === 'urgency' ? html`<th>${PS.t('الرمز')}</th>` : ''}${COLORED.indexOf(currentList) !== -1 ? html`<th>${PS.t('اللون')}</th>` : ''}${currentList === 'followup_status' ? html`<th>${PS.t('النوع (للحسابات)')}</th>` : ''}<th>${PS.t('الحالة')}</th><th></th></tr></thead>
						<tbody>${rows}</tbody></table></div>
					<form class="ps-row ps-add-value" data-add>
						<input class="ps-input ps-input--auto" name="label" placeholder="${PS.t('قيمة جديدة')}" required aria-label="${PS.t('قيمة جديدة')}">
						${CODED.indexOf(currentList) !== -1 ? html`<input class="ps-input ps-input--code" name="code" placeholder="${PS.t('الرمز')}" aria-label="${PS.t('الرمز')}">` : ''}
						${COLORED.indexOf(currentList) !== -1 ? html`<input type="color" name="color" value="#1c7ed6" aria-label="${PS.t('اللون')}">` : ''}
						${currentList === 'followup_status' ? html`<select class="ps-input ps-input--auto" name="type" aria-label="${PS.t('النوع')}">${Object.keys(types).map(function (t) { return html`<option value="${t}">${types[t]}</option>`; })}</select>` : ''}
						<button type="submit" class="ps-btn ps-btn--primary">+ ${PS.t('إضافة')}</button>
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
					ui.confirm(PS.t('حذف هذه القيمة نهائيًا؟'), PS.t('حذف'), 'danger').then(function (ok) {
						if (!ok) return;
						PS.api.del('lists/item/' + id).then(function () { ui.toast(PS.t('تم الحذف'), 'success'); listsTab(box); })
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
				PS.api.put('lists/item/' + tr.dataset.id, body).then(function () { ui.toast(PS.t('تم الحفظ'), 'success'); if (f === 'hidden') listsTab(box); })
					.catch(function (er) { ui.toast(errMsg(er), 'error'); listsTab(box); });
			};
			box.querySelector('[data-add]').onsubmit = function (e) {
				e.preventDefault();
				const fd = new FormData(e.target);
				const body = {};
				fd.forEach(function (v, k) { body[k] = v; });
				PS.api.post('lists/' + currentList, body).then(function () { ui.toast(PS.t('تمت الإضافة'), 'success'); listsTab(box); })
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
			const groups = [[PS.t('المشروع'), function (k) { return k.indexOf('.') === -1; }], [PS.t('الأطراف'), function (k) { return /^(owner|executor|direct|broker)\./.test(k); }], [PS.t('الأصناف'), function (k) { return /^items?\./.test(k); }]];
			box.innerHTML = html`<form class="ps-card" data-rules>
				<h2 class="ps-card__title">${PS.t('الخانات الإجبارية')}</h2>
				<p class="ps-muted ps-small">${PS.t('الخانات المختارة تظهر بالأصفر وتمنع الحفظ إذا كانت فارغة.')}</p>
				${groups.map(function (g) {
					return html`<fieldset class="ps-party ps-mt"><legend>${g[0]}</legend><div class="ps-checks">${Object.keys(d.fields).filter(g[1]).map(function (k) {
						return html`<label class="ps-check"><input type="checkbox" name="req" value="${k}" ${req.indexOf(k) !== -1 ? ui.raw('checked') : ''}> ${d.fields[k]}</label>`;
					})}</div></fieldset>`;
				})}
				<h2 class="ps-card__title ps-mt">${PS.t('قواعد أخرى')}</h2>
				<div class="ps-checks">
					<label class="ps-check"><input type="checkbox" name="sent_after_received" ${v.sent_after_received ? ui.raw('checked') : ''}> ${PS.t('تاريخ الإرسال ≥ تاريخ الاستلام')}</label>
					<label class="ps-check"><input type="checkbox" name="email_format" ${v.email_format ? ui.raw('checked') : ''}> ${PS.t('الإيميل بصيغة صحيحة')}</label>
					<label class="ps-check"><input type="checkbox" name="phone_format" ${v.phone_format ? ui.raw('checked') : ''}> ${PS.t('الهاتف أرقام فقط')}</label>
				</div>
				<p class="ps-muted ps-small ps-mt">${PS.t('قواعد ثابتة: رقم المشروع ورقم العرض لا يتكرران، القيم من القوائم فقط، تاريخ إرسال العرض إجباري عند إرسال صنف.')}</p>
				<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">💾 ${PS.t('حفظ القواعد')}</button></div>
			</form>`.html;
			box.querySelector('[data-rules]').onsubmit = function (e) {
				e.preventDefault();
				const f = e.target;
				const body = { validation: { required: Array.from(f.querySelectorAll('[name=req]:checked')).map(function (c) { return c.value; }), sent_after_received: f.sent_after_received.checked, email_format: f.email_format.checked, phone_format: f.phone_format.checked } };
				PS.api.put('settings', body).then(function (r) {
					PS.config.settings.validation = r.settings.validation;
					ui.toast(PS.t('تم حفظ القواعد'), 'success');
				}).catch(function (er) { ui.toast(errMsg(er), 'error'); });
			};
		});
	}

	/* ------------------------------------------------------------------ */
	/* Réglages généraux, e-mail du matin, Cron                            */
	/* ------------------------------------------------------------------ */

	/** Son de test : 1 s à 440 Hz, fichier WAV fabriqué dans le navigateur (lu comme un message vocal). */
	function toneWav() {
		const rate = 22050, n = rate, buf = new DataView(new ArrayBuffer(44 + n * 2));
		const w = function (o, s) { for (let i = 0; i < s.length; i++) buf.setUint8(o + i, s.charCodeAt(i)); };
		w(0, 'RIFF'); buf.setUint32(4, 36 + n * 2, true); w(8, 'WAVEfmt '); buf.setUint32(16, 16, true); buf.setUint16(20, 1, true);
		buf.setUint16(22, 1, true); buf.setUint32(24, rate, true); buf.setUint32(28, rate * 2, true); buf.setUint16(32, 2, true);
		buf.setUint16(34, 16, true); w(36, 'data'); buf.setUint32(40, n * 2, true);
		for (let i = 0; i < n; i++) buf.setInt16(44 + i * 2, Math.sin(2 * Math.PI * 440 * i / rate) * 12000, true);
		return new Blob([buf], { type: 'audio/wav' });
	}

	/**
	 * « 🎙 اختبار الصوت » (1.3.3) : haut-parleur du téléphone, micro (niveau capté), analyse par le serveur
	 * (format, durée, muet ou non, conversion pour Telegram) et envoi d'essai sur Telegram. Rien n'est enregistré.
	 */
	function soundTest(sec) {
		const st = { heard: null, rec: null };
		const vbox = sec.querySelector('[data-voice]');
		if (vbox) st.rec = PS.voice.recorder(vbox);
		sec.querySelector('[data-tone]').onclick = function () {
			const a = new Audio(URL.createObjectURL(toneWav()));
			a.play().then(function () { sec.querySelector('[data-tone-ask]').hidden = false; })
				.catch(function (er) { st.heard = false; ui.toast(PS.t('تعذّر تشغيل الصوت:') + ' ' + er.message, 'error'); });
		};
		sec.querySelectorAll('[data-heard]').forEach(function (b) {
			b.onclick = function () { st.heard = b.dataset.heard === '1'; sec.querySelector('[data-tone-ask]').innerHTML = st.heard ? '✔' : '✘'; };
		});
		sec.querySelector('[data-sound-run]').onclick = function (e) {
			const btn = e.currentTarget;
			const v = st.rec ? st.rec.result() : null;
			if (!v) { ui.toast(PS.t('سجّل أولًا (الخطوة 2).'), 'error'); return; }
			const fd = new FormData();
			fd.append('voice', v.blob, 'voice.' + v.ext);
			if (sec.querySelector('[data-sound-tg]').checked) fd.append('telegram', '1');
			btn.disabled = true;
			const out = sec.querySelector('[data-sound-report]');
			out.innerHTML = html`<p class="ps-muted">${PS.t('جاري المعالجة…')}</p>`.html;
			PS.api.post('voice-test', fd).then(function (r) {
				const ok = function (b) { return b === true ? '✔' : (b === false ? '✘' : '—'); };
				const peak = v.peak || 0;
				const rows = [
					[PS.t('مكبّر الصوت (النغمة)'), st.heard === null ? '—' : ok(st.heard), st.heard === false ? PS.t('ارفع صوت الوسائط في الهاتف وألغِ الوضع الصامت.') : ''],
					[PS.t('الميكروفون (مستوى الصوت)'), ok(peak >= 0.05), Math.round(peak * 100) + '%' + (peak < 0.05 ? ' — ' + PS.t('الميكروفون لا يلتقط: اسمح به للمتصفح وأغلق التطبيقات التي تستعمله.') : '')],
					[PS.t('الملف المسجَّل'), ok(r.size > 1000), v.blob.type + ' · ' + Math.round(r.size / 1024) + ' KB · ' + v.seconds + ' s'],
					[PS.t('الخادم: الصيغة'), ok(!!r.format), r.format || PS.t('غير معروفة')],
					[PS.t('الخادم: يحتوي على صوت'), r.silent === null ? '—' : ok(!r.silent), r.seconds !== null ? r.seconds + ' s' : ''],
					[PS.t('تحويل إلى صيغة Telegram'), r.format === 'webm' ? ok(r.oggOk) : '—', ''],
					[PS.t('Telegram'), r.telegram === null ? '—' : ok(String(r.telegram).indexOf('ok:') === 0), r.telegram && String(r.telegram).indexOf('ok:') !== 0 ? r.telegram : (r.telegram ? r.telegram.slice(3) : '')],
				];
				out.innerHTML = html`<table class="ps-table ps-sound-report"><tbody>${rows.map(function (x) {
					return html`<tr><th>${x[0]}</th><td class="ps-sound-report__ok">${x[1]}</td><td dir="auto">${x[2]}</td></tr>`;
				})}</tbody></table><p class="ps-small ps-muted" dir="ltr">${navigator.userAgent}</p>`.html;
			}).catch(function (er) {
				out.innerHTML = html`<div class="ps-alert ps-alert--error">${errMsg(er)}</div>`.html;
			}).then(function () { btn.disabled = false; });
		};
	}

	/**
	 * « 📁 تخزين الملفات — Google Drive » (1.5.0) : les fichiers joints sont rangés dans le Drive de la société.
	 * Deux méthodes : compte Gmail (client OAuth + bouton « ربط ») ou Workspace (compte de service + Drive partagé).
	 */
	function gdriveCard(sec) {
		// Retour de Google après « ربط » : #/parametres?tab=general&gdrive=ok|error:…
		const back = (location.hash.match(/[?&]gdrive=([^&]+)/) || [])[1];
		if (back) {
			const msg = decodeURIComponent(back);
			ui.toast(msg === 'ok' ? PS.t('✔ تم ربط Google Drive') : msg.replace(/^error:/, ''), msg === 'ok' ? 'success' : 'error');
			history.replaceState(null, '', location.pathname + location.search + '#/parametres?tab=general');
		}
		PS.api.get('gdrive').then(function (s) { draw(s); }).catch(function (e) { sec.innerHTML = html`<div class="ps-alert ps-alert--error">${errMsg(e)}</div>`.html; });
		function draw(s) {
			const method = s.method || 'oauth';
			sec.innerHTML = html`<h2 class="ps-card__title">📁 ${PS.t('تخزين الملفات — Google Drive')}</h2>
				<p class="ps-small">${PS.t('الفريق يضيف ويفتح الملفات من الموقع، والملفات نفسها تُحفظ في Google Drive الخاص بالشركة (مجلد لكل مشروع) لتوفير مساحة الخادم. لا أحد يرى رابط Drive: الفتح يمر عبر الموقع بعد التحقق من الصلاحيات.')}</p>
				${s.connected
					? html`<div class="ps-alert ps-alert--success">✔ ${PS.t('مربوط بـ Google Drive')}${s.account ? html` — <span dir="ltr">${s.account}</span>` : ''} · ☁ ${s.inDrive} ${PS.t('ملف في Drive')} · 🖥 ${s.pending} ${PS.t('ملف على الخادم في الانتظار')} (${F.size(s.localBytes)})</div>`
					: html`<div class="ps-alert ps-alert--warning">${PS.t('غير مربوط: الملفات تبقى على الخادم إلى أن يتم الربط، ثم تُنقل تلقائيًا إلى Drive.')} (🖥 ${s.pending} ${PS.t('ملف')}، ${F.size(s.localBytes)})</div>`}
				${s.lastError ? html`<p class="ps-small ps-text-danger">${PS.t('آخر خطأ:')} ${s.lastError}</p>` : ''}
				<div class="ps-row">
					<label class="ps-check"><input type="radio" name="gd-method" value="oauth" ${method === 'oauth' ? ui.raw('checked') : ''}> ${PS.t('حساب Gmail عادي')}</label>
					<label class="ps-check"><input type="radio" name="gd-method" value="service" ${method === 'service' ? ui.raw('checked') : ''}> ${PS.t('Google Workspace (Drive مشترك للشركة)')}</label>
				</div>
				<form data-gd-form class="ps-mt ps-gd-form" novalidate>
					<div data-gd="oauth" ${method === 'oauth' ? '' : ui.raw('hidden')}>
						<ol class="ps-small ps-gd-steps">
							<li>${PS.t('افتح')} <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener" dir="ltr">console.cloud.google.com</a> ${PS.t('بحساب Gmail الشركة ← أنشئ مشروعًا ← فعّل «Google Drive API».')}</li>
							<li>${PS.t('APIs & Services ← OAuth consent screen ← External ← أضف بريدك في «Test users» (أو اضغط Publish).')}</li>
							<li>${PS.t('Credentials ← Create credentials ← OAuth client ID ← النوع «Web application» ← في «Authorized redirect URIs» الصق هذا الرابط:')}<br><code dir="ltr" class="ps-code-inline">${s.redirectUri}</code> <button type="button" class="ps-btn ps-btn--small" data-copy="${s.redirectUri}">📋 ${PS.t('نسخ')}</button></li>
							<li>${PS.t('انسخ Client ID و Client Secret هنا ← «حفظ وربط Google Drive» ← وافق في صفحة Google.')}</li>
						</ol>
						<div class="ps-grid">
							<label class="ps-field" data-key="clientId"><span class="ps-field__label">Client ID</span><input name="clientId" dir="ltr" value="${s.clientId || ''}" autocomplete="off"></label>
							<label class="ps-field" data-key="clientSecret"><span class="ps-field__label">Client Secret</span><input name="clientSecret" type="password" dir="ltr" autocomplete="new-password" placeholder="${s.hasSecret ? PS.t('محفوظ — اتركه فارغًا للإبقاء عليه') : ''}"></label>
						</div>
					</div>
					<div data-gd="service" ${method === 'service' ? '' : ui.raw('hidden')}>
						<ol class="ps-small ps-gd-steps">
							<li>${PS.t('Google Cloud ← فعّل «Google Drive API» ← IAM & Admin ← Service accounts ← أنشئ حسابًا ← Keys ← Add key ← JSON (يُحمَّل ملف).')}</li>
							<li>${PS.t('في Google Drive: أنشئ «Drive مشترك» (Shared drive) ← أضف بريد حساب الخدمة (client_email) كـ «مدير محتوى».')}</li>
							<li>${PS.t('الصق محتوى ملف JSON ورابط المجلد هنا ← «حفظ وربط Google Drive».')}</li>
						</ol>
						<div class="ps-grid">
							<label class="ps-field ps-span-2" data-key="saJson"><span class="ps-field__label">${PS.t('مفتاح حساب الخدمة (محتوى ملف JSON)')}</span><textarea name="saJson" dir="ltr" rows="4" placeholder="${s.hasKey ? PS.t('محفوظ — اتركه فارغًا للإبقاء عليه') : '{ "type": "service_account", … }'}"></textarea></label>
						</div>
					</div>
					<label class="ps-field ps-mt" data-key="folderId"><span class="ps-field__label">${PS.t('مجلد Drive (رابط أو معرّف) — اختياري مع Gmail: يُنشأ مجلد «Protecta Suivi» تلقائيًا')}</span><input name="folderId" dir="ltr" value="${s.folderId || ''}"></label>
				</form>
				<div class="ps-row">
					<button type="button" class="ps-btn ps-btn--primary" data-gd-save>☁ ${PS.t('حفظ وربط Google Drive')}</button>
					${s.connected ? html`<button type="button" class="ps-btn" data-gd-sync>🔄 ${PS.t('مزامنة الآن')} (${s.pending})</button><button type="button" class="ps-btn ps-btn--danger" data-gd-off>${PS.t('فك الربط')}</button>` : ''}
				</div>`.html;
			const form = sec.querySelector('[data-gd-form]');
			sec.onchange = function (e) {
				if (e.target.name !== 'gd-method') return;
				sec.querySelectorAll('[data-gd]').forEach(function (d) { d.hidden = d.dataset.gd !== e.target.value; });
			};
			sec.onclick = function (e) {
				const cp = e.target.closest('[data-copy]');
				if (cp) { navigator.clipboard.writeText(cp.dataset.copy).then(function () { ui.toast(PS.t('تم النسخ'), 'success'); }).catch(function () {}); return; }
				if (e.target.closest('[data-gd-save]')) {
					const m = sec.querySelector('[name=gd-method]:checked').value;
					const body = { method: m, folderId: form.folderId.value, clientId: form.clientId.value, clientSecret: form.clientSecret.value, saJson: form.saJson.value };
					PS.api.post('gdrive/config', body).then(function (r) {
						ui.showErrors(form, {});
						if (r.authUrl) { window.location.href = r.authUrl; return; } // accord chez Google, puis retour ici
						ui.toast(PS.t('✔ تم ربط Google Drive'), 'success');
						draw(r.status);
					}).catch(function (er) {
						if (er.data && er.data.data && er.data.data.errors) ui.showErrors(form, er.data.data.errors);
						ui.toast(errMsg(er), 'error');
					});
				}
				if (e.target.closest('[data-gd-sync]')) {
					PS.api.post('gdrive/sync').then(function (r) {
						ui.toast(PS.t('أُرسل إلى Drive: ') + r.sent + (r.failed ? ' · ' + PS.t('فشل: ') + r.failed : '') + ' · ' + PS.t('في الانتظار: ') + r.pending, r.failed ? 'error' : 'success');
						draw(r.status);
					}).catch(function (er) { ui.toast(errMsg(er), 'error'); });
				}
				if (e.target.closest('[data-gd-off]')) {
					ui.confirm(PS.t('فك ربط Google Drive؟ الملفات الموجودة في Drive تبقى هناك، لكن لن تُفتح من الموقع حتى إعادة الربط.'), PS.t('فك الربط'), 'danger').then(function (ok) {
						if (!ok) return;
						PS.api.post('gdrive/disconnect').then(draw).catch(function (er) { ui.toast(errMsg(er), 'error'); });
					});
				}
			};
		}
	}

	function generalTab(box) {
		return PS.api.get('settings').then(function (d) {
			const s = d.settings;
			box.innerHTML = html`<form class="ps-card" data-general novalidate>
				<h2 class="ps-card__title">${PS.t('الأمان والتنبيهات')}</h2>
				<div class="ps-grid">${Object.keys(d.numeric).map(function (k) {
					const n = d.numeric[k];
					return html`<label class="ps-field"><span class="ps-field__label">${n[2]}</span><input type="number" name="${k}" data-key="${k}" min="${n[0]}" max="${n[1]}" value="${s[k]}"></label>`;
				})}
					<label class="ps-field" data-key="default_lang"><span class="ps-field__label">${PS.t('لغة الواجهة الافتراضية')}</span><select class="ps-input" name="default_lang">${Object.keys(PS.i18n.languages).map(function (c) {
						return html`<option value="${c}" ${s.default_lang === c ? ui.raw('selected') : ''}>${PS.i18n.languages[c]}</option>`;
					})}</select><span class="ps-muted ps-small">${PS.t('للحسابات التي لم تختر لغة، والبريد وTelegram')}</span></label>
				</div>
				<h2 class="ps-card__title ps-mt">${PS.t('البريد الصباحي')}</h2>
				<label class="ps-check"><input type="checkbox" name="digest_enabled" ${s.digest_enabled ? ui.raw('checked') : ''}> ${PS.t('تفعيل إرسال ملخص المهام كل صباح (للحسابات التي فعّلت «البريد الصباحي»)')}</label>
				<p class="ps-muted ps-small">${PS.t('آخر إرسال:')} ${d.digestLastRun || '—'}</p>
				<h2 class="ps-card__title ps-mt">${PS.t('إعدادات إرسال البريد')}</h2>
				<p class="ps-small">${PS.t('إذا لم يصل البريد التجريبي: أنشئ حساب بريد في cPanel ←')} <strong>Email Accounts</strong> (${PS.t('مثلًا')} <span dir="ltr">app@${PS.t('موقعك.com')}</span>)${PS.t('، ثم فعّل SMTP بهذه المعطيات: الخادم')} <span dir="ltr">mail.${PS.t('موقعك.com')}</span>${PS.t('، المنفذ 465، التشفير SSL، اسم المستخدم = البريد كاملًا، وكلمة سره.')}</p>
				<div class="ps-grid">
					<label class="ps-field" data-key="mail_from_email"><span class="ps-field__label">${PS.t('بريد المرسل')}</span><input class="ps-input" type="email" dir="ltr" name="mail_from_email" value="${s.mail_from_email}" placeholder="${d.mailFrom}"></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('اسم المرسل')}</span><input class="ps-input" name="mail_from_name" value="${s.mail_from_name}"></label>
				</div>
				<label class="ps-check"><input type="checkbox" name="smtp_enabled" ${s.smtp_enabled ? ui.raw('checked') : ''}> ${PS.t('الإرسال عبر SMTP (حساب بريد حقيقي — أنصح به على HostGator)')}</label>
				<div class="ps-grid">
					<label class="ps-field" data-key="smtp_host"><span class="ps-field__label">${PS.t('خادم SMTP')}</span><input class="ps-input" dir="ltr" name="smtp_host" value="${s.smtp_host}" placeholder="mail.example.com"></label>
					<label class="ps-field" data-key="smtp_port"><span class="ps-field__label">${PS.t('المنفذ')}</span><input class="ps-input" type="number" dir="ltr" name="smtp_port" value="${s.smtp_port}"></label>
					<label class="ps-field" data-key="smtp_secure"><span class="ps-field__label">${PS.t('التشفير')}</span><select class="ps-input" name="smtp_secure">${[['ssl', 'SSL (465)'], ['tls', 'TLS (587)'], ['none', PS.t('بدون')]].map(function (o) {
						return html`<option value="${o[0]}" ${s.smtp_secure === o[0] ? ui.raw('selected') : ''}>${o[1]}</option>`;
					})}</select></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('اسم المستخدم (البريد كاملًا)')}</span><input class="ps-input" dir="ltr" name="smtp_user" value="${s.smtp_user}" autocomplete="off"></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('كلمة السر')}</span><input class="ps-input" type="password" dir="ltr" name="smtp_pass" autocomplete="new-password" placeholder="${d.smtpPassSet ? PS.t('محفوظة — اتركها فارغة للإبقاء عليها') : ''}"></label>
				</div>
				<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">💾 ${PS.t('حفظ')}</button></div>
				<h2 class="ps-card__title ps-mt">${PS.t('إشعارات Telegram')}</h2>
				<ol class="ps-small ps-steps">
					<li>${PS.t('في Telegram افتح')} <a href="https://t.me/BotFather" target="_blank" rel="noopener" dir="ltr">@BotFather</a> ← <span dir="ltr">/newbot</span> ← ${PS.t('اختر اسمًا (مثلًا «Protecta Suivi») ← انسخ')} <strong>${PS.t('الرمز (token)')}</strong>.</li>
					<li>${PS.t('الصقه هنا، فعّل الإشعارات ثم')} <strong>${PS.t('حفظ')}</strong>.</li>
					<li>${PS.t('كل موظف يربط حسابه من 🔔 ← «ربط Telegram» (ضغطة واحدة).')}</li>
				</ol>
				${d.telegramBot ? html`<p class="ps-alert ps-alert--success">✔ ${PS.t('متصل بالبوت')} <a href="https://t.me/${d.telegramBot}" target="_blank" rel="noopener" dir="ltr">@${d.telegramBot}</a></p>` : ''}
				<div class="ps-grid">
					<label class="ps-field" data-key="telegram_token"><span class="ps-field__label">${PS.t('رمز البوت (token)')}</span><input class="ps-input" type="password" dir="ltr" name="telegram_token" autocomplete="off" placeholder="${d.telegramTokenSet ? PS.t('محفوظ — اتركه فارغًا للإبقاء عليه') : '123456789:AA…'}"></label>
				</div>
				<label class="ps-check ps-mt"><input type="checkbox" name="telegram_enabled" ${s.telegram_enabled ? ui.raw('checked') : ''}> ${PS.t('تفعيل إشعارات Telegram (الإشعارات 🔔، المهام والرسائل الصوتية، الملخص الصباحي)')}</label>
				<div class="ps-row"><button type="submit" class="ps-btn ps-btn--primary">💾 ${PS.t('حفظ')}</button><button type="button" class="ps-btn" data-tg-test>✈ ${PS.t('رسالة تجريبية لي على Telegram')}</button></div>
				<h2 class="ps-card__title ps-mt">${PS.t('تجربة الإرسال')}</h2>
				<div class="ps-row">
					<input class="ps-input" type="email" dir="ltr" data-test-to value="${d.myEmail || ''}" placeholder="${PS.t('البريد الذي يصله الاختبار')}" style="max-width:320px">
					<button type="button" class="ps-btn" data-test-mail>✉ ${PS.t('إرسال بريد تجريبي')}</button>
				</div>
				<p class="ps-muted ps-small">${PS.t('احفظ أولًا ثم جرّب. إذا وصل البريد إلى «Spam»، استعمل SMTP.')}</p>
				<div data-test-result></div>
			</form>
			<section class="ps-card" data-gdrive><p class="ps-muted">${PS.t('جاري التحميل…')}</p></section>
			<section class="ps-card" data-sound-test>
				<h2 class="ps-card__title">🎙 ${PS.t('اختبار الصوت')}</h2>
				<p class="ps-small">${PS.t('إذا كانت الرسائل الصوتية بدون صوت: قم بهذا الاختبار على نفس الهاتف، ثم أرسل لقطة شاشة للنتيجة.')}</p>
				<ol class="ps-sound-steps">
					<li><strong>${PS.t('مكبّر الصوت:')}</strong> <button type="button" class="ps-btn ps-btn--small" data-tone>🔊 ${PS.t('تشغيل نغمة')}</button>
						<span data-tone-ask hidden> ${PS.t('هل سمعت النغمة؟')} <button type="button" class="ps-btn ps-btn--small" data-heard="1">✔ ${PS.t('نعم')}</button> <button type="button" class="ps-btn ps-btn--small" data-heard="0">✘ ${PS.t('لا')}</button></span></li>
					<li><strong>${PS.t('الميكروفون:')}</strong> ${PS.t('سجّل 5 ثوانٍ وأنت تتكلم، ثم استمع.')}${PS.voice && PS.voice.supported() ? PS.voice.boxHtml() : html`<div class="ps-alert ps-alert--error">${PS.t('التسجيل غير متاح في هذا المتصفح أو الموقع ليس https.')}</div>`}</li>
					<li><strong>${PS.t('الخادم + Telegram:')}</strong> <label class="ps-check"><input type="checkbox" data-sound-tg ${d.telegramBot ? ui.raw('checked') : ''}> ${PS.t('إرسال التسجيل إلى Telegram الخاص بي')}</label>
						<button type="button" class="ps-btn ps-btn--primary" data-sound-run>🔍 ${PS.t('تحليل التسجيل')}</button></li>
				</ol>
				<div data-sound-report></div>
			</section>
			<section class="ps-card">
				<h2 class="ps-card__title">${PS.t('المهمة اليومية (Cron Job في cPanel)')}</h2>
				<p>${PS.t('منبّه في الاستضافة يفتح هذا الرابط السري مرة في اليوم، فيُرسل البريد الصباحي حتى لو لم يدخل أحد. في cPanel ←')} <strong>Cron Jobs</strong> ← Once Per Day ← ${PS.t('اختر الساعة ← الصق الأمر في «Command» (التفاصيل في README):')}</p>
				<pre class="ps-code" dir="ltr">wget -q -O /dev/null "${d.cronUrl}"</pre>
				<p class="ps-small"><a href="${d.cronUrl}" target="_blank" rel="noopener">▶ ${PS.t('تجربة الرابط الآن')}</a> — ${PS.t('يجب أن تظهر كلمة «OK».')}</p>
				<p class="ps-muted ps-small">${PS.t('أو (بديل):')} <code dir="ltr">${d.cronCli}</code></p>
				<p class="ps-small">${PS.t('الرمز السري (64 حرفًا) جزء من الرابط. إذا تسرّب، جدّده ثم حدّث أمر الـ Cron.')}</p>
				<button type="button" class="ps-btn ps-btn--danger" data-regen>🔄 ${PS.t('تجديد الرمز السري')}</button>
			</section>`.html;
			const form = box.querySelector('[data-general]');
			form.onsubmit = function (e) {
				e.preventDefault();
				const body = { digest_enabled: form.digest_enabled.checked, smtp_enabled: form.smtp_enabled.checked, smtp_secure: form.smtp_secure.value, smtp_pass: form.smtp_pass.value, telegram_enabled: form.telegram_enabled.checked, telegram_token: form.telegram_token.value, default_lang: form.default_lang.value };
				['mail_from_email', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_user'].forEach(function (k) { body[k] = form[k].value; });
				Object.keys(d.numeric).forEach(function (k) { body[k] = Number(form[k].value); });
				PS.api.put('settings', body).then(function (r) {
					PS.config.settings.inactivityMinutes = r.settings.inactivity_minutes;
					PS.config.settings.creatorEditHours = r.settings.creator_edit_hours;
					ui.showErrors(form, {});
					ui.toast(PS.t('تم الحفظ'), 'success');
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
				out.innerHTML = html`<p class="ps-muted">${PS.t('جاري الإرسال…')}</p>`.html;
				PS.api.post('settings/test-email', { to: box.querySelector('[data-test-to]').value }).then(function (r) {
					out.innerHTML = html`<div class="ps-alert ps-alert--success">✔ ${PS.t('قَبِل الخادم الرسالة وأُرسلت إلى')} <span dir="ltr">${r.to}</span>${r.smtp ? PS.t(' (عبر SMTP)') : ''}. ${PS.t('إذا لم تجدها خلال دقائق، انظر في «Spam».')}</div>`.html;
				}).catch(function (er) {
					out.innerHTML = html`<div class="ps-alert ps-alert--error">${errMsg(er)}</div>`.html;
				}).then(function () { btn.disabled = false; });
			};
			box.querySelector('[data-tg-test]').onclick = function () {
				PS.api.post('settings/telegram-test').then(function () { ui.toast(PS.t('✔ أُرسلت الرسالة إلى Telegram'), 'success'); })
					.catch(function (er) { ui.toast(errMsg(er), 'error'); });
			};
			soundTest(box.querySelector('[data-sound-test]'));
			gdriveCard(box.querySelector('[data-gdrive]'));
			box.querySelector('[data-regen]').onclick = function () {
				ui.confirm(PS.t('تجديد الرمز؟ الرابط القديم يتوقف عن العمل، ويجب تحديث أمر الـ Cron في cPanel.'), PS.t('تجديد'), 'danger').then(function (ok) {
					if (!ok) return;
					PS.api.post('settings/cron-token').then(function () { ui.toast(PS.t('تم تجديد الرمز'), 'success'); generalTab(box); })
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
		title: PS.t('الإعدادات'),
		icon: '⚙️',
		cap: 'ps_manage_settings',
		order: 90,
		group: 'admin',
		render: function (main, params) {
			const tab = TABS.some(function (t) { return t[0] === params.tab; }) ? params.tab : 'lists';
			main.innerHTML = html`<div class="ps-page-head"><h1>${PS.t('الإعدادات')}</h1></div>
				<div class="ps-tabs" role="tablist">${TABS.map(function (t) {
					return html`<button type="button" role="tab" class="ps-tab" data-stab="${t[0]}" aria-selected="${t[0] === tab ? 'true' : 'false'}">${t[1]}</button>`;
				})}</div><div data-stab-body><div class="ps-loading">${PS.t('جاري التحميل…')}</div></div>`.html;
			main.querySelector('.ps-tabs').onclick = function (e) {
				const b = e.target.closest('[data-stab]');
				if (b) PS.router.go('parametres', { tab: b.dataset.stab });
			};
			return PS.settingsTabs[tab](main.querySelector('[data-stab-body]'));
		},
	});
})();
