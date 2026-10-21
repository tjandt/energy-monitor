-- Energy Monitor – Datenbankschema (aus dem Code rekonstruiert; bitte mit der
-- produktiven DB abgleichen, bevor etwas angewendet wird).

CREATE TABLE IF NOT EXISTS meter_readings (
	id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	utility_type  VARCHAR(20)  NOT NULL,            -- Strom | Wasser | Warmwasser | Waerme
	reading_date  DATETIME     NOT NULL,
	counter_value DECIMAL(12,3) NOT NULL,
	KEY idx_type_date (utility_type, reading_date)
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS tariffs (
	id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	utility_type       VARCHAR(20)  NOT NULL,
	valid_from         DATE         NOT NULL,
	valid_to           DATE         NULL,
	price_per_unit     DECIMAL(10,4) NOT NULL,       -- Cent pro Einheit
	base_price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0, -- Cent pro Monat
	advance_monthly    DECIMAL(10,2) NOT NULL DEFAULT 0, -- Abschlag pro Monat in EURO (nur Strom), s. migrations/001
	KEY idx_type_from (utility_type, valid_from)
) CHARACTER SET utf8mb4;

CREATE TABLE IF NOT EXISTS weather_data (
	date     DATE PRIMARY KEY,
	avg_temp DECIMAL(5,1) NULL,
	min_temp DECIMAL(5,1) NULL,
	max_temp DECIMAL(5,1) NULL,
	station  VARCHAR(20)  NULL
) CHARACTER SET utf8mb4;

-- Empfehlung (optional): doppelte Ablesungen zum selben Zeitpunkt verhindern.
-- Vorher prüfen: SELECT utility_type, reading_date, COUNT(*) FROM meter_readings
--                GROUP BY 1,2 HAVING COUNT(*) > 1;
-- ALTER TABLE meter_readings ADD UNIQUE KEY uq_type_date (utility_type, reading_date);
