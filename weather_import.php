<?php
/**
 * weather_import.php – DWD Tageswerte-Import (Klimastation "KL")
 * -----------------------------------------------------------------------
 * Kann sowohl direkt aufgerufen werden (z.B. per Cronjob:
 *   php weather_import.php
 * oder im Browser: weather_import.php?station=02444&from_year=2015)
 * als auch intern von api.php (?action=import_weather) inkludiert werden.
 *
 * NEU gegenüber der ursprünglichen Version:
 * Wird keine Stations-ID übergeben und ist auch in config.php keine
 * fest hinterlegt, sucht das Skript automatisch die nächstgelegene AKTIVE
 * DWD-Klimastation zur konfigurierten Position (lat/lon), statt eine
 * möglicherweise falsche/veraltete Stations-ID zu raten.
 */

if (!defined('EM_STANDALONE_GUARD')) {
	define('EM_STANDALONE_GUARD', true);
	// Nur wenn direkt aufgerufen (nicht von api.php inkludiert) Header setzen
	if (empty($_GET['inline'])) {
		header('Content-Type: application/json; charset=utf-8');
	}
}

$cfg = require __DIR__ . '/config.php';
date_default_timezone_set($cfg['app']['timezone']);

$log = [];
$errors = [];
$imported = 0;

try {
	$pdo = (function () {
		require_once __DIR__ . '/includes/Database.php';
		return Database::getConnection();
	})();
} catch (Throwable $e) {
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	if (empty($_GET['inline'])) exit;
	return;
}

// -------------------------------------------------------------------
// 1) Stations-ID bestimmen
// -------------------------------------------------------------------
$stationOverride = preg_replace('/\D/', '', $_GET['station'] ?? '');
$station = $stationOverride ?: ($cfg['weather']['dwd_station_id'] ?? null);

function findNearestActiveStation(float $lat, float $lon, array &$log): ?string {
	$listUrl = 'https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/daily/kl/recent/KL_Tageswerte_Beschreibung_Stationen.txt';
	$context = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
	$content = @file_get_contents($listUrl, false, $context);
	if (!$content) {
		$log[] = 'Stationsliste konnte nicht geladen werden.';
		return null;
	}

	$lines = preg_split('/\r\n|\r|\n/', trim($content));
	$best = null;
	$bestDist = null;

	// Kopfzeilen überspringen (Stations_id von_datum bis_datum ... / --------)
	foreach (array_slice($lines, 2) as $line) {
		if (strlen(trim($line)) < 40) continue;
		// Feste Spaltenbreiten laut DWD-Format, robust per Regex geparst:
		if (!preg_match('/^(\d{5})\s+(\d{8})\s+(\d{8})\s+(-?\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(.+?)\s{2,}/', $line, $m)) {
			continue;
		}
		[$_, $id, $von, $bis, $hoehe, $slat, $slon, $name] = $m;

		// Nur Stationen berücksichtigen, deren Daten bis vor Kurzem reichen
		$bisTs = DateTime::createFromFormat('Ymd', $bis);
		if (!$bisTs || $bisTs->getTimestamp() < strtotime('-90 days')) continue;

		$dist = haversineKm($lat, $lon, (float) $slat, (float) $slon);
		if ($bestDist === null || $dist < $bestDist) {
			$bestDist = $dist;
			$best = ['id' => $id, 'name' => trim($name), 'dist' => $dist];
		}
	}

	if ($best) {
		$log[] = sprintf('Nächste aktive Station automatisch ermittelt: %s (ID %s, %.1f km entfernt)', $best['name'], $best['id'], $best['dist']);
		return $best['id'];
	}
	$log[] = 'Keine passende aktive Station gefunden.';
	return null;
}

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
	$R = 6371;
	$dLat = deg2rad($lat2 - $lat1);
	$dLon = deg2rad($lon2 - $lon1);
	$a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
	return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

if (!$station) {
	$station = findNearestActiveStation((float) $cfg['weather']['lat'], (float) $cfg['weather']['lon'], $log);
	if (!$station) {
		echo json_encode(['success' => false, 'error' => 'Keine DWD-Station gefunden. Bitte station-Parameter oder config.php->weather.dwd_station_id setzen.', 'log' => $log]);
		if (empty($_GET['inline'])) exit;
		return;
	}
}
$station = str_pad($station, 5, '0', STR_PAD_LEFT);

$fromYear = max(1990, (int) ($_GET['from_year'] ?? $cfg['weather']['import_from_year']));
$toYear = (int) date('Y');

// -------------------------------------------------------------------
// 2) Schema prüfen (min_temp/max_temp/station optional)
// -------------------------------------------------------------------
$hasFull = (bool) $pdo->query(
	"SELECT COUNT(*) FROM information_schema.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weather_data' AND COLUMN_NAME = 'min_temp'"
)->fetchColumn();

$upsertSql = $hasFull
	? "INSERT INTO weather_data (date, avg_temp, min_temp, max_temp, station)
	   VALUES (?, ?, ?, ?, ?)
	   ON DUPLICATE KEY UPDATE avg_temp=VALUES(avg_temp), min_temp=VALUES(min_temp),
	     max_temp=VALUES(max_temp), station=VALUES(station)"
	: "INSERT INTO weather_data (date, avg_temp) VALUES (?, ?)
	   ON DUPLICATE KEY UPDATE avg_temp=VALUES(avg_temp)";
