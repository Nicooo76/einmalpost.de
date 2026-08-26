-- Anhänge bis 16 MB: payload von MEDIUMBLOB auf LONGBLOB.
--
-- Vorher endete die Spalte bei 16.777.215 Byte und der CHECK bei 64 KB. Mit
-- Versionsbyte, Salz, IV, Tag, Dateinamen und Auffüllung liegt ein
-- ausgeschöpfter Anhang darüber.
--
-- Gefahrlos wiederholbar: Beide Schritte prüfen vorher den Ist-Zustand.

-- Alten CHECK entfernen, falls vorhanden.
SET @vorhanden := (
    SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'payload_hoechstens_64k'
);
SET @anweisung := IF(@vorhanden > 0,
    'ALTER TABLE secrets DROP CONSTRAINT payload_hoechstens_64k',
    'DO 0');
PREPARE schritt FROM @anweisung;
EXECUTE schritt;
DEALLOCATE PREPARE schritt;

-- Spaltentyp anheben.
ALTER TABLE secrets MODIFY payload LONGBLOB NOT NULL;

-- Neuen CHECK setzen, falls er noch fehlt.
SET @schonDa := (
    SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'payload_hoechstens_16m'
);
SET @anweisung := IF(@schonDa = 0,
    'ALTER TABLE secrets ADD CONSTRAINT payload_hoechstens_16m CHECK (LENGTH(payload) <= 16500000)',
    'DO 0');
PREPARE schritt FROM @anweisung;
EXECUTE schritt;
DEALLOCATE PREPARE schritt;
