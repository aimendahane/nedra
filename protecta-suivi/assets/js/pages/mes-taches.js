/**
 * Page « مهامي » (#/taches).
 * Contenu prévu : عروضك المتأخرة، الأصناف «لازم قرار»، المتابعات المبرمجة اليوم أو الفائتة، وتسليمات الأسبوع.
 * Développement : المرحلة 3. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'taches',
		title: 'مهامي',
		icon: '✅',
		cap: 'ps_access',
		order: 10,
		group: 'work',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>مهامي</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 3).')}
					<p class="ps-muted" style="text-align:center">عروضك المتأخرة، الأصناف «لازم قرار»، المتابعات المبرمجة اليوم أو الفائتة، وتسليمات الأسبوع.</p>
				</div>`.html;
		},
	});
})();
