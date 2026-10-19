<?php
/**
 * Energy Monitor – Zentrale Konfiguration
 * -----------------------------------------------------------------------
 * Diese Datei bündelt alle Einstellungen der App. Bitte vor dem Deployment
 * die Werte im Abschnitt "db" und ggf. "weather" an die eigene Umgebung
 * anpassen.
 *
 * Eigene Werte (v.a. Zugangsdaten) am besten in config.local.php ablegen:
 * Die Datei ist in .gitignore eingetragen und überschreibt rekursiv die
 * Werte hier, z.B.  <?php return ['db' => ['user' => '…', 'pass' => '…']];
 *
 * SICHERHEITSHINWEIS: Diese Datei enthält Zugangsdaten im Klartext.
 * Die mitgelieferte .htaccess sperrt sie für den direkten Aufruf (Apache 2.4);
 * bei nginx o.ä. bitte entsprechend selbst sperren. Die hier hinterlegten
 * Standard-Zugangsdaten sind Beispielwerte aus der Entwicklung – Passwort ändern!
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

	// Gradtage nach VDI 3807 (Gt20/15): je Tag mit Ø-Temperatur unter der
	// Heizgrenze zählt (Raumtemperatur − Ø-Temperatur). Wird für die
	// witterungsbereinigte Auswertung von Zählern mit 'degree_day_adjust' genutzt.
	'degree_days' => [
		'indoor_temp'  => 20,
		'heating_limit' => 15,
		// darunter ist der Zeitraum zu warm für eine aussagekräftige Bereinigung
		'min_degree_days' => 15,
		// mindestens dieser Anteil der Tage muss Wetterdaten haben
		'min_coverage' => 0.8,
	],

	// Optionaler Passwortschutz (Standard: aus, App läuft im Heimnetz).
	// Hash erzeugen:  php -r "echo password_hash('MeinPasswort', PASSWORD_DEFAULT), PHP_EOL;"
	// und in config.local.php ablegen: ['auth' => ['enabled' => true, 'password_hash' => '$2y$…']]
	'auth' => [
		'enabled'       => false,
		'password_hash' => '',
		'session_hours' => 24 * 30,
		// nach so vielen Fehlversuchen von einer IP wird für lockout_minutes gesperrt
		'max_attempts'    => 5,
		'lockout_minutes' => 10,
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
			'degree_day_adjust' => true, // witterungsbereinigter Vorjahresvergleich
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
