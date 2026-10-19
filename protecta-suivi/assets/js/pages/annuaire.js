/**
 * Page « الشركات والمنافسون » (#/annuaire).
 * Contenu prévu : دليل الشركات والمنافسين، مع الدمج للإدارة.
 * Développement : المرحلة 2 و 5. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'annuaire',
		title: 'الشركات والمنافسون',
		icon: '🏢',
		cap: 'ps_access',
		order: 70,
		group: 'analysis',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>الشركات والمنافسون</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 2 و 5).')}
					<p class="ps-muted" style="text-align:center">دليل الشركات والمنافسين، مع الدمج للإدارة.</p>
				</div>`.html;
		},
	});
})();
