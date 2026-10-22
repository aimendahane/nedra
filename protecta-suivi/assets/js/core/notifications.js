/**
 * Cloche 🔔 des notifications (version 1.0.2) — dans l'en-tête de toutes les pages.
 *
 *   - nombre de notifications non lues, rafraîchi chaque minute (appel « passif » : ne prolonge
 *     pas la session) et après chaque action (PS.notifications.refresh()) ;
 *   - clic → liste des dernières notifications ; clic sur une ligne → marquée lue + ouverture du projet ;
 *   - « تعليم الكل كمقروء » et la case « بريد فوري عند كل تغيير » (préférence du compte).
 * Les règles (qui est prévenu de quoi) sont côté serveur : inc/services/notifications.php.
 */
window.PS = window.PS || {};

PS.notifications = (function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	let items = [];

	function el(id) { return document.getElementById(id); }

	function setCount(n) {
		const c = el('ps-bell-count');
		if (!c) return;
		c.hidden = !n;
		c.textContent = n > 99 ? '99+' : String(n || '');
		el('ps-bell').setAttribute('aria-label', n ? PS.t('الإشعارات (') + n + PS.t(' جديدة)') : PS.t('الإشعارات'));
	}

	function refresh() {
		return PS.api.get('notifications?limit=0', { passive: true }).then(function (r) { setCount(r.unread); }).catch(function () {});
	}

	function renderPanel(unread) {
		const prefs = PS.config.user.prefs || {};
		el('ps-bell-panel').innerHTML = html`<div class="ps-bell__head"><strong>${PS.t('الإشعارات')}</strong>
			${unread ? html`<button type="button" class="ps-btn ps-btn--small ps-btn--ghost" data-bell-all>${PS.t('تعليم الكل كمقروء')}</button>` : ''}</div>
			${items.length ? html`<ul class="ps-bell__list">${items.map(function (n) {
				return html`<li><button type="button" class="ps-bell__item ${n.read ? '' : 'is-unread'}" data-bell-id="${n.id}" data-bell-project="${n.projectId || ''}">
					<span class="ps-bell__title">${n.title}</span><span class="ps-bell__body">${n.body || ''}</span>
					<span class="ps-bell__meta">${n.actorName ? n.actorName + ' · ' : ''}${PS.format.dateTime(n.createdAt)}</span></button></li>`;
			})}</ul>` : html`<p class="ps-muted ps-bell__foot">${PS.t('لا توجد إشعارات بعد. ستصلك هنا تغييرات المشاريع التي تتابعها.')}</p>`}
			<label class="ps-check ps-bell__foot"><input type="checkbox" data-bell-mail ${prefs.instantEmail ? ui.raw('checked') : ''}> ${PS.t('بريد فوري عند كل تغيير')}</label>
			<div class="ps-bell__foot ps-bell__tg" data-bell-tg></div>`.html;
		renderTelegram();
	}

	/* ---- Telegram (1.2.0) : ربط الحساب بضغطة واحدة ---- */
	let tg = null;
	let tgTimer = null;

	function renderTelegram() {
		const box = document.querySelector('[data-bell-tg]');
		if (!box) return;
		const draw = function () {
			if (!tg || !tg.enabled) {
				// 1.3.5 : Telegram pas encore activé → dire où l'activer (الإدارة) au lieu de ne rien montrer.
				const admin = !!(PS.config.user.caps || {}).ps_manage_settings;
				box.hidden = false;
				box.innerHTML = admin
					? html`<span class="ps-muted">✈ ${PS.t('Telegram غير مفعّل بعد:')}</span> <a class="ps-link" href="#/parametres?tab=general">${PS.t('الإعدادات ← عام ← إشعارات Telegram')}</a>`.html
					: html`<span class="ps-muted">✈ ${PS.t('Telegram غير مفعّل بعد (اطلب من الإدارة تفعيله).')}</span>`.html;
				return;
			}
			box.hidden = false;
			box.innerHTML = tg.linked
				? html`<span class="ps-tg-ok">✈ ${PS.t('مربوط بـ Telegram')}</span> <button type="button" class="ps-link" data-tg-unlink>${PS.t('فك الربط')}</button>`.html
				: html`<button type="button" class="ps-btn ps-btn--small ps-btn--tg" data-tg-link>✈ ${PS.t('ربط Telegram')}</button> <span class="ps-muted">${PS.t('لتصلك الإشعارات على هاتفك')}</span>`.html;
		};
		if (tg) return draw();
		PS.api.get('me/telegram', { passive: true }).then(function (r) { tg = r; draw(); }).catch(function () {});
	}

	/** Ouvre le lien du bot puis vérifie toutes les 3 s (2 min) que l'utilisateur a appuyé sur « Start ». */
	function linkTelegram() {
		const win = window.open('about:blank', '_blank');
		PS.api.post('me/telegram/link').then(function (r) {
			if (win) win.location.href = r.url; else window.location.href = r.url;
			ui.toast(PS.t('اضغط «Start» في Telegram ثم ارجع هنا'), 'info');
			let n = 0;
			clearInterval(tgTimer);
			tgTimer = setInterval(function () {
				if (++n > 40) return clearInterval(tgTimer);
				PS.api.post('me/telegram/check', {}, { passive: true }).then(function (s) {
					if (!s.linked) return;
					clearInterval(tgTimer);
					tg = s;
					renderTelegram();
					ui.toast(PS.t('✔ تم ربط حسابك بـ Telegram'), 'success');
				}).catch(function () {});
			}, 3000);
		}).catch(function (e) { if (win) win.close(); ui.toast(e.message, 'error'); });
	}

	/*
	 * 1.4.0 (demande du client) : à la connexion, tant que le compte n'est pas lié à Telegram (et que Telegram
	 * est activé), une fenêtre propose « ربط الآن ». Une fois par session ; elle revient à la connexion suivante.
	 */
	function promptTelegram() {
		let seen = false;
		try { seen = sessionStorage.getItem('ps_tg_prompt') === '1'; } catch (e) { /* stockage indisponible */ }
		if (seen) return;
		PS.api.get('me/telegram', { passive: true }).then(function (r) {
			tg = r;
			if (!r.enabled || r.linked) return;
			try { sessionStorage.setItem('ps_tg_prompt', '1'); } catch (e) { /* idem */ }
			ui.modal({
				title: '✈ ' + PS.t('اربط حسابك بـ Telegram'),
				body: html`<p>${PS.t('لتصلك المهام والرسائل الصوتية والإشعارات مباشرة على هاتفك.')}</p>
					<ol class="ps-small"><li>${PS.t('اضغط «ربط الآن»: يفتح Telegram.')}</li><li>${PS.t('اضغط Start في Telegram.')}</li><li>${PS.t('ارجع هنا: يظهر «مربوط بـ Telegram».')}</li></ol>`,
				buttons: [{ id: 'link', label: '✈ ' + PS.t('ربط الآن'), kind: 'primary' }, { id: 'later', label: PS.t('لاحقًا') }],
				onButton: function (id) { if (id === 'link') linkTelegram(); return true; },
			});
		}).catch(function () {});
	}

	function open() {
		const panel = el('ps-bell-panel');
		panel.hidden = false;
		el('ps-bell').setAttribute('aria-expanded', 'true');
		panel.innerHTML = ('<p class="ps-muted ps-bell__foot">' + PS.t('جاري التحميل…') + '</p>');
		PS.api.get('notifications?limit=30').then(function (r) {
			items = r.items;
			setCount(r.unread);
			renderPanel(r.unread);
		}).catch(function (e) { panel.innerHTML = html`<p class="ps-bell__foot">${e.message}</p>`.html; });
	}

	function close() {
		const panel = el('ps-bell-panel');
		if (panel.hidden) return;
		panel.hidden = true;
		el('ps-bell').setAttribute('aria-expanded', 'false');
	}

	function init() {
		const btn = el('ps-bell');
		if (!btn) return;
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (el('ps-bell-panel').hidden) open(); else close();
		});
		el('ps-bell-panel').addEventListener('click', function (e) {
			e.stopPropagation();
			const it = e.target.closest('[data-bell-id]');
			if (it) {
				PS.api.post('notifications/read', { ids: [Number(it.dataset.bellId)] }).then(function (r) { setCount(r.unread); }).catch(function () {});
				close();
				if (it.dataset.bellProject) PS.router.go('projet', { id: it.dataset.bellProject });
				return;
			}
			if (e.target.closest('[data-tg-link]')) return linkTelegram();
			if (e.target.closest('[data-tg-unlink]')) {
				return PS.api.post('me/telegram/unlink').then(function (s) { tg = s; renderTelegram(); ui.toast(PS.t('تم فك الربط'), 'success'); });
			}
			if (e.target.closest('[data-bell-all]')) {
				PS.api.post('notifications/read', { all: true }).then(function () { open(); }).catch(function (er) { ui.toast(er.message, 'error'); });
			}
		});
		el('ps-bell-panel').addEventListener('change', function (e) {
			const cb = e.target.closest('[data-bell-mail]');
			if (!cb) return;
			PS.api.post('me/prefs', { instantEmail: cb.checked }).then(function (u) {
				PS.config.user.prefs = u.prefs;
				ui.toast(cb.checked ? PS.t('ستصلك رسالة بريد عند كل تغيير') : PS.t('أُوقف البريد الفوري'), 'success');
			}).catch(function (er) { ui.toast(er.message, 'error'); });
		});
		document.addEventListener('click', close);
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
		refresh();
		setInterval(refresh, 60 * 1000);
		promptTelegram();
	}

	document.addEventListener('DOMContentLoaded', init);

	return { refresh, setCount };
})();
