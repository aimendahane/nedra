/**
 * Page « متابعة العروض » (#/offres).
 * Contenu prévu : Suivi Offres : متابعة العروض صنف بصنف، مع تنبيه «لازم قرار».
 * Développement : المرحلة 3. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'offres',
		title: 'متابعة العروض',
		icon: '📨',
		cap: 'ps_access',
		order: 40,
		group: 'work',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>متابعة العروض</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 3).')}
					<p class="ps-muted" style="text-align:center">Suivi Offres : متابعة العروض صنف بصنف، مع تنبيه «لازم قرار».</p>
				</div>`.html;
		},
	});
})();
