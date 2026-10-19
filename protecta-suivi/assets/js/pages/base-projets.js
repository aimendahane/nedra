/**
 * Page « المشاريع » (#/projets).
 * Contenu prévu : Base Projets : جدول كل المشاريع مع البحث والفلاتر والفرز.
 * Développement : المرحلة 2. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'projets',
		title: 'المشاريع',
		icon: '📁',
		cap: 'ps_access',
		order: 30,
		group: 'work',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>المشاريع</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 2).')}
					<p class="ps-muted" style="text-align:center">Base Projets : جدول كل المشاريع مع البحث والفلاتر والفرز.</p>
				</div>`.html;
		},
	});
})();
