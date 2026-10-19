/**
 * Page « Recap » (#/recap).
 * Contenu prévu : ملخص السنة: المؤشرات، الجداول والرسوم البيانية.
 * Développement : المرحلة 4. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'recap',
		title: 'Recap',
		icon: '📊',
		cap: 'ps_view_recap',
		order: 60,
		group: 'analysis',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>Recap</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 4).')}
					<p class="ps-muted" style="text-align:center">ملخص السنة: المؤشرات، الجداول والرسوم البيانية.</p>
				</div>`.html;
		},
	});
})();
