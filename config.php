<?php
/**
 * Energy Monitor – Zentrale Konfiguration
 * -----------------------------------------------------------------------
 * Diese Datei bündelt alle Einstellungen der App. Bitte vor dem Deployment
 * die Werte im Abschnitt "db" und ggf. "weather" an die eigene Umgebung
 * anpassen.
 *
 * SICHERHEITSHINWEIS: Diese Datei enthält Zugangsdaten im Klartext.
 * Stelle sicher, dass sie NICHT über den Webserver direkt aufrufbar ist
 * (z.B. per .htaccess "Deny from all" oder außerhalb des Document-Root).
 * Da die App laut Anforderung nur im internen Heimnetz läuft, wurde bewusst
 * auf ein Login verzichtet – ein Basisschutz auf Webserver-Ebene
 * (z.B. per Reverse-Proxy/Firewall-Regel) wird trotzdem empfohlen.
 */

return [
	'db' => [
		'host'    => '192.168.178.53',
		'port'    => 3306,
		'dbname'  => 'verbrauchsdaten',
		'user'    => 'xbmc',
		'pass'    => 'xbmc',
		'charset' => 'utf8mb4',
	],

	'app' => [
		'timezone'        => 'Europe/Berlin',
		// Warnung im Dashboard, wenn der letzte Zählerstand älter ist als X Tage
		'warn_stale_days' => 5,
		// Toleranz für die Trend-Anzeige (gut/schlecht/neutral), z.B. 0.05 = 5%
		'trend_tolerance' => 0.05,
		// Wie viele Vorjahre für die saisonale Schätzung der laufenden Periode
		// herangezogen werden (siehe EnergyService::getSeasonalAverage)
		'forecast_years_seasonal' => 3,
		// Horizont der Langzeit-Prognose (Monate)
		'forecast_months_ahead' => 6,
		// Wachstumsrate bei der Langzeit-Prognose wird auf +/- diesen Wert
		// begrenzt, um Ausreißer/Datenfehler nicht zu extrapolieren
		'growth_rate_clamp' => 0.30,
	],

	// Nur noch diese vier Zähler werden unterstützt (Gas wurde entfernt)
	'utilities' => [
		'Strom' => [
			'label'      => 'Strom',
			'unit'       => 'kWh',
			'is_heating' => false, // für Wetter-Overlay im Chart relevant
			'color'      => '#FF9500',
			'icon'       => 'bolt',
		],
		'Wasser' => [
			'label'      => 'Kaltwasser',
			'unit'       => 'm³',
			'is_heating' => false,
			'color'      => '#0071E3',
			'icon'       => 'drop',
		],
		'Warmwasser' => [
			'label'      => 'Warmwasser',
			'unit'       => 'm³',
			'is_heating' => true,
			'color'      => '#FF3B30',
			'icon'       => 'shower',
		],
		'Waerme' => [
			'label'      => 'Wärme',
			'unit'       => 'kWh',
			'is_heating' => true,
			'color'      => '#AF52DE',
			'icon'       => 'flame',
		],
	],

	'weather' => [
		// Standort für die Wetterdaten (Melsungen)
		'lat' => 51.08,
		'lon' => 9.55,
		// Wenn leer/null: die nächstgelegene AKTIVE DWD-Klimastation wird
		// automatisch anhand von lat/lon ermittelt (siehe weather_import.php).
		// Kann hier fest gesetzt werden, um die Auto-Suche zu überspringen,
		// z.B. '02444' – ID vorher über die Stationssuche bestätigen!
		'dwd_station_id' => null,
		'import_from_year' => 2015,
	],
];
