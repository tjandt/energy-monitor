<?php
// report.php – Jahresbericht als PDF:  report.php?year=2025
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/EnergyService.php';
require_once __DIR__ . '/includes/YearReportPdf.php';

Config::all();
Auth::requirePage();

try {
	$year = (int) ($_GET['year'] ?? date('Y'));
	$pdf = new YearReportPdf((new EnergyService())->getYearReport($year));
	header('Cache-Control: no-store');
	$pdf->Output('I', "Energiebericht_$year.pdf");
} catch (InvalidArgumentException $e) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=utf-8');
	echo $e->getMessage();
} catch (Throwable $e) {
	error_log('Bericht-Fehler: ' . $e);
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Der Bericht konnte nicht erstellt werden (Details im PHP-Error-Log).';
}