$stmt = $pdo->prepare($upsertSql);

// -------------------------------------------------------------------
// 3) Archive laden (recent + historical) und importieren
// -------------------------------------------------------------------
$baseUrl = 'https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/daily/kl/';

// Die historische Datei hat je Station einen individuellen Zeitraum im
// Dateinamen -> zuerst per Verzeichnislisting den echten Dateinamen finden.
function findHistoricalFilename(string $baseUrl, string $station, array &$log): ?string {
	$dirUrl = $baseUrl . 'historical/';
	$context = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
	$html = @file_get_contents($dirUrl, false, $context);
	if (!$html) return null;
	if (preg_match('/tageswerte_KL_' . $station . '_\d{8}_\d{8}_hist\.zip/', $html, $m)) {
		return $m[0];
	}
	$log[] = "Keine historische Archivdatei für Station $station im Verzeichnis gefunden.";
	return null;
}

$histFile = findHistoricalFilename($baseUrl, $station, $log);
$archives = [
	'recent' => $baseUrl . "recent/tageswerte_KL_{$station}_akt.zip",
];
if ($histFile) {
	$archives['historical'] = $baseUrl . 'historical/' . $histFile;
}

$tmpDir = sys_get_temp_dir() . '/dwd_' . $station . '_' . time();
@mkdir($tmpDir, 0700, true);

foreach ($archives as $archiveName => $url) {
	$log[] = "Lade {$archiveName}: {$url}";
	$context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
	$zipData = @file_get_contents($url, false, $context);

	if (!$zipData || strlen($zipData) < 100) {
		$errors[] = "Konnte {$archiveName}-Archiv nicht laden (Station {$station} korrekt?)";
		continue;
	}

	$zipFile = $tmpDir . "/{$archiveName}.zip";
	file_put_contents($zipFile, $zipData);

	$za = new ZipArchive();
	if ($za->open($zipFile) !== true) {
		$errors[] = "ZIP-Fehler bei {$archiveName}";
		continue;
	}
	$za->extractTo($tmpDir . "/{$archiveName}");
	$za->close();

	$dataFiles = glob($tmpDir . "/{$archiveName}/produkt_klima_tag_*.txt");
	if (!$dataFiles) {
		$errors[] = "Keine Datendatei im {$archiveName}-Archiv gefunden";
		continue;
	}

	$lines = file($dataFiles[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!$lines) { $errors[] = 'Leere Datendatei'; continue; }

	$headers = array_map('trim', explode(';', $lines[0]));
	$idxDate = array_search('MESS_DATUM', $headers);
	$idxTmk = array_search('TMK', $headers);
	$idxTnk = array_search('TNK', $headers);
	$idxTxk = array_search('TXK', $headers);

	if ($idxDate === false || $idxTmk === false) {
		$errors[] = "Unbekanntes CSV-Format in {$archiveName}";
		continue;
	}

	$count = 0;
	$pdo->beginTransaction();
	try {
		foreach (array_slice($lines, 1) as $line) {
			$cols = array_map('trim', explode(';', $line));
			if (count($cols) <= max($idxDate, $idxTmk)) continue;

			$rawDate = $cols[$idxDate] ?? '';
			if (strlen($rawDate) < 8) continue;
			$dateStr = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);
			$year = (int) substr($dateStr, 0, 4);
			if ($year < $fromYear || $year > $toYear) continue;

			$avgRaw = $cols[$idxTmk] ?? '-999';
			$avg = ($avgRaw === '-999' || trim($avgRaw) === '') ? null : (float) $avgRaw;
			if ($avg === null) continue;

			if ($hasFull) {
				$minRaw = $idxTnk !== false ? ($cols[$idxTnk] ?? '-999') : '-999';
				$maxRaw = $idxTxk !== false ? ($cols[$idxTxk] ?? '-999') : '-999';
				$min = ($minRaw === '-999' || trim($minRaw) === '') ? null : (float) $minRaw;
				$max = ($maxRaw === '-999' || trim($maxRaw) === '') ? null : (float) $maxRaw;
				$stmt->execute([$dateStr, $avg, $min, $max, "DWD_{$station}"]);
			} else {
				$stmt->execute([$dateStr, $avg]);
			}
			$count++;
		}
		$pdo->commit();
		$imported += $count;
		$log[] = "{$archiveName}: {$count} Tage importiert";
	} catch (Throwable $e) {
		$pdo->rollBack();
		$errors[] = "DB-Fehler {$archiveName}: " . $e->getMessage();
	}
}

// Aufräumen
array_map('unlink', glob($tmpDir . '/*/*.txt') ?: []);
array_map('unlink', glob($tmpDir . '/*.zip') ?: []);
array_map('rmdir', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);

$response = [
	'success'  => empty($errors) || $imported > 0,
	'imported' => $imported,
	'station'  => $station,
	'log'      => $log,
	'errors'   => $errors,
	'message'  => "$imported Tage importiert (Station $station)",
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
