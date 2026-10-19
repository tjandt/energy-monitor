<?php
/**
 * Period.php – Zeitraum-Hilfen (Woche/Monat/Jahr), rein funktional.
 */

final class Period {

	public const GRANULARITIES = ['week', 'month', 'year'];

	private const MONTHS = [1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun',
		7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez'];

	public static function assertValid(string $granularity): void {
		if (!in_array($granularity, self::GRANULARITIES, true)) {
			throw new InvalidArgumentException("Ungültige Granularität: $granularity");
		}
	}

	/**
	 * Start (inklusiv) und Ende (exklusiv) der Periode, die $ref enthält.
	 * @return array{0: DateTime, 1: DateTime}
	 */
	public static function bounds(string $granularity, DateTimeInterface $ref): array {
		$ref = DateTime::createFromInterface($ref);
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

	public static function label(string $granularity, DateTimeInterface $date): string {
		switch ($granularity) {
			case 'week':
				return 'KW ' . $date->format('W') . '/' . $date->format('o');
			case 'year':
				return $date->format('Y');
			case 'month':
			default:
				return self::monthName((int) $date->format('n')) . ' ' . $date->format('Y');
		}
	}

	public static function monthName(int $month): string {
		return self::MONTHS[$month];
	}

	/** Anteil der Periode (0..1), der bis $nowTs verstrichen ist. */
	public static function elapsedFraction(DateTimeInterface $start, DateTimeInterface $end, int $nowTs): float {
		$total = $end->getTimestamp() - $start->getTimestamp();
		if ($total <= 0) return 0.0;
		return max(0.0, min(1.0, ($nowTs - $start->getTimestamp()) / $total));
	}

	/** Faktor, mit dem eine monatliche Grundgebühr auf die Periode umgelegt wird. */
	public static function monthlyFeeFactor(string $granularity): float {
		switch ($granularity) {
			case 'week': return 7 / 30.44;
			case 'year': return 12.0;
			case 'month':
			default: return 1.0;
		}
	}
}
