<?php
/**
 * WeatherImporter.php – DWD Tageswerte-Import (Klimastation "KL")
 * -----------------------------------------------------------------------
 * Ohne Stations-ID wird die nächstgelegene AKTIVE DWD-Klimastation zur in
 * config.php hinterlegten Position (lat/lon) ermittelt.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';

class WeatherImporter {

	private const BASE_URL = 'https://opendata.dwd.de/climate_environment/CDC/observations_germany/climate/daily/kl/';

	private PDO $pdo;
	private array $cfg;
	private array $log = [];
	private array $errors = [];

	public function __construct(?PDO $pdo = null) {
		$this->pdo = $pdo ?? Database::getConnection();
		$this->cfg = Config::get('weather');
	}

	public function run(?string $stationOverride = null, ?int $fromYear = null): array {
		@set_time_limit(180);
		$this->log = [];
		$this->errors = [];

		$station = preg_replace('/\D/', '', (string) $stationOverride) ?: ($this->cfg['dwd_station_id'] ?? null);
		if (!$station) {
			$station = $this->findNearestActiveStation((float) $this->cfg['lat'], (float) $this->cfg['lon']);
		}
		if (!$station) {
			return ['success' => false, 'imported' => 0, 'log' => $this->log, 'errors' => $this->errors,
				'error' => 'Keine DWD-Station gefunden. Bitte station-Parameter oder config.php->weather.dwd_station_id setzen.'];
		}
		$station = str_pad((string) $station, 5, '0', STR_PAD_LEFT);

		$fromYear = max(1990, $fromYear ?? (int) $this->cfg['import_from_year']);
		$toYear = (int) date('Y');

		$archives = ['recent' => self::BASE_URL . "recent/tageswerte_KL_{$station}_akt.zip"];
		if ($hist = $this->findHistoricalFilename($station)) {
			$archives['historical'] = self::BASE_URL . 'historical/' . $hist;
		}

		$upsert = $this->prepareUpsert();
		$imported = 0;
		foreach ($archives as $name => $url) {
			$this->log[] = "Lade {$name}: {$url}";
			$csv = $this->downloadProductFile($url, $name, $station);
			if ($csv === null) continue;

			$rows = self::parseProductFile($csv, $fromYear, $toYear);
			if ($rows === null) {
				$this->errors[] = "Unbekanntes CSV-Format in {$name}";
				continue;
			}
			$imported += $this->store($upsert, $rows, $name, $station);
		}

		return [
			'success'  => empty($this->errors) || $imported > 0,
			'imported' => $imported,
			'station'  => $station,
			'log'      => $this->log,
			'errors'   => $this->errors,
			'message'  => "$imported Tage importiert (Station $station)",
		];
	}

	// ------------------------------------------------------------------
	// Parser (rein, ohne I/O -> testbar)
	// ------------------------------------------------------------------

	/**
	 * @return array<int, array{0: string, 1: float, 2: ?float, 3: ?float}>|null
	 *         [Datum, Ø-Temp, Min, Max]; null bei unbekanntem Format.
	 */
	public static function parseProductFile(string $csv, int $fromYear, int $toYear): ?array {
		$lines = preg_split('/\r\n|\r|\n/', trim($csv));
		$headers = array_map('trim', explode(';', array_shift($lines) ?? ''));
		$iDate = array_search('MESS_DATUM', $headers, true);
		$iAvg = array_search('TMK', $headers, true);
		$iMin = array_search('TNK', $headers, true);
		$iMax = array_search('TXK', $headers, true);
		if ($iDate === false || $iAvg === false) return null;

		$num = static fn(?string $v): ?float => ($v === null || $v === '' || $v === '-999' || !is_numeric($v)) ? null : (float) $v;

		$rows = [];
		foreach ($lines as $line) {
			$cols = array_map('trim', explode(';', $line));
			$raw = $cols[$iDate] ?? '';
			if (strlen($raw) < 8) continue;
			$year = (int) substr($raw, 0, 4);
			if ($year < $fromYear || $year > $toYear) continue;
			$avg = $num($cols[$iAvg] ?? null);
			if ($avg === null) continue;
			$rows[] = [
				substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2),
				$avg,
				$iMin !== false ? $num($cols[$iMin] ?? null) : null,
				$iMax !== false ? $num($cols[$iMax] ?? null) : null,
			];
		}
		return $rows;
	}

	/**
	 * Zeilen der Stationsliste -> nächstgelegene Station, deren Daten
	 * mindestens bis $minEnd (Ymd) reichen.
	 * @return array{id: string, name: string, dist: float}|null
	 */
	public static function nearestStation(string $list, float $lat, float $lon, string $minEnd): ?array {
		$best = null;
		// Kopfzeilen überspringen (Stations_id von_datum bis_datum ... / ------)
		foreach (array_slice(preg_split('/\r\n|\r|\n/', trim($list)), 2) as $line) {
			if (!preg_match('/^(\d{5})\s+(\d{8})\s+(\d{8})\s+(-?\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(.+?)\s{2,}/', $line, $m)) continue;
			if ($m[3] < $minEnd) continue;
			$dist = self::haversineKm($lat, $lon, (float) $m[5], (float) $m[6]);
			if ($best === null || $dist < $best['dist']) {
				$best = ['id' => $m[1], 'name' => trim($m[7]), 'dist' => $dist];
			}
		}
		return $best;
	}

	public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);
		$a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
		return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
	}

	// ------------------------------------------------------------------
	// I/O
	// ------------------------------------------------------------------

	private function http(string $url, int $timeout): ?string {
		$ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
		$body = @file_get_contents($url, false, $ctx);
		$status = 0;
		if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
			$status = (int) $m[1];
		}
		return ($body !== false && $status === 200) ? $body : null;
	}

	private function findNearestActiveStation(float $lat, float $lon): ?string {
		$list = $this->http(self::BASE_URL . 'recent/KL_Tageswerte_Beschreibung_Stationen.txt', 20);
		if ($list === null) {
			$this->log[] = 'Stationsliste konnte nicht geladen werden.';
			return null;
		}
		$best = self::nearestStation($list, $lat, $lon, date('Ymd', strtotime('-90 days')));
		if (!$best) {
			$this->log[] = 'Keine passende aktive Station gefunden.';
			return null;
		}
		$this->log[] = sprintf('Nächste aktive Station automatisch ermittelt: %s (ID %s, %.1f km entfernt)', $best['name'], $best['id'], $best['dist']);
		return $best['id'];
	}

	/** Die historische Datei hat je Station einen individuellen Zeitraum im Namen. */
	private function findHistoricalFilename(string $station): ?string {
		$html = $this->http(self::BASE_URL . 'historical/', 20);
		if ($html !== null && preg_match('/tageswerte_KL_' . $station . '_\d{8}_\d{8}_hist\.zip/', $html, $m)) {
			return $m[0];
		}
		$this->log[] = "Keine historische Archivdatei für Station $station im Verzeichnis gefunden.";
		return null;
	}

	/** Lädt das ZIP und liefert den Inhalt der Datei produkt_klima_tag_*.txt. */
	private function downloadProductFile(string $url, string $name, string $station): ?string {
		$zipData = $this->http($url, 60);
		if ($zipData === null || strlen($zipData) < 100) {
			$this->errors[] = "Konnte {$name}-Archiv nicht laden (Station {$station} korrekt?)";
			return null;
		}
		$tmp = tempnam(sys_get_temp_dir(), 'dwd_');
		try {
			file_put_contents($tmp, $zipData);
			$za = new ZipArchive();
			if ($za->open($tmp) !== true) {
				$this->errors[] = "ZIP-Fehler bei {$name}";
				return null;
			}
			try {
				for ($i = 0; $i < $za->numFiles; $i++) {
					if (preg_match('#(^|/)produkt_klima_tag_.*\.txt$#', $za->getNameIndex($i))) {
						return $za->getFromIndex($i);
					}
				}
			} finally {
				$za->close();
			}
			$this->errors[] = "Keine Datendatei im {$name}-Archiv gefunden";
			return null;
		} finally {
			@unlink($tmp);
		}
	}

	private function prepareUpsert(): PDOStatement {
		$hasFull = (bool) $this->pdo->query(
			"SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weather_data' AND COLUMN_NAME = 'min_temp'"
		)->fetchColumn();

		return $this->pdo->prepare($hasFull
			? 'INSERT INTO weather_data (date, avg_temp, min_temp, max_temp, station) VALUES (?, ?, ?, ?, ?)
			   ON DUPLICATE KEY UPDATE avg_temp=VALUES(avg_temp), min_temp=VALUES(min_temp),
			     max_temp=VALUES(max_temp), station=VALUES(station)'
			: 'INSERT INTO weather_data (date, avg_temp) VALUES (?, ?)
			   ON DUPLICATE KEY UPDATE avg_temp=VALUES(avg_temp)'
		);
	}

	private function store(PDOStatement $upsert, array $rows, string $name, string $station): int {
		$full = $upsert->queryString !== null && str_contains($upsert->queryString, 'min_temp');
		$this->pdo->beginTransaction();
		try {
			foreach ($rows as [$date, $avg, $min, $max]) {
				$upsert->execute($full ? [$date, $avg, $min, $max, "DWD_{$station}"] : [$date, $avg]);
			}
			$this->pdo->commit();
			$this->log[] = "{$name}: " . count($rows) . ' Tage importiert';
			return count($rows);
		} catch (Throwable $e) {
			$this->pdo->rollBack();
			error_log("Wetterimport {$name}: " . $e->getMessage());
			$this->errors[] = "DB-Fehler {$name}";
			return 0;
		}
	}
}
