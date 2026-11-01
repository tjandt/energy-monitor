<?php
/**
 * EnergyService.php
 * -----------------------------------------------------------------------
 * Fassade für die Business-Logik des Energy Monitors. Die eigentliche
 * Arbeit steckt in ReadingRepository (Zählerstände/Interpolation),
 * Forecaster (Prognosen) und TariffRepository (Tarife/Kosten).
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Num.php';
require_once __DIR__ . '/Period.php';
require_once __DIR__ . '/ReadingRepository.php';
require_once __DIR__ . '/TariffRepository.php';
require_once __DIR__ . '/Forecaster.php';
require_once __DIR__ . '/DegreeDays.php';

/** Eingabe ist formal gültig, aber unplausibel – mit force=1 trotzdem speicherbar. */
class ImplausibleReadingException extends InvalidArgumentException {}

class EnergyService {

	private PDO $pdo;
	private array $cfg;
	private array $utilities;
	private ReadingRepository $readings;
	private TariffRepository $tariffs;
	private Forecaster $forecaster;
	private DegreeDays $degreeDays;

	public function __construct(?PDO $pdo = null, ?array $config = null) {
		if ($config !== null) Config::override($config);
		$full = Config::all();
		$this->pdo = $pdo ?? Database::getConnection();
		$this->cfg = $full['app'];
		$this->utilities = $full['utilities'];
		$this->readings = new ReadingRepository($this->pdo);
		$this->tariffs = new TariffRepository($this->pdo, $this->utilities);
		$this->forecaster = new Forecaster($this->readings, $this->cfg);
		$this->degreeDays = new DegreeDays($this->pdo, $full['degree_days']);
	}

	// =====================================================================
	// META
	// =====================================================================

	public function getUtilityKeys(): array {
		return array_keys($this->utilities);
	}

	public function getUtilityMeta(string $type): array {
		$this->assertValidType($type);
		return $this->utilities[$type];
	}

	/** Für das Frontend: Typ => Label/Farbe/Einheit/Icon. */
	public function getUtilities(): array {
		$out = [];
		foreach ($this->utilities as $key => $u) {
			$out[$key] = ['label' => $u['label'], 'unit' => $u['unit'], 'color' => $u['color'], 'icon' => $u['icon'],
				'advance' => !empty($u['advance'])];
		}
		return $out;
	}

	private function assertValidType(string $type): void {
		if (!isset($this->utilities[$type])) {
			throw new InvalidArgumentException("Unbekannter Zählertyp: $type");
		}
	}

	public function getLastReading(string $type): ?array {
		$this->assertValidType($type);
		return $this->readings->last($type);
	}

	// =====================================================================
	// DASHBOARD
	// =====================================================================

	/**
	 * Kacheln für die Periode, die $offset Perioden (Woche/Monat/Jahr) vor der
	 * aktuellen liegt. offset 0 = laufende Periode mit Prognose; ältere
	 * Perioden zeigen den tatsächlichen Verbrauch.
	 */
	public function getDashboard(string $granularity = 'month', int $offset = 0): array {
		Period::assertValid($granularity);
		$periodStart = $this->periodStartFor($granularity, $offset);
		$result = [];
		foreach ($this->getUtilityKeys() as $type) {
			$result[$type] = $this->getPeriodComparison($type, $granularity, $periodStart);
		}
		return $result;
	}

	/** Angaben für die Zeitraum-Navigation (Beschriftung, wie weit man zurück kann). */
	public function getPeriodNav(string $granularity = 'month', int $offset = 0): array {
		Period::assertValid($granularity);
		$start = Period::startBack($granularity, $this->checkOffset($offset));
		$first = null;
		foreach ($this->getUtilityKeys() as $type) {
			$ts = $this->readings->firstTimestamp($type);
			if ($ts !== null) $first = min($first ?? PHP_INT_MAX, $ts);
		}
		return [
			'granularity' => $granularity,
			'offset'      => $offset,
			'label'       => Period::longLabel($granularity, $start),
			'is_current'  => $offset === 0,
			'max_back'    => $first === null ? 0 : Period::periodsBetween($granularity, $first),
		];
	}

	private function checkOffset(int $offset): int {
		if ($offset < 0 || $offset > 1200) {
			throw new InvalidArgumentException('Ungültiger Zeitraum-Versatz (nur Gegenwart und Vergangenheit)');
		}
		return $offset;
	}

	/** null = laufende Periode, sonst Beginn der zurückliegenden Periode. */
	private function periodStartFor(string $granularity, int $offset): ?DateTime {
		return $this->checkOffset($offset) === 0 ? null : Period::startBack($granularity, $offset);
	}

