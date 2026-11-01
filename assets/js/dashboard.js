// dashboard.js – Energy Monitor Frontend-Logik (Übersicht, Chart, Zählerstand erfassen)

class EnergyApp {
	constructor() {
		this.state = {
			selectedType: 'Strom',
			dashboardGranularity: 'month', // week | month | year
			dashboardOffset: 0,            // Perioden zurück (0 = aktuell)
			chartView: 'timeline',         // timeline | years (Jahresvergleich)
			cumulative: false,             // nur Jahresvergleich
			chartPeriod: 'year',           // last_week | last_month | year | custom
			chartMode: 'usage',            // usage | cost
			showForecast: false,
		};
		this.chart = null;
		this.lastChartData = null;
		this.labels = {};        // Typ -> Anzeigename (aus dem Dashboard)
		this.seq = { dashboard: 0, chart: 0 }; // verwirft veraltete Antworten bei schnellem Umschalten
		this.readingModal = EM.modal('readingModal');
		this.readingsModal = EM.modal('readingsModal');
		this.reportModal = EM.modal('reportModal');
		this.readingsPaging = { type: null, offset: 0, pageSize: 25 };

		EM.initTheme(() => this.lastChartData && (this.state.chartView === 'years' ? this.renderYearComparison(this.lastChartData) : this.renderChart(this.lastChartData)));
		this.bindStaticEvents();
		this.loadDashboard();
		this.loadChart();
	}

