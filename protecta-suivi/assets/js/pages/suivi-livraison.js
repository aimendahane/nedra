/**
 * Page « متابعة التسليم » (#/livraison).
 * Contenu prévu : Suivi Livraison : التسليم والفواتير للأصناف المقبولة.
 * Développement : المرحلة 3. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'livraison',
		title: 'متابعة التسليم',
		icon: '🚚',
		cap: 'ps_access',
		order: 50,
		group: 'work',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>متابعة التسليم</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 3).')}
					<p class="ps-muted" style="text-align:center">Suivi Livraison : التسليم والفواتير للأصناف المقبولة.</p>
				</div>`.html;
		},
	});
})();
