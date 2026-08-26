<?php

declare(strict_types=1);

namespace Einmalpost;

use PDO;

/**
 * Rate-Limit für das Erstellen.
 *
 * Gespeichert wird ein HMAC der IP-Adresse mit einem täglich wechselnden
 * Schlüssel, nie die Adresse selbst. Nach einem Tageswechsel lässt sich der
 * Bezug zur IP auch rechnerisch nicht mehr herstellen, ohne den Pepper zu
 * kennen - und der steht in der Konfiguration außerhalb der Datenbank.
 *
 * Wer also die Datenbank erbeutet, hält 32 Byte Rauschen in der Hand.
 */
final class RateLimiter
{
    public const FENSTER_SEKUNDEN = 3600;

    public function __construct(
        private readonly DatabaseAccess $database,
        private readonly string $pepper,
        private readonly int $max,
    ) {
    }

    /**
     * Zählt einen Versuch und sagt, ob er noch erlaubt ist.
     */
    public function allow(string $clientIp): bool
    {
        $fingerabdruck = $this->fingerprint($clientIp);
        $pdo           = $this->database->pdo();

        // Hochzählen und Fenster erneuern in einer Anweisung. Ein
        // abgelaufenes Fenster beginnt dabei von vorn - das Rate-Limit läuft
        // damit von selbst ab, ohne dass jemand aufräumen müsste.
        $anweisung = $pdo->prepare(
            'INSERT INTO rate_limits (ip_hmac, hits, expires_at) '
            . 'VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)) '
            . 'ON DUPLICATE KEY UPDATE '
            . '  hits = IF(expires_at <= UTC_TIMESTAMP(), 1, hits + 1), '
            . '  expires_at = IF(expires_at <= UTC_TIMESTAMP(), '
            . '                  DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), expires_at)'
        );
        $anweisung->bindValue(1, $fingerabdruck, PDO::PARAM_LOB);
        $anweisung->bindValue(2, self::FENSTER_SEKUNDEN, PDO::PARAM_INT);
        $anweisung->bindValue(3, self::FENSTER_SEKUNDEN, PDO::PARAM_INT);
        $anweisung->execute();

        $lesen = $pdo->prepare('SELECT hits FROM rate_limits WHERE ip_hmac = ?');
        $lesen->bindValue(1, $fingerabdruck, PDO::PARAM_LOB);
        $lesen->execute();

        $treffer = (int) $lesen->fetchColumn();
        $lesen->closeCursor();

        return $treffer <= $this->max;
    }

    /**
     * Der gespeicherte Wert: HMAC-SHA256 der Adresse mit dem Tagesschlüssel.
     *
     * Der Tagesschlüssel wird aus dem Pepper und dem heutigen Datum in UTC
     * abgeleitet. Dieselbe Adresse ergibt morgen einen anderen Wert.
     */
    public function fingerprint(string $clientIp, ?string $tag = null): string
    {
        $tag ??= gmdate('Y-m-d');

        $tagesschluessel = hash_hmac('sha256', $tag, $this->pepper, true);

        return hash_hmac('sha256', $clientIp, $tagesschluessel, true);
    }
}
