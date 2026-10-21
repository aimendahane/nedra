/**
 * Traduction de l'interface (version 1.3.0) — même principe que inc/i18n.php :
 * le texte ARABE est la clé ; PS.t('نص') renvoie la traduction de la langue du compte, ou l'arabe.
 *
 *   PS.t('نص')                 → texte traduit (espaces autour conservés)
 *   PS.i18n.lang / dir / rtl   → 'ar' | 'en' | 'fr', 'rtl' | 'ltr', true | false
 *
 * Le dictionnaire de la langue en cours (inc/i18n/<langue>.json) est fourni par le serveur dans PS.config.i18n.
 * Ce fichier est chargé en premier (les autres fichiers utilisent PS.t dès leur chargement).
 */
window.PS = window.PS || {};

PS.i18n = (function () {
	'use strict';
	const cfg = (PS.config && PS.config.i18n) || {};
	const lang = cfg.lang || 'ar';
	const dict = cfg.dict || {};
	const dir = cfg.dir || 'rtl';
	return { lang: lang, dir: dir, rtl: dir === 'rtl', dict: dict, languages: cfg.languages || {} };
})();

PS.t = function (ar) {
	if (ar === null || ar === undefined) return '';
	const s = String(ar);
	if (PS.i18n.lang === 'ar' || !s) return s;
	const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(s);
	const tr = PS.i18n.dict[m[2]];
	return tr === undefined ? s : m[1] + tr + m[3];
};
