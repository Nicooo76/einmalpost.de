<?php

declare(strict_types=1);

namespace Einmalpost;

use PDO;

/**
 * Speichern und Verbrauchen von Geheimnissen.
 *
 * Der Server sieht hier ausschließlich Schlüsseltext. Er kennt weder den
 * Klartext noch den Schlüssel und kann den payload nicht lesen.
 */
final class SecretStore
{
    /** random_bytes(16), gespeichert als BINARY(16). */
    public const ID_LENGTH = 16;

    /**
     * Harte Obergrenze für den payload, zusätzlich zum CHECK in der Datenbank.
     *
     * MariaDB überträgt keine Pakete über max_allowed_packet, und der steht
     * auf 16 MiB (16.777.216 Byte). Ein größerer payload ließe sich gar
     * nicht erst schreiben - unabhängig vom Spaltentyp. Statt diese
     * serverweite Einstellung anzufassen, die alle Domains betrifft, bleibt
     * die Grenze darunter.
     */
    public const PAYLOAD_MAX_BYTES = 16_500_000;

    /**
     * Was ein Absender höchstens hineinlegen darf: 16 MB.
     *
     * Dezimal, nicht binär - das ist die Einheit, in der Dateigrößen
     * angezeigt werden. Der Rest bis zur payload-Grenze ist Platz für
     * Versionsbyte, Salz, IV, Tag, Dateinamen und die Auffüllung.
     */
    public const NUTZLAST_MAX_BYTES = 16_000_000;

    /** Ein leerer payload ist kein Geheimnis. */
    public const PAYLOAD_MIN_BYTES = 1;

    /** Genau diese drei Lebensdauern, alles andere wird abgelehnt. */
    public const ALLOWED_TTL = [3600, 86400, 604800];

    public function __construct(private readonly DatabaseAccess $database)
    {
    }

    /**
     * Legt ein Geheimnis an und gibt die rohe ID (16 Byte) zurück.
     *
     * Führt genau ein INSERT aus, sonst nichts. Kein Lesen, kein Zählen,
     * kein Protokollieren.
     *
     * @throws ValidationError
     */
    public function create(string $payload, int $ttl): string
    {
        $laenge = strlen($payload);

        if ($laenge < self::PAYLOAD_MIN_BYTES) {
            throw new ValidationError('payload ist leer.');
        }

        if ($laenge > self::PAYLOAD_MAX_BYTES) {
            throw new ValidationError('payload ist zu groß.');
        }

        if (!in_array($ttl, self::ALLOWED_TTL, true)) {
            throw new ValidationError('ttl ist nicht erlaubt.');
        }

        $id = random_bytes(self::ID_LENGTH);

        // Der Ablaufzeitpunkt entsteht in der Datenbank, nicht in PHP. So
        // hängt er nicht an der Zeitzone oder der Uhr des Webservers.
        $anweisung = $this->database->pdo()->prepare(
            'INSERT INTO secrets (id, payload, expires_at) '
            . 'VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))'
        );

        $anweisung->bindValue(1, $id, PDO::PARAM_LOB);
        $anweisung->bindValue(2, $payload, PDO::PARAM_LOB);
        $anweisung->bindValue(3, $ttl, PDO::PARAM_INT);
        $anweisung->execute();

        return $id;
    }

    /**
     * Verbraucht ein Geheimnis: liest und löscht es in einem einzigen,
     * atomaren Schritt.
     *
     * DELETE ... RETURNING erledigt beides in einer Anweisung. Ein SELECT
     * mit anschließendem DELETE hätte dazwischen ein Fenster von
     * Millisekunden, in dem zwei gleichzeitige Anfragen beide den Klartext
     * bekämen - und Mail-Gateways prüfen Links regelmäßig mehrfach und
     * parallel.
     *
     * expires_at wird in derselben Bedingung geprüft. Eine abgelaufene Zeile,
     * die das Aufräumen übersehen hat, wird dadurch nicht ausgeliefert.
     *
     * @param string $idEncoded Die ID aus dem Link, base64url.
     *
     * @return string|null Der payload, oder null. Null bedeutet "gibt es
     *                     nicht", "abgelaufen" oder "schon abgerufen" - und
     *                     zwar ununterscheidbar, weil alle drei Fälle
     *                     dieselbe Abfrage durchlaufen.
     */
    public function consume(string $idEncoded): ?string
    {
        $id = Base64Url::decode($idEncoded);

        if ($id === null || strlen($id) !== self::ID_LENGTH) {
            // Kein früher Ausstieg. Eine formal unbrauchbare ID läuft durch
            // dieselbe Abfrage wie eine gültige, damit sie sich zeitlich
            // nicht von einer unbekannten ID unterscheidet. Der Ersatzwert
            // ist zufällig und trifft daher praktisch sicher keine Zeile.
            $id = random_bytes(self::ID_LENGTH);
        }

        $anweisung = $this->database->pdo()->prepare(
            'DELETE FROM secrets WHERE id = ? AND expires_at > UTC_TIMESTAMP() RETURNING payload'
        );

        $anweisung->bindValue(1, $id, PDO::PARAM_LOB);
        $anweisung->execute();

        /** @var mixed $payload */
        $payload = $anweisung->fetchColumn();
        $anweisung->closeCursor();

        if (is_string($payload)) {
            return $payload;
        }

        // PDO liefert BLOB-Spalten je nach Treiber auch als Datenstrom.
        if (is_resource($payload)) {
            $inhalt = stream_get_contents($payload);

            return $inhalt === false ? null : $inhalt;
        }

        return null;
    }

    /**
     * Löscht abgelaufene Zeilen. Wird vom Cron aufgerufen.
     *
     * @return int Anzahl gelöschter Zeilen.
     */
    public function purgeExpired(): int
    {
        $pdo = $this->database->pdo();

        $geheimnisse = $pdo->exec('DELETE FROM secrets WHERE expires_at <= UTC_TIMESTAMP()');
        $limits      = $pdo->exec('DELETE FROM rate_limits WHERE expires_at <= UTC_TIMESTAMP()');

        return (int) $geheimnisse + (int) $limits;
    }
}
