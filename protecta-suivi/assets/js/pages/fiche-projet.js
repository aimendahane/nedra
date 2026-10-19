/**
 * Page « إدخال مشروع » (#/projet).
 * Contenu prévu : Fiche Projet : معلومات المشروع، الأطراف، الأصناف، المنافسون، سجل المتابعة والملفات.
 * Développement : المرحلة 2. Pour l'instant, la page affiche un message d'attente.
 */
(function () {
	'use strict';

	PS.router.register({
		id: 'projet',
		title: 'إدخال مشروع',
		icon: '➕',
		cap: 'ps_create_project',
		order: 20,
		group: 'work',
		render: function (main) {
			main.innerHTML = PS.ui.html`
				<div class="ps-page-head"><h1>إدخال مشروع</h1></div>
				<div class="ps-card">${PS.ui.empty('🛠️', 'هذه الصفحة قيد الإنجاز (المرحلة 2).')}
					<p class="ps-muted" style="text-align:center">Fiche Projet : معلومات المشروع، الأطراف، الأصناف، المنافسون، سجل المتابعة والملفات.</p>
				</div>`.html;
		},
	});
})();
