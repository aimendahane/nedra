/**
 * الإعدادات › البيانات (الإدارة) :
 *   - استيراد النسخة الأولى (backup.json du prototype) : aperçu d'abord (rien n'est écrit), choix
 *     « عاجل » → U ou VU et devise des montants, puis import (sauvegarde automatique avant) ;
 *   - تصدير CSV / Excel (المشاريع، العروض، التسليم) ;
 *   - نسخة احتياطية JSON (téléchargement), sauvegardes automatiques du serveur, استرجاع (avec confirmation).
 */
window.PS = window.PS || {};
PS.settingsTabs = PS.settingsTabs || {};

(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;

	function download(path, params) {
		const qs = new URLSearchParams(Object.assign({}, params || {}, { _wpnonce: PS.config.nonce })).toString();
		window.location.href = PS.config.restUrl + path + '?' + qs;
	}

	function reportHtml(r) {
		const created = Object.keys(r.created).filter(function (k) { return r.created[k].length; });
		const conv = function (kind, title) {
			const c = r.conversions[kind] || {};
			const keys = Object.keys(c);
			return keys.length ? html`<li><strong>${title}:</strong> ${keys.map(function (k) { return k + ' (' + c[k] + ')'; }).join(' · ')}</li>` : '';
		};
		const labels = { employee: 'موظفون', place: 'أماكن', category: 'تصنيفات', item_type: 'أصناف', importance: 'أهمية' };
		return html`<div class="ps-alert ${r.dryRun ? 'ps-alert--info' : 'ps-alert--success'}">
			<strong>${r.dryRun ? 'معاينة (لم يُحفظ شيء بعد)' : '✔ تم الاستيراد'}:</strong>
			${r.imported} مشروع ${r.dryRun ? 'سيتم استيراده' : 'تم استيراده'} من ${r.total}${r.skipped.length ? ' · ' + r.skipped.length + ' موجود مسبقًا (يُتجاهل)' : ''}${r.backup ? ' · نسخة احتياطية: ' + r.backup : ''}
		</div>
		<ul class="ps-report">
			${created.map(function (k) { return html`<li><strong>قيم جديدة في «${labels[k] || k}»:</strong> ${r.created[k].join('، ')}</li>`; })}
			<li><strong>الشركات:</strong> ${r.companies.created} جديدة، ${r.companies.reused} موجودة · <strong>منافسون:</strong> ${r.competitors}</li>
			${conv('urgency', 'الاستعجال')}${conv('followup', 'حالة المتابعة')}${conv('source', 'مصدر الطلب')}${conv('offer', 'حالة العرض')}
		</ul>
		${r.warnings.length ? html`<details class="ps-mt" ${r.warnings.length < 8 ? ui.raw('open') : ''}><summary>تنبيهات (${r.warnings.length})</summary><ul class="ps-report">${r.warnings.map(function (w) { return html`<li>${w}</li>`; })}</ul></details>` : ''}`;
	}

	function dataTab(box) {
		return Promise.all([PS.api.get('backup/list'), L.load()]).then(function (res) {
			const backups = res[0];
			box.innerHTML = html`
			<section class="ps-card">
				<h2 class="ps-card__title">📥 استيراد النسخة الأولى (backup.json)</h2>
				<p class="ps-muted ps-small">المشاريع الموجودة (نفس رقم المشروع) لا تُستبدل أبدًا. الملفات المرفقة في النسخة الأولى غير موجودة في ملف النسخة ولا تُستورد.</p>
				<form data-import class="ps-grid">
					<label class="ps-field ps-field--required"><span class="ps-field__label">ملف JSON *</span><input type="file" name="file" accept=".json,application/json" required></label>
					<label class="ps-field"><span class="ps-field__label">القيمة القديمة «عاجل» تصبح</span><select name="urgentAs"><option value="U">U – Urgent</option><option value="VU">VU – Very Urgent</option></select></label>
					<label class="ps-field"><span class="ps-field__label">عملة المبالغ القديمة</span><select name="currencyId">${ui.raw(L.options('currency', (L.byCode('currency', 'LYD') || {}).id, { noEmpty: true }))}</select></label>
				</form>
				<div class="ps-row"><button type="button" class="ps-btn" data-preview>🔍 معاينة</button><button type="button" class="ps-btn ps-btn--primary" data-run disabled>📥 استيراد</button></div>
				<div data-import-report class="ps-mt"></div>
			</section>
			<section class="ps-card">
				<h2 class="ps-card__title">📤 تصدير</h2>
				<div class="ps-row">
					<button type="button" class="ps-btn" data-export="projects|csv">المشاريع CSV</button><button type="button" class="ps-btn" data-export="projects|xlsx">المشاريع Excel</button>
					<button type="button" class="ps-btn" data-export="offers|csv">العروض CSV</button><button type="button" class="ps-btn" data-export="offers|xlsx">العروض Excel</button>
					<button type="button" class="ps-btn" data-export="delivery|csv">التسليم CSV</button><button type="button" class="ps-btn" data-export="delivery|xlsx">التسليم Excel</button>
				</div>
			</section>
			<section class="ps-card">
				<h2 class="ps-card__title">💾 النسخ الاحتياطي</h2>
				<p>نسخة كاملة (المشاريع، الأصناف، الشركات، القوائم، الإعدادات، الحسابات) بصيغة JSON. الملفات المرفقة تُحفظ بنسخة cPanel.</p>
				<div class="ps-row"><button type="button" class="ps-btn ps-btn--primary" data-backup>⬇ تحميل نسخة احتياطية الآن</button></div>
				<h3 class="ps-mt">النسخ المحفوظة على الخادم (تلقائية قبل كل تحديث أو استيراد أو استرجاع)</h3>
				${backups.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact"><thead><tr><th>الملف</th><th>التاريخ</th><th>الحجم</th><th></th></tr></thead><tbody>${backups.slice(0, 30).map(function (b) {
					return html`<tr><td dir="ltr" class="ps-small">${b.name}</td><td>${b.date}</td><td>${F.size(b.size)}</td><td><button type="button" class="ps-btn ps-btn--small" data-bfile="${b.name}">⬇</button></td></tr>`;
				})}</tbody></table></div>` : html`<p class="ps-muted">لا توجد نسخ بعد.</p>`}
				<h3 class="ps-mt">استرجاع نسخة احتياطية</h3>
				<div class="ps-alert ps-alert--warning">الاسترجاع يعيد كل البيانات إلى حالة النسخة. قبل ذلك تُحفظ نسخة تلقائية من الحالة الحالية (يمكن التراجع).</div>
				<form data-restore class="ps-grid">
					<label class="ps-field"><span class="ps-field__label">ملف النسخة (JSON)</span><input type="file" name="file" accept=".json,application/json" required></label>
					<label class="ps-field"><span class="ps-field__label">اكتب «استرجاع» للتأكيد</span><input name="confirm" autocomplete="off"></label>
				</form>
				<div class="ps-row"><button type="button" class="ps-btn ps-btn--danger" data-restore-run>↩ استرجاع</button></div>
				<div data-restore-report class="ps-mt"></div>
			</section>`.html;

			const imp = box.querySelector('[data-import]');
			const runBtn = box.querySelector('[data-run]');
			function doImport(dry) {
				if (!imp.file.files[0]) { ui.toast('اختر ملف JSON', 'error'); return; }
				const fd = new FormData();
				fd.append('file', imp.file.files[0]);
				fd.append('dryRun', dry ? '1' : '0');
				fd.append('urgentAs', imp.urgentAs.value);
				fd.append('currencyId', imp.currencyId.value);
				const out = box.querySelector('[data-import-report]');
				out.innerHTML = '<p class="ps-muted">جاري المعالجة…</p>';
				PS.api.post('import/prototype', fd).then(function (r) {
					out.innerHTML = reportHtml(r).html;
					runBtn.disabled = !(dry && r.imported > 0);
					if (!dry) { L.load(true); ui.toast('تم استيراد ' + r.imported + ' مشروع', 'success'); }
				}).catch(function (e) { out.innerHTML = ''; ui.toast(PS.settingsTabs.errMsg(e), 'error'); });
			}
			imp.addEventListener('change', function () { runBtn.disabled = true; });
			box.querySelector('[data-preview]').onclick = function () { doImport(true); };
			runBtn.onclick = function () {
				ui.confirm('استيراد المشاريع المعروضة في المعاينة؟', 'استيراد').then(function (ok) { if (ok) doImport(false); });
			};

			box.onclick = function (e) {
				const ex = e.target.closest('[data-export]');
				if (ex) {
					const p = ex.dataset.export.split('|');
					if (p[0] === 'projects') download('export/projects', { format: p[1] });
					else download('export/items', { view: p[0], format: p[1] });
					return;
				}
				if (e.target.closest('[data-backup]')) { download('backup/download'); return; }
				const bf = e.target.closest('[data-bfile]');
				if (bf) { download('backup/file', { name: bf.dataset.bfile }); return; }
				if (e.target.closest('[data-restore-run]')) {
					const f = box.querySelector('[data-restore]');
					if (!f.file.files[0]) { ui.toast('اختر ملف النسخة', 'error'); return; }
					if (f.confirm.value.trim() !== 'استرجاع') { ui.toast('اكتب «استرجاع» للتأكيد', 'error'); return; }
					const fd = new FormData();
					fd.append('file', f.file.files[0]);
					fd.append('confirm', 'استرجاع');
					const out = box.querySelector('[data-restore-report]');
					out.innerHTML = '<p class="ps-muted">جاري الاسترجاع…</p>';
					PS.api.post('backup/restore', fd).then(function (r) {
						out.innerHTML = html`<div class="ps-alert ps-alert--success">✔ تم الاسترجاع: ${r.tables.projects || 0} مشروع · حسابات أُعيد إنشاؤها: ${r.users_created} · نسخة الحالة السابقة: ${r.backup}</div>`.html;
						L.load(true);
					}).catch(function (er) { out.innerHTML = ''; ui.toast(PS.settingsTabs.errMsg(er), 'error'); });
				}
			};
		});
	}

	PS.settingsTabs.data = dataTab;
})();
