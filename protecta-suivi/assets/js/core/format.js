/**
 * Formatage pour l'affichage : dates, mois, nombres, montants.
 * Aucune règle de calcul métier ici (elles sont dans inc/calculs.php côté serveur).
 */
window.PS = window.PS || {};

PS.format = (function () {
	'use strict';

	/** Mois tels qu'utilisés en Tunisie/Libye dans le fichier d'origine. */
	const MONTHS = ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

	/** « 2026-10-19 » → « 19/10/2026 » (chiffres latins, comme le fichier Excel). */
	function date(iso) {
		if (!iso) return '';
		const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
		return m ? m[3] + '/' + m[2] + '/' + m[1] : iso;
	}

	/** Numéro de mois (1-12) → nom arabe. */
	function month(n) {
		return MONTHS[(Number(n) || 0) - 1] || '';
	}

	/** Nombre avec séparateur de milliers. */
	function number(n, decimals) {
		if (n === null || n === undefined || n === '') return '';
		return Number(n).toLocaleString('en-US', {
			minimumFractionDigits: decimals || 0,
			maximumFractionDigits: decimals === undefined ? 3 : decimals,
		});
	}

	/** Montant + code devise (jamais additionné entre devises). */
	function money(amount, currencyCode) {
		if (amount === null || amount === undefined || amount === '') return '';
		return number(amount, 2) + ' ' + (currencyCode || '');
	}

	/** Pourcentage à 1 décimale, « — » si null. */
	function percent(p) {
		return p === null || p === undefined ? '—' : Number(p).toFixed(1) + ' %';
	}

	return { MONTHS, date, month, number, money, percent };
})();