	/**
	 * Verbrauch/Prognose einer Periode inkl. Vergleich zu den letzten 2 Vorjahren.
	 * $periodStart = null: laufende Periode (Prognose). Sonst eine zurückliegende
	 * Periode: Ist-Verbrauch (nur wenn die Ablesungen das Periodenende nicht
	 * erreichen, wird wie bei der Prognose hochgerechnet).
	 */
	public function getPeriodComparison(string $type, string $granularity, ?DateTimeInterface $periodStart = null): array {
		$this->assertValidType($type);
		Period::assertValid($granularity);
		$isPast = $periodStart !== null;
		$now = new DateTime();
		$anchor = $isPast ? DateTime::createFromInterface($periodStart) : $now;
		$meta = $this->utilities[$type];

		// Für zurückliegende Perioden "jetzt" = letzte Sekunde der Periode
		$asOf = $isPast ? (clone Period::bounds($granularity, $anchor)[1])->modify('-1 second') : $now;
		$forecast = $this->forecaster->currentPeriod($type, $granularity, $asOf);
		$lastReading = $this->readings->last($type);

		$compare = [];
		for ($i = 1; $i <= 2; $i++) {
			$refDate = (clone $anchor)->modify("-{$i} year");
			[$start, $end] = Period::bounds($granularity, $refDate);
			$val = $this->readings->consumption($type, $start->getTimestamp(), $end->getTimestamp());
			$compare[] = [
				'label' => Period::label($granularity, $refDate),
				'value' => round($val, 1),
				'cost'  => $this->periodCost($type, $val, $refDate, $granularity),
			];
		}

		$staleDays = $lastReading
			? (int) floor((time() - strtotime($lastReading['reading_date'])) / 86400)
			: null;

		return [
			'label'              => $meta['label'],
			'unit'               => $meta['unit'],
			'icon'               => $meta['icon'],
			'color'              => $meta['color'],
			'last_reading'       => $lastReading,
			'stale_days'         => $staleDays,
			'stale_warning'      => $staleDays !== null && $staleDays >= $this->cfg['warn_stale_days'],
			'granularity'        => $granularity,
			'is_past'            => $isPast,
			'current_label'      => ($isPast ? '' : 'Prognose ') . Period::label($granularity, $anchor),
			'estimate'           => $forecast['estimate'],
			'elapsed_fraction'   => $forecast['elapsed_fraction'],
			'seasonal_reference' => $forecast['seasonal_reference'],
			'compare'            => $compare,
			'trend'              => $this->calcTrend($forecast['estimate'], $compare[0]['value']),
			'weather_adjusted'   => $this->weatherAdjusted($type, $granularity, $asOf, $forecast),
			'advance'            => !$isPast && !empty($meta['advance']) ? $this->advanceCheck($type) : null,
			'cost_estimate'      => $this->periodCost($type, $forecast['estimate'], $anchor, $granularity),
		];
	}

	public function forecastCurrentPeriod(string $type, string $granularity, ?DateTimeInterface $now = null): array {
		$this->assertValidType($type);
		return $this->forecaster->currentPeriod($type, $granularity, $now);
	}

	public function getLongTermForecast(string $type, ?int $monthsAhead = null): array {
		$this->assertValidType($type);
		$months = $monthsAhead ?? (int) $this->cfg['forecast_months_ahead'];
		return $this->forecaster->longTerm($type, max(1, min(24, $months)));
	}

	/**
	 * Witterungsbereinigter Vergleich: spezifischer Verbrauch (Einheit je
	 * Gradtag) im bisherigen Verlauf der laufenden Periode gegenüber demselben
	 * Zeitabschnitt des Vorjahres. null bei Zählern ohne Bereinigung, fehlenden
	 * Wetterdaten oder zu wenig Heizbedarf (z.B. Sommer).
	 */
	private function weatherAdjusted(string $type, string $granularity, DateTimeInterface $now, array $forecast): ?array {
		if (empty($this->utilities[$type]['degree_day_adjust']) || $forecast['data_until'] === null) return null;

		[$start, $end] = Period::bounds($granularity, $now);
		$until = new DateTime($forecast['data_until']);
		[$prevStart, $prevEnd] = Period::bounds($granularity, (clone $now)->modify('-1 year'));
		$cut = (new DateTime())->setTimestamp((int) round(
			$prevStart->getTimestamp() + $forecast['elapsed_fraction'] * ($prevEnd->getTimestamp() - $prevStart->getTimestamp())
		));
		if ($forecast['elapsed_fraction'] >= 0.999) { // vollständig erfasste Periode: ganze Tage vergleichen
			$until = $end;
			$cut = $prevEnd;
		}

		$gt = $this->degreeDays->between($start, $until);
		$gtPrev = $this->degreeDays->between($prevStart, $cut);
		$min = $this->degreeDays->minDegreeDays();
		if ($gt === null || $gtPrev === null || $gt < $min || $gtPrev < $min) return null;

		$cons = $forecast['elapsed_consumption'];
		$consPrev = $this->readings->consumption($type, $prevStart->getTimestamp(), $cut->getTimestamp());
		if ($cons <= 0 || $consPrev <= 0) return null;

		$spec = $cons / $gt;
		$specPrev = $consPrev / $gtPrev;
		return [
			'specific'      => round($spec, 3),
			'specific_prev' => round($specPrev, 3),
			'change_pct'    => round(($spec / $specPrev - 1) * 100, 1),
			'trend'         => $this->calcTrend($spec, $specPrev),
			'gt'            => (int) round($gt),
			'gt_prev'       => (int) round($gtPrev),
			'unit'          => $this->utilities[$type]['unit'] . '/Gt',
		];
	}

