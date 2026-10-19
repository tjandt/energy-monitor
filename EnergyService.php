<?php
/**
 * EnergyService.php
 * -----------------------------------------------------------------------
 * Zentrale Business-Logik für den Energy Monitor.
 *
 * Prognose-Ansatz (bewusst kein reines Lineares Hochrechnen):
 *
 *   1) LAUFENDE PERIODE (Woche/Monat/Jahr):
 *      Es wird eine gewichtete Mischung aus zwei Schätzern gebildet:
 *        - "linear":   bisheriger Verbrauch in der Periode, hochgerechnet
 *                       auf die volle Periodenlänge
 *        - "saisonal": Durchschnittsverbrauch derselben Periode
 *                       (gleicher Monat/gleiche Kalenderwoche) aus den
 *                       letzten N Vorjahren
 *      Gewichtet wird mit dem Anteil der bereits verstrichenen Zeit in der
 *      Periode: Am Anfang der Periode (wenig eigene Daten) überwiegt die
 *      saisonale Schätzung, gegen Ende überwiegt der tatsächliche Verlauf.
 *      Das vermeidet die Über-/Unterschätzung, die rein lineare
 *      Hochrechnung typischerweise am Anfang eines Zeitraums produziert.
 *
 *   2) LANGZEIT-PROGNOSE (3-6 Monate):
 *      Saisonales Muster (Ø-Verbrauch je Kalendermonat aus der Historie)
 *      wird mit einer aus den letzten 24 Monaten abgeleiteten Wachstums-/
 *      Trendrate kombiniert (geclampt, um Ausreißer nicht zu extrapolieren).
 */

require_once __DIR__ . '/Database.php';

class EnergyService {

	private PDO $pdo;
	private array $cfg;
	private array $utilities;

	public function __construct() {
		$this->pdo = Database::getConnection();
		$full = require __DIR__ . '/../config.php';
		$this->cfg = $full['app'];
		$this->utilities = $full['utilities'];
	}

	public function getUtilityKeys(): array {
		return array_keys($this->utilities);
	}

	public function getUtilityMeta(string $type): array {
		if (!isset($this->utilities[$type])) {
			throw new InvalidArgumentException("Unbekannter Zählertyp: $type");
		}
		return $this->utilities[$type];
	}

	private function assertValidType(string $type): void {
		if (!isset($this->utilities[$type])) {
			throw new InvalidArgumentException("Unbekannter Zählertyp: $type");
		}
	}

	private function assertValidGranularity(string $g): void {
		if (!in_array($g, ['week', 'month', 'year'], true)) {
			throw new InvalidArgumentException("Ungültige Granularität: $g");
		}
	}

	// =====================================================================
	// ÜBERSICHT / LETZTE ZÄHLERSTÄNDE
	// =====================================================================

	public function getLastReading(string $type): ?array {
		$this->assertValidType($type);
		$stmt = $this->pdo->prepare(
			'SELECT counter_value, reading_date FROM meter_readings
			 WHERE utility_type = ? ORDER BY reading_date DESC LIMIT 1'
		);
		$stmt->execute([$type]);
		$row = $stmt->fetch();
		return $row ?: null;
	}

	// =====================================================================
	// DASHBOARD: LAUFENDE PERIODE MIT VERGLEICH ZU VORJAHREN
	// =====================================================================

	/**
	 * Liefert für alle Utilities die Prognose der laufenden Periode
	 * (Woche/Monat/Jahr) inkl. Vergleich zu den letzten 2 Vorjahren,
	 * Kostenschätzung und Trend.
	 */
	public function getDashboard(string $granularity = 'month'): array {
		$this->assertValidGranularity($granularity);
		$result = [];
		foreach ($this->getUtilityKeys() as $type) {
			$result[$type] = $this->getPeriodComparison($type, $granularity);
		}
		return $result;
	}

