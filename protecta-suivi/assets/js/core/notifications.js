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
		el('ps-bell').setAttribute('aria-label', n ? 'الإشعارات (' + n + ' جديدة)' : 'الإشعارات');
	}

	function refresh() {
		return PS.api.get('notifications?limit=0', { passive: true }).then(function (r) { setCount(r.unread); }).catch(function () {});
	}

	function renderPanel(unread) {
		const prefs = PS.config.user.prefs || {};
		el('ps-bell-panel').innerHTML = html`<div class="ps-bell__head"><strong>الإشعارات</strong>
			${unread ? html`<button type="button" class="ps-btn ps-btn--small ps-btn--ghost" data-bell-all>تعليم الكل كمقروء</button>` : ''}</div>
			${items.length ? html`<ul class="ps-bell__list">${items.map(function (n) {
				return html`<li><button type="button" class="ps-bell__item ${n.read ? '' : 'is-unread'}" data-bell-id="${n.id}" data-bell-project="${n.projectId || ''}">
					<span class="ps-bell__title">${n.title}</span><span class="ps-bell__body">${n.body || ''}</span>
					<span class="ps-bell__meta">${n.actorName ? n.actorName + ' · ' : ''}${PS.format.dateTime(n.createdAt)}</span></button></li>`;
			})}</ul>` : html`<p class="ps-muted ps-bell__foot">لا توجد إشعارات بعد. ستصلك هنا تغييرات المشاريع التي تتابعها.</p>`}
			<label class="ps-check ps-bell__foot"><input type="checkbox" data-bell-mail ${prefs.instantEmail ? ui.raw('checked') : ''}> بريد فوري عند كل تغيير</label>`.html;
	}

	function open() {
		const panel = el('ps-bell-panel');
		panel.hidden = false;
		el('ps-bell').setAttribute('aria-expanded', 'true');
		panel.innerHTML = '<p class="ps-muted ps-bell__foot">جاري التحميل…</p>';
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
			if (e.target.closest('[data-bell-all]')) {
				PS.api.post('notifications/read', { all: true }).then(function () { open(); }).catch(function (er) { ui.toast(er.message, 'error'); });
			}
		});
		el('ps-bell-panel').addEventListener('change', function (e) {
			const cb = e.target.closest('[data-bell-mail]');
			if (!cb) return;
			PS.api.post('me/prefs', { instantEmail: cb.checked }).then(function (u) {
				PS.config.user.prefs = u.prefs;
				ui.toast(cb.checked ? 'ستصلك رسالة بريد عند كل تغيير' : 'أُوقف البريد الفوري', 'success');
			}).catch(function (er) { ui.toast(er.message, 'error'); });
		});
		document.addEventListener('click', close);
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
		refresh();
		setInterval(refresh, 60 * 1000);
	}

	document.addEventListener('DOMContentLoaded', init);

	return { refresh, setCount };
})();
