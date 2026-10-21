<?php
/**
 * api.php – Einziger Einstiegspunkt für alle AJAX-Aufrufe des Frontends.
 *
 * Schreibende Aktionen erfordern POST und den Header
 * "X-Requested-With: XMLHttpRequest" (einfacher CSRF-Schutz: Fremdseiten
 * können diesen Header nicht ohne CORS-Freigabe setzen).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/EnergyService.php';
require_once __DIR__ . '/includes/WeatherImporter.php';
require_once __DIR__ . '/includes/Auth.php';

function respond(array $data, int $code = 200): never {
	http_response_code($code);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function fail(string $message, int $code = 400, array $extra = []): never {
	respond(['success' => false, 'error' => $message] + $extra, $code);
}

/** Skalarer String-Parameter (Arrays o.ä. aus ?x[]=1 werden ignoriert). */
function param(array $source, string $key, ?string $default = null): ?string {
	return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default;
}

function requireWrite(): void {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Nur POST erlaubt', 405);
	if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') fail('Ungültige Anfrage', 403);
}

try {
	Config::all(); // setzt auch die Zeitzone
	Auth::requireApi();
	$service = new EnergyService();
	$action = param($_GET, 'action', '');

	switch ($action) {

		case 'get_dashboard':
			respond($service->getDashboard(param($_GET, 'granularity', 'month')));

		case 'get_chart_data':
			respond($service->getChartData(
				param($_GET, 'type', ''),
				param($_GET, 'period', 'year'),
				param($_GET, 'mode', 'usage'),
				param($_GET, 'start'),
				param($_GET, 'end'),
				param($_GET, 'forecast') === '1'
			));

		case 'get_forecast':
			$months = param($_GET, 'months');
			respond($service->getLongTermForecast(param($_GET, 'type', ''), $months !== null ? (int) $months : null));

		case 'get_year_comparison':
			respond($service->getYearComparison(
				param($_GET, 'type', ''),
				param($_GET, 'mode', 'usage'),
				param($_GET, 'cumulative') === '1'
			));

		case 'get_report_years':
			respond(['years' => $service->getReportYears()]);

		case 'get_readings':
			respond($service->getReadings(
				param($_GET, 'type', ''),
				(int) param($_GET, 'limit', '50'),
				(int) param($_GET, 'offset', '0')
			));

		case 'delete_reading':
			requireWrite();
			$id = (int) param($_POST, 'id', '0');
			if ($id <= 0) fail('Ungültige ID');
			$service->deleteReading($id);
			respond(['success' => true]);

		case 'save_reading':
			requireWrite();
			try {
				$id = (int) param($_POST, 'id', '0');
				$service->saveReading(
					param($_POST, 'type', ''),
					param($_POST, 'date', ''),
					param($_POST, 'time', ''),
					param($_POST, 'value', ''),
					param($_POST, 'force') === '1',
					$id > 0 ? $id : null
				);
			} catch (ImplausibleReadingException $e) {
				fail($e->getMessage(), 409, ['needs_confirmation' => true]);
			}
			respond(['success' => true]);

		case 'get_tariffs':
			respond(['tariffs' => $service->getTariffs(), 'utilities' => $service->getUtilities()]);

		case 'save_tariff':
			requireWrite();
			$service->saveTariff($_POST);
			respond(['success' => true]);

		case 'delete_tariff':
			requireWrite();
			$id = (int) param($_POST, 'id', '0');
			if ($id <= 0) fail('Ungültige ID');
			$service->deleteTariff($id);
			respond(['success' => true]);

		case 'import_weather':
			requireWrite();
			$result = (new WeatherImporter())->run(param($_POST, 'station'), isset($_POST['from_year']) ? (int) $_POST['from_year'] : null);
			respond($result, ($result['success'] ?? false) ? 200 : 502);

		default:
			fail('Unbekannte Aktion', 404);
	}
} catch (InvalidArgumentException $e) {
	fail($e->getMessage(), 400);
} catch (DatabaseUnavailableException $e) {
	fail($e->getMessage(), 503);
} catch (Throwable $e) {
	error_log('API-Fehler: ' . $e);
	fail('Serverfehler – Details stehen im PHP-Error-Log.', 500);
}