	public function getPeriodComparison(string $type, string $granularity): array {
		$this->assertValidType($type);
		$this->assertValidGranularity($granularity);
		$now = new DateTime();
		$meta = $this->utilities[$type];

		$forecast = $this->forecastCurrentPeriod($type, $granularity, $now);
		$lastReading = $this->getLastReading($type);

		// Vergleichswerte: gleiche Periode vor 1 und vor 2 Jahren
		$compare = [];
		for ($i = 1; $i <= 2; $i++) {
			$refDate = (clone $now)->modify("-{$i} year");
			[$start, $end] = $this->periodBounds($granularity, $refDate);
			$val = $this->getConsumptionForPeriod($type, $start, $end);
			$compare[] = [
				'label' => $this->periodLabel($granularity, $refDate),
				'value' => round($val, 1),
				'cost'  => $this->estimateCost($type, $val, $refDate->format('Y-m-d'), $granularity),
			];
		}

		$trend = $this->calcTrend($forecast['estimate'], $compare[0]['value'] ?? 0);

		$staleDays = $lastReading
			? (int) floor((time() - strtotime($lastReading['reading_date'])) / 86400)
			: null;

		return [
			'label'          => $meta['label'],
			'unit'           => $meta['unit'],
			'icon'           => $meta['icon'],
			'color'          => $meta['color'],
			'last_reading'   => $lastReading,
			'stale_days'     => $staleDays,
			'stale_warning'  => $staleDays !== null && $staleDays >= $this->cfg['warn_stale_days'],
			'granularity'    => $granularity,
			'current_label'  => 'Prognose ' . $this->periodLabel($granularity, $now),
			'estimate'       => $forecast['estimate'],
			'elapsed_fraction' => $forecast['elapsed_fraction'],
			'seasonal_reference' => $forecast['seasonal_reference'],
			'compare'        => $compare,
			'trend'          => $trend,
			'cost_estimate'  => $this->estimateCost($type, $forecast['estimate'], $now->format('Y-m-d'), $granularity),
		];
	}

	// =====================================================================
	// PROGNOSE LAUFENDE PERIODE (Kern-Algorithmus)
	// =====================================================================

	public function forecastCurrentPeriod(string $type, string $granularity, ?DateTime $now = null): array {
		$this->assertValidType($type);
		$this->assertValidGranularity($granularity);
		$now = $now ?: new DateTime();

		[$periodStart, $periodEnd] = $this->periodBounds($granularity, $now);
		$elapsedFraction = $this->elapsedFraction($periodStart, $periodEnd, $now);

		$elapsedConsumption = $this->getConsumptionForPeriod($type, $periodStart, $now);
		$linearEstimate = $elapsedFraction > 0.02 ? ($elapsedConsumption / $elapsedFraction) : 0.0;

		$seasonalEstimate = $this->getSeasonalAverage($type, $granularity, $now);

		if ($seasonalEstimate === null) {
			// Keine historischen Vergleichsdaten vorhanden -> nur linear
			$blended = $linearEstimate;
		} elseif ($elapsedFraction <= 0.02) {
			// Periode hat gerade erst begonnen -> auf Saisonwert verlassen
			$blended = $seasonalEstimate;
		} else {
			$blended = $elapsedFraction * $linearEstimate + (1 - $elapsedFraction) * $seasonalEstimate;
		}

		return [
			'estimate'            => round($blended, 2),
			'elapsed_consumption' => round($elapsedConsumption, 2),
			'elapsed_fraction'    => round($elapsedFraction, 3),
			'linear_estimate'     => round($linearEstimate, 2),
			'seasonal_reference'  => $seasonalEstimate !== null ? round($seasonalEstimate, 2) : null,
		];
	}

	/**
	 * Durchschnittsverbrauch derselben Periode (gleicher Monat / gleiche
	 * ISO-Kalenderwoche) aus den letzten N Vorjahren (config: forecast_years_seasonal).
	 */
	private function getSeasonalAverage(string $type, string $granularity, DateTime $now): ?float {
		$years = (int) $this->cfg['forecast_years_seasonal'];
		$values = [];
		for ($i = 1; $i <= $years; $i++) {
			$refDate = (clone $now)->modify("-{$i} year");
			[$start, $end] = $this->periodBounds($granularity, $refDate);
			$val = $this->getConsumptionForPeriod($type, $start, $end);
			if ($val > 0) {
				$values[] = $val;
			}
		}
		if (empty($values)) {
			return null;
		}
		return array_sum($values) / count($values);
	}

