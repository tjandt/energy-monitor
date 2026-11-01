<?php
/**
 * Forecaster.php – Prognosen auf Basis der Zählerstände.
 *
 *   1) LAUFENDE PERIODE (Woche/Monat/Jahr)
 *      Gesamtverbrauch = bisheriger Verbrauch + erwarteter Restverbrauch.
 *      Der Restverbrauch stammt aus dem Saisonprofil der letzten N Vorjahre
 *      (gleiche Periode), skaliert mit dem Verhältnis "heute bisher" zu
 *      "Vorjahre im gleichen Zeitabschnitt". Die Skalierung wird mit dem
 *      verstrichenen Periodenanteil gewichtet (am Anfang: Saisonwert, am
 *      Ende: tatsächlicher Verlauf) und auf 0,5–2,0 begrenzt.
 *      Das ist deutlich robuster als lineares Hochrechnen, v.a. bei
 *      Heizenergie (Januar × 12 ≠ Jahresverbrauch).
 *      Ohne Vorjahresdaten wird linear hochgerechnet.
 *
 *   2) LANGZEIT-PROGNOSE (n Monate)
 *      Ø-Verbrauch je Kalendermonat der letzten N Jahre, skaliert auf das
 *      Niveau der letzten 12 Monate (Faktor auf ±growth_rate_clamp begrenzt).
 */

require_once __DIR__ . '/Period.php';
require_once __DIR__ . '/ReadingRepository.php';

class Forecaster {

	private ReadingRepository $readings;
	private array $cfg;

	public function __construct(ReadingRepository $readings, array $appCfg) {
		$this->readings = $readings;
		$this->cfg = $appCfg;
	}

	public function currentPeriod(string $type, string $granularity, ?DateTimeInterface $now = null): array {
		Period::assertValid($granularity);
		$now = $now ? DateTime::createFromInterface($now) : new DateTime();

		// Daten reichen nur bis zur letzten Ablesung -> dorthin "spulen"
		$lastTs = $this->readings->lastTimestamp($type);
		$effNowTs = $lastTs !== null ? min($now->getTimestamp(), $lastTs) : $now->getTimestamp();

		[$start, $end] = Period::bounds($granularity, $now);
		$fraction = Period::elapsedFraction($start, $end, $effNowTs);
		$elapsed = $this->readings->consumption($type, $start->getTimestamp(), $effNowTs);
		$linear = $fraction > 0.02 ? $elapsed / $fraction : 0.0;

		$totals = [];
		$partials = [];
		$years = (int) $this->cfg['forecast_years_seasonal'];
		for ($i = 1; $i <= $years; $i++) {
			[$rs, $re] = Period::bounds($granularity, (clone $now)->modify("-{$i} year"));
			$total = $this->readings->consumption($type, $rs->getTimestamp(), $re->getTimestamp());
			if ($total <= 0) continue;
			$cut = (int) round($rs->getTimestamp() + $fraction * ($re->getTimestamp() - $rs->getTimestamp()));
			$totals[] = $total;
			$partials[] = $this->readings->consumption($type, $rs->getTimestamp(), $cut);
		}
		$seasonalTotal = $totals ? array_sum($totals) / count($totals) : null;
		$seasonalElapsed = $partials ? array_sum($partials) / count($partials) : null;

		if ($seasonalTotal === null) {
			$estimate = $linear;
		} elseif ($fraction <= 0.02) {
			$estimate = $seasonalTotal;
		} elseif ($seasonalElapsed > 0) {
			$scale = max(0.5, min(2.0, $elapsed / $seasonalElapsed));
			$scale = $fraction * $scale + (1 - $fraction);
			$estimate = $elapsed + max(0.0, $seasonalTotal - $seasonalElapsed) * $scale;
		} else {
			$estimate = $fraction * $linear + (1 - $fraction) * $seasonalTotal;
		}

		return [
			'estimate'            => round($estimate, 2),
			'elapsed_consumption' => round($elapsed, 2),
			'elapsed_fraction'    => round($fraction, 3),
			'linear_estimate'     => round($linear, 2),
			'seasonal_reference'  => $seasonalTotal !== null ? round($seasonalTotal, 2) : null,
			'data_until'          => $lastTs !== null ? date('Y-m-d H:i:s', $effNowTs) : null,
		];
	}

	public function longTerm(string $type, int $monthsAhead): array {
		$monthly = $this->monthlyHistory($type);
		$n = count($monthly);
		if ($n < 2) {
			return ['forecast' => [], 'growth_rate' => 0.0, 'basis_months' => $n];
		}

		$years = max(1, (int) $this->cfg['forecast_years_seasonal']);
		$byMonth = [];
		foreach ($monthly as $ym => $val) {
			$byMonth[(int) substr($ym, 5, 2)][] = $val;
		}
		$seasonal = [];
		foreach ($byMonth as $m => $vals) {
			$vals = array_slice($vals, -$years);
			$seasonal[$m] = array_sum($vals) / count($vals);
		}
		$overallAvg = array_sum($monthly) / $n;

		// Niveau-Faktor: letzte 12 Monate gegen das, was das Saisonprofil erwarten ließ
		$level = 1.0;
		if ($n >= 12) {
			$recent = array_slice($monthly, -12, null, true);
			$modeled = 0.0;
			foreach (array_keys($recent) as $ym) {
				$modeled += $seasonal[(int) substr($ym, 5, 2)] ?? $overallAvg;
			}
			if ($modeled > 0) $level = array_sum($recent) / $modeled;
		}
		$clamp = (float) $this->cfg['growth_rate_clamp'];
		$level = max(1 - $clamp, min(1 + $clamp, $level));

		$cursor = new DateTime(array_key_last($monthly) . '-01');
		$forecast = [];
		for ($i = 1; $i <= $monthsAhead; $i++) {
			$cursor->modify('+1 month');
			$base = $seasonal[(int) $cursor->format('n')] ?? $overallAvg;
			$forecast[$cursor->format('Y-m')] = round(max(0.0, $base * $level), 2);
		}

		return [
			'forecast'     => $forecast,
			'growth_rate'  => round(($level - 1) * 100, 1),
			'basis_months' => $n,
		];
	}

	/**
	 * Verbrauch je vollständig durch Ablesungen abgedecktem Kalendermonat
	 * (Y-m => Wert), aufsteigend sortiert.
	 */
	public function monthlyHistory(string $type): array {
		$first = $this->readings->firstTimestamp($type);
		$last = $this->readings->lastTimestamp($type);
		if ($first === null || $last === null) return [];

		$monthly = [];
		[$cursor] = Period::bounds('month', (new DateTime())->setTimestamp($first));
		if ($cursor->getTimestamp() < $first) $cursor->modify('+1 month');
		while (true) {
			$next = (clone $cursor)->modify('+1 month');
			if ($next->getTimestamp() > $last) break;
			$monthly[$cursor->format('Y-m')] = $this->readings->consumption($type, $cursor->getTimestamp(), $next->getTimestamp());
			$cursor = $next;
		}
		return $monthly;
	}
}
