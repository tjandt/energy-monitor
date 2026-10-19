<?php
/**
 * ReadingRepository.php – Zählerstände laden, interpolieren, speichern.
 *
 * Alle Zählerstände eines Zählertyps werden pro Request einmal geladen
 * (wenige hundert Zeilen) und im Speicher ausgewertet. Aus den Rohwerten wird
 * eine monoton wachsende "kumulierte" Reihe gebildet (Rücksprünge, z.B. bei
 * Zählerwechsel, zählen als Verbrauch 0). Zwischen zwei Ablesungen wird linear
 * interpoliert, d.h. der Verbrauch wird gleichmäßig auf die Tage dazwischen
 * verteilt. Dadurch sind Kalendermonate/-wochen auch dann korrekt bewertbar,
 * wenn nicht genau am Monatsersten abgelesen wurde.
 */

class ReadingRepository {

	private PDO $pdo;

	/** @var array<string, array{ts: int[], raw: float[], cum: float[], dates: string[]}> */
	private array $cache = [];

	public function __construct(PDO $pdo) {
		$this->pdo = $pdo;
	}

	private function load(string $type): array {
		if (!isset($this->cache[$type])) {
			$stmt = $this->pdo->prepare(
				'SELECT reading_date, counter_value FROM meter_readings
				 WHERE utility_type = ? ORDER BY reading_date ASC'
			);
			$stmt->execute([$type]);

			$series = ['ts' => [], 'raw' => [], 'cum' => [], 'dates' => []];
			$cum = 0.0;
			$prev = null;
			foreach ($stmt->fetchAll() as $row) {
				$ts = strtotime($row['reading_date']);
				$val = (float) $row['counter_value'];
				if ($prev !== null) {
					$cum += max(0.0, $val - $prev);
				}
				$prev = $val;
				$series['ts'][] = $ts;
				$series['raw'][] = $val;
				$series['cum'][] = $cum;
				$series['dates'][] = $row['reading_date'];
			}
			$this->cache[$type] = $series;
		}
		return $this->cache[$type];
	}

	public function count(string $type): int {
		return count($this->load($type)['ts']);
	}

	/** @return array{counter_value: string, reading_date: string}|null */
	public function last(string $type): ?array {
		$s = $this->load($type);
		$i = count($s['ts']) - 1;
		return $i < 0 ? null : ['counter_value' => (string) $s['raw'][$i], 'reading_date' => $s['dates'][$i]];
	}

	public function firstTimestamp(string $type): ?int {
		return $this->load($type)['ts'][0] ?? null;
	}

	public function lastTimestamp(string $type): ?int {
		$ts = $this->load($type)['ts'];
		return $ts ? $ts[count($ts) - 1] : null;
	}

	/**
	 * Kumulierter (interpolierter) Zählerstand zum Zeitpunkt $ts.
	 * null, wenn $ts vor der ersten Ablesung liegt; nach der letzten Ablesung
	 * bleibt der Wert konstant.
	 */
	public function valueAt(string $type, int $ts): ?float {
		$s = $this->load($type);
		$n = count($s['ts']);
		if ($n === 0 || $ts < $s['ts'][0]) return null;
		if ($ts >= $s['ts'][$n - 1]) return $s['cum'][$n - 1];

		// letzte Ablesung <= $ts per Binärsuche
		$lo = 0;
		$hi = $n - 1;
		while ($lo < $hi) {
			$mid = intdiv($lo + $hi + 1, 2);
			if ($s['ts'][$mid] <= $ts) $lo = $mid; else $hi = $mid - 1;
		}
		$t0 = $s['ts'][$lo];
		$t1 = $s['ts'][$lo + 1];
		if ($t1 === $t0) return $s['cum'][$lo];
		return $s['cum'][$lo] + ($s['cum'][$lo + 1] - $s['cum'][$lo]) * (($ts - $t0) / ($t1 - $t0));
	}

	/**
	 * Verbrauch im Intervall [$fromTs, $toTs]. Beginnt das Intervall vor der
	 * ersten Ablesung, ist der Verbrauch unbekannt und wird als 0 gewertet,
	 * außer $clamp ist gesetzt (dann wird auf den abgedeckten Bereich
	 * zugeschnitten).
	 */
	public function consumption(string $type, int $fromTs, int $toTs, bool $clamp = false): float {
		if ($clamp) {
			$first = $this->firstTimestamp($type);
			$last = $this->lastTimestamp($type);
			if ($first === null) return 0.0;
			$fromTs = max($fromTs, $first);
			$toTs = min($toTs, $last);
		}
		if ($toTs <= $fromTs) return 0.0;
		$a = $this->valueAt($type, $fromTs);
		$b = $this->valueAt($type, $toTs);
		if ($a === null || $b === null) return 0.0;
		return max(0.0, $b - $a);
	}

	/**
	 * Rohwerte der Ablesungen unmittelbar vor und nach $ts (für die
	 * Plausibilitätsprüfung). Eine Ablesung exakt zu $ts zählt als "same".
	 * @return array{prev: ?float, next: ?float, same: bool}
	 */
	public function neighbours(string $type, int $ts): array {
		$s = $this->load($type);
		$out = ['prev' => null, 'next' => null, 'same' => false];
		foreach ($s['ts'] as $i => $t) {
			if ($t === $ts) $out['same'] = true;
			elseif ($t < $ts) $out['prev'] = $s['raw'][$i];
			elseif ($out['next'] === null) $out['next'] = $s['raw'][$i];
		}
		return $out;
	}

	public function insert(string $type, string $datetime, float $value): void {
		$stmt = $this->pdo->prepare(
			'INSERT INTO meter_readings (utility_type, reading_date, counter_value) VALUES (?,?,?)'
		);
		$stmt->execute([$type, $datetime, $value]);
		unset($this->cache[$type]);
	}
}
