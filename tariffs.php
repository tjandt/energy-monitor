<?php
// tariffs.php – reine Ansichtsseite (braucht keine DB), alle Datenoperationen laufen über api.php
require_once __DIR__ . '/includes/Auth.php';
Config::all();
Auth::requirePage();
$utilities = Config::get('utilities');
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Tarifverwaltung – Energy Monitor</title>
	<link rel="stylesheet" href="assets/css/style.css">
	<script>
		// Theme vor dem ersten Rendern setzen (verhindert Aufblitzen im falschen Design)
		try {
			document.documentElement.setAttribute('data-theme', localStorage.getItem('em-theme') ||
				(matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
		} catch (e) { /* Storage gesperrt */ }
	</script>
</head>
<body>

<div class="topbar">
	<div class="topbar-inner">
		<div class="brand">
			<svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 12.6 12 21.2 2.8 12 4 4l8-1.2z"/></svg>
			Tarifverwaltung
		</div>
		<div class="topbar-actions">
			<a href="index.php" class="icon-btn" id="backBtn" title="Zurück zum Dashboard"></a>
			<?php if (Auth::enabled()): ?>
			<form method="post" action="logout.php" style="margin:0"><button type="submit" class="btn btn-outline" title="Abmelden"><span id="logoutIcon"></span><span class="label">Abmelden</span></button></form>
			<?php endif; ?>
			<button id="themeToggle" class="icon-btn" title="Darstellung umschalten"><span id="themeIcon"></span></button>
			<button id="addTariffBtn" class="btn btn-primary"><span id="plusIcon"></span><span class="label">Neuer Tarif</span></button>
		</div>
	</div>
</div>

<div class="container">
	<div class="info-box">
		💡 Preise werden intern in <strong>Cent</strong> gespeichert (z.B. 3250 = 32,50&nbsp;€). Ein Tarif gilt ab
		„Gültig von“ bis „Gültig bis“ (leer = unbegrenzt). Bei mehreren gültigen Einträgen für denselben
		Zeitraum wird immer der zuletzt begonnene verwendet.
		Der <strong>Abschlag</strong> (in Euro) ist nur bei Strom möglich – Wasser und Wärme laufen über die Nebenkosten.
	</div>

	<div class="table-card">
		<table>
			<thead>
				<tr>
					<th>Typ</th>
					<th>Gültig von</th>
					<th>Gültig bis</th>
					<th>Preis / Einheit</th>
					<th>Grundgebühr / Monat</th>
					<th>Abschlag / Monat</th>
					<th>Status</th>
					<th></th>
				</tr>
			</thead>
			<tbody id="tariffTableBody">
				<tr><td colspan="8" style="text-align:center; color:var(--text-secondary); padding:30px">Lade…</td></tr>
			</tbody>
		</table>
	</div>
</div>

<!-- Modal: Tarif anlegen/bearbeiten -->
<div class="modal-overlay" id="tariffModal">
	<div class="modal-box">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px">
			<h2 class="modal-title" id="tariffModalTitle" style="margin:0">Neuer Tarif</h2>
			<button type="button" id="tariffModalClose" class="icon-btn" aria-label="Schließen"></button>
		</div>
		<div class="alert alert-error d-none" id="tariffAlert"></div>

		<form id="tariffForm">
			<input type="hidden" name="id" id="tariffId">

			<div class="field">
				<label>Zählertyp</label>
				<select name="utility_type" id="tariffType" class="input" required>
					<?php foreach ($utilities as $key => $meta): ?>
						<option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($meta['label']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="field-row">
				<div class="field">
					<label>Gültig von</label>
					<input type="date" name="valid_from" id="tariffValidFrom" class="input" required>
				</div>
				<div class="field">
					<label>Gültig bis (optional)</label>
					<input type="date" name="valid_to" id="tariffValidTo" class="input">
				</div>
			</div>

			<div class="field">
				<label>Preis pro Einheit (Cent)</label>
				<input type="number" step="0.01" name="price_per_unit" id="tariffPricePerUnit" class="input" placeholder="z.B. 32.50 für 0,325 €/kWh" required>
			</div>

			<div class="field">
				<label>Grundgebühr pro Monat (Cent)</label>
				<input type="number" step="0.01" name="base_price_monthly" id="tariffBasePrice" class="input" placeholder="z.B. 1200 für 12,00 €/Monat" required>
			</div>

			<div class="field d-none" id="advanceField">
				<label>Monatlicher Abschlag (€, optional)</label>
				<input type="number" step="0.01" min="0" name="advance_monthly" id="tariffAdvance" class="input" placeholder="z.B. 95 für 95,00 €/Monat">
			</div>

			<div class="modal-actions">
				<button type="submit" class="btn btn-primary btn-block">Speichern</button>
				<button type="button" id="tariffModalCancel" class="btn-text">Abbrechen</button>
			</div>
		</form>
	</div>
</div>

<div class="toast" id="toast"></div>

<script src="assets/js/icons.js"></script>
<script src="assets/js/common.js"></script>
<script src="assets/js/tariffs.js"></script>
<script>
	document.getElementById('plusIcon').innerHTML = icon('plus');
	document.getElementById('tariffModalClose').innerHTML = icon('close');
	document.getElementById('backBtn').innerHTML = icon('back');
	const lo = document.getElementById('logoutIcon'); if (lo) lo.innerHTML = icon('logout');
</script>
</body>
</html>
