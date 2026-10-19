<?php
/**
 * DegreeDays.php – Gradtagzahl (VDI 3807) aus den Tagesmitteltemperaturen.
 */

class DegreeDays {

	private PDO $pdo;
	private array $cfg;

	public function __construct(PDO $pdo, array $cfg) {
		$this->pdo = $pdo;
		$this->cfg = $cfg;
	}

	public function minDegreeDays(): float {
		return (float) $this->cfg['min_degree_days'];
	}

	/**
	 * Gradtage der Tage [$from, $to) (nur volle Tage). null, wenn zu wenige
	 * Tage Wetterdaten haben.
	 */
	public function between(DateTimeInterface $from, DateTimeInterface $to): ?float {
		$fromDay = DateTime::createFromInterface($from)->setTime(0, 0, 0);
		$toDay = DateTime::createFromInterface($to)->setTime(0, 0, 0);
		$days = (int) round(($toDay->getTimestamp() - $fromDay->getTimestamp()) / 86400);
		if ($days < 1) return null;

		$stmt = $this->pdo->prepare('SELECT avg_temp FROM weather_data WHERE date >= ? AND date < ? AND avg_temp IS NOT NULL');
		$stmt->execute([$fromDay->format('Y-m-d'), $toDay->format('Y-m-d')]);
		$rows = $stmt->fetchAll();
		if (count($rows) < $days * (float) $this->cfg['min_coverage']) return null;

		$gt = 0.0;
		foreach ($rows as $r) {
			$t = (float) $r['avg_temp'];
			if ($t < (float) $this->cfg['heating_limit']) $gt += (float) $this->cfg['indoor_temp'] - $t;
		}
		// fehlende Tage anteilig hochrechnen
		return $gt * $days / count($rows);
	}
}
