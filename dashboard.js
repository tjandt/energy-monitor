// dashboard.js – Energy Monitor Frontend-Logik

class EnergyApp {
	constructor() {
		this.state = {
			selectedType: 'Strom',
			dashboardGranularity: 'month', // week | month | year
			chartPeriod: 'year',           // last_week | last_month | year | custom
			chartMode: 'usage',            // usage | cost
			showForecast: false,
			chart: null,
		};
		this.initTheme();
		this.bindStaticEvents();
		this.loadDashboard();
		this.loadChart();
	}

	// ------------------------------------------------------------------
	// Theme
	// ------------------------------------------------------------------
	initTheme() {
		const saved = localStorage.getItem('em-theme');
		const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
		const theme = saved || (prefersDark ? 'dark' : 'light');
		document.documentElement.setAttribute('data-theme', theme);
		this.updateThemeIcon(theme);
	}

	toggleTheme() {
		const current = document.documentElement.getAttribute('data-theme');
		const next = current === 'dark' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-theme', next);
		localStorage.setItem('em-theme', next);
		this.updateThemeIcon(next);
		this.loadChart(); // Chart-Farben neu zeichnen
	}

	updateThemeIcon(theme) {
		const el = document.getElementById('themeIcon');
		if (el) el.innerHTML = icon(theme === 'dark' ? 'sun' : 'moon');
	}

	// ------------------------------------------------------------------
	// Init / Events
	// ------------------------------------------------------------------
	bindStaticEvents() {
		document.getElementById('themeToggle')?.addEventListener('click', () => this.toggleTheme());
		document.getElementById('weatherBtn')?.addEventListener('click', () => this.importWeather());
		document.getElementById('addBtn')?.addEventListener('click', () => this.openModal(this.state.selectedType));
		document.getElementById('modalClose')?.addEventListener('click', () => this.closeModal());
		document.getElementById('modalCancel')?.addEventListener('click', () => this.closeModal());
		document.getElementById('readingForm')?.addEventListener('submit', (e) => this.saveReading(e));

		document.querySelectorAll('#dashGranularity button').forEach(btn => {
			btn.addEventListener('click', () => this.setDashboardGranularity(btn.dataset.value));
		});
		document.querySelectorAll('#chartPeriod button').forEach(btn => {
			btn.addEventListener('click', () => this.setChartPeriod(btn.dataset.value));
		});
		document.querySelectorAll('#chartMode button').forEach(btn => {
			btn.addEventListener('click', () => this.setChartMode(btn.dataset.value));
		});
		document.getElementById('goCustomRange')?.addEventListener('click', () => this.loadChart());
		document.getElementById('forecastCheckbox')?.addEventListener('change', (e) => {
			this.state.showForecast = e.target.checked;
			this.loadChart();
		});
	}

	async fetchAPI(action, params = {}) {
		const url = new URL('api.php', window.location.href);
		url.searchParams.set('action', action);
		for (const k in params) if (params[k] !== null && params[k] !== undefined) url.searchParams.set(k, params[k]);
		const res = await fetch(url);
		if (!res.ok) {
			const err = await res.json().catch(() => ({ error: 'Unbekannter Fehler' }));
			throw new Error(err.error || `HTTP ${res.status}`);
		}
		return res.json();
	}

	// ------------------------------------------------------------------
	// Dashboard (Kachel-Übersicht)
	// ------------------------------------------------------------------
	setDashboardGranularity(value) {
		this.state.dashboardGranularity = value;
		this.updateSegmented('dashGranularity', value);
		this.loadDashboard();
	}

	updateSegmented(groupId, value) {
		document.querySelectorAll(`#${groupId} button`).forEach(btn => {
			btn.classList.toggle('active', btn.dataset.value === value);
		});
	}