	// ------------------------------------------------------------------
	// Events
	// ------------------------------------------------------------------
	bindStaticEvents() {
		const $ = (id) => document.getElementById(id);
		$('weatherBtn').addEventListener('click', () => this.importWeather());
		$('addBtn').addEventListener('click', () => this.openModal(this.state.selectedType));
		$('modalClose').addEventListener('click', () => this.readingModal.close());
		$('modalCancel').addEventListener('click', () => this.readingModal.close());
		$('readingForm').addEventListener('submit', (e) => this.saveReading(e));
		$('readingsBtn').addEventListener('click', () => this.openReadings(this.state.selectedType));
		$('reportBtn').addEventListener('click', () => this.openReport());
		$('reportClose').addEventListener('click', () => this.reportModal.close());
		$('reportYear').addEventListener('change', () => this.updateReportLink());
		$('reportLink').addEventListener('click', () => setTimeout(() => this.reportModal.close(), 200));
		$('readingsClose').addEventListener('click', () => this.readingsModal.close());
		$('readingsType').addEventListener('change', (e) => this.loadReadings(e.target.value, true));
		$('readingsMore').addEventListener('click', () => this.loadReadings(this.readingsPaging.type, false));
		$('readingsBody').addEventListener('click', (e) => {
			const btn = e.target.closest('button[data-action]');
			if (!btn) return;
			if (btn.dataset.action === 'edit') this.editReading(btn.dataset.id);
			if (btn.dataset.action === 'delete') this.deleteReading(btn.dataset.id);
		});
		$('cumulativeCheckbox').addEventListener('change', (e) => {
			this.state.cumulative = e.target.checked;
			this.loadChart();
		});
		$('goCustomRange').addEventListener('click', () => this.loadChart());
		$('forecastCheckbox').addEventListener('change', (e) => {
			this.state.showForecast = e.target.checked;
			this.loadChart();
		});

		this.bindSegmented('dashGranularity', (v) => {
			this.state.dashboardGranularity = v;
			this.state.dashboardOffset = 0; // Versatz gilt nur für die jeweilige Einheit
			this.loadDashboard();
		});
		$('periodPrev').addEventListener('click', () => this.shiftPeriod(1));
		$('periodNext').addEventListener('click', () => this.shiftPeriod(-1));
		$('periodToday').addEventListener('click', () => { this.state.dashboardOffset = 0; this.loadDashboard(); });
		document.addEventListener('keydown', (e) => {
			// Pfeiltasten blättern, außer beim Tippen oder bei geöffnetem Dialog
			if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
			if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
			if (e.target.closest('input, select, textarea, [contenteditable]') || document.querySelector('.modal-overlay.active')) return;
			this.shiftPeriod(e.key === 'ArrowLeft' ? 1 : -1);
		});
		this.bindSegmented('chartView', (v) => {
			this.state.chartView = v;
			this.updateChartControls();
			this.loadChart();
		});
		this.bindSegmented('chartPeriod', (v) => {
			this.state.chartPeriod = v;
			$('customRange').classList.toggle('visible', v === 'custom');
			if (v !== 'custom') this.loadChart();
		});
		this.bindSegmented('chartMode', (v) => { this.state.chartMode = v; this.loadChart(); });

		// Event-Delegation: bleibt gültig, wenn die Kacheln neu gerendert werden
		const grid = $('dashboard');
		grid.addEventListener('click', (e) => {
			const add = e.target.closest('[data-action="add"]');
			if (add) { e.stopPropagation(); this.openModal(add.dataset.type); return; }
			const card = e.target.closest('.card[data-type]');
			if (card) this.selectType(card.dataset.type);
		});
		grid.addEventListener('keydown', (e) => {
			if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.card[data-type]')) {
				e.preventDefault();
				this.selectType(e.target.dataset.type);
			}
		});
	}

	bindSegmented(groupId, onSelect) {
		document.querySelectorAll(`#${groupId} button`).forEach((btn) => {
			btn.addEventListener('click', () => {
				document.querySelectorAll(`#${groupId} button`).forEach((b) => b.classList.toggle('active', b === btn));
				onSelect(btn.dataset.value);
			});
		});
	}

	// ------------------------------------------------------------------
	// Dashboard (Kachel-Übersicht)
	// ------------------------------------------------------------------
	/** $delta > 0: weiter zurück, < 0: Richtung Gegenwart (nie darüber hinaus). */
	shiftPeriod(delta) {
		const next = Math.max(0, Math.min(this.maxBack ?? Infinity, this.state.dashboardOffset + delta));
		if (next === this.state.dashboardOffset) return;
		this.state.dashboardOffset = next;
		this.loadDashboard();
	}

	updatePeriodNav(period) {
		this.maxBack = period.max_back;
		const $ = (id) => document.getElementById(id);
		$('periodLabel').textContent = period.label;
		$('periodPrev').disabled = period.offset >= period.max_back;
		$('periodNext').disabled = period.offset <= 0;
		$('periodToday').classList.toggle('d-none', period.offset <= 0);
	}

	async loadDashboard() {
		const seq = ++this.seq.dashboard;
		let res;
		try {
			res = await EM.api('get_dashboard', { params: {
				granularity: this.state.dashboardGranularity,
				offset: this.state.dashboardOffset,
			} });
		} catch (e) {
			if (seq === this.seq.dashboard) EM.toast('Fehler beim Laden: ' + e.message);
			return;
		}
		if (seq !== this.seq.dashboard) return;
		const data = res.cards;
		this.updatePeriodNav(res.period);

		const granLabel = { week: 'Woche', month: 'Monat', year: 'Jahr' }[this.state.dashboardGranularity];
		const grid = document.getElementById('dashboard');
		grid.innerHTML = Object.entries(data).map(([type, item]) => {
			this.labels[type] = item.label;
			(this.units ||= {})[type] = item.unit;
			return this.cardHtml(type, item, granLabel);
		}).join('');
	}

	cardHtml(type, item, granLabel) {
		const esc = EM.escape;
		const staleText = item.last_reading
			? `Zuletzt: ${EM.fmtDate(item.last_reading.reading_date)}${item.stale_warning ? ` (${item.stale_days} Tage her ⚠️)` : ''}`
			: 'Noch keine Messwerte';
		const compareHtml = item.compare.map((c) => `
			<div class="compare-item">
				<div class="compare-label">${esc(c.label)}</div>
				<div class="compare-value">${EM.fmt(c.value)} ${esc(item.unit)}</div>
				${c.cost !== null ? `<div class="compare-value cost">${EM.fmtMoney(c.cost)}</div>` : ''}
			</div>`).join('');
		// zurückliegende, vollständig erfasste Perioden zeigen den Ist-Wert, sonst Prognose/Hochrechnung
		const pct = Math.round(item.elapsed_fraction * 100);
		const complete = item.is_past && pct >= 100;
		const valueLabel = complete ? 'Verbrauch' : (item.is_past ? 'Hochgerechnet' : 'Prognose');
		const coverage = complete ? '' : ` (${pct}% der Periode erfasst)`;
		const trendRef = granLabel === 'Jahr' ? 'Vorjahr' : 'Vorjahres-' + granLabel;

		return `
			<div class="card ${this.state.selectedType === type ? 'selected' : ''}" data-type="${esc(type)}" role="button" tabindex="0" aria-label="${esc(item.label)} im Verlauf anzeigen">
				<button class="add-fab" data-action="add" data-type="${esc(type)}" title="Zählerstand erfassen" aria-label="Zählerstand für ${esc(item.label)} erfassen">${icon('plus')}</button>
				<div class="card-top">
					<div>
						<div class="card-title">${esc(item.label)}${item.is_past ? '<span class="badge-past">Rückblick</span>' : ''}</div>
						<div class="card-reading">${item.last_reading ? EM.fmt(item.last_reading.counter_value) : '–'} <span class="unit">${esc(item.unit)}</span></div>
						<div class="card-date ${item.stale_warning ? 'stale' : ''}">${staleText}</div>
					</div>
					<div class="icon-box" style="background:${esc(item.color)}">${icon(item.icon)}</div>
				</div>

				<div class="trend-pill trend-${esc(item.trend)}"><span class="dot"></span>${this.trendText(item.trend)} ggü. ${trendRef}</div>

				<div class="compare-label" style="margin-bottom:6px">${esc(item.current_label)}${coverage}</div>
				<div class="compare-row">
					<div class="compare-item">
						<div class="compare-label">${valueLabel}</div>
						<div class="compare-value">${EM.fmt(item.estimate)} ${esc(item.unit)}</div>
						${item.cost_estimate !== null ? `<div class="compare-value cost">${EM.fmtMoney(item.cost_estimate)}</div>` : ''}
					</div>
				</div>

				<div class="card-divider"></div>
				<div class="compare-label" style="margin-bottom:6px">Vergleich (${granLabel})</div>
				<div class="compare-row">${compareHtml}</div>
				${this.weatherAdjustedHtml(item)}
				${this.advanceHtml(item)}
			</div>`;
	}

	weatherAdjustedHtml(item) {
		const w = item.weather_adjusted;
		if (!w) return '';
		const sign = w.change_pct > 0 ? '+' : '';
		const cls = w.trend === 'good' ? 'good' : w.trend === 'bad' ? 'bad' : '';
		return `<div class="weather-adj" title="Verbrauch je Gradtag (VDI 3807), bisheriger Verlauf der Periode ggü. gleichem Zeitabschnitt im Vorjahr">
			🌡️ Witterungsbereinigt: <strong class="${cls}">${sign}${EM.fmt(w.change_pct)} %</strong> ggü. Vorjahr
			(${EM.fmt(w.specific, 2)} vs. ${EM.fmt(w.specific_prev, 2)} ${EM.escape(w.unit)}; ${w.gt} vs. ${w.gt_prev} Gt)
		</div>`;
	}

	advanceHtml(item) {
		const a = item.advance;
		if (!a) return '';
		const nachzahlung = a.balance < 0;
		const status = Math.abs(a.balance) < 1 ? 'ausgeglichen'
			: `${nachzahlung ? 'Nachzahlung' : 'Guthaben'} ≈ <strong class="${nachzahlung ? 'bad' : 'good'}">${EM.fmtMoney(Math.abs(a.balance))}</strong>`;

		// geplante Änderung des Abschlags im Abrechnungsjahr ("ab 01.07.: 120 €")
		const next = a.advance_next ? ` (ab ${EM.fmtDate(a.advance_next.from)}: ${EM.fmtMoney(a.advance_next.amount)})` : '';
		// Empfehlung für die restlichen Zahlungen, nur wenn sie vom geplanten Abschlag abweicht
		const planned = a.advance_next ? a.advance_next.amount : a.advance_monthly;
		const hint = a.remaining_payments > 0 && Math.abs(a.suggested_monthly - planned) >= 1
			? ` · Empfehlung für die restl. ${a.remaining_payments} Zahlungen: ${EM.fmtMoney(a.suggested_monthly)}/Monat` : '';

		const changes = (a.advance_changes || []).map((c) => `ab ${EM.fmtDate(c.from)}: ${EM.fmtMoney(c.amount)}`).join('; ');
		const title = `Abrechnungsjahr ${EM.fmtDate(a.period_start)} – ${EM.fmtDate(a.period_end)}: `
			+ `Abschläge gesamt ${EM.fmtMoney(a.advance_total)} (bisher fällig ${EM.fmtMoney(a.advance_paid)}) gegen erwartete Kosten ${EM.fmtMoney(a.expected_cost)}`
			+ (changes ? `. Änderungen: ${changes}` : '');
		return `<div class="weather-adj" title="${EM.escape(title)}">
			💶 Abschlag ${EM.fmtMoney(a.advance_monthly)}/Monat${next}: ${status}${hint}${a.tariff_missing ? ' (Tarif unvollständig)' : ''}
		</div>`;
	}

	trendText(trend) {
		return { good: 'Weniger', bad: 'Mehr', neutral: 'Konstant' }[trend] || 'Konstant';
	}

	selectType(type) {
		this.state.selectedType = type;
		document.querySelectorAll('.card').forEach((c) => c.classList.toggle('selected', c.dataset.type === type));
		this.loadChart();
	}

	// ------------------------------------------------------------------
	// Chart (historische Verläufe)
	// ------------------------------------------------------------------
	/** Zeitraum-/Prognose-Bedienelemente passen zur gewählten Ansicht. */
	updateChartControls() {
		const years = this.state.chartView === 'years';
		const $ = (id) => document.getElementById(id);
		$('chartPeriod').classList.toggle('d-none', years);
		$('customRange').classList.toggle('visible', !years && this.state.chartPeriod === 'custom');
		$('forecastToggle').classList.toggle('d-none', years);
		$('cumulativeToggle').classList.toggle('d-none', !years);
	}

	async loadChart() {
		if (this.state.chartView === 'years') return this.loadYearComparison();

		const params = {
			type: this.state.selectedType,
			period: this.state.chartPeriod,
			mode: this.state.chartMode,
			forecast: this.state.showForecast ? '1' : '0',
		};
		if (this.state.chartPeriod === 'custom') {
			params.start = document.getElementById('dateStart').value;
			params.end = document.getElementById('dateEnd').value;
			if (!params.start || !params.end) return;
		}

		const seq = ++this.seq.chart;
		let data;
		try {
			data = await EM.api('get_chart_data', { params });
		} catch (e) {
			if (seq === this.seq.chart) EM.toast('Fehler beim Laden des Charts: ' + e.message);
			return;
		}
		if (seq !== this.seq.chart) return;

		this.lastChartData = data;
		this.renderChart(data);
	}

	async loadYearComparison() {
		const seq = ++this.seq.chart;
		let data;
		try {
			data = await EM.api('get_year_comparison', { params: {
				type: this.state.selectedType,
				mode: this.state.chartMode,
				cumulative: this.state.cumulative ? '1' : '0',
			} });
		} catch (e) {
			if (seq === this.seq.chart) EM.toast('Fehler beim Laden des Jahresvergleichs: ' + e.message);
			return;
		}
		if (seq !== this.seq.chart) return;

		this.lastChartData = data;
		this.renderYearComparison(data);
	}

	chartTheme() {
		const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
		return {
			isDark,
			gridColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)',
			textColor: isDark ? '#98989D' : '#86868B',
		};
	}

	renderYearComparison(data) {
		const ctx = document.getElementById('mainChart').getContext('2d');
		this.chart?.destroy();
		const { gridColor, textColor } = this.chartTheme();
		const main = data.color || '#0071E3';

		// aktuelles Jahr kräftig, ältere Jahre blasser und gestrichelt
		const n = data.series.length;
		const datasets = data.series.map((s, i) => {
			const age = n - 1 - i;
			const color = age === 0 ? main : this.hexToRgba(main, age === 1 ? 0.6 : 0.35);
			return {
				label: String(s.year),
				data: s.values,
				borderColor: color,
				backgroundColor: color,
				borderWidth: age === 0 ? 3 : 2,
				borderDash: age === 0 ? [] : [age === 1 ? 6 : 2, 4],
				pointRadius: age === 0 ? 3 : 0,
				pointHoverRadius: 5,
				tension: 0.3,
				spanGaps: false,
			};
		});

		this.chart = new Chart(ctx, {
			type: 'line',
			data: { labels: data.labels, datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: true, position: 'top', labels: { color: textColor, usePointStyle: true, boxWidth: 8 } },
					tooltip: { callbacks: { label: (c) => c.parsed.y === null ? null : `${c.dataset.label}: ${EM.fmt(c.parsed.y, 1)} ${data.unit}` } },
				},
				scales: {
					x: { grid: { display: false }, ticks: { color: textColor } },
					y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor }, title: { display: true, text: data.unit, color: textColor } },
				},
			},
		});
		document.getElementById('forecastNote').classList.add('d-none');
		if (!data.series.length) EM.toast('Für den Jahresvergleich liegen noch nicht genug Daten vor.');
	}

	renderChart(data) {
		const ctx = document.getElementById('mainChart').getContext('2d');
		this.chart?.destroy();

		const nActual = data.values.length;
		const nForecast = (data.forecast_values || []).length;
		const labels = [...data.labels, ...(data.forecast_labels || [])];

		const { isDark, gridColor, textColor } = this.chartTheme();
		const mainColor = data.color || '#0071E3';
		const pad = (n) => new Array(n).fill(null);

		const datasets = [{
			label: this.state.chartMode === 'cost' ? 'Kosten (€)' : `Verbrauch (${data.unit})`,
			data: [...data.values, ...pad(nForecast)],
			backgroundColor: mainColor,
			borderRadius: 5,
			maxBarThickness: 46,
			order: 2,
			yAxisID: 'y',
		}];

		if (nForecast > 0) {
			datasets.push({
				label: `Prognose (Ø pro ${data.forecast_per || 'Woche'})`,
				data: [...pad(nActual), ...data.forecast_values],
				backgroundColor: this.hexToRgba(mainColor, 0.28),
				borderColor: mainColor,
				borderWidth: 1.5,
				borderDash: [5, 4],
				borderRadius: 5,
				maxBarThickness: 46,
				order: 2,
				yAxisID: 'y',
			});
		}

		if (data.show_temp) {
			datasets.push({
				label: 'Ø Temperatur (°C)',
				data: [...data.temps, ...pad(nForecast)],
				type: 'line',
				borderColor: isDark ? '#98989D' : '#6E6E73',
				borderWidth: 2,
				pointRadius: 0,
				tension: 0.4,
				spanGaps: true,
				order: 1,
				yAxisID: 'y1',
			});
		}

		this.chart = new Chart(ctx, {
			type: 'bar',
			data: { labels, datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: true, position: 'top', labels: { color: textColor, usePointStyle: true, boxWidth: 8 } },
					tooltip: {
						callbacks: {
							label: (c) => c.parsed.y === null ? null : `${c.dataset.label}: ${EM.fmt(c.parsed.y, 2)}`,
						},
					},
				},
				scales: {
					x: { grid: { display: false }, ticks: { color: textColor, maxRotation: 0, autoSkip: true } },
					y: { position: 'left', beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
					y1: {
						display: !!data.show_temp,
						position: 'right',
						grid: { display: false },
						min: -10, max: 35,
						ticks: { color: textColor },
					},
				},
			},
		});

		document.getElementById('forecastNote').classList.toggle('d-none', nForecast === 0);
	}

	hexToRgba(hex, alpha) {
		const n = parseInt(hex.replace('#', ''), 16);
		return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
	}

	// ------------------------------------------------------------------
	// Wetter-Import
	// ------------------------------------------------------------------
	async importWeather() {
		const btn = document.getElementById('weatherBtn');
		btn.disabled = true;
		EM.toast('Wetterimport läuft…', 60000);
		try {
			const res = await EM.api('import_weather', { body: {} });
			EM.toast(res.message || 'Import abgeschlossen');
			this.loadChart();
		} catch (e) {
			EM.toast('Fehler beim Wetterimport: ' + e.message, 6000);
		} finally {
			btn.disabled = false;
		}
	}

	// ------------------------------------------------------------------
	// Modal: Zählerstand erfassen
	// ------------------------------------------------------------------
	/** Ohne $reading: neuen Zählerstand erfassen, sonst die übergebene Ablesung bearbeiten. */
	openModal(type, reading = null) {
		const now = new Date();
		const $ = (id) => document.getElementById(id);
		$('inputType').value = type;
		$('inputId').value = reading ? reading.id : '';
		$('modalReadingTitle').textContent = `${reading ? 'Bearbeiten' : 'Zählerstand'}: ${this.labels[type] || type}`;
		$('formAlert').classList.add('d-none');
		$('inputDate').value = reading ? reading.reading_date.slice(0, 10) : EM.localDate(now);
		$('inputTime').value = reading ? reading.reading_date.slice(11, 16) : EM.localTime(now);
		$('inputValue').value = reading ? EM.fmtFixed(reading.counter_value, 0, 3) : '';
		this.readingModal.open();
		$('inputValue').focus();
	}

	// ------------------------------------------------------------------
	// Jahresbericht (PDF)
	// ------------------------------------------------------------------
	async openReport() {
		this.reportModal.open();
		const sel = document.getElementById('reportYear');
		try {
			const { years } = await EM.api('get_report_years');
			sel.innerHTML = years.map((y) => `<option value="${y}">${y}</option>`).join('');
			// vorbelegt: letztes abgeschlossenes Jahr, falls vorhanden
			const lastDone = String(new Date().getFullYear() - 1);
			if (years.map(String).includes(lastDone)) sel.value = lastDone;
		} catch (e) {
			EM.toast('Fehler beim Laden der Jahre: ' + e.message);
		}
		this.updateReportLink();
	}

	updateReportLink() {
		const year = document.getElementById('reportYear').value;
		document.getElementById('reportLink').href = `report.php?year=${encodeURIComponent(year)}`;
	}

	// ------------------------------------------------------------------
	// Zählerstände verwalten (Liste, ändern, löschen)
	// ------------------------------------------------------------------
	openReadings(type) {
		const sel = document.getElementById('readingsType');
		sel.innerHTML = Object.entries(this.labels)
			.map(([t, label]) => `<option value="${EM.escape(t)}">${EM.escape(label)}</option>`).join('');
		sel.value = type;
		this.readingsModal.open();
		this.loadReadings(type, true);
	}

	async loadReadings(type, reset) {
		const pg = this.readingsPaging;
		if (reset) { pg.type = type; pg.offset = 0; document.getElementById('readingsBody').innerHTML = ''; }
		let res;
		try {
			res = await EM.api('get_readings', { params: { type, limit: pg.pageSize, offset: pg.offset } });
		} catch (e) {
			EM.toast('Fehler beim Laden: ' + e.message);
			return;
		}
		const unit = (this.units || {})[type] || '';
		const body = document.getElementById('readingsBody');
		this.readingRows = reset ? res.readings : [...(this.readingRows || []), ...res.readings];
		const rows = res.readings.map((r) => `
			<tr>
				<td>${EM.fmtDate(r.reading_date)} <span style="color:var(--text-secondary)">${EM.escape(r.reading_date.slice(11, 16))}</span></td>
				<td><strong>${EM.fmtFixed(r.counter_value, 0, 3)}</strong> ${EM.escape(unit)}</td>
				<td><div class="row-actions">
					<button data-action="edit" data-id="${r.id}">Bearbeiten</button>
					<button data-action="delete" data-id="${r.id}" class="danger">Löschen</button>
				</div></td>
			</tr>`).join('');
		if (reset && !res.readings.length) {
			body.innerHTML = '<tr><td colspan="3" style="text-align:center; color:var(--text-secondary); padding:24px">Noch keine Zählerstände.</td></tr>';
		} else {
			body.insertAdjacentHTML('beforeend', rows);
		}
		pg.offset += res.readings.length;
		document.getElementById('readingsMore').classList.toggle('d-none', pg.offset >= res.total);
	}

	editReading(id) {
		const reading = (this.readingRows || []).find((r) => String(r.id) === String(id));
		if (reading) this.openModal(reading.utility_type, reading);
	}

	async deleteReading(id) {
		const reading = (this.readingRows || []).find((r) => String(r.id) === String(id));
		const what = reading ? `${EM.fmtDate(reading.reading_date)}: ${EM.fmtFixed(reading.counter_value, 0, 3)}` : 'diesen Zählerstand';
		if (!confirm(`Zählerstand ${what} wirklich löschen?`)) return;
		try {
			await EM.api('delete_reading', { body: { id } });
			EM.toast('Gelöscht');
			this.afterReadingChange();
		} catch (e) {
			EM.toast('Fehler: ' + e.message, 6000);
		}
	}

	afterReadingChange() {
		this.loadDashboard();
		this.loadChart();
		if (document.getElementById('readingsModal').classList.contains('active')) {
			this.loadReadings(this.readingsPaging.type, true);
		}
	}

	async saveReading(e, force = false) {
		e.preventDefault();
		const form = document.getElementById('readingForm');
		const body = new FormData(form);
		if (force) body.set('force', '1');
		const alertBox = document.getElementById('formAlert');
		const submit = form.querySelector('[type="submit"]');
		submit.disabled = true;
		try {
			await EM.api('save_reading', { body });
			this.readingModal.close();
			EM.toast('Gespeichert');
			this.afterReadingChange();
		} catch (err) {
			if (err.needsConfirmation && confirm(err.message + '\n\nTrotzdem speichern (z.B. nach einem Zählerwechsel)?')) {
				submit.disabled = false;
				return this.saveReading(e, true);
			}
			alertBox.textContent = err.message;
			alertBox.classList.remove('d-none');
		} finally {
			submit.disabled = false;
		}
	}
}

document.addEventListener('DOMContentLoaded', () => {
	window.app = new EnergyApp();
});
