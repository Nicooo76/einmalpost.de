-- einmalpost.de - Schema.
--
-- Genau diese Tabellen und genau diese Spalten. Jede weitere Spalte, die
-- Rückschlüsse auf Personen oder Zeitpunkte erlaubt, ist ausgeschlossen:
-- kein created_at, keine ip, kein user_agent, kein subject, kein filename,
-- kein view_count, kein referrer.
--
-- Was nicht gespeichert wird, kann niemand herausverlangen.

SET SESSION sql_mode = 'STRICT_ALL_TABLES';

-- --------------------------------------------------------------------------
-- Die Geheimnisse selbst.
--
-- payload = iv(12) ‖ ciphertext ‖ tag(16), im Browser mit AES-256-GCM
-- verschlüsselt. Der Server kennt den Schlüssel nicht und kann diese Spalte
-- nicht lesen.
--
-- LONGBLOB, nicht MEDIUMBLOB: Die Nutzlast darf 16 MB groß sein, und mit
-- Versionsbyte, Salz, IV, Tag, Dateinamen und Auffüllung liegt der payload
-- darüber. MEDIUMBLOB endet bei 16.777.215 Byte und läge damit zu knapp.
--
-- Nach oben begrenzt MariaDB ohnehin über max_allowed_packet (16 MiB); die
-- Grenze hier bleibt bewusst darunter.
--
-- Der CHECK-Constraint hält die Grenze in der Datenbank fest, zusätzlich zur
-- harten Prüfung in PHP.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS secrets (
  id          BINARY(16) NOT NULL PRIMARY KEY,
  payload     LONGBLOB   NOT NULL,
  expires_at  DATETIME   NOT NULL,
  KEY idx_expires (expires_at),
  CONSTRAINT payload_hoechstens_16m CHECK (LENGTH(payload) <= 16500000)
) ENGINE=InnoDB DEFAULT CHARSET=binary;

-- --------------------------------------------------------------------------
-- Rate-Limit.
--
-- ip_hmac ist ein HMAC-SHA256 der IP-Adresse mit einem täglich wechselnden
-- Schlüssel, der aus dem Pepper der Konfiguration abgeleitet wird. Die
-- IP-Adresse selbst wird nirgends gespeichert. Nach einem Tageswechsel ist
-- der Bezug zur IP auch rechnerisch nicht mehr herstellbar, ohne den Pepper
-- zu kennen.
--
-- expires_at liegt eine Stunde in der Zukunft. Abgelaufene Zeilen räumt
-- derselbe Cron mit weg, der auch die Geheimnisse aufräumt.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  ip_hmac     BINARY(32)       NOT NULL PRIMARY KEY,
  hits        INT UNSIGNED     NOT NULL DEFAULT 0,
  expires_at  DATETIME         NOT NULL,
  KEY idx_rate_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=binary;
