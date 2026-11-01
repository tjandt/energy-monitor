<?php
/**
 * Num.php – Zahleneingaben robust parsen.
 */

final class Num {

	/**
	 * Akzeptiert deutsche ("1.234,5"), englische ("1234.5") und einfache
	 * ("1234") Schreibweisen. Ein einzelner Punkt wird nur dann als
	 * Tausendertrenner gewertet, wenn exakt drei Ziffern folgen UND kein Komma
	 * vorkommt ("1.234" = 1234, aber "12.5" = 12,5 und "0.123" = 0,123).
	 */
	public static function parse(string $raw): ?float {
		$s = trim(str_replace(["\u{00A0}", ' '], '', $raw));
		if ($s === '' || !preg_match('/^-?[\d.,]+$/', $s)) return null;

		if (str_contains($s, ',')) {
			// Komma = Dezimaltrenner, Punkte = Tausendertrenner
			$s = str_replace('.', '', $s);
			if (substr_count($s, ',') > 1) return null;
			$s = str_replace(',', '.', $s);
		} elseif (substr_count($s, '.') > 1) {
			// mehrere Punkte -> nur als Tausendertrenner sinnvoll
			if (!preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) return null;
			$s = str_replace('.', '', $s);
		} elseif (preg_match('/^-?[1-9]\d{0,2}\.\d{3}$/', $s)) {
			$s = str_replace('.', '', $s);
		}
		return is_numeric($s) ? (float) $s : null;
	}
}
