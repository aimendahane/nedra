/**
 * Client de l'API REST du thème (/wp-json/ps/v1/...).
 *   PS.api.get('lists')                → Promise<données>
 *   PS.api.post('me/prefs', {theme})   → Promise<données>
 *   options.passive = true              → n'est pas compté comme une activité (rafraîchissements automatiques)
 *
 * En cas de session expirée (401), l'utilisateur est renvoyé vers la page de connexion.
 */
window.PS = window.PS || {};

PS.api = (function () {
	'use strict';

	function request(method, path, body, options) {
		const opts = options || {};
		const headers = { 'X-WP-Nonce': PS.config.nonce, 'Accept': 'application/json' };
		if (opts.passive) headers['X-PS-Passive'] = '1';
		const init = { method: method, headers: headers, credentials: 'same-origin' };
		if (body !== undefined && !(body instanceof FormData)) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		} else if (body instanceof FormData) {
			init.body = body;
		}

		return fetch(PS.config.restUrl + path, init).catch(function () {
			// Pas de réponse du tout (connexion coupée, délai dépassé…).
			throw new Error(PS.t('تعذّر الاتصال بالخادم (انقطاع أو مهلة). أعد المحاولة.'));
		}).then(function (res) {
			return res.text().then(function (text) {
				let data = null;
				try { data = text ? JSON.parse(text) : null; } catch (e) {
					// Réponse non JSON (page d'erreur de l'hébergeur, délai dépassé, pare-feu…) : message lisible + code HTTP.
					const plain = String(text || '').replace(/<(script|style)[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
					data = { message: PS.t('ردّ غير متوقّع من الخادم') + ' (HTTP ' + res.status + ')' + (plain ? ' : ' + plain : '') };
				}
				if (res.ok) return data;

				const code = data && data.code;
				if (res.status === 401 || code === 'rest_cookie_invalid_nonce') {
					PS.session && PS.session.expired(code === 'ps_session_expired' ? 'expired' : '');
				}
				const err = new Error((data && data.message) || PS.t('حدث خطأ، حاول مرة أخرى.'));
				err.status = res.status;
				err.code = code;
				err.data = data;
				throw err;
			});
		});
	}

	return {
		get: function (path, options) { return request('GET', path, undefined, options); },
		post: function (path, body, options) { return request('POST', path, body, options); },
		put: function (path, body, options) { return request('PUT', path, body, options); },
		del: function (path, options) { return request('DELETE', path, undefined, options); },
	};
})();
