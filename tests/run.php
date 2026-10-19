<?php
/**
 * Einfache Tests ohne Framework:  php tests/run.php
 * Nutzt eine In-Memory-SQLite-Datenbank, benötigt keine MySQL-Instanz.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/EnergyService.php';
require_once __DIR__ . '/../includes/WeatherImporter.php';

$config = require __DIR__ . '/../config.php';
Config::override($config);

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void {
	global $passed, $failed;
	if ($ok) { $passed++; return; }
	$failed++;
	echo "FAIL: $name" . ($detail !== '' ? " – $detail" : '') . PHP_EOL;
}
function near(string $name, float $actual, float $expected, float $eps = 0.01): void {
	check($name, abs($actual - $expected) <= $eps, "erwartet $expected, erhalten $actual");
}
function throwsA(string $name, string $class, callable $fn): void {
	try { $fn(); check($name, false, 'keine Exception'); }
	catch (Throwable $e) { check($name, $e instanceof $class, get_class($e) . ': ' . $e->getMessage()); }
}

function newDb(): PDO {
	$pdo = new PDO('sqlite::memory:', null, null, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);
	$pdo->exec('CREATE TABLE meter_readings (id INTEGER PRIMARY KEY AUTOINCREMENT, utility_type TEXT, reading_date TEXT, counter_value REAL)');
	$pdo->exec('CREATE TABLE tariffs (id INTEGER PRIMARY KEY AUTOINCREMENT, utility_type TEXT, valid_from TEXT, valid_to TEXT, price_per_unit REAL, base_price_monthly REAL)');
	$pdo->exec('CREATE TABLE weather_data (date TEXT PRIMARY KEY, avg_temp REAL)');
	return $pdo;
}
function seed(PDO $pdo, string $type, array $rows): void {
	$st = $pdo->prepare('INSERT INTO meter_readings (utility_type, reading_date, counter_value) VALUES (?,?,?)');
	foreach ($rows as [$d, $v]) $st->execute([$type, $d, $v]);
}

// ---------------------------------------------------------------- Num::parse
near('Num 1.234,5', Num::parse('1.234,5'), 1234.5);
near('Num 1234.5', Num::parse('1234.5'), 1234.5);
near('Num 12.5', Num::parse('12.5'), 12.5);
near('Num 1.234 = Tausender', Num::parse('1.234'), 1234);
near('Num 0.123', Num::parse('0.123'), 0.123);
near('Num 1.234.567', Num::parse('1.234.567'), 1234567);
near('Num 17,25', Num::parse('17,25'), 17.25);
check('Num abc', Num::parse('abc') === null);
check('Num leer', Num::parse('') === null);
check('Num 1,2,3', Num::parse('1,2,3') === null);

// ---------------------------------------------------------------- Period
[$s, $e] = Period::bounds('week', new DateTime('2025-01-01 10:00'));
check('KW-Start Montag', $s->format('Y-m-d') === '2024-12-30', $s->format('Y-m-d'));
check('Monat-Ende', Period::bounds('month', new DateTime('2025-12-15'))[1]->format('Y-m-d') === '2026-01-01');
check('Label Woche nutzt ISO-Jahr', Period::label('week', new DateTime('2025-01-01')) === 'KW 01/2025');

// ---------------------------------------------------------------- Interpolation
$pdo = newDb();
seed($pdo, 'Strom', [['2025-01-01 00:00:00', 1000], ['2025-01-11 00:00:00', 1100], ['2025-01-21 00:00:00', 1300]]);
$repo = new ReadingRepository($pdo);
$t = fn(string $d) => (new DateTime($d))->getTimestamp();
check('vor erster Ablesung = null', $repo->valueAt('Strom', $t('2024-12-31')) === null);
near('Mitte erstes Intervall', $repo->valueAt('Strom', $t('2025-01-06')), 50);
near('Verbrauch 6.–16.1.', $repo->consumption('Strom', $t('2025-01-06'), $t('2025-01-16')), 50 + 100, 0.01);
near('nach letzter Ablesung konstant', $repo->valueAt('Strom', $t('2025-03-01')), 300);

// Zählerwechsel / Rücksprung zählt als 0
$pdo = newDb();
seed($pdo, 'Strom', [['2025-01-01 00:00:00', 9000], ['2025-01-11 00:00:00', 9100], ['2025-01-21 00:00:00', 5], ['2025-01-31 00:00:00', 105]]);
$repo = new ReadingRepository($pdo);
near('Zählerwechsel: Gesamtverbrauch', $repo->consumption('Strom', $t('2025-01-01'), $t('2025-01-31')), 200);

// ---------------------------------------------------------------- Prognose
// Gleichmäßiger Verbrauch: 10 kWh/Tag über 3 Jahre, täglich abgelesen
$pdo = newDb();
$st = $pdo->prepare('INSERT INTO meter_readings (utility_type, reading_date, counter_value) VALUES (?,?,?)');
$d = new DateTime('2022-01-01 00:00:00');
$end = new DateTime('2025-06-15 12:00:00');
$v = 0;
$pdo->beginTransaction();
while ($d <= $end) { $st->execute(['Strom', $d->format('Y-m-d H:i:s'), $v]); $v += 10; $d->modify('+1 day'); }
$pdo->commit();
$svc = new EnergyService($pdo, $config);
$f = $svc->forecastCurrentPeriod('Strom', 'month', new DateTime('2025-06-15 12:00:00'));
near('Monatsprognose bei konstantem Verbrauch (30 Tage × 10)', $f['estimate'], 300, 3);
$f = $svc->forecastCurrentPeriod('Strom', 'year', new DateTime('2025-06-15 12:00:00'));
near('Jahresprognose bei konstantem Verbrauch', $f['estimate'], 3650, 40);

// Stark saisonal (Winter-Heizung) – lineares Hochrechnen würde massiv überschätzen
$pdo = newDb();
$st = $pdo->prepare('INSERT INTO meter_readings (utility_type, reading_date, counter_value) VALUES (?,?,?)');
$monthKwh = [1 => 3000, 2 => 2800, 3 => 2000, 4 => 1000, 5 => 400, 6 => 200, 7 => 150, 8 => 150, 9 => 400, 10 => 1000, 11 => 2000, 12 => 2800]; // 15.900/Jahr
$v = 0;
$pdo->beginTransaction();
foreach ([2022, 2023, 2024, 2025] as $y) {
	foreach ($monthKwh as $m => $kwh) {
		$st->execute(['Waerme', sprintf('%d-%02d-01 00:00:00', $y, $m), $v]);
		$v += $kwh;
		if ($y === 2025 && $m === 2) break 2;
	}
}
$st->execute(['Waerme', '2025-03-01 00:00:00', $v]);
$pdo->commit();
$svc = new EnergyService($pdo, $config);
$f = $svc->forecastCurrentPeriod('Waerme', 'year', new DateTime('2025-03-01 00:00:00'));
check('Jahresprognose Heizung ≈ Vorjahre (15.900), nicht linear 6× Q1', abs($f['estimate'] - 15900) < 600, (string) $f['estimate']);
check('linear wäre deutlich zu hoch', $f['linear_estimate'] > 30000, (string) $f['linear_estimate']);

// Langzeit-Prognose
$lt = $svc->getLongTermForecast('Waerme', 3);
check('Langzeit: 3 Monate', count($lt['forecast']) === 3, json_encode($lt));
check('Langzeit: erster Monat ist März', array_key_first($lt['forecast']) === '2025-03' || array_key_first($lt['forecast']) === '2025-04', (string) array_key_first($lt['forecast']));

$cf = $svc->getChartData('Waerme', 'custom', 'usage', '2025-01-01', '2025-03-02', true);
check('Chart: Prognose pro Woche skaliert (März 2000 kWh → ≈ 452/Woche)', $cf['forecast_per'] === 'Woche' && count($cf['forecast_values']) === 6 && abs($cf['forecast_values'][0] - 2000 * 7 / 31) < 1, json_encode($cf['forecast_values']));
check('Chart: Prognose-Labels deutsch', $cf['forecast_labels'][0] === 'Mär 2025', $cf['forecast_labels'][0] ?? '');

// ---------------------------------------------------------------- Chart
$pdo = newDb();
seed($pdo, 'Strom', [['2025-01-01 00:00:00', 0], ['2025-01-31 00:00:00', 300], ['2025-02-10 00:00:00', 400]]);
$svc = new EnergyService($pdo, $config);
$c = $svc->getChartData('Strom', 'custom', 'usage', '2025-01-01', '2025-01-10');
check('Chart: 10 Tagesbalken', count($c['labels']) === 10, (string) count($c['labels']));
near('Chart: 10 kWh/Tag', $c['values'][0], 10);
near('Chart: Summe 100', array_sum($c['values']), 100);
$c = $svc->getChartData('Strom', 'custom', 'usage', '2025-01-01', '2025-03-31');
check('Chart: Wochen-Gruppierung >60 Tage', $c['weekly'] === true);
near('Chart: Gesamtverbrauch bis letzte Ablesung', array_sum($c['values']), 400);
throwsA('Chart: Ende vor Start', InvalidArgumentException::class, fn() => $svc->getChartData('Strom', 'custom', 'usage', '2025-02-01', '2025-01-01'));
throwsA('Chart: ungültiger Typ', InvalidArgumentException::class, fn() => $svc->getChartData('Gas', 'year', 'usage', null, null));

// ---------------------------------------------------------------- Tarife & Kosten
$svc->saveTariff(['utility_type' => 'Strom', 'valid_from' => '2025-01-01', 'price_per_unit' => '30,5', 'base_price_monthly' => '1200']);
$c = $svc->getChartData('Strom', 'custom', 'cost', '2025-01-01', '2025-01-10');
near('Kosten/Tag = 10 kWh × 0,305 € + Grundgebühr anteilig', $c['values'][0], 3.05 + 12 / 30.44, 0.02);
throwsA('Tarif: bis vor von', InvalidArgumentException::class, fn() => $svc->saveTariff(['utility_type' => 'Strom', 'valid_from' => '2025-05-01', 'valid_to' => '2025-01-01', 'price_per_unit' => '1', 'base_price_monthly' => '0']));
throwsA('Tarif: negativer Preis', InvalidArgumentException::class, fn() => $svc->saveTariff(['utility_type' => 'Strom', 'valid_from' => '2025-05-01', 'price_per_unit' => '-1', 'base_price_monthly' => '0']));
throwsA('Tarif: Müll-Datum', InvalidArgumentException::class, fn() => $svc->saveTariff(['utility_type' => 'Strom', 'valid_from' => '2025-02-30', 'price_per_unit' => '1', 'base_price_monthly' => '0']));
$svc->saveTariff(['utility_type' => 'Strom', 'valid_from' => '2025-02-01', 'price_per_unit' => '40', 'base_price_monthly' => '0']);
check('Tarif: neuester gewinnt', count($svc->getTariffs()) === 2);
$c = $svc->getChartData('Strom', 'custom', 'cost', '2025-02-01', '2025-02-05');
near('Tarifwechsel greift', $c['values'][0], 4.0, 0.01);

// ---------------------------------------------------------------- saveReading
$pdo = newDb();
seed($pdo, 'Strom', [['2025-01-01 08:00:00', 1000], ['2025-03-01 08:00:00', 1200]]);
$svc = new EnergyService($pdo, $config);
$svc->saveReading('Strom', '2025-02-01', '08:00', '1.100,5');
$last = $pdo->query("SELECT counter_value FROM meter_readings WHERE reading_date = '2025-02-01 08:00:00'")->fetchColumn();
near('Speichern parst deutsches Format', (float) $last, 1100.5);
throwsA('Plausibilität: kleiner als vorher', ImplausibleReadingException::class, fn() => $svc->saveReading('Strom', '2025-02-15', '08:00', '900'));
throwsA('Plausibilität: größer als nachfolgender', ImplausibleReadingException::class, fn() => $svc->saveReading('Strom', '2025-02-15', '08:00', '5000'));
$svc->saveReading('Strom', '2025-02-15', '08:00', '900', true);
check('force speichert trotzdem', (int) $pdo->query('SELECT COUNT(*) FROM meter_readings')->fetchColumn() === 4);
throwsA('Duplikat', InvalidArgumentException::class, fn() => $svc->saveReading('Strom', '2025-02-01', '08:00', '1100', true));
throwsA('Zukunft', InvalidArgumentException::class, fn() => $svc->saveReading('Strom', date('Y-m-d', strtotime('+3 days')), '08:00', '99999'));
throwsA('Ungültiges Datum', InvalidArgumentException::class, fn() => $svc->saveReading('Strom', '2025-13-01', '08:00', '1'));
throwsA('Ungültige Zahl', InvalidArgumentException::class, fn() => $svc->saveReading('Strom', '2025-04-01', '08:00', 'abc'));
throwsA('Ungültiger Typ', InvalidArgumentException::class, fn() => $svc->saveReading('Gas', '2025-04-01', '08:00', '1'));
check('Cache nach Insert invalidiert', $svc->getLastReading('Strom')['reading_date'] === '2025-03-01 08:00:00');
$svc->saveReading('Strom', '2025-04-01', '08:00', '1300');
check('Neuer letzter Stand sichtbar', $svc->getLastReading('Strom')['reading_date'] === '2025-04-01 08:00:00');

// Dashboard ohne Daten darf nicht crashen
$svc = new EnergyService(newDb(), $config);
$dash = $svc->getDashboard('week');
check('Dashboard leer: 4 Zähler', count($dash) === 4);
check('Dashboard leer: Schätzung 0', $dash['Strom']['estimate'] == 0 && $dash['Strom']['last_reading'] === null);
throwsA('Dashboard: ungültige Granularität', InvalidArgumentException::class, fn() => $svc->getDashboard('day'));

// ---------------------------------------------------------------- Wetter-Parser
$csv = "STATIONS_ID;MESS_DATUM;QN_3;TMK;TNK;TXK;eor\n02444;20240101;10;3.5;1.0;6.2;eor\n02444;20240102;10;-999;-999;-999;eor\n02444;20100101;10;1.0;0;2;eor\n";
$rows = WeatherImporter::parseProductFile($csv, 2015, 2025);
check('Wetter: 1 gültige Zeile', $rows !== null && count($rows) === 1 && $rows[0][0] === '2024-01-01' && $rows[0][2] === 1.0, json_encode($rows));
check('Wetter: unbekanntes Format', WeatherImporter::parseProductFile("A;B\n1;2", 2015, 2025) === null);
$list = "Stations_id von_datum bis_datum Stationshoehe geoBreite geoLaenge Stationsname Bundesland\n----------- --------- --------- ------------- --------- --------- ----------------------------------------- ----------\n"
	. "00001 19370101 19860630            478     47.8413    8.8493 Aach                                      Baden-Württemberg\n"
	. "02444 20000101 20991231            230     51.0900    9.5400 Melsungen-Test                            Hessen\n"
	. "00002 19950901 20240101            230     51.0800    9.5500 Alt-Aktiv                                 Hessen\n";
$best = WeatherImporter::nearestStation($list, 51.08, 9.55, '20260101');
check('Wetter: nächste AKTIVE Station', $best !== null && $best['id'] === '02444', json_encode($best));

echo PHP_EOL . "$passed bestanden, $failed fehlgeschlagen" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
