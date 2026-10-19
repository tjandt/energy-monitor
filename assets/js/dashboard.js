// dashboard.js – Energy Monitor Frontend-Logik (Übersicht, Chart, Zählerstand erfassen)

class EnergyApp {
	constructor() {
		this.state = {
			selectedType: 'Strom',
			dashboardGranularity: 'month', // week | month | year
			chartPeriod: 'year',           // last_week | last_month | year | custom
			chartMode: 'usage',            // usage | cost
			showForecast: false,
		};
		this.chart = null;
		this.lastChartData = null;
		this.labels = {};        // Typ -> Anzeigename (aus dem Dashboard)
		this.seq = { dashboard: 0, chart: 0 }; // verwirft veraltete Antworten bei schnellem Umschalten
		this.readingModal = EM.modal('readingModal');

		EM.initTheme(() => this.lastChartData && this.renderChart(this.lastChartData));
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
		$('goCustomRange').addEventListener('click', () => this.loadChart());
		$('forecastCheckbox').addEventListener('change', (e) => {
			this.state.showForecast = e.target.checked;
			this.loadChart();
		});

		this.bindSegmented('dashGranularity', (v) => { this.state.dashboardGranularity = v; this.loadDashboard(); });
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
	async loadDashboard() {
		const seq = ++this.seq.dashboard;
		let data;
		try {
			data = await EM.api('get_dashboard', { params: { granularity: this.state.dashboardGranularity } });
		} catch (e) {
			if (seq === this.seq.dashboard) EM.toast('Fehler beim Laden: ' + e.message);
			return;
		}
		if (seq !== this.seq.dashboard) return;

		const granLabel = { week: 'Woche', month: 'Monat', year: 'Jahr' }[this.state.dashboardGranularity];
		const grid = document.getElementById('dashboard');
		grid.innerHTML = Object.entries(data).map(([type, item]) => {
			this.labels[type] = item.label;
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
		const trendRef = granLabel === 'Jahr' ? 'Vorjahr' : 'Vorjahres-' + granLabel;

		return `
			<div class="card ${this.state.selectedType === type ? 'selected' : ''}" data-type="${esc(type)}" role="button" tabindex="0" aria-label="${esc(item.label)} im Verlauf anzeigen">
				<button class="add-fab" data-action="add" data-type="${esc(type)}" title="Zählerstand erfassen" aria-label="Zählerstand für ${esc(item.label)} erfassen">${icon('plus')}</button>
				<div class="card-top">
					<div>
						<div class="card-title">${esc(item.label)}</div>
						<div class="card-reading">${item.last_reading ? EM.fmt(item.last_reading.counter_value) : '–'} <span class="unit">${esc(item.unit)}</span></div>
						<div class="card-date ${item.stale_warning ? 'stale' : ''}">${staleText}</div>
					</div>
					<div class="icon-box" style="background:${esc(item.color)}">${icon(item.icon)}</div>
				</div>

				<div class="trend-pill trend-${esc(item.trend)}"><span class="dot"></span>${this.trendText(item.trend)} ggü. ${trendRef}</div>

				<div class="compare-label" style="margin-bottom:6px">${esc(item.current_label)} (${Math.round(item.elapsed_fraction * 100)}% der Periode erfasst)</div>
				<div class="compare-row">
					<div class="compare-item">
						<div class="compare-label">Prognose</div>
						<div class="compare-value">${EM.fmt(item.estimate)} ${esc(item.unit)}</div>
						${item.cost_estimate !== null ? `<div class="compare-value cost">${EM.fmtMoney(item.cost_estimate)}</div>` : ''}
					</div>
				</div>

				<div class="card-divider"></div>
				<div class="compare-label" style="margin-bottom:6px">Vergleich (${granLabel})</div>
				<div class="compare-row">${compareHtml}</div>
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
	async loadChart() {
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

	renderChart(data) {
		const ctx = document.getElementById('mainChart').getContext('2d');
		this.chart?.destroy();

		const nActual = data.values.length;
		const nForecast = (data.forecast_values || []).length;
		const labels = [...data.labels, ...(data.forecast_labels || [])];

		const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
		const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
		const textColor = isDark ? '#98989D' : '#86868B';
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
	openModal(type) {
		const now = new Date();
		document.getElementById('inputType').value = type;
		document.getElementById('modalReadingTitle').textContent = `Zählerstand: ${this.labels[type] || type}`;
		document.getElementById('formAlert').classList.add('d-none');
		document.getElementById('inputDate').value = EM.localDate(now);
		document.getElementById('inputTime').value = EM.localTime(now);
		document.getElementById('inputValue').value = '';
		this.readingModal.open();
		document.getElementById('inputValue').focus();
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
			this.loadDashboard();
			this.loadChart();
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
