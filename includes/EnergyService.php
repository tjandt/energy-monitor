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

/** Eingabe ist formal gültig, aber unplausibel – mit force=1 trotzdem speicherbar. */
class ImplausibleReadingException extends InvalidArgumentException {}

class EnergyService {

	private PDO $pdo;
	private array $cfg;
	private array $utilities;
	private ReadingRepository $readings;
	private TariffRepository $tariffs;
	private Forecaster $forecaster;

	public function __construct(?PDO $pdo = null, ?array $config = null) {
		if ($config !== null) Config::override($config);
		$full = Config::all();
		$this->pdo = $pdo ?? Database::getConnection();
		$this->cfg = $full['app'];
		$this->utilities = $full['utilities'];
		$this->readings = new ReadingRepository($this->pdo);
		$this->tariffs = new TariffRepository($this->pdo, $this->utilities);
		$this->forecaster = new Forecaster($this->readings, $this->cfg);
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
		if ($withForecast && $mode === 'usage') {
			// Monatswerte auf die Balkenbreite des Charts (Woche/Tag) umrechnen,
			// sonst wären Prognose- und Ist-Balken nicht vergleichbar.
			foreach ($this->getLongTermForecast($type)['forecast'] as $ym => $val) {
				$daysInMonth = (int) date('t', strtotime($ym . '-01'));
				$forecastLabels[] = Period::monthName((int) substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4);
				$forecastValues[] = round($val * ($weekly ? 7 : 1) / $daysInMonth, 2);
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
	// NEUEN ZÄHLERSTAND SPEICHERN
	// =====================================================================

	/**
	 * Speichert eine Ablesung. Ohne $force wird auf Plausibilität geprüft
	 * (kein Zählerstand kleiner als der vorherige bzw. größer als der
	 * nachfolgende, z.B. durch Zahlendreher oder falsches Komma); bei
	 * Zählerwechsel kann mit $force trotzdem gespeichert werden.
	 */
	public function saveReading(string $type, string $date, string $time, string $rawValue, bool $force = false): void {
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

		$n = $this->readings->neighbours($type, $when->getTimestamp());
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

		$this->readings->insert($type, $when->format('Y-m-d H:i:s'), $value);
	}
}
