<?php
/**
 * YearReportPdf.php – Jahresbericht als PDF (FPDF, Kernschriften).
 *
 * Erwartet die Struktur aus EnergyService::getYearReport(). Texte werden von
 * UTF-8 nach Windows-1252 umkodiert (deckt Umlaute und das €-Zeichen ab).
 */

require_once __DIR__ . '/vendor/fpdf/fpdf.php';

class YearReportPdf extends FPDF {

	private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
	private const MARGIN = 15;
	private const WIDTH = 180;

	private array $r;

	public function __construct(array $report) {
		parent::__construct('P', 'mm', 'A4');
		$this->r = $report;
		$this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
		$this->SetAutoPageBreak(true, 18);
		$this->AliasNbPages();
		$this->SetTitle('Energiebericht ' . $report['year'], true);
		$this->SetAuthor('Energy Monitor', true);
		$this->SetCreator('Energy Monitor', true);
		$this->build();
	}

	// ------------------------------------------------------------------
	// Hilfsfunktionen
	// ------------------------------------------------------------------

	private function t(string $s): string {
		if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
		return iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
	}

	private static function num(?float $n, int $d = 1): string {
		return $n === null ? '–' : number_format($n, $d, ',', '.');
	}

	private static function money(?float $n): string {
		return $n === null ? '–' : number_format($n, 2, ',', '.') . ' €';
	}

	private static function pct(?float $n): string {
		return $n === null ? '–' : ($n > 0 ? '+' : '') . number_format($n, 1, ',', '.') . ' %';
	}

	private function rgb(string $hex): array {
		$n = hexdec(ltrim($hex, '#'));
		return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
	}

	private function txt(float $w, float $h, string $s, string $align = 'L', bool $fill = false, int $ln = 0): void {
		$this->Cell($w, $h, $this->t($s), 0, $ln, $align, $fill);
	}

	private function font(string $style, float $size, array $color = [29, 29, 31]): void {
		$this->SetFont('Helvetica', $style, $size);
		$this->SetTextColor(...$color);
	}

	/** Farbe für Veränderungen: weniger Verbrauch/Kosten = gut (grün). */
	private function deltaColor(?float $pct): array {
		if ($pct === null || abs($pct) < 5) return [110, 110, 115];
		return $pct < 0 ? [30, 123, 60] : [196, 30, 58];
	}

	public function Footer(): void {
		$this->SetY(-12);
		$this->font('', 8, [134, 134, 139]);
		$this->txt(120, 5, 'Energy Monitor · Energiebericht ' . $this->r['year']);
		$this->txt(60, 5, 'Seite ' . $this->PageNo() . ' von {nb}', 'R');
	}

	// ------------------------------------------------------------------
	// Aufbau
	// ------------------------------------------------------------------

	private function build(): void {
		$this->overviewPage();
		foreach ($this->r['utilities'] as $u) {
			$this->utilityPage($u);
		}
	}