	/**
	 * Start/Ende der Periode, die $ref enthält.
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function periodBounds(string $granularity, DateTime $ref): array {
		$ref = clone $ref;
		switch ($granularity) {
			case 'week':
				$start = (clone $ref)->setISODate((int) $ref->format('o'), (int) $ref->format('W'), 1)->setTime(0, 0, 0);
				$end = (clone $start)->modify('+7 days');
				break;
			case 'year':
				$start = new DateTime($ref->format('Y') . '-01-01 00:00:00');
				$end = (clone $start)->modify('+1 year');
				break;
			case 'month':
			default:
				$start = new DateTime($ref->format('Y-m') . '-01 00:00:00');
				$end = (clone $start)->modify('+1 month');
				break;
		}
		return [$start, $end];
	}

	private function elapsedFraction(DateTime $start, DateTime $end, DateTime $now): float {
		$total = $end->getTimestamp() - $start->getTimestamp();
		if ($total <= 0) return 0.0;
		$elapsed = $now->getTimestamp() - $start->getTimestamp();
		return max(0.0, min(1.0, $elapsed / $total));
	}

	private function periodLabel(string $granularity, DateTime $date): string {
		static $monate = [1=>'Jan',2=>'Feb',3=>'Mär',4=>'Apr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Dez'];
		switch ($granularity) {
			case 'week':
				return 'KW ' . $date->format('W') . '/' . $date->format('Y');
			case 'year':
				return $date->format('Y');
			case 'month':
			default:
				return $monate[(int) $date->format('n')] . ' ' . $date->format('Y');
		}
	}

	private function calcTrend(float $current, float $reference): string {
		if ($reference <= 0) return 'neutral';
		$diffPercent = ($current - $reference) / $reference;
		$tol = (float) $this->cfg['trend_tolerance'];
		if ($diffPercent > $tol) return 'bad';
		if ($diffPercent < -$tol) return 'good';
		return 'neutral';
	}

	/**
	 * Verbrauch zwischen zwei Zeitpunkten = (Zählerstand am Ende) - (Zählerstand am Anfang),
	 * jeweils der letzte bekannte Wert vor/an dem jeweiligen Zeitpunkt.
	 */
	private function getConsumptionForPeriod(string $type, DateTime $start, DateTime $end): float {
		$v1 = $this->getValueAtOrBefore($type, $start);
		$v2 = $this->getValueAtOrBefore($type, $end);
		if ($v1 === null || $v2 === null) return 0.0;
		return max(0.0, $v2 - $v1);
	}

	private function getValueAtOrBefore(string $type, DateTime $date): ?float {
		$stmt = $this->pdo->prepare(
			'SELECT counter_value FROM meter_readings
			 WHERE utility_type = ? AND reading_date <= ?
			 ORDER BY reading_date DESC LIMIT 1'
		);
		$stmt->execute([$type, $date->format('Y-m-d H:i:s')]);
		$val = $stmt->fetchColumn();
		return $val === false ? null : (float) $val;
	}

	// =====================================================================
	// LANGZEIT-PROGNOSE (3-6 Monate)
	// =====================================================================

	public function getLongTermForecast(string $type, ?int $monthsAhead = null): array {
		$this->assertValidType($type);
		$monthsAhead = $monthsAhead ?? (int) $this->cfg['forecast_months_ahead'];

		$monthly = $this->getMonthlyHistory($type);
		if (count($monthly) < 2) {
			return ['forecast' => [], 'growth_rate' => 0.0, 'basis_months' => count($monthly)];
		}

		ksort($monthly);
		$keys = array_keys($monthly);
		$values = array_values($monthly);
		$n = count($values);

		// Saisonales Muster: Ø-Verbrauch je Kalendermonat
		$seasonalSum = [];
		$seasonalCount = [];
		foreach ($monthly as $ym => $val) {
			$m = (int) substr($ym, 5, 2);
			$seasonalSum[$m] = ($seasonalSum[$m] ?? 0) + $val;
			$seasonalCount[$m] = ($seasonalCount[$m] ?? 0) + 1;
		}
		$seasonal = [];
		foreach ($seasonalSum as $m => $sum) {
			$seasonal[$m] = $sum / $seasonalCount[$m];
		}
		$overallAvg = array_sum($values) / $n;

		// Wachstumsrate aus den letzten 24 Monaten (bzw. gröber bei weniger Historie)
		$growth = 0.0;
		if ($n >= 24) {
			$recent12 = array_sum(array_slice($values, -12));
			$prior12 = array_sum(array_slice($values, -24, 12));
			if ($prior12 > 0) $growth = ($recent12 - $prior12) / $prior12;
		} elseif ($n >= 13) {
			$recentCount = min(6, intdiv($n, 2));
			$recent = array_slice($values, -$recentCount);
			$earlier = array_slice($values, 0, $recentCount);
			$earlierAvg = array_sum($earlier) / count($earlier);
			if ($earlierAvg > 0) {
				$recentAvg = array_sum($recent) / count($recent);
				$growth = ($recentAvg - $earlierAvg) / $earlierAvg;
			}
		}
		$clamp = (float) $this->cfg['growth_rate_clamp'];
		$growth = max(-$clamp, min($clamp, $growth));

		$lastKey = end($keys);
		$cursor = new DateTime($lastKey . '-01');
		$forecast = [];
		for ($i = 1; $i <= $monthsAhead; $i++) {
			$cursor->modify('+1 month');
			$m = (int) $cursor->format('n');
			$base = $seasonal[$m] ?? $overallAvg;
			$forecast[$cursor->format('Y-m')] = round(max(0, $base * (1 + $growth)), 2);
		}

		return [
			'forecast'     => $forecast,
			'growth_rate'  => round($growth * 100, 1),
			'basis_months' => $n,
		];
	}

