/**
 * Page d'accueil « مهامي » (#/taches) — brief §4.5.
 *
 * Pour l'utilisateur connecté (employé lié à son compte) :
 *   1. العروض المتأخرة — لازم قرار : ses أصناف « قيد الإعداد » dont le أجل est dépassé (hors L) ;
 *   2. المتابعات المبرمجة : projets dont la prochaine date de suivi est aujourd'hui ou dépassée ;
 *   3. مشاريع بدون متابعة : pas de suivi depuis plus de 7 jours ;
 *   4. تسليمات الأسبوع : livraisons non faites dont le أجل tombe dans les 7 jours (retards compris) ;
 *   0. مهام مُسندة (1.0.1) : tâches libres confiées par الإدارة / مسؤول مشروع, bouton « تمت ».
 * الإدارة : toute l'équipe, filtrable par employé.
 * Bouton « مهمة جديدة » (capacité ps_assign_tasks) + bloc « مهام أرسلتها » pour suivre ses demandes.
 * PS.taskModal({ projectId, projectLabel }) est aussi utilisé depuis Fiche Projet.
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;

	function block(icon, title, rows, emptyText, rowFn, more) {
		return html`<section class="ps-card ps-task">
			<h2 class="ps-card__title"><span aria-hidden="true">${icon}</span> ${title} <span class="ps-task__count ${rows.length ? 'is-on' : ''}">${rows.length}</span>${more || ''}</h2>
			${rows.length ? html`<ul class="ps-task__list">${rows.map(rowFn)}</ul>` : html`<p class="ps-muted">✔ ${emptyText}</p>`}
		</section>`;
	}

	function who(r, showEmp) { return showEmp && r.employeeId ? html` · <span class="ps-muted">${L.label(r.employeeId)}</span>` : ''; }

	function errMsg(er) { return (er && er.message) || PS.t('حدث خطأ'); }

	/* ---- Message vocal (1.2.0) : enregistrement dans le navigateur, 5 minutes au plus ---- */
	const VOICE_MAX = 300;

	/**
	 * Type d'enregistrement : Opus d'abord (Ogg pour Firefox, WebM pour Chrome/Android) — le serveur vérifie qu'il
	 * n'est pas muet et en fait une copie Ogg pour Telegram ; MP4/AAC pour l'iPhone (seul format de Safari).
	 */
	function voiceType() {
		const types = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/webm', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4'];
		for (let i = 0; i < types.length; i++) if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
		return '';
	}

	function voiceSupported() { return !!(window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.isSecureContext); }

	function fmtSeconds(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }

	/**
	 * Enregistreur attaché à un conteneur [data-voice]. Renvoie { result(), stop() } ;
	 * result() = { blob, seconds, ext } ou null.
	 */
	function voiceRecorder(box) {
		const st = { rec: null, stream: null, chunks: [], blob: null, seconds: 0, timer: null, t0: 0, meter: null, peak: 0 };
		const $ = function (sel) { return box.querySelector(sel); };
		function showState(state) {
			box.dataset.state = state;
			$('[data-voice-rec]').innerHTML = state === 'recording' ? PS.t('⏹ إيقاف التسجيل') : (state === 'done' ? PS.t('🎙 إعادة التسجيل') : PS.t('🎙 تسجيل رسالة صوتية'));
			$('[data-voice-time]').hidden = state === 'idle';
			$('[data-voice-audio]').hidden = state !== 'done';
			$('[data-voice-del]').hidden = state !== 'done';
			$('[data-voice-level]').hidden = state !== 'recording';
		}
		/* Niveau du micro pendant l'enregistrement (1.3.1) : on voit tout de suite si le son arrive. */
		function meterStart(stream) {
			st.peak = 0;
			try {
				const AC = window.AudioContext || window.webkitAudioContext;
				const ac = new AC();
				const an = ac.createAnalyser();
				an.fftSize = 512;
				ac.createMediaStreamSource(stream).connect(an);
				// Relié à la sortie à volume 0 : certains navigateurs n'analysent qu'un graphe audio « actif ».
				const mute = ac.createGain();
				mute.gain.value = 0;
				an.connect(mute);
				mute.connect(ac.destination);
				if (ac.state === 'suspended' && ac.resume) ac.resume();
				st.meter = { ac: ac, an: an, buf: new Uint8Array(an.fftSize) };
			} catch (e) { st.meter = null; }
		}
		function meterRead() {
			if (!st.meter) return;
			st.meter.an.getByteTimeDomainData(st.meter.buf);
			let p = 0;
			for (let i = 0; i < st.meter.buf.length; i++) p = Math.max(p, Math.abs(st.meter.buf[i] - 128) / 128);
			st.peak = Math.max(st.peak, p);
			$('[data-voice-level] i').style.width = Math.min(100, Math.round(p * 140)) + '%';
		}
		function meterStop() {
			if (!st.meter) return;
			const measured = st.meter.ac.state === 'running'; // mesure fiable seulement si l'analyse a tourné
			try { st.meter.ac.close(); } catch (e) { /* déjà fermé */ }
			st.meter = null;
			// Aucun son capté : micro coupé, mauvais micro choisi ou autorisation partielle.
			if (measured && st.peak < 0.01) {
				const several = $('[data-voice-mic]').options.length > 1;
				ui.toast(PS.t('لم يُلتقط أي صوت: تأكّد أن الميكروفون يعمل وغير مكتوم، ثم أعد التسجيل.') + (several ? ' ' + PS.t('جرّب ميكروفونًا آخر من القائمة 🎤.') : ''), 'error');
			}
		}
		function tick() {
			st.seconds = (Date.now() - st.t0) / 1000;
			meterRead();
			$('[data-voice-time]').textContent = '● ' + fmtSeconds(st.seconds) + ' / ' + fmtSeconds(VOICE_MAX);
			if (st.seconds >= VOICE_MAX) stop();
		}
		function stop() {
			if (st.rec && st.rec.state !== 'inactive') st.rec.stop();
			clearInterval(st.timer);
		}
		/*
		 * Choix du micro (1.3.4) : sur un PC il y a souvent plusieurs entrées (webcam, casque, « Stereo Mix »,
		 * écran HDMI) et le navigateur peut prendre une entrée muette. Les noms ne sont connus qu'après autorisation.
		 */
		function savedMic() { try { return localStorage.getItem('ps_mic') || ''; } catch (e) { return ''; } }
		function listMics() {
			if (!navigator.mediaDevices.enumerateDevices) return;
			navigator.mediaDevices.enumerateDevices().then(function (all) {
				const mics = all.filter(function (d) { return d.kind === 'audioinput' && d.deviceId !== 'communications'; });
				const sel = $('[data-voice-mic]');
				const cur = sel.value || savedMic();
				sel.innerHTML = mics.map(function (d, i) {
					return html`<option value="${d.deviceId}" ${d.deviceId === cur ? ui.raw('selected') : ''}>🎤 ${d.label || PS.t('ميكروفون') + ' ' + (i + 1)}</option>`.html;
				}).join('');
				sel.hidden = mics.length < 2;
			}).catch(function () { /* liste indisponible : micro par défaut */ });
		}
		function start() {
			const id = $('[data-voice-mic]').value || savedMic();
			const want = id && id !== 'default' ? { deviceId: { exact: id } } : true;
			navigator.mediaDevices.getUserMedia({ audio: want }).catch(function (er) {
				// Micro mémorisé débranché → micro par défaut.
				if (want !== true && er && (er.name === 'OverconstrainedError' || er.name === 'NotFoundError')) return navigator.mediaDevices.getUserMedia({ audio: true });
				throw er;
			}).then(function (stream) {
				listMics();
				const type = voiceType();
				st.stream = stream;
				st.chunks = [];
				st.rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
				st.rec.ondataavailable = function (e) { if (e.data && e.data.size) st.chunks.push(e.data); };
				st.rec.onstop = function () {
					stream.getTracks().forEach(function (t) { t.stop(); });
					meterStop();
					st.blob = new Blob(st.chunks, { type: st.rec.mimeType || type || 'audio/webm' });
					$('[data-voice-audio]').src = URL.createObjectURL(st.blob);
					$('[data-voice-time]').textContent = '🎙 ' + fmtSeconds(st.seconds);
					showState('done');
				};
				meterStart(stream);
				st.rec.start(1000);
				st.t0 = Date.now();
				st.timer = setInterval(tick, 100);
				tick();
				showState('recording');
			}).catch(function () { ui.toast(PS.t('لم يُسمح باستعمال الميكروفون. فعّله من إعدادات المتصفح.'), 'error'); });
		}
		box.addEventListener('click', function (e) {
			if (e.target.closest('[data-voice-rec]')) { if (box.dataset.state === 'recording') stop(); else start(); }
			if (e.target.closest('[data-voice-del]')) { st.blob = null; showState('idle'); }
		});
		$('[data-voice-mic]').addEventListener('change', function (e) { try { localStorage.setItem('ps_mic', e.target.value); } catch (er) { /* navigation privée */ } });
		listMics();
		showState('idle');
		return {
			result: function () {
				if (!st.blob) return null;
				const t = st.blob.type;
				return { blob: st.blob, peak: st.peak, seconds: Math.round(st.seconds), ext: t.indexOf('ogg') >= 0 ? 'ogg' : t.indexOf('mp4') >= 0 ? 'm4a' : 'webm' };
			},
			stop: function () { stop(); if (st.stream) st.stream.getTracks().forEach(function (t) { t.stop(); }); },
		};
	}

	/** Zone d'enregistrement (bouton, chrono, niveau du micro, réécoute, suppression). */
	function voiceBoxHtml() {
		return html`<div class="ps-voice" data-voice><button type="button" class="ps-btn ps-voice__rec" data-voice-rec></button><span class="ps-voice__time" data-voice-time dir="ltr"></span><span class="ps-voice__level" data-voice-level aria-hidden="true"><i></i></span><select class="ps-input ps-input--sm ps-voice__mic" data-voice-mic aria-label="${PS.t('الميكروفون')}" hidden></select><audio data-voice-audio controls></audio><button type="button" class="ps-btn ps-btn--ghost ps-btn--small" data-voice-del>🗑 ${PS.t('حذف')}</button></div>`;
	}

	/** Lecteur audio d'une tâche (fichier servi par l'API après contrôle des droits). */
	function voicePlayer(r) {
		if (!r.hasVoice) return '';
		// Durée affichée à côté : un enregistrement WebM n'indique pas sa durée au lecteur (« 0:00 / 0:00 »).
		return html`${r.voiceSeconds ? html`<span class="ps-voice__dur" dir="ltr">🎙 ${fmtSeconds(r.voiceSeconds)}</span>` : ''}<audio class="ps-voice__player" controls preload="metadata" src="${PS.config.restUrl + 'free-tasks/' + r.id + '/voice?_wpnonce=' + PS.config.nonce}"></audio>`;
	}

	/*
	 * Secours de lecture (1.3.2) : si le lecteur du téléphone n'arrive pas à lire l'adresse (certains Android),
	 * le fichier est téléchargé entièrement puis lu depuis la mémoire ; une erreur du serveur est affichée.
	 */
	document.addEventListener('error', function (e) {
		const a = e.target;
		if (!a || !a.classList || !a.classList.contains('ps-voice__player') || a.dataset.fallback) return;
		a.dataset.fallback = '1';
		fetch(a.src, { credentials: 'same-origin' }).then(function (res) {
			const type = res.headers.get('content-type') || '';
			if (!res.ok || type.indexOf('json') >= 0) {
				return res.json().then(function (j) { throw new Error((j && j.message) || PS.t('حدث خطأ')); });
			}
			return res.blob();
		}).then(function (blob) {
			a.src = URL.createObjectURL(blob);
			a.play().catch(function () { /* l'utilisateur appuiera sur ▶ */ });
		}).catch(function (er) { ui.toast(errMsg(er), 'error'); });
	}, true);

	/**
	 * Fenêtre « مهمة جديدة ». Résout avec la tâche créée (ou null si fermée).
	 * @param {Object} o { projectId, projectLabel, task (modification d'une tâche existante) }
	 */
	function taskModal(o) {
		o = o || {};
		const t = o.task || {};
		const tomorrow = new Date(Date.parse(PS.config.today + 'T12:00:00Z') + 86400000).toISOString().slice(0, 10);
		return L.load().then(function () {
			let created = null;
			let recorder = null;
			return ui.modal({
				title: t.id ? PS.t('تعديل المهمة') : PS.t('📌 مهمة جديدة'),
				body: html`<form class="ps-form" data-task-form novalidate>
					${o.projectLabel ? html`<p class="ps-small">${PS.t('المشروع:')} <strong>${o.projectLabel}</strong></p>` : ''}
					<label class="ps-field" data-key="employeeId"><span class="ps-field__label">${PS.t('الموظف *')}</span>
						<select class="ps-input" name="employeeId"><option value="">—</option>${ui.raw(L.options('employee', t.employeeId, { noEmpty: true }))}</select></label>
					<label class="ps-field" data-key="title"><span class="ps-field__label">${PS.t('المهمة *')}</span>
						<input class="ps-input" name="title" maxlength="255" value="${t.title || ''}" placeholder="${PS.t('مثلًا: اتصل بالحريف لتأكيد الطلبية')}"></label>
					<label class="ps-field" data-key="dueDate"><span class="ps-field__label">${PS.t('الأجل')}</span>
						<input class="ps-input" type="date" name="dueDate" value="${t.id ? (t.dueDate || '') : tomorrow}"></label>
					<label class="ps-field" data-key="note"><span class="ps-field__label">${PS.t('ملاحظة')}</span>
						<textarea class="ps-input" name="note" rows="3">${t.note || ''}</textarea></label>
					${t.id ? '' : (voiceSupported()
						? voiceBoxHtml()
						: html`<p class="ps-muted ps-small">🎙 ${PS.t('الرسائل الصوتية تتطلب متصفحًا حديثًا واتصالًا آمنًا (https).')}</p>`)}
					<p class="ps-muted ps-small">${PS.t('اكتب المهمة أو سجّل رسالة صوتية (أو الاثنين). تظهر في «مهامي» عند الموظف، ويصله بريد و/أو رسالة Telegram.')}</p>
				</form>`,
				buttons: [{ id: 'save', label: t.id ? PS.t('💾 حفظ') : PS.t('📌 إرسال المهمة'), kind: 'primary' }, { id: 'cancel', label: PS.t('إلغاء') }],
				onOpen: function (wrap) {
					const vbox = wrap.querySelector('[data-voice]');
					if (vbox) recorder = voiceRecorder(vbox);
					// Une erreur disparaît dès que le champ est corrigé.
					wrap.addEventListener('input', function (e) {
						const field = e.target.closest('.ps-field--error');
						if (!field) return;
						field.classList.remove('ps-field--error');
						const err = field.querySelector('.ps-field__error');
						if (err) err.remove();
					});
				},
				onButton: function (id, wrap, close) {
					if (id !== 'save') return true;
					const f = wrap.querySelector('[data-task-form]');
					const body = { employeeId: f.employeeId.value, title: f.title.value, dueDate: f.dueDate.value, note: f.note.value };
					if (o.projectId) body.projectId = o.projectId;
					const voice = recorder ? recorder.result() : null;
					if (recorder && wrap.querySelector('[data-voice]').dataset.state === 'recording') { ui.toast(PS.t('أوقف التسجيل أولًا'), 'error'); return false; }
					let payload = body;
					if (voice) {
						// Formulaire multipart : les champs + le fichier audio.
						payload = new FormData();
						Object.keys(body).forEach(function (k) { payload.append(k, body[k] === undefined || body[k] === null ? '' : body[k]); });
						payload.append('voiceSeconds', voice.seconds);
						payload.append('voice', voice.blob, 'voice.' + voice.ext);
					}
					const req = t.id ? PS.api.put('free-tasks/' + t.id, Object.assign({ action: 'edit' }, body)) : PS.api.post('free-tasks', payload);
					req.then(function (r) {
						created = r;
						ui.toast(t.id ? PS.t('تم حفظ المهمة') : PS.t('أُرسلت المهمة إلى ') + L.label(r.employeeId), 'success');
						close('save');
					}).catch(function (er) {
						if (er.data && er.data.data && er.data.data.errors) ui.showErrors(f, er.data.data.errors);
						ui.toast(errMsg(er), 'error');
					});
					return false;
				},
			}).then(function () { if (recorder) recorder.stop(); return created; });
		});
	}
	PS.taskModal = taskModal;

	/** Change le statut d'une tâche puis recharge la page مهامي. */
	function taskAction(id, action, confirmText) {
		const go = function () {
			return PS.api.put('free-tasks/' + id, { action: action }).then(function () {
				ui.toast({ done: PS.t('أحسنت! تم إنجاز المهمة ✔'), reopen: PS.t('أُعيد فتح المهمة'), cancel: PS.t('أُلغيت المهمة') }[action], 'success');
				PS.router.refresh();
			}).catch(function (er) { ui.toast(errMsg(er), 'error'); });
		};
		return confirmText ? ui.confirm(confirmText, PS.t('تأكيد')).then(function (ok) { if (ok) go(); }) : go();
	}

	function dueText(r, today) {
		if (!r.dueDate) return PS.t('بدون أجل');
		if (r.late) return html`<strong>${PS.t('متأخرة — الأجل')} ${F.date(r.dueDate)}</strong>`;
		return r.dueDate === today ? html`<strong>${PS.t('اليوم')}</strong>` : PS.t('الأجل ') + F.date(r.dueDate);
	}

	function assignedBlock(data, showEmp) {
		return block('📌', PS.t('مهام مُسندة'), data.assigned || [], PS.t('لا توجد مهام مُسندة'), function (r) {
			return html`<li class="ps-task__item ${r.late ? 'is-danger' : (r.dueDate === data.today ? 'is-warning' : '')}" data-task="${r.id}">
				<div class="ps-task__row"><span><strong>${r.title}</strong>${r.projectId ? html` · <a href="#/projet?id=${r.projectId}" class="ps-mono">${r.projectNo}</a>` : ''}</span>
				<button type="button" class="ps-btn ps-btn--small ps-btn--primary" data-task-done="${r.id}">✔ ${PS.t('تمت')}</button></div>
				<span class="ps-small">${dueText(r, data.today)}${r.createdByName ? PS.t(' · من ') + r.createdByName : ''}${who(r, showEmp)}</span>
				${r.note ? html`<span class="ps-small ps-task__note">${r.note}</span>` : ''}${voicePlayer(r)}</li>`;
		});
	}

	function sentBlock(data) {
		if (!data.sent || !data.sent.length) return '';
		return block('📤', PS.t('مهام أرسلتها'), data.sent, '', function (r) {
			const done = r.status === 'done';
			return html`<li class="ps-task__item ${done ? 'is-done' : (r.late ? 'is-danger' : '')}">
				<div class="ps-task__row"><span>${done ? '✔ ' : ''}<strong>${r.title}</strong> → ${L.label(r.employeeId)}${r.projectId ? html` · <a href="#/projet?id=${r.projectId}" class="ps-mono">${r.projectNo}</a>` : ''}</span>
				${done ? '' : html`<span class="ps-row ps-row--tight"><button type="button" class="ps-btn ps-btn--small" data-task-edit="${r.id}">✎</button><button type="button" class="ps-btn ps-btn--small ps-btn--ghost" data-task-cancel="${r.id}">${PS.t('إلغاء')}</button></span>`}</div>
				<span class="ps-small">${done ? PS.t('تمت ') + F.date(String(r.doneAt).slice(0, 10)) + (r.doneByName ? ' · ' + r.doneByName : '') : dueText(r, data.today)}</span>${voicePlayer(r)}</li>`;
		});
	}

	function bindTaskButtons(main, data) {
		main.addEventListener('click', function (e) {
			const d = e.target.closest('[data-task-done]');
			if (d) return taskAction(d.dataset.taskDone, 'done');
			const c = e.target.closest('[data-task-cancel]');
			if (c) return taskAction(c.dataset.taskCancel, 'cancel', PS.t('إلغاء هذه المهمة؟ تبقى محفوظة كـ«ملغاة».'));
			const ed = e.target.closest('[data-task-edit]');
			if (ed) {
				const t = (data.sent || []).concat(data.assigned || []).find(function (x) { return String(x.id) === ed.dataset.taskEdit; });
				return taskModal({ task: t, projectLabel: t && t.projectNo ? t.projectNo + ' — ' + t.projectName : '' }).then(function (r) { if (r) PS.router.refresh(); });
			}
			if (e.target.closest('[data-new-task]')) taskModal().then(function (r) { if (r) PS.router.refresh(); });
		});
	}

	function render(main, params, data) {
		const admin = PS.router.can('ps_edit_all_projects');
		const showEmp = admin && !data.employeeId;
		const name = PS.config.user.name;
		const newBtn = PS.router.can('ps_assign_tasks') ? html`<button type="button" class="ps-btn ps-btn--primary" data-new-task>📌 ${PS.t('مهمة جديدة')}</button>` : '';
		let head = html`<div class="ps-page-head"><h1>${PS.t('مهامي')}</h1><span class="ps-muted">${F.date(data.today)}</span><div class="ps-page-head__actions">${newBtn}</div></div>`;
		if (admin) {
			head = html`<div class="ps-page-head"><h1>${data.employeeId ? PS.t('مهام') + ' ' + L.label(data.employeeId) : PS.t('مهام الفريق')}</h1>
				<div class="ps-page-head__actions">${newBtn}<label class="ps-field ps-field--compact"><span class="ps-field__label">${PS.t('الموظف')}</span>
				<select class="ps-input" data-emp><option value="">${PS.t('كل الفريق')}</option>${ui.raw(L.options('employee', data.employeeId, { noEmpty: true }))}</select></label></div></div>`;
		}
		bindTaskButtons(main, data);
		if (!data.linked) {
			main.innerHTML = html`${head}<div class="ps-alert ps-alert--info">${PS.t('مرحبًا')} ${name}. ${PS.t('حسابك غير مرتبط بموظف من القائمة، لذلك لا تظهر مهام شخصية. اطلب من الإدارة ربط حسابك.')}</div>
				<div class="ps-tasks">${sentBlock(data)}</div>`.html;
			return;
		}
		main.innerHTML = html`${head}
			<div class="ps-tasks">
			${assignedBlock(data, showEmp)}
			${block('⚠️', PS.t('عروض متأخرة — لازم قرار'), data.lateOffers, PS.t('لا توجد عروض متأخرة'), function (r) {
				return html`<li class="ps-task__item is-danger"><a href="#/projet?id=${r.projectId}"><strong class="ps-mono">${r.projectNo}</strong> ${L.label(r.itemTypeId)} — ${r.projectName}</a>
					<span class="ps-small">${PS.t('الأجل')} ${F.date(r.dueDate)} · <strong>${PS.t('متأخر')} ${r.daysLate} ${PS.t('يوم')}</strong>${who(r, showEmp)}</span></li>`;
			}, data.lateOffers.length ? html` <a class="ps-btn ps-btn--small ps-btn--ghost" href="#/offres?late=1${data.employeeId ? '&employeeId=' + data.employeeId : ''}">${PS.t('فتح في العروض ←')}</a>` : '')}
			${block('📞', PS.t('متابعات مبرمجة اليوم أو فائتة'), data.followupsDue, PS.t('لا توجد متابعات مبرمجة اليوم'), function (r) {
				const overdue = r.nextDate < data.today;
				return html`<li class="ps-task__item ${overdue ? 'is-danger' : 'is-warning'}"><a href="#/projet?id=${r.projectId}&tab=followups"><strong class="ps-mono">${r.projectNo}</strong> ${r.projectName}</a>
					<span class="ps-small">${overdue ? PS.t('كانت مبرمجة') : PS.t('اليوم')} ${F.date(r.nextDate)}${r.companyName ? ' · ' + r.companyName : ''}${who(r, showEmp)}</span></li>`;
			})}
			${block('🕒', PS.t('مشاريع بدون متابعة منذ أكثر من 7 أيام'), data.noFollowup, PS.t('كل المشاريع متابعة'), function (r) {
				return html`<li class="ps-task__item"><a href="#/projet?id=${r.projectId}&tab=followups"><strong class="ps-mono">${r.projectNo}</strong> ${r.projectName}</a>
					<span class="ps-small">${r.lastDate ? PS.t('آخر متابعة ') + F.date(r.lastDate) : PS.t('لا توجد متابعة مسجلة')}${who(r, showEmp)}</span></li>`;
			})}
			${block('🚚', PS.t('تسليمات هذا الأسبوع'), data.deliveries, PS.t('لا توجد تسليمات هذا الأسبوع'), function (r) {
				return html`<li class="ps-task__item ${r.late ? 'is-danger' : ''}"><a href="#/projet?id=${r.projectId}"><strong class="ps-mono">${r.projectNo}</strong> ${L.label(r.itemTypeId)} — ${r.projectName}</a>
					<span class="ps-small">${PS.t('أجل التسليم')} ${F.date(r.deliveryDueDate)}${r.late ? html` · <strong>${PS.t('متأخر')} ${r.delayDays} ${PS.t('يوم')}</strong>` : ''}${who(r, showEmp)}</span></li>`;
			}, data.deliveries.length ? html` <a class="ps-btn ps-btn--small ps-btn--ghost" href="#/livraison${data.employeeId ? '?employeeId=' + data.employeeId : ''}">${PS.t('فتح في التسليم ←')}</a>` : '')}
			${sentBlock(data)}
			</div>`.html;
		const sel = main.querySelector('[data-emp]');
		if (sel) sel.addEventListener('change', function () { PS.router.go('taches', sel.value ? { employeeId: sel.value } : null); });
	}

	PS.router.register({
		id: 'taches',
		title: PS.t('مهامي'),
		icon: '✅',
		cap: 'ps_access',
		order: 10,
		group: 'work',
		render: function (main, params) {
			return L.load().then(function () {
				return PS.api.get('tasks' + (params.employeeId ? '?employeeId=' + encodeURIComponent(params.employeeId) : '')).then(function (d) {
					render(main, params, d);
				});
			});
		},
	});
	// Réutilisé par « 🎙 اختبار الصوت » (الإعدادات ← عام).
	PS.voice = { recorder: voiceRecorder, boxHtml: voiceBoxHtml, supported: voiceSupported };
})();