	/**
	 * Abschlags-Check für das laufende Abrechnungsjahr: Summe der Abschläge
	 * gegen die erwarteten Kosten -> erwartete Nach-/Rückzahlung.
	 *
	 * Tarifänderungen im Jahr (Arbeits-/Grundpreis oder Abschlag) werden
	 * tagesgenau berücksichtigt: Das Jahr wird an Monatsgrenzen und
	 * Tarifwechseln in Abschnitte zerlegt; jeder Abschnitt wird mit dem dort
	 * gültigen Tarif bewertet (Ist-Verbrauch, ab der letzten Ablesung
	 * Prognose). Jeder Abschlag wird mit dem Tarif bewertet, der an seinem
	 * Fälligkeitstag (config 'advance_due_day') gilt.
	 */
	public function advanceCheck(string $type, ?DateTimeInterface $now = null): ?array {
		$now = $now ? DateTime::createFromInterface($now) : new DateTime();
		$startMonth = max(1, min(12, (int) ($this->utilities[$type]['billing_start_month'] ?? 1)));
		$year = (int) $now->format('Y') - ((int) $now->format('n') < $startMonth ? 1 : 0);
		$pStart = new DateTime(sprintf('%d-%02d-01', $year, $startMonth));
		$pEnd = (clone $pStart)->modify('+1 year');

		$first = $this->readings->firstTimestamp($type);
		$last = $this->readings->lastTimestamp($type);
		if ($first === null) return null;
		$forecast = $this->forecaster->longTerm($type, 24)['forecast'];

		// durchschnittlicher Tagesverbrauch der abgedeckten Zeit als Rückfall
		$covFrom = max($first, $pStart->getTimestamp());
		$covTo = min($last, $pEnd->getTimestamp());
		$avgDaily = null;
		if ($covTo > $covFrom) {
			$avgDaily = $this->readings->consumption($type, $covFrom, $covTo) / (($covTo - $covFrom) / 86400);
		} elseif ($history = $this->forecaster->monthlyHistory($type)) {
			$avgDaily = array_sum($history) / (count($history) * 30.44);
		}
		if ($avgDaily === null && !$forecast) return null;

		$expected = 0.0;
		$consumption = 0.0;
		$tariffMissing = false;
		foreach ($this->segments($type, $pStart, $pEnd) as [$a, $b]) {
			$aTs = $a->getTimestamp();
			$bTs = $b->getTimestamp();
			$dim = (int) $a->format('t');
			$ym = $a->format('Y-m');
			$rate = isset($forecast[$ym]) ? $forecast[$ym] / $dim : ($avgDaily ?? 0.0); // je Tag
			$days = ($bTs - $aTs) / 86400;

			if ($aTs >= $first && $bTs <= $last) {
				$cons = $this->readings->consumption($type, $aTs, $bTs);          // Ist
			} elseif ($bTs <= $first) {
				$cons = ($avgDaily ?? $rate) * $days;                                // vor der ersten Ablesung unbekannt
			} elseif ($aTs >= $last) {
				$cons = $rate * $days;                                               // Zukunft: Prognose
			} else {                                                                 // Abschnitt enthält die letzte Ablesung
				$cons = $this->readings->consumption($type, max($aTs, $first), $last, true)
					+ $rate * (($bTs - $last) / 86400);
			}
			$consumption += $cons;
			$cost = $this->tariffs->cost($type, $cons, $a->format('Y-m-d'), $days / $dim);
			if ($cost === null) $tariffMissing = true; else $expected += $cost;
		}

		$advanceTotal = 0.0;
		$advancePaid = 0.0;
		$remaining = 0;
		$changes = [];
		$prevAmount = null;
		foreach ($this->advanceSchedule($type, $pStart) as ['due' => $due, 'amount' => $amount]) {
			$advanceTotal += $amount;
			if ($due->getTimestamp() <= $now->getTimestamp()) $advancePaid += $amount; else $remaining++;
			if ($prevAmount !== null && abs($amount - $prevAmount) > 0.004) {
				$changes[] = ['from' => $due->format('Y-m-d'), 'amount' => round($amount, 2)];
			}
			$prevAmount = $amount;
		}
		if ($advanceTotal <= 0) return null;

		$next = null;
		foreach ($changes as $c) {
			if ($c['from'] > $now->format('Y-m-d')) { $next = $c; break; }
		}

		return [
			'period_start'     => $pStart->format('Y-m-d'),
			'period_end'       => (clone $pEnd)->modify('-1 day')->format('Y-m-d'),
			'advance_monthly'  => round($this->tariffs->advanceAt($type, $now->format('Y-m-d')), 2),
			'advance_next'     => $next,      // nächste Änderung des Abschlags im Abrechnungsjahr
			'advance_changes'  => $changes,
			'advance_paid'     => round($advancePaid, 2),
			'advance_total'    => round($advanceTotal, 2),
			'expected_cost'    => round($expected, 2),
			'expected_consumption' => round($consumption, 1),
			'balance'          => round($advanceTotal - $expected, 2), // >0 Guthaben, <0 Nachzahlung
			// Abschlag, der ab sofort für die restlichen Zahlungen nötig wäre (sonst Jahreskosten/12)
			'remaining_payments' => $remaining,
			'suggested_monthly' => $remaining > 0
				? max(0, (int) ceil(($expected - $advancePaid) / $remaining))
				: (int) ceil($expected / 12),
			'tariff_missing'   => $tariffMissing,
		];
	}

