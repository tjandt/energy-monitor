<?php
require_once __DIR__ . '/includes/Auth.php';
Config::all();
Auth::requirePage();
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Energy Monitor</title>
	<link rel="stylesheet" href="assets/css/style.css">
	<script>
		// Theme vor dem ersten Rendern setzen (verhindert Aufblitzen im falschen Design)
		try {
			document.documentElement.setAttribute('data-theme', localStorage.getItem('em-theme') ||
				(matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
		} catch (e) { /* Storage gesperrt */ }
	</script>
	<script src="assets/vendor/chart.umd.js"></script>
</head>
<body>

<div class="topbar">
	<div class="topbar-inner">
		<div class="brand">
			<svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
			Energy Monitor
		</div>
		<div class="topbar-actions">
			<a href="tariffs.php" class="btn btn-outline" title="Tarife verwalten"><span id="tagIcon"></span><span class="label">Tarife</span></a>
			<button id="reportBtn" class="btn btn-outline" title="Jahresbericht als PDF"><span id="reportIcon"></span><span class="label">Bericht</span></button>
			<button id="readingsBtn" class="btn btn-outline" title="Zählerstände ansehen, ändern, löschen"><span id="listIcon"></span><span class="label">Zählerstände</span></button>
			<button id="weatherBtn" class="btn btn-outline" title="Wetterdaten aktualisieren"><span id="cloudIcon"></span><span class="label">Wetter&nbsp;Update</span></button>
			<button id="addBtn" class="btn btn-primary"><span id="plusIcon"></span><span class="label">Zählerstand</span></button>
			<?php if (Auth::enabled()): ?>
			<form method="post" action="logout.php" style="margin:0"><button type="submit" class="btn btn-outline" title="Abmelden"><span id="logoutIcon"></span><span class="label">Abmelden</span></button></form>
			<?php endif; ?>
			<button id="themeToggle" class="icon-btn" title="Darstellung umschalten"><span id="themeIcon"></span></button>
		</div>
	</div>
</div>

<div class="container">

	<div class="controls-row">
		<h1 class="section-title" style="margin:0">Übersicht</h1>
		<div class="controls-right">
			<div class="period-nav" role="group" aria-label="Zeitraum wählen">
				<button type="button" id="periodPrev" class="icon-btn" title="Vorheriger Zeitraum" aria-label="Vorheriger Zeitraum"></button>
				<span id="periodLabel" class="period-label" aria-live="polite">–</span>
				<button type="button" id="periodNext" class="icon-btn" title="Nächster Zeitraum" aria-label="Nächster Zeitraum" disabled></button>
				<button type="button" id="periodToday" class="btn-text d-none" title="Zurück zum aktuellen Zeitraum">Aktuell</button>
			</div>
			<div class="segmented" id="dashGranularity">
				<button data-value="week">Woche</button>
				<button data-value="month" class="active">Monat</button>
				<button data-value="year">Jahr</button>
			</div>
		</div>
	</div>

	<div class="grid" id="dashboard">
		<div style="padding:40px; text-align:center; color:var(--text-secondary)">Lade Daten…</div>
	</div>

	<div class="chart-section">
		<h2 class="section-title">Verlauf &amp; Prognose</h2>

		<div class="chart-toolbar">
			<div class="segmented" id="chartView">
				<button data-value="timeline" class="active">Verlauf</button>
				<button data-value="years">Jahresvergleich</button>
			</div>

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

			<label class="forecast-toggle" id="forecastToggle">
				<input type="checkbox" id="forecastCheckbox">
				Prognose anzeigen
			</label>

			<label class="forecast-toggle d-none" id="cumulativeToggle">
				<input type="checkbox" id="cumulativeCheckbox">
				Kumuliert
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
			Die gestrichelten Balken zeigen eine <strong>Schätzung</strong> je künftigem Monat (als Ø pro Woche bzw. Tag,
			damit sie mit den Ist-Balken vergleichbar sind) auf Basis des saisonalen Verbrauchsmusters der letzten Jahre
			und des Niveaus der letzten 12 Monate – keine Garantie für den tatsächlichen Verbrauch.
		</div>
	</div>
</div>

<!-- Modal: Jahresbericht -->
<div class="modal-overlay" id="reportModal">
	<div class="modal-box">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px">
			<h2 class="modal-title" style="margin:0">Jahresbericht (PDF)</h2>
			<button type="button" id="reportClose" class="icon-btn" aria-label="Schließen"></button>
		</div>
		<p style="color:var(--text-secondary); font-size:14px; margin:0 0 16px">
			Verbrauch, Kosten und Vorjahresvergleich je Zähler als PDF. Nicht vollständig erfasste Monate werden gekennzeichnet.
		</p>
		<div class="field">
			<label for="reportYear">Jahr</label>
			<select id="reportYear" class="input"></select>
		</div>
		<div class="modal-actions">
			<a id="reportLink" class="btn btn-primary btn-block" href="report.php" target="_blank" rel="noopener">PDF erstellen</a>
		</div>
	</div>
</div>

<!-- Modal: Zählerstände verwalten -->
<div class="modal-overlay" id="readingsModal">
	<div class="modal-box wide">
		<div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:16px">
			<h2 class="modal-title" style="margin:0">Zählerstände</h2>
			<select id="readingsType" class="input" style="width:auto; flex:1; max-width:200px" aria-label="Zähler"></select>
			<button type="button" id="readingsClose" class="icon-btn" aria-label="Schließen"></button>
		</div>
		<div class="table-card" style="box-shadow:none; border:1px solid var(--separator)">
			<div class="modal-scroll">
				<table>
					<thead><tr><th>Datum</th><th>Zählerstand</th><th></th></tr></thead>
					<tbody id="readingsBody"><tr><td colspan="3" style="text-align:center; color:var(--text-secondary)">Lade…</td></tr></tbody>
				</table>
			</div>
		</div>
		<div class="modal-actions">
			<button type="button" id="readingsMore" class="btn btn-outline btn-block d-none">Mehr laden</button>
		</div>
	</div>
</div>

<!-- Modal: Zählerstand erfassen / bearbeiten (liegt über der Liste) -->
<div class="modal-overlay" id="readingModal">
	<div class="modal-box">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px">
			<h2 class="modal-title" id="modalReadingTitle" style="margin:0">Zählerstand erfassen</h2>
			<button type="button" id="modalClose" class="icon-btn" aria-label="Schließen"></button>
		</div>
		<div class="alert alert-error d-none" id="formAlert"></div>

		<form id="readingForm">
			<input type="hidden" name="type" id="inputType">
			<input type="hidden" name="id" id="inputId">

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
				<input type="text" name="value" id="inputValue" class="input" placeholder="z.B. 12.345,6" inputmode="decimal" autocomplete="off" required>
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
<script src="assets/js/common.js"></script>
<script src="assets/js/dashboard.js"></script>
<script>
	document.getElementById('plusIcon').innerHTML = icon('plus');
	document.getElementById('modalClose').innerHTML = icon('close');
	document.getElementById('readingsClose').innerHTML = icon('close');
	document.getElementById('listIcon').innerHTML = icon('list');
	document.getElementById('tagIcon').innerHTML = icon('tag');
	document.getElementById('cloudIcon').innerHTML = icon('cloud');
	document.getElementById('periodPrev').innerHTML = icon('back');
	document.getElementById('periodNext').innerHTML = icon('forward');
	document.getElementById('reportIcon').innerHTML = icon('file');
	document.getElementById('reportClose').innerHTML = icon('close');
	const lo = document.getElementById('logoutIcon'); if (lo) lo.innerHTML = icon('logout');
</script>
</body>
</html>
