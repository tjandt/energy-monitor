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

	public function __construct(PDO $pdo, array $utilities) {
		$this->pdo = $pdo;
		$this->utilities = $utilities;
	}

	public function all(): array {
		if ($this->cache === null) {
			$this->cache = $this->pdo->query(
				'SELECT id, utility_type, valid_from, valid_to, price_per_unit, base_price_monthly
				 FROM tariffs ORDER BY utility_type, valid_from DESC'
			)->fetchAll();
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

		if ($id) {
			$this->pdo->prepare(
				'UPDATE tariffs SET utility_type=?, valid_from=?, valid_to=?, price_per_unit=?, base_price_monthly=? WHERE id=?'
			)->execute([$type, $validFrom, $validTo, $price, $base, $id]);
		} else {
			$this->pdo->prepare(
				'INSERT INTO tariffs (utility_type, valid_from, valid_to, price_per_unit, base_price_monthly) VALUES (?,?,?,?,?)'
			)->execute([$type, $validFrom, $validTo, $price, $base]);
		}
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
	public function at(string $type, string $date): ?array {
		$best = null;
		foreach ($this->all() as $t) {
			if ($t['utility_type'] !== $type || $t['valid_from'] > $date) continue;
			if ($t['valid_to'] !== null && $t['valid_to'] < $date) continue;
			if ($best === null || $t['valid_from'] > $best['valid_from']) $best = $t;
		}
		if (!$best) return null;
		return [
			'price_per_unit'     => ((float) $best['price_per_unit']) / 100,
			'base_price_monthly' => ((float) $best['base_price_monthly']) / 100,
		];
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