	/**
	 * Monatlicher Verbrauch aus der kompletten Historie (Rohdaten-Diffs summiert
	 * je Kalendermonat). Der aktuelle, noch nicht abgeschlossene Monat wird
	 * ausgeschlossen, damit die Saisonwerte nicht verfälscht werden.
	 */
	private function getMonthlyHistory(string $type): array {
		$stmt = $this->pdo->prepare(
			'SELECT reading_date, counter_value FROM meter_readings
			 WHERE utility_type = ? ORDER BY reading_date ASC'
		);
		$stmt->execute([$type]);
		$rows = $stmt->fetchAll();

		$monthly = [];
		for ($i = 1; $i < count($rows); $i++) {
			$diff = max(0, (float) $rows[$i]['counter_value'] - (float) $rows[$i - 1]['counter_value']);
			$ym = date('Y-m', strtotime($rows[$i]['reading_date']));
			$monthly[$ym] = ($monthly[$ym] ?? 0) + $diff;
		}
		unset($monthly[date('Y-m')]);
		return $monthly;
	}

	// =====================================================================
	// HISTORISCHE CHART-DATEN (mit Zeitfiltern, Wetter-Overlay, Kosten)
	// =====================================================================

	public function getChartData(string $type, string $period, ?string $mode, ?string $start, ?string $end, bool $withForecast = false): array {
		$this->assertValidType($type);
		$mode = $mode === 'cost' ? 'cost' : 'usage';
		$allowedPeriods = ['last_week', 'last_month', 'year', 'custom'];
		if (!in_array($period, $allowedPeriods, true)) {
			throw new InvalidArgumentException('Ungültiger Zeitraum');
		}

		$now = new DateTime();
		$groupByWeek = false;

		if ($period === 'custom') {
			if (!$start || !$end || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
				throw new InvalidArgumentException('Ungültiger Zeitraum für "custom" (Format YYYY-MM-DD erforderlich)');
			}
			$rangeStart = new DateTime($start . ' 00:00:00');
			$rangeEnd = new DateTime($end . ' 23:59:59');
			$days = ($rangeEnd->getTimestamp() - $rangeStart->getTimestamp()) / 86400;
			$groupByWeek = $days > 60;
		} elseif ($period === 'last_week') {
			$rangeStart = (clone $now)->modify('-1 week');
			$rangeEnd = $now;
		} elseif ($period === 'last_month') {
			$rangeStart = (clone $now)->modify('-1 month');
			$rangeEnd = $now;
		} else { // year
			$rangeStart = new DateTime($now->format('Y') . '-01-01 00:00:00');
			$rangeEnd = $now;
			$groupByWeek = true;
		}

		// Rohdaten holen (mit Rand-Datenpunkt davor für korrekte erste Differenz)
		$stmt = $this->pdo->prepare(
			'SELECT reading_date, counter_value FROM meter_readings
			 WHERE utility_type = ? AND reading_date <= ?
			 ORDER BY reading_date ASC'
		);
		$stmt->execute([$type, $rangeEnd->format('Y-m-d H:i:s')]);
		$allRows = $stmt->fetchAll();

		// Nur Zeilen ab dem letzten Wert VOR rangeStart berücksichtigen, damit
		// die erste Differenz im Zeitraum korrekt berechnet werden kann.
		$rows = [];
		$prevBeforeRange = null;
		foreach ($allRows as $row) {
			if (strtotime($row['reading_date']) < $rangeStart->getTimestamp()) {
				$prevBeforeRange = $row;
			} else {
				if ($prevBeforeRange !== null && empty($rows)) {
					$rows[] = $prevBeforeRange;
				}
				$rows[] = $row;
			}
		}

		$groupKeyFn = function (int $ts) use ($groupByWeek) {
			return $groupByWeek ? date('o-\WW', $ts) : date('Y-m-d', $ts);
		};
		$labelFn = function (string $key) use ($groupByWeek) {
			if ($groupByWeek) {
				[$year, $week] = explode('-W', $key);
				return 'KW ' . $week;
			}
			return date('d.m.', strtotime($key));
		};

		$aggregated = []; // key => ['consumption'=>x, 'mid_date'=>Y-m-d]
		for ($i = 1; $i < count($rows); $i++) {
			$diff = max(0, (float) $rows[$i]['counter_value'] - (float) $rows[$i - 1]['counter_value']);
			$ts = strtotime($rows[$i]['reading_date']);
			$key = $groupKeyFn($ts);
			if (!isset($aggregated[$key])) {
				$aggregated[$key] = ['consumption' => 0.0, 'mid_date' => date('Y-m-d', $ts)];
			}
			$aggregated[$key]['consumption'] += $diff;
			$aggregated[$key]['mid_date'] = date('Y-m-d', $ts); // letzter Tag der Gruppe als Referenz für Tarif
		}
		ksort($aggregated);

		$labels = [];
		$values = [];
		foreach ($aggregated as $key => $data) {
			$labels[] = $labelFn($key);
			if ($mode === 'cost') {
				// Genauer als "heutiger Tarif": der zum jeweiligen Datenpunkt gültige Tarif
				$values[] = $this->estimateWorkPriceOnly($type, $data['consumption'], $data['mid_date']);
			} else {
				$values[] = round($data['consumption'], 2);
			}
		}

		// Wetter-Overlay (nur für Heizungs-relevante Utilities)
		$temps = [];
		$meta = $this->utilities[$type];
		if ($meta['is_heating'] && !empty($aggregated)) {
			$wStmt = $this->pdo->prepare(
				'SELECT date, avg_temp FROM weather_data WHERE date BETWEEN ? AND ?'
			);
			$wStmt->execute([$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')]);
			$wRows = $wStmt->fetchAll();

			$wAgg = [];
			$wCount = [];
			foreach ($wRows as $row) {
				$ts = strtotime($row['date']);
				$key = $groupKeyFn($ts);
				$wAgg[$key] = ($wAgg[$key] ?? 0) + (float) $row['avg_temp'];
				$wCount[$key] = ($wCount[$key] ?? 0) + 1;
			}
			foreach ($aggregated as $key => $data) {
				$temps[] = isset($wAgg[$key]) && $wCount[$key] > 0
					? round($wAgg[$key] / $wCount[$key], 1)
					: null;
			}
		}

		$forecastLabels = [];
		$forecastValues = [];
		if ($withForecast && $mode === 'usage') {
			$lt = $this->getLongTermForecast($type);
			foreach ($lt['forecast'] as $ym => $val) {
				$forecastLabels[] = date('M Y', strtotime($ym . '-01'));
				$forecastValues[] = $val;
			}
		}

		return [
			'labels'          => $labels,
			'values'          => $values,
			'temps'           => $temps,
			'show_temp'       => !empty($temps),
			'unit'            => $mode === 'cost' ? '€' : $meta['unit'],
			'color'           => $meta['color'],
			'forecast_labels' => $forecastLabels,
			'forecast_values' => $forecastValues,
		];
	}

	// =====================================================================
	// TARIFE
	// =====================================================================

	public function getTariffs(): array {
		$stmt = $this->pdo->query(
			'SELECT id, utility_type, valid_from, valid_to, price_per_unit, base_price_monthly
			 FROM tariffs ORDER BY utility_type, valid_from DESC'
		);
		return $stmt->fetchAll();
	}

	public function saveTariff(array $data): void {
		$type = $data['utility_type'] ?? '';
		$this->assertValidType($type);

		$validFrom = $data['valid_from'] ?? null;
		if (!$validFrom || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
			throw new InvalidArgumentException('Gültig-ab-Datum fehlt oder ungültig');
		}
		$validTo = !empty($data['valid_to']) ? $data['valid_to'] : null;
		$pricePerUnit = (float) str_replace(',', '.', (string) ($data['price_per_unit'] ?? 0));
		$basePriceMonthly = (float) str_replace(',', '.', (string) ($data['base_price_monthly'] ?? 0));
		$id = !empty($data['id']) ? (int) $data['id'] : null;

		if ($id) {
			$stmt = $this->pdo->prepare(
				'UPDATE tariffs SET utility_type=?, valid_from=?, valid_to=?, price_per_unit=?, base_price_monthly=? WHERE id=?'
			);
			$stmt->execute([$type, $validFrom, $validTo, $pricePerUnit, $basePriceMonthly, $id]);
		} else {
			$stmt = $this->pdo->prepare(
				'INSERT INTO tariffs (utility_type, valid_from, valid_to, price_per_unit, base_price_monthly) VALUES (?,?,?,?,?)'
			);
			$stmt->execute([$type, $validFrom, $validTo, $pricePerUnit, $basePriceMonthly]);
		}
	}

	public function deleteTariff(int $id): void {
		$stmt = $this->pdo->prepare('DELETE FROM tariffs WHERE id = ?');
		$stmt->execute([$id]);
	}

	/**
	 * Tarif, der an $date gültig ist. Preise sind in der DB in CENT
	 * hinterlegt und werden hier direkt nach Euro umgerechnet zurückgegeben.
	 */
	private function getTariff(string $type, string $date): ?array {
		$stmt = $this->pdo->prepare(
			'SELECT price_per_unit, base_price_monthly FROM tariffs
			 WHERE utility_type = ? AND valid_from <= ?
			   AND (valid_to IS NULL OR valid_to >= ?)
			 ORDER BY valid_from DESC LIMIT 1'
		);
		$stmt->execute([$type, $date, $date]);
		$row = $stmt->fetch();
		if (!$row) return null;
		return [
			'price_per_unit' => ((float) $row['price_per_unit']) / 100,
			'base_price_monthly' => ((float) $row['base_price_monthly']) / 100,
		];
	}

	private function periodCostFactor(string $granularity): float {
		switch ($granularity) {
			case 'week': return 7 / 30.44;
			case 'year': return 12.0;
			case 'month':
			default: return 1.0;
		}
	}

	private function estimateCost(string $type, float $consumption, string $date, string $granularity): ?float {
		$tariff = $this->getTariff($type, $date);
		if (!$tariff) return null;
		$workPrice = $consumption * $tariff['price_per_unit'];
		$baseFee = $tariff['base_price_monthly'] * $this->periodCostFactor($granularity);
		return round($workPrice + $baseFee, 2);
	}

	private function estimateWorkPriceOnly(string $type, float $consumption, string $date): ?float {
		$tariff = $this->getTariff($type, $date);
		if (!$tariff) return null;
		return round($consumption * $tariff['price_per_unit'], 2);
	}

	// =====================================================================
	// NEUEN ZÄHLERSTAND SPEICHERN
	// =====================================================================

	public function saveReading(string $type, string $date, string $time, string $rawValue): void {
		$this->assertValidType($type);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			throw new InvalidArgumentException('Ungültiges Datum');
		}
		if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
			throw new InvalidArgumentException('Ungültige Uhrzeit');
		}
		$value = str_replace(['.', ','], ['', '.'], $rawValue);
		// Falls kein Tausenderpunkt vorhanden war, obige Ersetzung ist trotzdem korrekt,
		// da "," immer Dezimaltrennzeichen ist und "." als Tausendertrennzeichen entfernt wird.
		if (!is_numeric($value)) {
			throw new InvalidArgumentException('Der Zählerstand muss eine gültige Zahl sein');
		}

		$stmt = $this->pdo->prepare(
			'INSERT INTO meter_readings (utility_type, reading_date, counter_value) VALUES (?,?,?)'
		);
		$stmt->execute([$type, "$date $time:00", $value]);
	}
}