	/**
	 * Die 12 Abschlagszahlungen eines Abrechnungsjahres: Fälligkeitstag
	 * (config 'advance_due_day') und der dann gültige Abschlag.
	 * @return array<int, array{due: DateTime, amount: float}>
	 */
	private function advanceSchedule(string $type, DateTimeInterface $pStart): array {
		$dueDay = max(1, min(28, (int) ($this->utilities[$type]['advance_due_day'] ?? 1)));
		$out = [];
		$base = DateTime::createFromInterface($pStart);
		for ($i = 0; $i < 12; $i++) {
			$month = (clone $base)->modify("+{$i} month");
			$due = (clone $month)->setDate((int) $month->format('Y'), (int) $month->format('n'), $dueDay);
			$out[] = ['due' => $due, 'amount' => $this->tariffs->advanceAt($type, $due->format('Y-m-d'))];
		}
		return $out;
	}

	/**
	 * Zerlegt [$from, $to) an Monatsgrenzen und Tarifwechseln. Innerhalb eines
	 * Abschnitts ist der Tarif konstant und es gibt keinen Monatswechsel.
	 * @return array<int, array{0: DateTime, 1: DateTime}>
	 */
	private function segments(string $type, DateTimeInterface $from, DateTimeInterface $to): array {
		$fromD = DateTime::createFromInterface($from);
		$toD = DateTime::createFromInterface($to);
		$points = [$fromD->format('Y-m-d') => true, $toD->format('Y-m-d') => true];
		for ($m = (clone $fromD)->modify('first day of next month'); $m < $toD; $m->modify('first day of next month')) {
			$points[$m->format('Y-m-d')] = true;
		}
		foreach ($this->tariffs->changeDates($type) as $d) {
			if ($d > $fromD->format('Y-m-d') && $d < $toD->format('Y-m-d')) $points[$d] = true;
		}
		ksort($points);
		$dates = array_map(fn($d) => new DateTime($d), array_keys($points));
		$out = [];
		for ($i = 0; $i + 1 < count($dates); $i++) $out[] = [$dates[$i], $dates[$i + 1]];
		return $out;
	}

	private function calcTrend(float $current, float $reference): string {
		if ($reference <= 0) return 'neutral';
		$diff = ($current - $reference) / $reference;
		$tol = (float) $this->cfg['trend_tolerance'];
		if ($diff > $tol) return 'bad';
		if ($diff < -$tol) return 'good';
		return 'neutral';
	}

	/** Kosten einer ganzen Periode (Arbeitspreis + anteilige Grundgebühr), Tarif vom Periodenbeginn. */
	private function periodCost(string $type, float $consumption, DateTimeInterface $ref, string $granularity): ?float {
		return $this->tariffs->cost($type, $consumption, $ref->format('Y-m-d'), Period::monthlyFeeFactor($granularity));
	}

	// =====================================================================
	// CHART-DATEN
	// =====================================================================

