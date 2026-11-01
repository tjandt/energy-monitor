<?php
/**
 * weather_import.php – DWD-Wetterimport per Kommandozeile / Cronjob:
 *
 *   php weather_import.php [stations-id] [ab-jahr]
 *
 * Aus dem Browser läuft der Import über api.php?action=import_weather
 * (Button "Wetter Update"); dieses Skript ist absichtlich nur per CLI nutzbar.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('Nur per CLI aufrufbar (php weather_import.php).');
}

require_once __DIR__ . '/includes/WeatherImporter.php';

Config::all();
$result = (new WeatherImporter())->run($argv[1] ?? null, isset($argv[2]) ? (int) $argv[2] : null);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
exit($result['success'] ? 0 : 1);