	private function overviewPage(): void {
		$r = $this->r;
		$this->AddPage();
		$this->font('B', 24);
		$this->txt(self::WIDTH, 11, 'Energiebericht ' . $r['year'], 'L', false, 1);
		$this->font('', 10, [134, 134, 139]);
		$stand = $r['data_until'] ? ' · Datenstand ' . date('d.m.Y', strtotime($r['data_until'])) : '';
		$this->txt(self::WIDTH, 6, 'Strom, Wasser und Wärme · erstellt am ' . date('d.m.Y', strtotime($r['generated_at'])) . $stand, 'L', false, 1);

		$partial = array_filter($r['utilities'], fn($u) => $u['complete_months'] < 12);
		if ($partial) {
			$this->Ln(3);
			$this->SetFillColor(255, 244, 229);
			$this->font('', 9, [140, 80, 0]);
			$this->SetX(self::MARGIN);
			$this->MultiCell(self::WIDTH, 5.5, $this->t('Hinweis: Das Jahr ist nicht für alle Zähler vollständig erfasst. Ausgewertet werden nur Monate, die durch Zählerstände lückenlos abgedeckt sind; Vorjahreswerte beziehen sich auf dieselben Monate.'), 0, 'L', true);
		}

		// Kennzahlen
		$this->Ln(5);
		$y = $this->GetY();
		$cmpDelta = $r['total_cmp_cost'] - $r['total_cmp_prev_cost'];
		$cmpPct = $r['total_cmp_prev_cost'] > 0 ? $cmpDelta / $r['total_cmp_prev_cost'] * 100 : null;
		$this->kpi(self::MARGIN, $y, 58, 'Gesamtkosten ' . $r['year'], self::money($r['total_cost']), [29, 29, 31]);
		$this->kpi(self::MARGIN + 61, $y, 58, 'Kosten Vorjahr (vergleichbar)', self::money($r['total_cmp_prev_cost'] > 0 ? $r['total_cmp_prev_cost'] : null), [29, 29, 31]);
		$this->kpi(self::MARGIN + 122, $y, 58, 'Veränderung', $cmpPct === null ? '–' : self::pct($cmpPct), $this->deltaColor($cmpPct));
		$this->SetY($y + 26);

		// Tabelle
		$this->font('B', 12);
		$this->txt(self::WIDTH, 8, 'Übersicht nach Zähler', 'L', false, 1);
		$w = [34, 14, 32, 32, 24, 22, 22];
		$this->tableHeader(['Zähler', 'Monate', 'Verbrauch', 'Vorjahr', 'Änderung', 'Kosten', 'Vorjahr'], $w, ['L', 'R', 'R', 'R', 'R', 'R', 'R']);
		foreach ($r['utilities'] as $u) {
			$hasCmp = $u['cmp_months'] > 0;
			$this->tableRow([
				$u['label'], (string) $u['complete_months'],
				self::num($u['usage'], 0) . ' ' . $u['unit'],
				$hasCmp ? self::num($u['cmp_prev_usage'], 0) . ' ' . $u['unit'] : '–',
				self::pct($hasCmp ? $u['change_pct'] : null),
				self::money($u['cost']),
				self::money($u['cmp_prev_cost']),
			], $w, ['L', 'R', 'R', 'R', 'R', 'R', 'R'], false, [4 => $this->deltaColor($hasCmp ? $u['change_pct'] : null)]);
		}

		// Auffälligkeiten
		$this->Ln(6);
		$this->font('B', 12);
		$this->txt(self::WIDTH, 8, 'Auffälligkeiten', 'L', false, 1);
		$this->font('', 10);
		$lines = $this->insights();
		if (!$lines) $lines = ['Keine Auffälligkeiten – für einen Vergleich liegen noch nicht genug Daten vor.'];
		foreach ($lines as $line) {
			$this->SetX(self::MARGIN);
			$this->MultiCell(self::WIDTH, 5.8, $this->t('•  ' . $line), 0, 'L');
			$this->Ln(1);
		}
	}

	private function insights(): array {
		$out = [];
		foreach ($this->r['utilities'] as $u) {
			if ($u['complete_months'] === 0) continue;
			$peak = null;
			foreach ($u['months'] as $i => $m) {
				if ($m['usage'] !== null && ($peak === null || $m['usage'] > $u['months'][$peak]['usage'])) $peak = $i;
			}
			$line = $u['label'] . ': höchster Verbrauch im ' . self::MONTHS[$peak] . ' (' . self::num($u['months'][$peak]['usage'], 0) . ' ' . $u['unit'] . ')';
			if ($u['cmp_months'] > 0 && $u['change_pct'] !== null) {
				$line .= '; ' . (abs($u['change_pct']) < 5 ? 'etwa unverändert' : ($u['change_pct'] > 0 ? 'Mehrverbrauch ' : 'Minderverbrauch ') . self::pct(abs($u['change_pct']))) . ' ggü. Vorjahr';
			}
			$out[] = $line . '.';
			if ($u['weather']) {
				$w = $u['weather'];
				$out[] = sprintf('%s witterungsbereinigt: %s je Gradtag (Vorjahr %s), das entspricht %s – bei %d statt %d Gradtagen.',
					$u['label'], self::num($w['specific'], 2) . ' ' . $u['unit'], self::num($w['specific_prev'], 2), self::pct($w['change_pct']), $w['gt'], $w['gt_prev']);
			}
			if ($u['advance'] && $u['advance']['balance'] !== null) {
				$b = $u['advance']['balance'];
				$out[] = sprintf('%s: Abschläge %s gegen tatsächliche Kosten %s – %s %s.', $u['label'],
					self::money($u['advance']['total']), self::money($u['cost']), $b < 0 ? 'Nachzahlung' : 'Guthaben', self::money(abs($b)));
			}
		}
		return $out;
	}

