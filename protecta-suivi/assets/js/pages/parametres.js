/**
 * Page « الإعدادات » (#/parametres) — réservée à la الإدارة.
 *
 * Étape 1 : affichage en lecture seule des listes déroulantes (pour vérifier les
 * valeurs par défaut). L'édition (ajouter, renommer, masquer, réordonner), les règles
 * de validation, les utilisateurs, l'import / export et la sauvegarde arrivent à l'étape 5.
 */
(function () {
	'use strict';

	const html = PS.ui.html;

	function chip(v, listKey, types) {
		const code = v.code && ['urgency', 'place'].includes(listKey) ? html`<span class="ps-chip__code">${v.code}</span>` : '';
		const dot = v.color ? PS.ui.raw('<span class="ps-dot" style="background:' + PS.ui.esc(v.color) + '"></span>') : '';
		const type = v.type ? html`<span class="ps-muted">(${types[v.type] || v.type})</span>` : '';
		const title = v.hidden ? (v.meta && v.meta.import_only ? 'للمشاريع المستوردة فقط' : 'مخفي') : '';
		return html`<span class="ps-chip ${v.hidden ? 'is-hidden' : ''}" title="${title}">${dot}${code}${v.label}${type}</span>`;
	}

	PS.router.register({
		id: 'parametres',
		title: 'الإعدادات',
		icon: '⚙️',
		cap: 'ps_manage_settings',
		order: 90,
		group: 'admin',
		render: function (main) {
			return PS.router.lists().then(function (data) {
				const cards = Object.keys(data.labels).map(function (key) {
					const values = data.lists[key] || [];
					return html`<section class="ps-card">
						<h2 class="ps-card__title">${data.labels[key]} <span class="ps-muted">(${values.length})</span></h2>
						<div class="ps-list-chips">${values.map(function (v) { return chip(v, key, data.followupTypes); })}</div>
					</section>`;
				});
				main.innerHTML = html`
					<div class="ps-page-head"><h1>الإعدادات</h1></div>
					<p class="ps-alert ps-alert--info">عرض القوائم المنسدلة. التعديل (إضافة، تغيير الاسم، إخفاء، ترتيب) والمستخدمين والاستيراد والتصدير: المرحلة 5.</p>
					${cards}`.html;
			});
		},
	});
})();
