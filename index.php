<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
	<title>Energy Monitor</title>
	<link rel="stylesheet" href="assets/css/style.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="topbar">
	<div class="topbar-inner">
		<div class="brand">
			<svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
			Energy Monitor
		</div>
		<div class="topbar-actions">
			<a href="tariffs.php" class="btn btn-outline"><span class="label">Tarife</span></a>
			<button id="weatherBtn" class="btn btn-outline" title="Wetterdaten aktualisieren"><span class="label">Wetter&nbsp;Update</span></button>
			<button id="addBtn" class="btn btn-primary"><span id="plusIcon"></span><span class="label">Zählerstand</span></button>
			<button id="themeToggle" class="icon-btn" title="Darstellung umschalten"><span id="themeIcon"></span></button>
		</div>
	</div>
</div>

<div class="container">

	<div class="controls-row">
		<h1 class="section-title" style="margin:0">Übersicht</h1>
		<div class="segmented" id="dashGranularity">
			<button data-value="week">Woche</button>
			<button data-value="month" class="active">Monat</button>
			<button data-value="year">Jahr</button>
		</div>
	</div>

	<div class="grid" id="dashboard">
		<div style="padding:40px; text-align:center; color:var(--text-secondary)">Lade Daten…</div>
	</div>

	<div class="chart-section">
		<h2 class="section-title">Verlauf &amp; Prognose</h2>

		<div class="chart-toolbar">
			<div class="segmented" id="chartPeriod">
				<button data-value="last_week">Woche</button>
				<button data-value="last_month">Monat</button>
				<button data-value="year" class="active">Jahr</button>
				<button data-value="custom">Benutzerdefiniert</button>
			</div>

			<div class="date-range" id="customRange">
				<input type="date" id="dateStart" class="date-input">
				<span style="color:var(--text-secondary)">–</span>
				<input type="date" id="dateEnd" class="date-input">
				<button id="goCustomRange" class="btn btn-primary" style="padding:6px 14px">Los</button>
			</div>

			<div class="spacer"></div>

			<label class="forecast-toggle">
				<input type="checkbox" id="forecastCheckbox">
				Prognose (6 Monate)
			</label>

			<div class="segmented" id="chartMode">
				<button data-value="usage" class="active">Verbrauch</button>
				<button data-value="cost">Kosten</button>
			</div>
		</div>

		<div class="chart-wrap">
			<canvas id="mainChart"></canvas>
		</div>

		<div class="forecast-note d-none" id="forecastNote">
			Die gestrichelten Balken zeigen eine <strong>Schätzung</strong> auf Basis des saisonalen Verbrauchsmusters
			der letzten Jahre und des jüngsten Verbrauchstrends – keine Garantie für den tatsächlichen Verbrauch.
		</div>
	</div>
</div>

<!-- Modal: Zählerstand erfassen -->
<div class="modal-overlay" id="readingModal">
	<div class="modal-box">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px">
			<h2 class="modal-title" id="modalReadingTitle" style="margin:0">Zählerstand erfassen</h2>
			<button type="button" id="modalClose" class="icon-btn" aria-label="Schließen"></button>
		</div>
		<div class="alert alert-error d-none" id="formAlert"></div>

		<form id="readingForm">
			<input type="hidden" name="type" id="inputType">

			<div class="field-row">
				<div class="field">
					<label>Datum</label>
					<input type="date" name="date" id="inputDate" class="input" required>
				</div>
				<div class="field">
					<label>Uhrzeit</label>
					<input type="time" name="time" id="inputTime" class="input" required>
				</div>
			</div>

			<div class="field">
				<label>Zählerstand</label>
				<input type="text" name="value" id="inputValue" class="input" placeholder="0,00" inputmode="decimal" required>
			</div>

			<div class="modal-actions">
				<button type="submit" class="btn btn-primary btn-block">Speichern</button>
				<button type="button" id="modalCancel" class="btn-text">Abbrechen</button>
			</div>
		</form>
	</div>
</div>

<div class="toast" id="toast"></div>

<script src="assets/js/icons.js"></script>
<script src="assets/js/dashboard.js"></script>
<script>
	document.getElementById('plusIcon').innerHTML = icon('plus');
	document.getElementById('modalClose').innerHTML = icon('close');
</script>
</body>
</html>
