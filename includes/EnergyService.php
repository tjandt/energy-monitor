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
			$out[$key] = ['label' => $u['label'], 'unit' => $u['unit'], 'color' => $u['color'], 'icon' => $u['icon']];
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

	public function getDashboard(string $granularity = 'month'): array {
		Period::assertValid($granularity);
		$result = [];
		foreach ($this->getUtilityKeys() as $type) {
			$result[$type] = $this->getPeriodComparison($type, $granularity);
		}
		return $result;
	}

	/** Prognose der laufenden Periode inkl. Vergleich zu den letzten 2 Vorjahren. */
	public function getPeriodComparison(string $type, string $granularity): array {
		$this->assertValidType($type);
		Period::assertValid($granularity);
		$now = new DateTime();
		$meta = $this->utilities[$type];

		$forecast = $this->forecaster->currentPeriod($type, $granularity, $now);
		$lastReading = $this->readings->last($type);

		$compare = [];
		for ($i = 1; $i <= 2; $i++) {
			$refDate = (clone $now)->modify("-{$i} year");
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
			'current_label'      => 'Prognose ' . Period::label($granularity, $now),
			'estimate'           => $forecast['estimate'],
			'elapsed_fraction'   => $forecast['elapsed_fraction'],
			'seasonal_reference' => $forecast['seasonal_reference'],
			'compare'            => $compare,
			'trend'              => $this->calcTrend($forecast['estimate'], $compare[0]['value']),
			'weather_adjusted'   => $this->weatherAdjusted($type, $granularity, $now, $forecast),
			'cost_estimate'      => $this->periodCost($type, $forecast['estimate'], $now, $granularity),
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

		[$start] = Period::bounds($granularity, $now);
		$until = new DateTime($forecast['data_until']);
		[$prevStart, $prevEnd] = Period::bounds($granularity, (clone $now)->modify('-1 year'));
		$cut = (new DateTime())->setTimestamp((int) round(
			$prevStart->getTimestamp() + $forecast['elapsed_fraction'] * ($prevEnd->getTimestamp() - $prevStart->getTimestamp())
		));

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
