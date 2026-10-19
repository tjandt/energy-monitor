# Energy Monitor

PHP/MySQL-Dashboard für Strom-, Kaltwasser-, Warmwasser- und Wärmezähler im Heimnetz
(Zählerstände erfassen, Verbrauch/Kosten auswerten, Prognosen, DWD-Wetter-Overlay).

## Struktur

```
index.php, tariffs.php      Seiten
api.php                     JSON-API (einziger AJAX-Einstiegspunkt)
weather_import.php          Wetterimport per CLI/Cron:  php weather_import.php [station] [ab-jahr]
config.php                  Konfiguration (eigene Werte in config.local.php, gitignored)
includes/                   Config, Database, EnergyService (Fassade), ReadingRepository,
                            TariffRepository, Forecaster, Period, Num, WeatherImporter
assets/                     css, js (common/dashboard/tariffs/icons), vendor/chart.umd.js (lokal)
sql/schema.sql              aus dem Code rekonstruiertes Schema (bitte gegen die echte DB prüfen)
tests/run.php               Tests:  php tests/run.php   (SQLite in-memory, kein MySQL nötig)
```

## Betrieb

* PHP ≥ 8.1 mit `pdo_mysql` und `zip`; Apache 2.4 (die `.htaccess`-Dateien sperren config, includes, tests, sql).
  Bei nginx o.ä. diese Pfade selbst sperren.
* DB-Zugang in `config.local.php` ablegen: `<?php return ['db' => ['user' => '…', 'pass' => '…']];`
* Schreibende API-Aufrufe verlangen POST + Header `X-Requested-With: XMLHttpRequest`.

## Rechenmodell (kurz)

* Zwischen zwei Ablesungen wird linear interpoliert – Monats-/Wochenwerte stimmen auch, wenn nicht genau zum
  Periodenwechsel abgelesen wurde. Zählerrücksprünge (Zählerwechsel) zählen als Verbrauch 0.
* Prognose laufende Periode: bisheriger Verbrauch + Restverbrauch aus dem Saisonprofil der Vorjahre,
  skaliert mit dem bisherigen Verlauf (siehe Kopfkommentar in `includes/Forecaster.php`).
* Langzeitprognose: Monatsprofil der letzten N Jahre × Niveau der letzten 12 Monate.
