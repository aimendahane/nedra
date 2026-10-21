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
		const labels = { employee: PS.t('موظفون'), place: PS.t('أماكن'), category: PS.t('تصنيفات'), item_type: PS.t('أصناف'), importance: PS.t('أهمية') };
		return html`<div class="ps-alert ${r.dryRun ? 'ps-alert--info' : 'ps-alert--success'}">
			<strong>${r.dryRun ? PS.t('معاينة (لم يُحفظ شيء بعد)') : PS.t('✔ تم الاستيراد')}:</strong>
			${r.imported} ${PS.t('مشروع')} ${r.dryRun ? PS.t('سيتم استيراده') : PS.t('تم استيراده')} ${PS.t('من')} ${r.total}${r.skipped.length ? ' · ' + r.skipped.length + PS.t(' موجود مسبقًا (يُتجاهل)') : ''}${r.backup ? PS.t(' · نسخة احتياطية: ') + r.backup : ''}
		</div>
		<ul class="ps-report">
			${created.map(function (k) { return html`<li><strong>${PS.t('قيم جديدة في «')}${labels[k] || k}»:</strong> ${r.created[k].join(PS.t('، '))}</li>`; })}
			<li><strong>${PS.t('الشركات:')}</strong> ${r.companies.created} ${PS.t('جديدة،')} ${r.companies.reused} ${PS.t('موجودة ·')} <strong>${PS.t('منافسون:')}</strong> ${r.competitors}</li>
			${conv('urgency', PS.t('الاستعجال'))}${conv('followup', PS.t('حالة المتابعة'))}${conv('source', PS.t('مصدر الطلب'))}${conv('offer', PS.t('حالة العرض'))}
		</ul>
		${r.warnings.length ? html`<details class="ps-mt" ${r.warnings.length < 8 ? ui.raw('open') : ''}><summary>${PS.t('تنبيهات (')}${r.warnings.length})</summary><ul class="ps-report">${r.warnings.map(function (w) { return html`<li>${w}</li>`; })}</ul></details>` : ''}`;
	}

	function dataTab(box) {
		return Promise.all([PS.api.get('backup/list'), L.load()]).then(function (res) {
			const backups = res[0];
			box.innerHTML = html`
			<section class="ps-card">
				<h2 class="ps-card__title">📥 ${PS.t('استيراد النسخة الأولى (backup.json)')}</h2>
				<p class="ps-muted ps-small">${PS.t('المشاريع الموجودة (نفس رقم المشروع) لا تُستبدل أبدًا. الملفات المرفقة في النسخة الأولى غير موجودة في ملف النسخة ولا تُستورد.')}</p>
				<form data-import class="ps-grid">
					<label class="ps-field ps-field--required"><span class="ps-field__label">${PS.t('ملف JSON *')}</span><input type="file" name="file" accept=".json,application/json" required></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('القيمة القديمة «عاجل» تصبح')}</span><select name="urgentAs"><option value="U">U – Urgent</option><option value="VU">VU – Very Urgent</option></select></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('عملة المبالغ القديمة')}</span><select name="currencyId">${ui.raw(L.options('currency', (L.byCode('currency', 'LYD') || {}).id, { noEmpty: true }))}</select></label>
				</form>
				<div class="ps-row"><button type="button" class="ps-btn" data-preview>🔍 ${PS.t('معاينة')}</button><button type="button" class="ps-btn ps-btn--primary" data-run disabled>📥 ${PS.t('استيراد')}</button></div>
				<div data-import-report class="ps-mt"></div>
			</section>
			<section class="ps-card">
				<h2 class="ps-card__title">📤 ${PS.t('تصدير')}</h2>
				<div class="ps-row">
					<button type="button" class="ps-btn" data-export="projects|csv">${PS.t('المشاريع CSV')}</button><button type="button" class="ps-btn" data-export="projects|xlsx">${PS.t('المشاريع Excel')}</button>
					<button type="button" class="ps-btn" data-export="offers|csv">${PS.t('العروض CSV')}</button><button type="button" class="ps-btn" data-export="offers|xlsx">${PS.t('العروض Excel')}</button>
					<button type="button" class="ps-btn" data-export="delivery|csv">${PS.t('التسليم CSV')}</button><button type="button" class="ps-btn" data-export="delivery|xlsx">${PS.t('التسليم Excel')}</button>
				</div>
			</section>
			<section class="ps-card">
				<h2 class="ps-card__title">💾 ${PS.t('النسخ الاحتياطي')}</h2>
				<p>${PS.t('نسخة كاملة (المشاريع، الأصناف، الشركات، القوائم، الإعدادات، الحسابات) بصيغة JSON. الملفات المرفقة تُحفظ بنسخة cPanel.')}</p>
				<div class="ps-row"><button type="button" class="ps-btn ps-btn--primary" data-backup>⬇ ${PS.t('تحميل نسخة احتياطية الآن')}</button></div>
				<h3 class="ps-mt">${PS.t('النسخ المحفوظة على الخادم (تلقائية قبل كل تحديث أو استيراد أو استرجاع)')}</h3>
				${backups.length ? html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact"><thead><tr><th>${PS.t('الملف')}</th><th>${PS.t('التاريخ')}</th><th>${PS.t('الحجم')}</th><th></th></tr></thead><tbody>${backups.slice(0, 30).map(function (b) {
					return html`<tr><td dir="ltr" class="ps-small">${b.name}</td><td>${b.date}</td><td>${F.size(b.size)}</td><td><button type="button" class="ps-btn ps-btn--small" data-bfile="${b.name}">⬇</button></td></tr>`;
				})}</tbody></table></div>` : html`<p class="ps-muted">${PS.t('لا توجد نسخ بعد.')}</p>`}
				<h3 class="ps-mt">${PS.t('استرجاع نسخة احتياطية')}</h3>
				<div class="ps-alert ps-alert--warning">${PS.t('الاسترجاع يعيد كل البيانات إلى حالة النسخة. قبل ذلك تُحفظ نسخة تلقائية من الحالة الحالية (يمكن التراجع).')}</div>
				<form data-restore class="ps-grid">
					<label class="ps-field"><span class="ps-field__label">${PS.t('ملف النسخة (JSON)')}</span><input type="file" name="file" accept=".json,application/json" required></label>
					<label class="ps-field"><span class="ps-field__label">${PS.t('اكتب «استرجاع» للتأكيد')}</span><input name="confirm" autocomplete="off"></label>
				</form>
				<div class="ps-row"><button type="button" class="ps-btn ps-btn--danger" data-restore-run>↩ ${PS.t('استرجاع')}</button></div>
				<div data-restore-report class="ps-mt"></div>
			</section>`.html;

			const imp = box.querySelector('[data-import]');
			const runBtn = box.querySelector('[data-run]');
			function doImport(dry) {
				if (!imp.file.files[0]) { ui.toast(PS.t('اختر ملف JSON'), 'error'); return; }
				const fd = new FormData();
				fd.append('file', imp.file.files[0]);
				fd.append('dryRun', dry ? '1' : '0');
				fd.append('urgentAs', imp.urgentAs.value);
				fd.append('currencyId', imp.currencyId.value);
				const out = box.querySelector('[data-import-report]');
				out.innerHTML = ('<p class="ps-muted">' + PS.t('جاري المعالجة…') + '</p>');
				PS.api.post('import/prototype', fd).then(function (r) {
					out.innerHTML = reportHtml(r).html;
					runBtn.disabled = !(dry && r.imported > 0);
					if (!dry) { L.load(true); ui.toast(PS.t('تم استيراد ') + r.imported + PS.t(' مشروع'), 'success'); }
				}).catch(function (e) { out.innerHTML = ''; ui.toast(PS.settingsTabs.errMsg(e), 'error'); });
			}
			imp.addEventListener('change', function () { runBtn.disabled = true; });
			box.querySelector('[data-preview]').onclick = function () { doImport(true); };
			runBtn.onclick = function () {
				ui.confirm(PS.t('استيراد المشاريع المعروضة في المعاينة؟'), PS.t('استيراد')).then(function (ok) { if (ok) doImport(false); });
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
					if (!f.file.files[0]) { ui.toast(PS.t('اختر ملف النسخة'), 'error'); return; }
					// Mot de confirmation accepté en arabe ou dans la langue de l'interface (le serveur reçoit toujours « استرجاع »).
					if (f.confirm.value.trim() !== 'استرجاع' && f.confirm.value.trim().toLowerCase() !== PS.t('استرجاع').toLowerCase()) { ui.toast(PS.t('اكتب «استرجاع» للتأكيد'), 'error'); return; }
					const fd = new FormData();
					fd.append('file', f.file.files[0]);
					fd.append('confirm', 'استرجاع');
					const out = box.querySelector('[data-restore-report]');
					out.innerHTML = ('<p class="ps-muted">' + PS.t('جاري الاسترجاع…') + '</p>');
					PS.api.post('backup/restore', fd).then(function (r) {
						out.innerHTML = html`<div class="ps-alert ps-alert--success">✔ ${PS.t('تم الاسترجاع:')} ${r.tables.projects || 0} ${PS.t('مشروع · حسابات أُعيد إنشاؤها:')} ${r.users_created} · ${PS.t('نسخة الحالة السابقة:')} ${r.backup}</div>`.html;
						L.load(true);
					}).catch(function (er) { out.innerHTML = ''; ui.toast(PS.settingsTabs.errMsg(er), 'error'); });
				}
			};
		});
	}

	PS.settingsTabs.data = dataTab;
})();