	private function utilityPage(array $u): void {
		$this->AddPage();
		$color = $this->rgb($u['color']);

		// Kopfband
		$this->SetFillColor(...$color);
		$this->Rect(self::MARGIN, self::MARGIN, self::WIDTH, 14, 'F');
		$this->SetXY(self::MARGIN + 5, self::MARGIN + 3);
		$this->font('B', 15, [255, 255, 255]);
		$this->txt(110, 8, $u['label']);
		$this->font('', 11, [255, 255, 255]);
		$this->txt(60, 8, (string) $this->r['year'], 'R');

		// Kennzahlen
		$y = self::MARGIN + 20;
		$hasCmp = $u['cmp_months'] > 0;
		$this->kpi(self::MARGIN, $y, 58, 'Verbrauch', self::num($u['usage'], 0) . ' ' . $u['unit'], [29, 29, 31],
			$u['complete_months'] < 12 ? $u['complete_months'] . ' von 12 Monaten' : null);
		$this->kpi(self::MARGIN + 61, $y, 58, 'Kosten', self::money($u['cost']), [29, 29, 31],
			$u['cost'] === null ? 'kein Tarif hinterlegt' : null);
		$this->kpi(self::MARGIN + 122, $y, 58, 'Veränderung Verbrauch', self::pct($hasCmp ? $u['change_pct'] : null), $this->deltaColor($hasCmp ? $u['change_pct'] : null),
			$hasCmp ? 'Vorjahr: ' . self::num($u['cmp_prev_usage'], 0) . ' ' . $u['unit'] : 'kein Vorjahr vorhanden');

		// Diagramm
		$this->SetY($y + 28);
		$this->font('B', 11);
		$this->txt(110, 7, 'Monatsverbrauch in ' . $u['unit']);
		$this->legend($u['label'], $color);
		$this->barChart(self::MARGIN, $this->GetY() + 9, self::WIDTH, 62, $u['months'], $color);

		// Tabelle
		$this->SetY($y + 28 + 7 + 9 + 62 + 8);
		$w = [34, 38, 38, 30, 40];
		$al = ['L', 'R', 'R', 'R', 'R'];
		$this->tableHeader(['Monat', 'Verbrauch', 'Vorjahr', 'Änderung', 'Kosten'], $w, $al);
		foreach ($u['months'] as $i => $m) {
			$pct = ($m['usage'] !== null && $m['prev_usage'] > 0) ? ($m['usage'] / $m['prev_usage'] - 1) * 100 : null;
			$this->tableRow([
				self::MONTHS[$i],
				$m['usage'] === null ? 'nicht erfasst' : self::num($m['usage'], 1) . ' ' . $u['unit'],
				$m['prev_usage'] === null ? '–' : self::num($m['prev_usage'], 1) . ' ' . $u['unit'],
				self::pct($pct),
				self::money($m['cost']),
			], $w, $al, $i % 2 === 1, [3 => $this->deltaColor($pct)]);
		}
		$this->tableRow(['Summe', self::num($u['usage'], 1) . ' ' . $u['unit'], $hasCmp ? self::num($u['cmp_prev_usage'], 1) . ' ' . $u['unit'] : '–',
			self::pct($hasCmp ? $u['change_pct'] : null), self::money($u['cost'])], $w, $al, false, [3 => $this->deltaColor($hasCmp ? $u['change_pct'] : null)], true);

		// Zusatzinfos
		$this->Ln(4);
		$this->font('', 9, [110, 110, 115]);
		if ($u['advance']) {
			$a = $u['advance'];
			$txt = 'Abschläge ' . $this->r['year'] . ': ' . self::money($a['total']) . ($a['balance'] === null
				? ' (Jahr noch nicht vollständig – kein Saldo)'
				: ' · tatsächliche Kosten ' . self::money($u['cost']) . ' · ' . ($a['balance'] < 0 ? 'Nachzahlung ' : 'Guthaben ') . self::money(abs($a['balance'])));
			$this->SetX(self::MARGIN);
			$this->MultiCell(self::WIDTH, 5, $this->t($txt), 0, 'L');
		}
		if ($u['weather']) {
			$w = $u['weather'];
			$this->SetX(self::MARGIN);
			$this->MultiCell(self::WIDTH, 5, $this->t(sprintf('Witterungsbereinigt (VDI 3807): %s je Gradtag bei %d Gradtagen; Vorjahr %s bei %d Gradtagen (%s).',
				self::num($w['specific'], 2) . ' ' . $u['unit'], $w['gt'], self::num($w['specific_prev'], 2), $w['gt_prev'], self::pct($w['change_pct']))), 0, 'L');
		}
	}

	// ------------------------------------------------------------------
	// Bausteine
	// ------------------------------------------------------------------

