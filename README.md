# Energy Monitor

PHP/MySQL-Dashboard für Strom-, Kaltwasser-, Warmwasser- und Wärmezähler im Heimnetz
(Zählerstände erfassen, Verbrauch/Kosten auswerten, Prognosen, DWD-Wetter-Overlay).

## Struktur

```
index.php, tariffs.php      Seiten
login.php, logout.php       optionaler Passwortschutz (config.php → auth)
report.php                  Jahresbericht als PDF (report.php?year=2025)
api.php                     JSON-API (einziger AJAX-Einstiegspunkt)
weather_import.php          Wetterimport per CLI/Cron:  php weather_import.php [station] [ab-jahr]
config.php                  Konfiguration (eigene Werte in config.local.php, gitignored)
includes/                   Config, Database, EnergyService (Fassade), ReadingRepository,
                            TariffRepository, Forecaster, DegreeDays, Auth, Period, Num, WeatherImporter,
                            YearReportPdf (+ vendor/fpdf, FPDF 1.9, freie Lizenz)
assets/                     css, js (common/dashboard/tariffs/icons), vendor/chart.umd.js (lokal)
sql/schema.sql              aus dem Code rekonstruiertes Schema (bitte gegen die echte DB prüfen)
tests/run.php               Tests:  php tests/run.php   (SQLite in-memory, kein MySQL nötig)
```

## Betrieb

* PHP ≥ 8.1 mit `pdo_mysql`, `zip` und `mbstring`; Apache 2.4 (die `.htaccess`-Dateien sperren config, includes, tests, sql).
  Bei nginx o.ä. diese Pfade selbst sperren.
* **Update von älteren Versionen:** einmalig `sql/migrations/001_tariff_advance.sql` einspielen (Spalte `advance_monthly`
  für den Stromabschlag). Ohne Migration läuft die App weiter, nur der Abschlag lässt sich nicht speichern.
* DB-Zugang in `config.local.php` ablegen: `<?php return ['db' => ['user' => '…', 'pass' => '…']];`
* Schreibende API-Aufrufe verlangen POST + Header `X-Requested-With: XMLHttpRequest`.

## Rechenmodell (kurz)

* Zwischen zwei Ablesungen wird linear interpoliert – Monats-/Wochenwerte stimmen auch, wenn nicht genau zum
  Periodenwechsel abgelesen wurde. Zählerrücksprünge (Zählerwechsel) zählen als Verbrauch 0.
* Prognose laufende Periode: bisheriger Verbrauch + Restverbrauch aus dem Saisonprofil der Vorjahre,
  skaliert mit dem bisherigen Verlauf (siehe Kopfkommentar in `includes/Forecaster.php`).
* Langzeitprognose: Monatsprofil der letzten N Jahre × Niveau der letzten 12 Monate.

## Funktionen im Überblick

* **Zählerstände verwalten:** Button „Zählerstände“ (Liste je Zähler, Bearbeiten/Löschen mit denselben Plausibilitätsprüfungen).
* **Kostenprognose:** Im Verlauf-Chart bei „Kosten“ + „Prognose anzeigen“ (Tarif des Prognosemonats, sonst aktueller Tarif).
* **Witterungsbereinigung:** Zähler mit `degree_day_adjust` (Standard: Wärme) zeigen auf der Kachel den Verbrauch je Gradtag
  (VDI 3807, Gt20/15, Werte in `config.php → degree_days`) gegenüber demselben Zeitabschnitt im Vorjahr. Voraussetzung:
  importierte Wetterdaten; im Sommer (zu wenig Gradtage) wird nichts angezeigt.
* **Jahresvergleich:** Chart-Ansicht „Jahresvergleich“ – Monatswerte oder kumuliert, Verbrauch oder Kosten, letzte 3 Jahre.
* **Login (optional):** in `config.local.php` aktivieren:
  `<?php return ['auth' => ['enabled' => true, 'password_hash' => '…']];`
  Hash: `php -r "echo password_hash('MeinPasswort', PASSWORD_DEFAULT), PHP_EOL;"`.
  Nach 5 Fehlversuchen wird die IP 10 Minuten gesperrt. Bei aktiviertem Login ohne Hash wird jeder Zugriff abgewiesen.
  Hinweis: Das Passwort geht im Klartext über die Leitung – bei Zugriff über das Heimnetz hinaus unbedingt HTTPS verwenden.
* **Stromabschlag:** Im Tarif (nur Strom) lässt sich ein monatlicher Abschlag in Euro hinterlegen. Die Stromkachel zeigt die
  erwartete Nach-/Rückzahlung für das Abrechnungsjahr (Beginn: `billing_start_month` in `config.php`) und eine Abschlagsempfehlung.
  Berechnung: Ist-Verbrauch + Prognose gegen die Summe der Abschläge. Tarif- und Abschlagswechsel im Jahr werden tagesgenau
  berücksichtigt (das Jahr wird an Monatsgrenzen und Tarifwechseln in Abschnitte zerlegt, jeder mit seinem Arbeits-/Grundpreis);
  jeder Abschlag zählt mit dem Betrag, der an seinem Fälligkeitstag (`advance_due_day`, Standard 1.) gilt. Eine Änderung zum
  Stichtag legen Sie als neuen Tarif mit „Gültig von“ = Stichtag an (Preise können gleich bleiben).
* **Jahresbericht (PDF):** Button „Bericht“ → Jahr wählen. Übersicht aller Zähler, je Zähler Monatsdiagramm und -tabelle mit
  Vorjahresvergleich, Kosten, Abschlagssaldo (Strom) und Witterungsbereinigung (Wärme). Nur vollständig erfasste Monate zählen.
