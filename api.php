<?php
/**
 * api.php – Einziger Einstiegspunkt für alle AJAX-Aufrufe des Frontends.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/EnergyService.php';

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone']);

function respond(array $data, int $code = 200): void {
	http_response_code($code);
	echo json_encode($data, JSON_UNESCAPED_UNICODE);
	exit;
}

function fail(string $message, int $code = 400): void {
	respond(['success' => false, 'error' => $message], $code);
}

try {
	$service = new EnergyService();
	$action = $_GET['action'] ?? '';
	$method = $_SERVER['REQUEST_METHOD'];

	switch ($action) {

		case 'get_dashboard':
			$granularity = $_GET['granularity'] ?? 'month';
			respond($service->getDashboard($granularity));
			break;

		case 'get_chart_data':
			$type = $_GET['type'] ?? '';
			$period = $_GET['period'] ?? 'year';
			$mode = $_GET['mode'] ?? 'usage';
			$start = $_GET['start'] ?? null;
			$end = $_GET['end'] ?? null;
			$withForecast = ($_GET['forecast'] ?? '') === '1';
			respond($service->getChartData($type, $period, $mode, $start, $end, $withForecast));
			break;

		case 'get_forecast':
			$type = $_GET['type'] ?? '';
			$months = isset($_GET['months']) ? (int) $_GET['months'] : null;
			respond($service->getLongTermForecast($type, $months));
			break;

		case 'save_reading':
			if ($method !== 'POST') fail('Nur POST erlaubt', 405);
			$service->saveReading(
				$_POST['type'] ?? '',
				$_POST['date'] ?? '',
				$_POST['time'] ?? '',
				$_POST['value'] ?? ''
			);
			respond(['success' => true]);
			break;

		case 'get_tariffs':
			respond(['tariffs' => $service->getTariffs()]);
			break;

		case 'save_tariff':
			if ($method !== 'POST') fail('Nur POST erlaubt', 405);
			$service->saveTariff($_POST);
			respond(['success' => true]);
			break;

		case 'delete_tariff':
			if ($method !== 'POST') fail('Nur POST erlaubt', 405);
			$id = (int) ($_POST['id'] ?? 0);
			if (!$id) fail('Ungültige ID');
			$service->deleteTariff($id);
			respond(['success' => true]);
			break;

		case 'import_weather':
			// Delegiert an das dedizierte DWD-Import-Skript, damit dessen
			// (potenziell langlaufende) Logik unabhängig testbar bleibt.
			ob_start();
			$_GET['inline'] = '1';
			require __DIR__ . '/weather_import.php';
			$output = ob_get_clean();
			echo $output;
			exit;

		default:
			fail('Unbekannte Aktion: ' . $action, 404);
	}
} catch (InvalidArgumentException $e) {
	fail($e->getMessage(), 400);
} catch (Throwable $e) {
	error_log('API-Fehler: ' . $e->getMessage());
	fail('Serverfehler: ' . $e->getMessage(), 500);
}
