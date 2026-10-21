-- Monatlicher Abschlag je Tarif (in Euro, nur für Strom genutzt).
-- Bestehende Installationen einmalig ausführen (vorher Backup!):
ALTER TABLE tariffs
	ADD COLUMN advance_monthly DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER base_price_monthly;
