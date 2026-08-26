-- Zweites Netz: räumt abgelaufene Zeilen auch dann weg, wenn der Cron
-- ausfällt oder abgeschaltet wurde.
--
-- ACHTUNG: Events laufen nur, wenn der Scheduler eingeschaltet ist.
-- Prüfen mit:  SELECT @@event_scheduler;
-- Einschalten (serverweit, in der my.cnf):  event_scheduler = ON
--
-- Auf dem Zielserver stand er beim Aufsetzen (2026-08-26) auf OFF. Solange
-- das so ist, gibt es nur ein Netz statt zwei. Der Test zu Zusage 10 prüft
-- diesen Zustand und wird rot, solange er aus ist.

DROP EVENT IF EXISTS einmalpost_aufraeumen;

-- Der Rumpf enthält mehrere Anweisungen. Ohne DELIMITER würde der Client
-- schon am ersten Semikolon abschneiden und ein halbes Event anlegen.
DELIMITER $$

CREATE EVENT einmalpost_aufraeumen
    ON SCHEDULE EVERY 10 MINUTE
    ON COMPLETION PRESERVE
    ENABLE
    COMMENT 'Entfernt abgelaufene Geheimnisse und Rate-Limit-Zeilen.'
    DO
        BEGIN
            DELETE FROM secrets     WHERE expires_at <= UTC_TIMESTAMP();
            DELETE FROM rate_limits WHERE expires_at <= UTC_TIMESTAMP();
        END$$

DELIMITER ;
