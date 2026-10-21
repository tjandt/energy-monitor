<?php
/**
 * TariffRepository.php – Tarife (CRUD) und Kostenberechnung.
 *
 * Preise sind in der DB in CENT hinterlegt und werden beim Rechnen in Euro
 * umgerechnet.
 */

require_once __DIR__ . '/Num.php';

class TariffRepository {

	private PDO $pdo;
	private array $utilities;
	private ?array $cache = null;
	/** false, solange sql/migrations/001_tariff_advance.sql nicht eingespielt ist */
	private bool $hasAdvance = true;

	public function __construct(PDO $pdo, array $utilities) {
		$this->pdo = $pdo;
		$this->utilities = $utilities;
	}

	public function all(): array {
		if ($this->cache === null) {
			try {
				$this->cache = $this->pdo->query(
					'SELECT id, utility_type, valid_from, valid_to, price_per_unit, base_price_monthly, advance_monthly
					 FROM tariffs ORDER BY utility_type, valid_from DESC'
				)->fetchAll();
			} catch (PDOException $e) {
				// Spalte advance_monthly fehlt noch (Migration nicht eingespielt) -> ohne Abschlag weiterarbeiten
				$this->hasAdvance = false;
				$this->cache = array_map(
					fn($r) => $r + ['advance_monthly' => 0],
					$this->pdo->query(
						'SELECT id, utility_type, valid_from, valid_to, price_per_unit, base_price_monthly
						 FROM tariffs ORDER BY utility_type, valid_from DESC'
					)->fetchAll()
				);
			}
		}
		return $this->cache;
	}

	public function save(array $data): void {
		$type = (string) ($data['utility_type'] ?? '');
		if (!isset($this->utilities[$type])) {
			throw new InvalidArgumentException("Unbekannter Zählertyp: $type");
		}

		$validFrom = (string) ($data['valid_from'] ?? '');
		if (!self::isDate($validFrom)) {
			throw new InvalidArgumentException('Gültig-ab-Datum fehlt oder ungültig');
		}
		$validTo = !empty($data['valid_to']) ? (string) $data['valid_to'] : null;
		if ($validTo !== null && (!self::isDate($validTo) || $validTo < $validFrom)) {
			throw new InvalidArgumentException('Gültig-bis-Datum ist ungültig oder liegt vor „Gültig von“');
		}

		$price = Num::parse((string) ($data['price_per_unit'] ?? ''));
		$base = Num::parse((string) ($data['base_price_monthly'] ?? '0'));
		if ($price === null || $price < 0 || $base === null || $base < 0) {
			throw new InvalidArgumentException('Preis und Grundgebühr müssen Zahlen ≥ 0 sein (in Cent)');
		}
		$id = !empty($data['id']) ? (int) $data['id'] : null;

		// Monatlicher Abschlag in EURO (nicht Cent) – nur für Zähler mit 'advance' => true
		$advance = 0.0;
		if (!empty($this->utilities[$type]['advance'])) {
			$advance = Num::parse((string) ($data['advance_monthly'] ?? '0')) ?? -1.0;
			if ($advance < 0) {
				throw new InvalidArgumentException('Der Abschlag muss eine Zahl ≥ 0 sein (in Euro)');
			}
		}
		$this->all(); // stellt $hasAdvance fest
		if ($advance > 0 && !$this->hasAdvance) {
			throw new RuntimeException('Spalte advance_monthly fehlt – bitte sql/migrations/001_tariff_advance.sql einspielen.');
		}

		if ($id) {
			$sql = 'UPDATE tariffs SET utility_type=?, valid_from=?, valid_to=?, price_per_unit=?, base_price_monthly=?'
				. ($this->hasAdvance ? ', advance_monthly=?' : '') . ' WHERE id=?';
			$params = [$type, $validFrom, $validTo, $price, $base];
			if ($this->hasAdvance) $params[] = $advance;
			$params[] = $id;
		} else {
			$sql = 'INSERT INTO tariffs (utility_type, valid_from, valid_to, price_per_unit, base_price_monthly'
				. ($this->hasAdvance ? ', advance_monthly' : '') . ') VALUES (?,?,?,?,?' . ($this->hasAdvance ? ',?' : '') . ')';
			$params = [$type, $validFrom, $validTo, $price, $base];
			if ($this->hasAdvance) $params[] = $advance;
		}
		$this->pdo->prepare($sql)->execute($params);
		$this->cache = null;
	}

	public function delete(int $id): void {
		$this->pdo->prepare('DELETE FROM tariffs WHERE id = ?')->execute([$id]);
		$this->cache = null;
	}

	/**
	 * Tarif, der an $date (Y-m-d) gültig ist, Preise in Euro.
	 * Bei mehreren Treffern gewinnt der zuletzt begonnene.
	 */
	/**
	 * Tage (Y-m-d), an denen sich für $type ein Tarif ändert: Beginn jedes
	 * Tarifs und der Tag nach seinem Ende. Aufsteigend, ohne Duplikate.
	 * @return string[]
	 */
	public function changeDates(string $type): array {
		$dates = [];
		foreach ($this->all() as $t) {
			if ($t['utility_type'] !== $type) continue;
			$dates[$t['valid_from']] = true;
			if ($t['valid_to'] !== null) {
				$dates[(new DateTime($t['valid_to']))->modify('+1 day')->format('Y-m-d')] = true;
			}
		}
		ksort($dates);
		return array_keys($dates);
	}

	/** Monatlicher Abschlag in Euro, der an $date gilt (0 = keiner hinterlegt). */
	public function advanceAt(string $type, string $date): float {
		$best = $this->best($type, $date);
		return $best ? (float) $best['advance_monthly'] : 0.0;
	}

	public function at(string $type, string $date): ?array {
		$best = $this->best($type, $date);
		if (!$best) return null;
		return [
			'price_per_unit'     => ((float) $best['price_per_unit']) / 100,
			'base_price_monthly' => ((float) $best['base_price_monthly']) / 100,
		];
	}

	private function best(string $type, string $date): ?array {
		$best = null;
		foreach ($this->all() as $t) {
			if ($t['utility_type'] !== $type || $t['valid_from'] > $date) continue;
			if ($t['valid_to'] !== null && $t['valid_to'] < $date) continue;
			if ($best === null || $t['valid_from'] > $best['valid_from']) $best = $t;
		}
		return $best;
	}

	/**
	 * Kosten für $consumption zum Tarif von $date. $monthsOfBaseFee legt fest,
	 * wie viele Monate Grundgebühr enthalten sind (0 = nur Arbeitspreis).
	 */
	public function cost(string $type, float $consumption, string $date, float $monthsOfBaseFee): ?float {
		$tariff = $this->at($type, $date);
		if (!$tariff) return null;
		return round($consumption * $tariff['price_per_unit'] + $tariff['base_price_monthly'] * $monthsOfBaseFee, 2);
	}

	private static function isDate(string $s): bool {
		$d = DateTime::createFromFormat('!Y-m-d', $s);
		return $d !== false && $d->format('Y-m-d') === $s;
	}
}