	private function kpi(float $x, float $y, float $w, string $label, string $value, array $valueColor, ?string $note = null): void {
		$this->SetFillColor(245, 245, 247);
		$this->Rect($x, $y, $w, 23, 'F');
		$this->SetXY($x + 3, $y + 2.5);
		$this->font('', 8, [134, 134, 139]);
		$this->txt($w - 6, 4, mb_strtoupper($label), 'L');
		$this->SetXY($x + 3, $y + 8);
		$this->font('B', 15, $valueColor);
		$this->txt($w - 6, 7, $value);
		if ($note) {
			$this->SetXY($x + 3, $y + 16.5);
			$this->font('', 8, [134, 134, 139]);
			$this->txt($w - 6, 4, $note);
		}
	}

	private function tableHeader(array $cols, array $w, array $align): void {
		$this->SetFillColor(242, 242, 247);
		$this->font('B', 8, [110, 110, 115]);
		$this->SetX(self::MARGIN);
		foreach ($cols as $i => $c) {
			$this->Cell($w[$i], 7, $this->t(mb_strtoupper($c)), 0, 0, $align[$i], true);
		}
		$this->Ln();
	}

	private function tableRow(array $cells, array $w, array $align, bool $zebra, array $colors = [], bool $bold = false): void {
		if ($zebra) $this->SetFillColor(250, 250, 252);
		$this->SetX(self::MARGIN);
		foreach ($cells as $i => $c) {
			$this->font($bold ? 'B' : '', 9, $colors[$i] ?? [29, 29, 31]);
			$this->Cell($w[$i], 6.2, $this->t($c), $bold && $i >= 0 ? 'T' : 0, 0, $align[$i], $zebra);
		}
		$this->Ln();
	}

	private function legend(string $label, array $color): void {
		$x = self::MARGIN + 112;
		$y = $this->GetY() + 2;
		$this->SetFillColor(...$color);
		$this->Rect($x, $y, 4, 3, 'F');
		$this->SetXY($x + 5, $y - 1);
		$this->font('', 8, [110, 110, 115]);
		$this->txt(26, 5, (string) $this->r['year']);
		$this->SetFillColor(200, 200, 205);
		$this->Rect($x + 24, $y, 4, 3, 'F');
		$this->SetXY($x + 29, $y - 1);
		$this->txt(26, 5, 'Vorjahr');
	}

	private function barChart(float $x, float $y, float $w, float $h, array $months, array $color): void {
		$max = 0.0;
		foreach ($months as $m) $max = max($max, (float) ($m['usage'] ?? 0), (float) ($m['prev_usage'] ?? 0));
		if ($max <= 0) {
			$this->SetXY($x, $y + $h / 2);
			$this->font('', 9, [134, 134, 139]);
			$this->txt($w, 5, 'Keine Verbrauchsdaten für dieses Jahr.', 'C');
			return;
		}

		// "schöne" Skala mit etwa 4–5 Teilstrichen
		$raw = $max / 4;
		$mag = 10 ** floor(log10($raw));
		$step = $mag;
		foreach ([1, 2, 2.5, 5, 10] as $f) { $step = $f * $mag; if ($step >= $raw) break; }
		$top = ceil($max / $step) * $step;

		$left = 14;
		$plotW = $w - $left;
		$plotH = $h - 6;
		$this->SetLineWidth(0.15);
		for ($v = 0; $v <= $top + 1e-9; $v += $step) {
			$yy = $y + $plotH - ($v / $top) * $plotH;
			$this->SetDrawColor(229, 229, 234);
			$this->Line($x + $left, $yy, $x + $w, $yy);
			$this->SetXY($x, $yy - 2);
			$this->font('', 7, [134, 134, 139]);
			$this->txt($left - 2, 4, self::num($v, $step < 1 ? 1 : 0), 'R');
		}

		$gw = $plotW / 12;
		$bw = $gw * 0.34;
		foreach ($months as $i => $m) {
			$gx = $x + $left + $i * $gw;
			if ($m['prev_usage'] !== null) {
				$bh = $m['prev_usage'] / $top * $plotH;
				$this->SetFillColor(200, 200, 205);
				$this->Rect($gx + $gw * 0.12, $y + $plotH - $bh, $bw, $bh, 'F');
			}
			if ($m['usage'] !== null) {
				$bh = $m['usage'] / $top * $plotH;
				$this->SetFillColor(...$color);
				$this->Rect($gx + $gw * 0.12 + $bw + 0.4, $y + $plotH - $bh, $bw, $bh, 'F');
			}
			$this->SetXY($gx, $y + $plotH + 1);
			$this->font('', 7, [134, 134, 139]);
			$this->txt($gw, 4, mb_substr(self::MONTHS[$i], 0, 3), 'C');
		}
	}
}