	async loadDashboard() {
		let data;
		try {
			data = await this.fetchAPI('get_dashboard', { granularity: this.state.dashboardGranularity });
		} catch (e) {
			this.showToast('Fehler beim Laden: ' + e.message);
			return;
		}

		const grid = document.getElementById('dashboard');
		grid.innerHTML = '';

		const granLabel = { week: 'Woche', month: 'Monat', year: 'Jahr' }[this.state.dashboardGranularity];

		for (const [type, item] of Object.entries(data)) {
			const isSelected = this.state.selectedType === type ? 'selected' : '';
			const staleClass = item.stale_warning ? 'stale' : '';
			const staleText = item.last_reading
				? `Zuletzt: ${this.fmtDate(item.last_reading.reading_date)}${item.stale_warning ? ` (${item.stale_days} Tage her ⚠️)` : ''}`
				: 'Noch keine Messwerte';

			const compareHtml = item.compare.map(c => `
				<div class="compare-item">
					<div class="compare-label">${c.label}</div>
					<div class="compare-value">${this.fmt(c.value)} ${item.unit}</div>
					${c.cost !== null ? `<div class="compare-value cost">${this.fmtMoney(c.cost)}</div>` : ''}
				</div>
			`).join('');

			const html = `
				<div class="card ${isSelected}" data-type="${type}">
					<button class="add-fab" data-action="add" data-type="${type}" title="Zählerstand erfassen">${icon('plus')}</button>
					<div class="card-top">
						<div>
							<div class="card-title">${item.label}</div>
							<div class="card-reading">${item.last_reading ? this.fmt(item.last_reading.counter_value) : '–'} <span class="unit">${item.unit}</span></div>
							<div class="card-date ${staleClass}">${staleText}</div>
						</div>
						<div class="icon-box" style="background:${item.color}">${icon(item.icon)}</div>
					</div>

					<div class="trend-pill trend-${item.trend}"><span class="dot"></span>${this.trendText(item.trend)} ggü. ${granLabel === 'Jahr' ? 'Vorjahr' : 'Vorjahres-' + granLabel}</div>

					<div class="compare-label" style="margin-bottom:6px">${item.current_label} (${Math.round(item.elapsed_fraction * 100)}% der Periode vergangen)</div>
					<div class="compare-row">
						<div class="compare-item">
							<div class="compare-label">Prognose</div>
							<div class="compare-value">${this.fmt(item.estimate)} ${item.unit}</div>
							${item.cost_estimate !== null ? `<div class="compare-value cost">${this.fmtMoney(item.cost_estimate)}</div>` : ''}
						</div>
					</div>

					<div class="card-divider"></div>
					<div class="compare-label" style="margin-bottom:6px">Vergleich (${granLabel})</div>
					<div class="compare-row">${compareHtml}</div>
				</div>
			`;
			grid.insertAdjacentHTML('beforeend', html);
		}

		grid.querySelectorAll('.card').forEach(card => {
			card.addEventListener('click', (e) => {
				if (e.target.closest('[data-action="add"]')) return;
				this.selectType(card.dataset.type);
			});
		});
		grid.querySelectorAll('[data-action="add"]').forEach(btn => {
			btn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.openModal(btn.dataset.type);
			});
		});
	}

	trendText(trend) {
		return { good: 'Weniger', bad: 'Mehr', neutral: 'Konstant' }[trend] || 'Konstant';
	}

	selectType(type) {
		this.state.selectedType = type;
		document.querySelectorAll('.card').forEach(c => c.classList.toggle('selected', c.dataset.type === type));
		this.loadChart();
	}

	// ------------------------------------------------------------------
	// Chart (historische Verläufe)
	// ------------------------------------------------------------------
	setChartPeriod(value) {
		this.state.chartPeriod = value;
		this.updateSegmented('chartPeriod', value);
		document.getElementById('customRange').classList.toggle('visible', value === 'custom');
		if (value !== 'custom') this.loadChart();
	}

	setChartMode(value) {
		this.state.chartMode = value;
		this.updateSegmented('chartMode', value);
		this.loadChart();
	}

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

		let data;
		try {
			data = await this.fetchAPI('get_chart_data', params);
		} catch (e) {
			this.showToast('Fehler beim Laden des Charts: ' + e.message);
			return;
		}

		this.renderChart(data);
	}

	renderChart(data) {
		const ctx = document.getElementById('mainChart').getContext('2d');
		if (this.state.chart) this.state.chart.destroy();

		const nActual = data.values.length;
		const nForecast = (data.forecast_values || []).length;
		const labels = [...data.labels, ...(data.forecast_labels || [])];

		const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
		const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
		const textColor = isDark ? '#98989D' : '#86868B';
		const mainColor = data.color || '#0071E3';

		const datasets = [{
			label: this.state.chartMode === 'cost' ? 'Kosten (€)' : `Verbrauch (${data.unit})`,
			data: [...data.values, ...new Array(nForecast).fill(null)],
			backgroundColor: mainColor,
			borderRadius: 5,
			maxBarThickness: 46,
			order: 2,
			yAxisID: 'y',
		}];

		if (nForecast > 0) {
			datasets.push({
				label: 'Prognose (nächste Monate)',
				data: [...new Array(nActual).fill(null), ...data.forecast_values],
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
				data: [...data.temps, ...new Array(nForecast).fill(null)],
				type: 'line',
				borderColor: isDark ? '#98989D' : '#6E6E73',
				borderWidth: 2,
				pointRadius: 0,
				tension: 0.4,
				order: 1,
				yAxisID: 'y1',
			});
		}

		this.state.chart = new Chart(ctx, {
			type: 'bar',
			data: { labels, datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: true, position: 'top', labels: { color: textColor, usePointStyle: true, boxWidth: 8 } },
				},
				scales: {
					x: { grid: { display: false }, ticks: { color: textColor, maxRotation: 0, autoSkip: true } },
					y: { position: 'left', grid: { color: gridColor }, ticks: { color: textColor } },
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
		const m = hex.replace('#', '');
		const bigint = parseInt(m, 16);
		const r = (bigint >> 16) & 255, g = (bigint >> 8) & 255, b = bigint & 255;
		return `rgba(${r},${g},${b},${alpha})`;
	}

	// ------------------------------------------------------------------
	// Wetter-Import
	// ------------------------------------------------------------------
	async importWeather() {
		this.showToast('Wetterimport läuft…');
		try {
			const res = await this.fetchAPI('import_weather');
			this.showToast(res.message || (res.success ? 'Import abgeschlossen' : 'Import fehlgeschlagen'));
			this.loadChart();
		} catch (e) {
			this.showToast('Fehler beim Wetterimport: ' + e.message);
		}
	}

	// ------------------------------------------------------------------
	// Modal: Zählerstand erfassen
	// ------------------------------------------------------------------
	openModal(type) {
		const modal = document.getElementById('readingModal');
		modal.classList.add('active');
		document.getElementById('inputType').value = type;
		document.getElementById('modalReadingTitle').textContent = `Zählerstand: ${type}`;
		document.getElementById('formAlert').classList.add('d-none');

		const now = new Date();
		document.getElementById('inputDate').value = now.toISOString().split('T')[0];
		document.getElementById('inputTime').value = now.toTimeString().slice(0, 5);
		document.getElementById('inputValue').value = '';
		document.getElementById('inputValue').focus();
	}

	closeModal() {
		document.getElementById('readingModal').classList.remove('active');
	}

	async saveReading(e) {
		e.preventDefault();
		const fd = new FormData(e.target);
		const alertBox = document.getElementById('formAlert');
		try {
			const res = await fetch('api.php?action=save_reading', { method: 'POST', body: fd });
			const json = await res.json();
			if (!res.ok || json.error) throw new Error(json.error || 'Speichern fehlgeschlagen');

			this.closeModal();
			this.showToast('Gespeichert');
			e.target.reset();
			this.loadDashboard();
			this.loadChart();
		} catch (err) {
			alertBox.textContent = err.message;
			alertBox.classList.remove('d-none');
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------
	showToast(msg) {
		const t = document.getElementById('toast');
		t.textContent = msg;
		t.classList.add('show');
		clearTimeout(this._toastTimer);
		this._toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
	}

	fmt(n) { return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 }).format(n ?? 0); }
	fmtMoney(n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n ?? 0); }
	fmtDate(d) { return new Date(d.replace(' ', 'T')).toLocaleDateString('de-DE'); }
}

document.addEventListener('DOMContentLoaded', () => {
	window.app = new EnergyApp();
});