	/**
	 * Verbrauch/Kosten je Tag (bis 60 Tage) bzw. je Kalenderwoche. Der
	 * Verbrauch zwischen zwei Ablesungen wird gleichmäßig auf die Tage
	 * dazwischen verteilt, damit die Balken unabhängig vom Ablesetakt
	 * vergleichbar sind und zu den Dashboard-Werten passen.
	 */
	public function getChartData(string $type, string $period, ?string $mode, ?string $start, ?string $end, bool $withForecast = false): array {
		$this->assertValidType($type);
		$mode = $mode === 'cost' ? 'cost' : 'usage';
		if (!in_array($period, ['last_week', 'last_month', 'year', 'custom'], true)) {
			throw new InvalidArgumentException('Ungültiger Zeitraum');
		}

		$now = new DateTime();
		$today = (clone $now)->setTime(0, 0, 0);
		$weekly = false;

		if ($period === 'custom') {
			$from = DateTime::createFromFormat('!Y-m-d', (string) $start);
			$to = DateTime::createFromFormat('!Y-m-d', (string) $end);
			if (!$from || !$to || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end) {
				throw new InvalidArgumentException('Ungültiger Zeitraum für "custom" (Format YYYY-MM-DD erforderlich)');
			}
			if ($to < $from) {
				throw new InvalidArgumentException('Das Enddatum liegt vor dem Startdatum');
			}
			$to->modify('+1 day'); // Ende exklusiv
			$days = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
			if ($days > 3660) {
				throw new InvalidArgumentException('Zeitraum zu groß (maximal 10 Jahre)');
			}
			$weekly = $days > 60;
		} elseif ($period === 'last_week') {
			$from = (clone $today)->modify('-6 days');
			$to = $now;
		} elseif ($period === 'last_month') {
			$from = (clone $today)->modify('-1 month')->modify('+1 day');
			$to = $now;
		} else {
			$from = new DateTime($now->format('Y') . '-01-01 00:00:00');
			$to = $now;
			$weekly = true;
		}

		$meta = $this->utilities[$type];
		$firstTs = $this->readings->firstTimestamp($type);
		$lastTs = $this->readings->lastTimestamp($type);
		$multiYear = $from->format('Y') !== (clone $to)->modify('-1 second')->format('Y');

		$labels = [];
		$values = [];
		$bucketOf = []; // Datum => Index (für Wetter-Zuordnung)
		foreach ($this->buildBuckets($from, $to, $weekly) as [$bStart, $bEnd]) {
			$sTs = $bStart->getTimestamp();
			$eTs = $bEnd->getTimestamp();
			// Buckets ganz außerhalb der Ablesungen sind leer und werden ausgelassen
			if ($firstTs === null || $eTs <= $firstTs || $sTs >= $lastTs) continue;
			$consumption = $this->readings->consumption($type, $sTs, $eTs, true);
			$lastDay = (clone $bEnd)->modify('-1 second');
			if ($mode === 'cost') {
				$days = max(1, (int) round(($eTs - $sTs) / 86400));
				$values[] = $this->tariffs->cost($type, $consumption, $lastDay->format('Y-m-d'), $days / 30.44);
			} else {
				$values[] = round($consumption, 2);
			}
			$labels[] = $weekly
				? 'KW ' . $bStart->format('W') . ($multiYear ? '/' . $bStart->format('o') : '')
				: $bStart->format('d.m.');
			for ($d = (clone $bStart)->setTime(0, 0, 0); $d < $bEnd; $d->modify('+1 day')) {
				$bucketOf[$d->format('Y-m-d')] = count($labels) - 1;
			}
		}

		$temps = [];
		if ($meta['is_heating'] && $labels) {
			$sum = array_fill(0, count($labels), 0.0);
			$cnt = array_fill(0, count($labels), 0);
			$stmt = $this->pdo->prepare('SELECT date, avg_temp FROM weather_data WHERE date >= ? AND date < ?');
			$stmt->execute([$from->format('Y-m-d'), $to->format('Y-m-d H:i:s')]);
			foreach ($stmt->fetchAll() as $row) {
				$idx = $bucketOf[substr($row['date'], 0, 10)] ?? null;
				if ($idx === null || $row['avg_temp'] === null) continue;
				$sum[$idx] += (float) $row['avg_temp'];
				$cnt[$idx]++;
			}
			foreach ($sum as $i => $s) {
				$temps[] = $cnt[$i] > 0 ? round($s / $cnt[$i], 1) : null;
			}
		}

		$forecastLabels = [];
		$forecastValues = [];
		if ($withForecast) {
			// Monatswerte auf die Balkenbreite des Charts (Woche/Tag) umrechnen,
			// sonst wären Prognose- und Ist-Balken nicht vergleichbar.
			$barDays = $weekly ? 7 : 1;
			foreach ($this->getLongTermForecast($type)['forecast'] as $ym => $val) {
				$daysInMonth = (int) date('t', strtotime($ym . '-01'));
				$scaled = $val * $barDays / $daysInMonth;
				$forecastLabels[] = Period::monthName((int) substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4);
				if ($mode === 'cost') {
					// Tarif des Prognosemonats, sonst der heute gültige
					$forecastValues[] = $this->tariffs->cost($type, $scaled, $ym . '-15', $barDays / 30.44)
						?? $this->tariffs->cost($type, $scaled, $today->format('Y-m-d'), $barDays / 30.44);
				} else {
					$forecastValues[] = round($scaled, 2);
				}
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
			'weekly'          => $weekly,
			'forecast_per'    => $weekly ? 'Woche' : 'Tag',
		];
	}

	/**
	 * Zerlegt [$from, $to) in Tages- bzw. ISO-Wochen-Buckets; Randbuckets
	 * werden auf den Zeitraum zugeschnitten.
	 * @return array<int, array{0: DateTime, 1: DateTime}>
	 */
	private function buildBuckets(DateTime $from, DateTime $to, bool $weekly): array {
		$buckets = [];
		$cursor = (clone $from)->setTime(0, 0, 0);
		while ($cursor < $to) {
			if ($weekly) {
				[$weekStart, $weekEnd] = Period::bounds('week', $cursor);
				$bStart = max($weekStart, $from);
				$bEnd = min($weekEnd, $to);
				$cursor = $weekEnd;
			} else {
				$bStart = max(clone $cursor, $from);
				$bEnd = min((clone $cursor)->modify('+1 day'), $to);
				$cursor->modify('+1 day');
			}
			$buckets[] = [clone $bStart, clone $bEnd];
		}
		return $buckets;
	}

	// =====================================================================
	// JAHRESVERGLEICH
	// =====================================================================

	/**
	 * Monatswerte (oder kumuliert seit Jahresbeginn) der letzten Jahre als
	 * Linien übereinander. Unvollständig erfasste Monate bleiben leer;
	 * kumuliert werden nur Jahre gezeigt, die ab dem 1. Januar abgedeckt sind.
	 */
	public function getYearComparison(string $type, ?string $mode = 'usage', bool $cumulative = false, int $years = 3): array {
		$this->assertValidType($type);
		$mode = $mode === 'cost' ? 'cost' : 'usage';
		$years = max(2, min(6, $years));
		$meta = $this->utilities[$type];
		$first = $this->readings->firstTimestamp($type);
		$last = $this->readings->lastTimestamp($type);
		$thisYear = (int) date('Y');

		$series = [];
		for ($y = $thisYear - $years + 1; $y <= $thisYear; $y++) {
			if ($first === null) break;
			if ($cumulative && (new DateTime("$y-01-01"))->getTimestamp() < $first) continue;

			$values = [];
			$running = 0.0;
			$broken = false;
			for ($m = 1; $m <= 12; $m++) {
				$mStart = new DateTime(sprintf('%d-%02d-01', $y, $m));
				$mEnd = (clone $mStart)->modify('+1 month');
				$sTs = $mStart->getTimestamp();
				$eTs = $mEnd->getTimestamp();
				$complete = $sTs >= $first && $eTs <= $last;
				$covered = $eTs > $first && $sTs < $last;
				if ($broken || !$covered || (!$cumulative && !$complete)) { $values[] = null; continue; }

				$part = $this->readings->consumption($type, $sTs, $eTs, true);
				if ($mode === 'cost') {
					$fraction = (min($eTs, $last) - max($sTs, $first)) / ($eTs - $sTs);
					$part = $this->tariffs->cost($type, $part, $mStart->format('Y-m-d'), $fraction);
					if ($part === null) { $broken = $cumulative; $values[] = null; continue; }
				}
				$running += $part;
				$values[] = round($cumulative ? $running : $part, 2);
			}
			if (array_filter($values, fn($v) => $v !== null)) {
				$series[] = ['year' => $y, 'values' => $values, 'current' => $y === $thisYear];
			}
		}

		return [
			'labels'     => array_map(fn($m) => Period::monthName($m), range(1, 12)),
			'series'     => $series,
			'unit'       => $mode === 'cost' ? '€' : $meta['unit'],
			'color'      => $meta['color'],
			'cumulative' => $cumulative,
		];
	}

	// =====================================================================
	// JAHRESBERICHT
	// =====================================================================

	/** Jahre, für die mindestens ein Zähler Daten hat (absteigend). */
	public function getReportYears(): array {
		$minYear = null;
		foreach ($this->getUtilityKeys() as $type) {
			$ts = $this->readings->firstTimestamp($type);
			if ($ts !== null) $minYear = min($minYear ?? PHP_INT_MAX, (int) date('Y', $ts));
		}
		return $minYear === null ? [] : range((int) date('Y'), $minYear);
	}

	/**
	 * Alle Zahlen für den Jahresbericht (PDF). Es zählen nur vollständig durch
	 * Ablesungen abgedeckte Monate; Vorjahresvergleiche beziehen sich nur auf
	 * Monate, die in beiden Jahren vollständig sind ("cmp_*").
	 */
	public function getYearReport(int $year): array {
		if ($year < 1990 || $year > (int) date('Y')) {
			throw new InvalidArgumentException('Ungültiges Berichtsjahr');
		}
		$now = new DateTime();
		$report = ['year' => $year, 'generated_at' => $now->format('Y-m-d H:i'), 'utilities' => [],
			'total_cost' => 0.0, 'total_cmp_cost' => 0.0, 'total_cmp_prev_cost' => 0.0];
		$lastAny = null;

		foreach ($this->utilities as $type => $meta) {
			$first = $this->readings->firstTimestamp($type);
			$last = $this->readings->lastTimestamp($type);
			if ($first === null) continue;
			$lastAny = max($lastAny ?? 0, $last);

			$cur = $this->reportMonths($type, $year, $first, $last);
			$prev = $this->reportMonths($type, $year - 1, $first, $last);

			$u = ['type' => $type, 'label' => $meta['label'], 'unit' => $meta['unit'], 'color' => $meta['color'],
				'months' => [], 'usage' => 0.0, 'cost' => null, 'complete_months' => 0,
				'cmp_usage' => 0.0, 'cmp_prev_usage' => 0.0, 'cmp_cost' => null, 'cmp_prev_cost' => null,
				'cmp_months' => 0, 'advance' => null, 'weather' => null];

			for ($m = 0; $m < 12; $m++) {
				$c = $cur[$m];
				$p = $prev[$m];
				$u['months'][] = ['usage' => $c['usage'], 'cost' => $c['cost'], 'prev_usage' => $p['usage'], 'prev_cost' => $p['cost']];
				if ($c['usage'] === null) continue;
				$u['complete_months']++;
				$u['usage'] += $c['usage'];
				if ($c['cost'] !== null) $u['cost'] = ($u['cost'] ?? 0.0) + $c['cost'];
				if ($p['usage'] !== null) {
					$u['cmp_months']++;
					$u['cmp_usage'] += $c['usage'];
					$u['cmp_prev_usage'] += $p['usage'];
					if ($c['cost'] !== null && $p['cost'] !== null) {
						$u['cmp_cost'] = ($u['cmp_cost'] ?? 0.0) + $c['cost'];
						$u['cmp_prev_cost'] = ($u['cmp_prev_cost'] ?? 0.0) + $p['cost'];
					}
				}
			}
			$u['change_pct'] = $u['cmp_prev_usage'] > 0 ? round(($u['cmp_usage'] / $u['cmp_prev_usage'] - 1) * 100, 1) : null;

			// Strom: Abschläge des Kalenderjahres gegen die tatsächlichen Kosten
			if (!empty($meta['advance']) && $u['cost'] !== null) {
				$advance = array_sum(array_column($this->advanceSchedule($type, new DateTime("$year-01-01")), 'amount'));
				if ($advance > 0) {
					$u['advance'] = ['total' => round($advance, 2), 'complete' => $u['complete_months'] === 12,
						'balance' => $u['complete_months'] === 12 ? round($advance - $u['cost'], 2) : null];
				}
			}
			// Wärme: Verbrauch je Gradtag, nur bei vollständigem Jahr und Wetterdaten
			if (!empty($meta['degree_day_adjust']) && $u['complete_months'] === 12 && $prev && !in_array(null, array_column($prev, 'usage'), true)) {
				$gt = $this->degreeDays->between(new DateTime("$year-01-01"), new DateTime(($year + 1) . '-01-01'));
				$gtPrev = $this->degreeDays->between(new DateTime(($year - 1) . '-01-01'), new DateTime("$year-01-01"));
				$prevUsage = array_sum(array_column($prev, 'usage'));
				if ($gt && $gtPrev && $u['usage'] > 0 && $prevUsage > 0) {
					$u['weather'] = ['gt' => (int) round($gt), 'gt_prev' => (int) round($gtPrev),
						'specific' => round($u['usage'] / $gt, 3), 'specific_prev' => round($prevUsage / $gtPrev, 3),
						'change_pct' => round((($u['usage'] / $gt) / ($prevUsage / $gtPrev) - 1) * 100, 1)];
				}
			}

			$report['utilities'][] = $u;
			if ($u['cost'] !== null) $report['total_cost'] += $u['cost'];
			if ($u['cmp_cost'] !== null) {
				$report['total_cmp_cost'] += $u['cmp_cost'];
				$report['total_cmp_prev_cost'] += $u['cmp_prev_cost'];
			}
		}
		$report['data_until'] = $lastAny ? date('Y-m-d', $lastAny) : null;
		return $report;
	}

	/** @return array<int, array{usage: ?float, cost: ?float}> 12 Monate; null = nicht vollständig erfasst */
	private function reportMonths(string $type, int $year, int $first, int $last): array {
		$out = [];
		for ($m = 1; $m <= 12; $m++) {
			$start = new DateTime(sprintf('%d-%02d-01', $year, $m));
			$sTs = $start->getTimestamp();
			$eTs = (clone $start)->modify('+1 month')->getTimestamp();
			if ($sTs < $first || $eTs > $last) { $out[] = ['usage' => null, 'cost' => null]; continue; }
			$usage = $this->readings->consumption($type, $sTs, $eTs);
			// Kosten abschnittsweise, damit Tarifwechsel mitten im Monat stimmen
			$cost = 0.0;
			foreach ($this->segments($type, $start, (clone $start)->modify('+1 month')) as [$a, $b]) {
				$days = ($b->getTimestamp() - $a->getTimestamp()) / 86400;
				$c = $this->tariffs->cost($type, $this->readings->consumption($type, $a->getTimestamp(), $b->getTimestamp()), $a->format('Y-m-d'), $days / (int) $a->format('t'));
				if ($c === null) { $cost = null; break; }
				$cost += $c;
			}
			$out[] = ['usage' => $usage, 'cost' => $cost === null ? null : round($cost, 2)];
		}
		return $out;
	}

	// =====================================================================
	// TARIFE
	// =====================================================================

	public function getTariffs(): array {
		return $this->tariffs->all();
	}

	public function saveTariff(array $data): void {
		$this->tariffs->save($data);
	}

	public function deleteTariff(int $id): void {
		$this->tariffs->delete($id);
	}

	// =====================================================================
	// ZÄHLERSTÄNDE: LISTE / SPEICHERN / ÄNDERN / LÖSCHEN
	// =====================================================================

	public function getReadings(string $type, int $limit = 50, int $offset = 0): array {
		$this->assertValidType($type);
		return [
			'readings' => $this->readings->list($type, max(1, min(200, $limit)), max(0, $offset)),
			'total'    => $this->readings->count($type),
		];
	}

	public function deleteReading(int $id): void {
		$this->readings->delete($id);
	}

	/**
	 * Speichert eine Ablesung (mit $id: ändert eine vorhandene; der Zählertyp
	 * bleibt dann unverändert). Ohne $force wird auf Plausibilität geprüft
	 * (kein Zählerstand kleiner als der vorherige bzw. größer als der
	 * nachfolgende, z.B. durch Zahlendreher oder falsches Komma); bei
	 * Zählerwechsel kann mit $force trotzdem gespeichert werden.
	 */
	public function saveReading(string $type, string $date, string $time, string $rawValue, bool $force = false, ?int $id = null): void {
		if ($id !== null) {
			$existing = $this->readings->find($id);
			if (!$existing) throw new InvalidArgumentException('Zählerstand nicht gefunden');
			$type = $existing['utility_type'];
		}
		$this->assertValidType($type);

		$when = DateTime::createFromFormat('!Y-m-d H:i', "$date $time");
		if (!$when || $when->format('Y-m-d H:i') !== "$date $time") {
			throw new InvalidArgumentException('Ungültiges Datum oder ungültige Uhrzeit');
		}
		if ($when->getTimestamp() > time() + 300) {
			throw new InvalidArgumentException('Der Zeitpunkt liegt in der Zukunft');
		}

		$value = Num::parse($rawValue);
		if ($value === null || $value < 0) {
			throw new InvalidArgumentException('Der Zählerstand muss eine gültige, nicht negative Zahl sein');
		}

		$n = $this->readings->neighbours($type, $when->getTimestamp(), $id);
		if ($n['same']) {
			throw new InvalidArgumentException('Für diesen Zeitpunkt existiert bereits ein Zählerstand');
		}
		if (!$force) {
			$fmt = fn(float $v) => number_format($v, 2, ',', '.');
			if ($n['prev'] !== null && $value < $n['prev']) {
				throw new ImplausibleReadingException("Der Wert {$fmt($value)} ist kleiner als der vorherige Zählerstand ({$fmt($n['prev'])}).");
			}
			if ($n['next'] !== null && $value > $n['next']) {
				throw new ImplausibleReadingException("Der Wert {$fmt($value)} ist größer als der nachfolgende Zählerstand ({$fmt($n['next'])}).");
			}
		}

		if ($id !== null) {
			$this->readings->update($id, $when->format('Y-m-d H:i:s'), $value);
		} else {
			$this->readings->insert($type, $when->format('Y-m-d H:i:s'), $value);
		}
	}
}
