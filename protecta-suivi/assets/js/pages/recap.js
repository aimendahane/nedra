/**
 * Page « Recap » (#/recap) — الإدارة seulement (brief §7).
 *
 * Filtres : السنة (obligatoire), الشهر، الموظف المتابع، المكان، مصدر الطلب.
 * Indicateurs avec comparaison à la même période de l'année précédente (↑/↓).
 * Graphiques Chart.js (fichier inclus dans le thème) : de droite à gauche, police du site,
 * valeurs écrites sur les graphiques, couleurs des réglages, lisibles en mode nuit.
 * Clic sur une barre / une part / une ligne → page correspondante filtrée.
 * Bouton « طباعة / PDF » (feuille assets/css/print.css).
 *
 * Toutes les valeurs viennent du serveur (GET /recap, calculs dans inc/calculs.php).
 */
(function () {
	'use strict';

	const ui = PS.ui;
	const html = ui.html;
	const L = PS.lists;
	const F = PS.format;

	/** Palette catégorielle (8 teintes, ordre fixe ; validée daltonisme). « أخرى » = gris. */
	const CATEGORICAL = {
		light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
		dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
	};
	const OTHER_GRAY = '#8a8f98';

	let charts = [];
	let st = null;

	/* ------------------------------------------------------------------ */
	/* Thème et outils graphiques                                          */
	/* ------------------------------------------------------------------ */

	function cssVar(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
	function isDark() {
		const t = document.documentElement.getAttribute('data-theme');
		return t === 'dark' || (t !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
	}
	function theme() {
		return { text: cssVar('--ps-text'), muted: cssVar('--ps-text-muted'), grid: cssVar('--ps-border'), surface: cssVar('--ps-surface'), primary: cssVar('--ps-primary'), font: cssVar('--ps-font') || 'Cairo' };
	}
	function categorical(i) { return CATEGORICAL[isDark() ? 'dark' : 'light'][i % 8]; }

	/**
	 * Petit greffon Chart.js : écrit les valeurs sur le graphique.
	 *   - barres empilées : TOTAL au-dessus de chaque barre ;
	 *   - barres simples : valeur au bout de la barre ;
	 *   - anneau : pourcentage dans chaque part (≥ 4 %).
	 */
	const valuesPlugin = {
		id: 'psValues',
		afterDatasetsDraw: function (chart) {
			const t = theme();
			const ctx = chart.ctx;
			const opt = chart.options.plugins.psValues || {};
			ctx.save();
			ctx.font = '700 12px ' + t.font;
			ctx.fillStyle = t.text;
			if (chart.config.type === 'doughnut') {
				const meta = chart.getDatasetMeta(0);
				const data = chart.data.datasets[0].data;
				const total = data.reduce(function (a, b) { return a + b; }, 0);
				meta.data.forEach(function (arc, i) {
					const pct = total ? data[i] / total * 100 : 0;
					if (pct < 4 || arc.hidden) return;
					const pos = arc.tooltipPosition();
					ctx.fillStyle = '#fff';
					ctx.textAlign = 'center';
					ctx.textBaseline = 'middle';
					ctx.fillText(pct.toFixed(1) + '%', pos.x, pos.y);
				});
			} else if (opt.stackTotals) {
				const metas = chart.getSortedVisibleDatasetMetas();
				chart.data.labels.forEach(function (_, i) {
					let total = 0;
					let top = Infinity;
					let x = 0;
					metas.forEach(function (m) {
						total += Number(chart.data.datasets[m.index].data[i]) || 0;
						const el = m.data[i];
						if (el) { top = Math.min(top, el.y); x = el.x; }
					});
					if (!total) return;
					ctx.textAlign = 'center';
					ctx.textBaseline = 'bottom';
					ctx.fillText(String(total), x, top - 4);
				});
			} else {
				const horizontal = chart.options.indexAxis === 'y';
				chart.getSortedVisibleDatasetMetas().forEach(function (m) {
					m.data.forEach(function (el, i) {
						const v = chart.data.datasets[m.index].data[i];
						if (!v) return;
						if (horizontal) {
							// Barres de droite à gauche (axe inversé) : la valeur se place à gauche du bout.
							ctx.textAlign = 'right';
							ctx.textBaseline = 'middle';
							ctx.fillText(String(v), el.x - 6, el.y);
						} else {
							ctx.textAlign = 'center';
							ctx.textBaseline = 'bottom';
							ctx.fillText(String(v), el.x, el.y - 4);
						}
					});
				});
			}
			ctx.restore();
		},
	};

	function baseOptions(extra) {
		const t = theme();
		return Object.assign({
			responsive: true,
			maintainAspectRatio: false,
			// Courte animation d'apparition (aucune si l'utilisateur préfère moins d'animations).
			animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 650, easing: 'easeOutQuart' },
			locale: 'ar',
			layout: { padding: { top: 22, left: 8, right: 8 } },
			plugins: {
				legend: { rtl: true, labels: { color: t.text, font: { family: t.font, size: 13 }, boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'rectRounded', padding: 16 } },
				tooltip: { rtl: true, textDirection: 'rtl', titleFont: { family: t.font, weight: '700' }, bodyFont: { family: t.font }, padding: 12, cornerRadius: 10, boxPadding: 6, usePointStyle: true },
			},
		}, extra || {});
	}

	function axes(horizontal, stacked) {
		const t = theme();
		const cat = { stacked: !!stacked, reverse: !horizontal, grid: { display: false }, ticks: { color: t.text, font: { family: t.font, size: 12 }, autoSkip: false } };
		const val = { stacked: !!stacked, beginAtZero: true, reverse: !!horizontal, grid: { color: t.grid }, border: { display: false }, ticks: { color: t.muted, font: { family: t.font }, precision: 0 } };
		return horizontal ? { y: Object.assign(cat, { reverse: false, position: 'right' }), x: val } : { x: cat, y: Object.assign(val, { position: 'right' }) };
	}

	function makeChart(canvas, config) {
		Chart.defaults.font.family = theme().font;
		const c = new Chart(canvas, config);
		charts.push(c);
		return c;
	}

	function destroyCharts() {
		charts.forEach(function (c) { c.destroy(); });
		charts = [];
	}

	/** Ouvre une page filtrée (année et filtres du Recap conservés). */
	function drill(page, extra) {
		const f = st.data.filters;
		const base = {};
		if (page === 'projets') {
			['year', 'month', 'followerEmpId', 'placeId', 'sourceId'].forEach(function (k) { if (f[k]) base[k] = f[k]; });
		} else if (f.year) {
			base.year = f.year;
		}
		PS.router.go(page, Object.assign(base, extra));
	}

	/* ------------------------------------------------------------------ */
	/* Indicateurs                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Comparaison N-1. `inverse` : indicateur où une hausse est MAUVAISE (المرفوضة، لم تُرسل، retards)
	 * → la flèche reste ↑/↓ mais la couleur suit le sens (vert = bon, rouge = mauvais).
	 */
	function delta(c, isRate, inverse) {
		if (!c || c.diff === null || c.diff === undefined) return html`<span class="ps-kpi__cmp ps-muted">— لا مقارنة</span>`;
		const prevYear = st.data.previousYear;
		const arrow = c.dir === 'up' ? '↑' : c.dir === 'down' ? '↓' : '=';
		let txt;
		if (isRate) txt = (c.diff > 0 ? '+' : '') + c.diff.toFixed(1) + ' نقطة';
		else if (c.pct === null) txt = c.diff ? 'جديد' : '';
		else txt = (c.pct > 0 ? '+' : '') + c.pct.toFixed(1) + ' %';
		const tone = c.dir === 'up' ? (inverse ? 'bad' : 'good') : c.dir === 'down' ? (inverse ? 'good' : 'bad') : '';
		return html`<span class="ps-kpi__cmp ps-kpi__cmp--${c.dir} ${tone ? 'ps-kpi__cmp--' + tone : ''}" title="${prevYear}: ${c.prev === null ? '—' : c.prev}">${arrow} ${txt} <span class="ps-muted">مقارنة بـ ${prevYear}</span></span>`;
	}

	function kpi(label, value, cmp, opts) {
		const o = opts || {};
		return html`<div class="ps-kpi ${o.cls || ''}" ${o.link ? ui.raw('data-drill="' + ui.esc(o.link) + '" role="link" tabindex="0"') : ''}>
			<span class="ps-kpi__label">${label}</span>
			<span class="ps-kpi__value">${value}</span>
			${o.sub ? html`<span class="ps-kpi__sub">${o.sub}</span>` : ''}
			${delta(cmp, o.rate, o.inverse)}
		</div>`;
	}

	function amountsKpi(label, amounts, cmp) {
		const keys = Object.keys(amounts);
		const lines = keys.length ? keys.map(function (cur) {
			const c = cmp[cur];
			const arrow = c && c.dir === 'up' ? '↑' : c && c.dir === 'down' ? '↓' : '';
			return html`<div class="ps-kpi__money"><strong>${F.number(amounts[cur], 0)}</strong> ${L.code(Number(cur))} <span class="ps-kpi__cmp ps-kpi__cmp--${c ? c.dir : ''} ${c && c.dir === 'up' ? 'ps-kpi__cmp--good' : c && c.dir === 'down' ? 'ps-kpi__cmp--bad' : ''}">${arrow}${c && c.pct !== null ? ' ' + Math.abs(c.pct).toFixed(1) + ' %' : ''}</span></div>`;
		}) : html`<span class="ps-muted">—</span>`;
		return html`<div class="ps-kpi ps-kpi--wide"><span class="ps-kpi__label">${label} <span class="ps-muted ps-small">(كل عملة على حدة)</span></span>${lines}</div>`;
	}

	function kpiGrid(d) {
		const k = d.kpis;
		const c = d.compare;
		return html`<div class="ps-kpis">
			${kpi('عدد المشاريع', k.projects, c.projects, { sub: k.cancelled ? 'ملغى: ' + k.cancelled + ' (غير محسوبة)' : 'ملغى: 0', link: 'projets' })}
			${kpi('المؤكدة', k.confirmed, c.confirmed, { cls: 'ps-kpi--good', link: 'projets|followupType=confirmed' })}
			${kpi('المرفوضة', k.rejected, c.rejected, { inverse: true, cls: 'ps-kpi--bad', link: 'projets|followupType=rejected' })}
			${kpi('قيد الدراسة', k.ongoing, c.ongoing, { link: 'projets|followupType=ongoing' })}
			${kpi('العروض المرسلة', k.sent, c.sent)}
			${kpi('لم تُرسل', k.notSent, c.notSent, { inverse: true, link: 'projets|offerStatus=not_sent' })}
			${kpi('نسبة النجاح', F.percent(k.successRate), c.successRate, { rate: true, cls: 'ps-kpi--hero', sub: 'المؤكدة ÷ المرسلة' })}
			${kpi('نسبة الإرسال', F.percent(k.sendRate), c.sendRate, { rate: true, sub: 'المرسلة ÷ (المرسلة + لم تُرسل)' })}
			${kpi('العروض المتأخرة', k.lateOffers, c.lateOffers, { inverse: true, cls: k.lateOffers ? 'ps-kpi--bad' : '', link: 'offres|late=1' })}
			${kpi('التسليمات المتأخرة', k.lateDeliveries, c.lateDeliveries, { inverse: true, cls: k.lateDeliveries ? 'ps-kpi--bad' : '', link: 'livraison|deliveryLate=1' })}
			${amountsKpi('مبالغ العروض', k.amounts, c.amounts)}
			${amountsKpi('مبالغ المشاريع المؤكدة', k.confirmedAmounts, c.confirmedAmounts)}
		</div>`;
	}

	/* ------------------------------------------------------------------ */
	/* Tableaux                                                            */
	/* ------------------------------------------------------------------ */

	function card(title, body, extra) {
		return html`<section class="ps-card ps-recap-card ${extra && extra.wide ? 'ps-recap-card--wide' : ''}"><h2 class="ps-card__title">${title}</h2>${body}</section>`;
	}

	function chartBox(id, height, tableHtml) {
		return html`<div class="ps-chart" style="height:${height}px"><canvas id="${id}" role="img"></canvas></div>
			${tableHtml ? html`<details class="ps-chart-table ps-no-print"><summary>عرض كجدول</summary>${tableHtml}</details>` : ''}`;
	}

	function simpleTable(headers, rows) {
		return html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact"><thead><tr>${headers.map(function (h) { return html`<th>${h}</th>`; })}</tr></thead>
			<tbody>${rows}</tbody></table></div>`;
	}

	function statusTable(d) {
		return simpleTable(['الحالة', 'العدد', '%'], d.byFollowupStatus.map(function (s) {
			return html`<tr class="ps-drill" data-drill="projets|followupStatusId=${s.id}"><td>${ui.badge(s.label, s.color)}</td><td>${s.count}</td><td>${s.pct.toFixed(1)}</td></tr>`;
		}));
	}

	function urgencyTable(d) {
		return simpleTable(['الاستعجال', 'العدد', '%'], d.byUrgency.map(function (u) {
			return html`<tr class="ps-drill" data-drill="projets|urgencyId=${u.id}"><td>${ui.badge(u.code, u.color)} <span class="ps-muted ps-small">${u.label}</span></td><td>${u.count}</td><td>${u.pct.toFixed(1)}</td></tr>`;
		}));
	}

	const EMP_COLS = [['label', 'الموظف'], ['count', 'العدد'], ['confirmed', 'مؤكدة'], ['rejected', 'مرفوضة'], ['delivered', 'مسلّمة'], ['notSent', 'لم تُرسل'], ['successRate', 'نسبة النجاح']];

	function employeeTable(d) {
		const sort = st.empSort || { key: 'count', dir: -1 };
		const rows = d.byEmployee.slice().sort(function (a, b) {
			const x = a[sort.key] === null ? -1 : a[sort.key];
			const y = b[sort.key] === null ? -1 : b[sort.key];
			return (x > y ? 1 : x < y ? -1 : 0) * sort.dir;
		});
		return html`<div class="ps-table-wrap"><table class="ps-table ps-table--compact" data-emp-table><thead><tr>${EMP_COLS.map(function (c) {
			return html`<th aria-sort="${sort.key === c[0] ? (sort.dir > 0 ? 'ascending' : 'descending') : 'none'}"><button type="button" class="ps-sort" data-emp-sort="${c[0]}">${c[1]} ${sort.key === c[0] ? (sort.dir > 0 ? '▲' : '▼') : ''}</button></th>`;
		})}</tr></thead><tbody>${rows.map(function (e) {
			return html`<tr class="ps-drill" data-drill="projets|followerEmpId=${e.id || ''}"><td>${e.label}</td><td>${e.count}</td><td>${e.confirmed}</td><td>${e.rejected}</td><td>${e.delivered}</td><td>${e.notSent}</td><td>${F.percent(e.successRate)}</td></tr>`;
		})}</tbody></table></div>`;
	}

	function sourceTable(d) {
		return simpleTable(['مصدر الطلب', 'العدد', 'مؤكدة', 'نسبة النجاح'], d.bySource.map(function (s) {
			return html`<tr class="ps-drill ${s.unspecified ? 'ps-row-muted' : ''}" data-drill="projets|sourceId=${s.unspecified ? 'unspecified' : s.id}"><td>${s.label}${s.unspecified ? html` <span class="ps-small ps-muted">(مشاريع مستوردة — خارج نسبة النجاح)</span>` : ''}</td><td>${s.count}</td><td>${s.confirmed}</td><td>${s.unspecified ? '—' : F.percent(s.successRate)}</td></tr>`;
		}));
	}

	function companiesTable(d) {
		if (!d.topCompanies.length) return ui.empty('🏢', 'لا توجد بيانات');
		return simpleTable(['الشركة', 'المشاريع', 'المؤكدة', 'مبالغ مؤكدة'], d.topCompanies.map(function (c) {
			const amounts = Object.keys(c.confirmedAmounts).map(function (cur) { return F.number(c.confirmedAmounts[cur], 0) + ' ' + L.code(Number(cur)); }).join(' · ');
			return html`<tr class="ps-drill" data-drill="projets|companyId=${c.id}"><td>${c.name}</td><td>${c.count}</td><td>${c.confirmed}</td><td class="ps-nowrap">${amounts || '—'}</td></tr>`;
		}));
	}

	function competitorsTable(d) {
		if (!d.competitors.length) return ui.empty('⚔️', 'لا يوجد منافسون مسجلون في هذه الفترة');
		return simpleTable(['المنافس', 'عدد المشاريع', 'الماركات', 'الأصناف'], d.competitors.map(function (c) {
			return html`<tr><td>${c.name}</td><td>${c.count}</td><td>${c.brands.join('، ') || '—'}</td><td>${c.itemTypes.join('، ') || '—'}</td></tr>`;
		}));
	}

	function reasonsTable(d) {
		return simpleTable(['السبب', 'العدد', '%'], d.notSentReasons.map(function (r) {
			return html`<tr class="ps-drill" data-drill="offres|offerStatus=not_sent&notSentReasonId=${r.id || ''}"><td>${r.label}</td><td>${r.count}</td><td>${r.pct.toFixed(1)}</td></tr>`;
		}));
	}

	/* ------------------------------------------------------------------ */
	/* Graphiques                                                          */
	/* ------------------------------------------------------------------ */

	function drawCharts(d) {
		destroyCharts();
		const t = theme();
		const tc = d.typeColors;

		// حسب الشهر : barres empilées جارية / مؤكدة / مرفوضة, total au-dessus.
		const series = [['ongoing', 'جارية'], ['confirmed', 'مؤكدة'], ['rejected', 'مرفوضة']];
		makeChart(document.getElementById('ch-month'), {
			type: 'bar',
			data: {
				labels: F.MONTHS,
				datasets: series.map(function (s, i) {
					return {
						label: s[1], key: s[0],
						data: d.byMonth.map(function (m) { return m[s[0]]; }),
						backgroundColor: tc[s[0]], borderColor: t.surface, borderWidth: { top: 2 },
						borderRadius: i === series.length - 1 ? { topLeft: 7, topRight: 7 } : 0, borderSkipped: false, maxBarThickness: 38,
					};
				}),
			},
			options: baseOptions({
				scales: axes(false, true),
				plugins: Object.assign(baseOptions().plugins, { psValues: { stackTotals: true } }),
				onClick: function (e, els, chart) {
					if (!els.length) return;
					const ds = chart.data.datasets[els[0].datasetIndex];
					drill('projets', { month: els[0].index + 1, followupType: ds.key });
				},
			}),
			plugins: [valuesPlugin],
		});

		// حسب التصنيف : anneau (8 parts au plus, « أخرى » en gris).
		if (d.byCategory.length) {
			makeChart(document.getElementById('ch-category'), {
				type: 'doughnut',
				data: {
					labels: d.byCategory.map(function (c) { return c.label + ' (' + c.pct.toFixed(1) + '%)'; }),
					datasets: [{ data: d.byCategory.map(function (c) { return c.count; }), backgroundColor: d.byCategory.map(function (c, i) { return c.other ? OTHER_GRAY : categorical(i); }), borderColor: t.surface, borderWidth: 3, hoverOffset: 8 }],
				},
				options: baseOptions({
					cutout: '58%',
					plugins: Object.assign(baseOptions().plugins, { legend: Object.assign(baseOptions().plugins.legend, { position: 'bottom' }) }),
					onClick: function (e, els) {
						if (!els.length) return;
						const c = d.byCategory[els[0].index];
						if (c.id) drill('projets', { categoryId: c.id });
					},
				}),
				plugins: [valuesPlugin],
			});
		}

		hbar('ch-types', d.byItemType, function (r) { return r.label; }, function (r) { drill('projets', { itemTypeId: r.id }); });
		hbar('ch-reasons', d.notSentReasons, function (r) { return r.label; }, function (r) { drill('offres', { offerStatus: 'not_sent', notSentReasonId: r.id || '' }); });
		hbar('ch-ns-types', d.notSentByType, function (r) { return r.label; }, function (r) { drill('offres', { offerStatus: 'not_sent', itemTypeId: r.id }); });

		// حسب المكان : barres avec le code ; nom complet dans l'infobulle.
		if (d.byPlace.length) {
			makeChart(document.getElementById('ch-places'), {
				type: 'bar',
				data: { labels: d.byPlace.map(function (p) { return p.code; }), datasets: [{ label: 'المشاريع', data: d.byPlace.map(function (p) { return p.count; }), backgroundColor: t.primary, borderRadius: { topLeft: 7, topRight: 7 }, borderSkipped: false, maxBarThickness: 42 }] },
				options: baseOptions({
					scales: axes(false, false),
					plugins: Object.assign(baseOptions().plugins, {
						legend: { display: false },
						tooltip: Object.assign(baseOptions().plugins.tooltip, { callbacks: { title: function (items) { const p = d.byPlace[items[0].dataIndex]; return p.code + ' – ' + p.label; } } }),
					}),
					onClick: function (e, els) { if (els.length && d.byPlace[els[0].index].id) drill('projets', { placeId: d.byPlace[els[0].index].id }); },
				}),
				plugins: [valuesPlugin],
			});
		}
	}

	/** Barres horizontales triées (une seule série, couleur principale). */
	function hbar(id, rows, labelFn, onPick) {
		const canvas = document.getElementById(id);
		if (!canvas || !rows.length) return;
		const t = theme();
		makeChart(canvas, {
			type: 'bar',
			data: { labels: rows.map(labelFn), datasets: [{ label: 'العدد', data: rows.map(function (r) { return r.count; }), backgroundColor: t.primary, borderRadius: { topLeft: 6, bottomLeft: 6 }, borderSkipped: false, maxBarThickness: 22 }] },
			options: baseOptions({
				indexAxis: 'y',
				layout: { padding: { left: 28, top: 4 } },
				scales: axes(true, false),
				plugins: Object.assign(baseOptions().plugins, { legend: { display: false } }),
				onClick: function (e, els) { if (els.length) onPick(rows[els[0].index]); },
			}),
			plugins: [valuesPlugin],
		});
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	function filtersBar() {
		const p = st.params;
		const years = (st.data ? st.data.years : [new Date().getFullYear()]);
		const year = Number(p.year) || years[0];
		return html`<div class="ps-card ps-toolbar ps-recap-filters"><div class="ps-filters ps-filters--inline">
			<label class="ps-field ps-field--compact ps-field--required"><span class="ps-field__label">السنة *</span><select class="ps-input" data-f="year">${years.map(function (y) { return html`<option value="${y}" ${y === year ? ui.raw('selected') : ''}>${y}</option>`; })}</select></label>
			<label class="ps-field ps-field--compact"><span class="ps-field__label">الشهر</span><select class="ps-input" data-f="month"><option value="">كل الأشهر</option>${F.MONTHS.map(function (m, i) { return html`<option value="${i + 1}" ${String(i + 1) === String(p.month) ? ui.raw('selected') : ''}>${m}</option>`; })}</select></label>
			<label class="ps-field ps-field--compact"><span class="ps-field__label">الموظف المتابع</span><select class="ps-input" data-f="followerEmpId">${ui.raw(L.options('employee', p.followerEmpId, { emptyLabel: 'الكل' }))}</select></label>
			<label class="ps-field ps-field--compact"><span class="ps-field__label">المكان</span><select class="ps-input" data-f="placeId">${ui.raw(L.options('place', p.placeId, { emptyLabel: 'الكل' }))}</select></label>
			<label class="ps-field ps-field--compact"><span class="ps-field__label">مصدر الطلب</span><select class="ps-input" data-f="sourceId">${ui.raw(L.options('source', p.sourceId, { emptyLabel: 'الكل' }).replace('(مخفي)', ''))}</select></label>
		</div></div>`;
	}

	function periodLabel(d) {
		const f = d.filters;
		const parts = [(f.month ? F.month(f.month) + ' ' : '') + f.year];
		if (f.followerEmpId) parts.push(L.label(f.followerEmpId));
		if (f.placeId) parts.push(L.label(f.placeId));
		if (f.sourceId) parts.push(L.label(f.sourceId));
		return parts.join(' · ');
	}

	function render() {
		const d = st.data;
		const head = html`<div class="ps-page-head"><h1>Recap — ${periodLabel(d)}</h1>
			<div class="ps-page-head__actions"><button type="button" class="ps-btn" data-action="print">🖨 طباعة / PDF</button></div></div>
			<p class="ps-print-only ps-muted">مقارنة بنفس الفترة من سنة ${d.previousYear} · طُبع في ${F.date(PS.config.today)}</p>`;
		if (!d.kpis.projects && !d.kpis.cancelled) {
			st.main.innerHTML = html`${head}${filtersBar()}<div class="ps-card">${ui.empty('📊', 'لا توجد مشاريع في هذه الفترة. غيّر السنة أو الفلاتر.')}</div>`.html;
			bind();
			return;
		}
		st.main.innerHTML = html`${head}${filtersBar()}${kpiGrid(d)}
			<div class="ps-recap-grid">
				${card('المشاريع حسب الشهر', chartBox('ch-month', 300, simpleTable(['الشهر', 'جارية', 'مؤكدة', 'مرفوضة', 'المجموع'], d.byMonth.map(function (m) { return html`<tr><td>${F.month(m.month)}</td><td>${m.ongoing}</td><td>${m.confirmed}</td><td>${m.rejected}</td><td><strong>${m.total}</strong></td></tr>`; }))), { wide: true })}
				${card('حسب التصنيف', d.byCategory.length ? chartBox('ch-category', 300, simpleTable(['التصنيف', 'العدد', '%'], d.byCategory.map(function (c) { return html`<tr><td>${c.label}</td><td>${c.count}</td><td>${c.pct.toFixed(1)}</td></tr>`; }))) : ui.empty('🍩', 'لا توجد بيانات'))}
				${card('حسب حالة المتابعة', statusTable(d))}
				${card('حسب درجة الاستعجال', urgencyTable(d))}
				${card('حسب مصدر الطلب', sourceTable(d))}
				${card('حسب الموظف المتابع', html`<div data-emp-box>${employeeTable(d)}</div>`, { wide: true })}
				${card('حسب الصنف المطلوب', d.byItemType.length ? chartBox('ch-types', Math.max(160, d.byItemType.length * 30 + 40), simpleTable(['الصنف', 'المشاريع'], d.byItemType.map(function (r) { return html`<tr><td>${r.label}</td><td>${r.count}</td></tr>`; }))) : ui.empty('📦', 'لا توجد بيانات'))}
				${card('حسب المكان', d.byPlace.length ? chartBox('ch-places', 260, simpleTable(['الرمز', 'المكان', 'العدد'], d.byPlace.map(function (p) { return html`<tr><td>${p.code}</td><td>${p.label}</td><td>${p.count}</td></tr>`; }))) : ui.empty('📍', 'لا توجد بيانات'))}
				${card('أهم 10 شركات', companiesTable(d), { wide: true })}
				${card('المنافسون الأكثر حضورا', competitorsTable(d), { wide: true })}
				${card('أسباب عدم الإرسال (' + d.notSentItems + ' صنف)', d.notSentReasons.length ? html`${chartBox('ch-reasons', Math.max(140, d.notSentReasons.length * 32 + 40))}${reasonsTable(d)}` : ui.empty('✔', 'لا توجد عروض لم تُرسل في هذه الفترة'))}
				${card('لم تُرسل حسب الصنف', d.notSentByType.length ? chartBox('ch-ns-types', Math.max(140, d.notSentByType.length * 32 + 40), simpleTable(['الصنف', 'العدد'], d.notSentByType.map(function (r) { return html`<tr><td>${r.label}</td><td>${r.count}</td></tr>`; }))) : ui.empty('✔', 'لا توجد بيانات'))}
			</div>`.html;
		bind();
		drawCharts(d);
	}

	function bind() {
		const m = st.main;
		m.addEventListener('change', function (e) {
			const k = e.target.dataset.f;
			if (!k) return;
			if (e.target.value) st.params[k] = e.target.value; else delete st.params[k];
			PS.router.setParams(st.params);
			load();
		});
		m.addEventListener('click', function (e) {
			if (e.target.closest('[data-action="print"]')) { window.print(); return; }
			const s = e.target.closest('[data-emp-sort]');
			if (s) {
				const key = s.dataset.empSort;
				st.empSort = { key: key, dir: st.empSort && st.empSort.key === key ? -st.empSort.dir : (key === 'label' ? 1 : -1) };
				m.querySelector('[data-emp-box]').innerHTML = employeeTable(st.data).html;
				return;
			}
			const dr = e.target.closest('[data-drill]');
			if (dr) {
				const parts = dr.dataset.drill.split('|');
				const extra = {};
				new URLSearchParams(parts[1] || '').forEach(function (v, k) { if (v !== '') extra[k] = v; });
				drill(parts[0], extra);
			}
		});
		m.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target.dataset.drill) e.target.click();
		});
	}

	function load() {
		const qs = new URLSearchParams(st.params).toString();
		return PS.api.get('recap?' + qs).then(function (d) {
			st.data = d;
			if (!st.params.year) st.params.year = d.filters.year;
			render();
		});
	}

	// Mode nuit / jour : redessiner les graphiques avec les couleurs du thème.
	function redrawIfVisible() { if (st && st.data && document.body.contains(st.main) && charts.length) drawCharts(st.data); }
	window.addEventListener('ps:theme', redrawIfVisible);
	window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', redrawIfVisible);
	window.addEventListener('beforeprint', function () { charts.forEach(function (c) { c.resize(); }); });
	window.addEventListener('afterprint', function () { charts.forEach(function (c) { c.resize(); }); });

	PS.router.register({
		id: 'recap',
		title: 'Recap',
		icon: '📊',
		cap: 'ps_view_recap',
		order: 60,
		group: 'analysis',
		render: function (main, params) {
			destroyCharts();
			return L.load().then(function () {
				st = { main: main, params: Object.assign({}, params), data: null };
				return load();
			});
		},
	});
})();
