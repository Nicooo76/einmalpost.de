<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\Base64Url;
use Einmalpost\SecretStore;
use Einmalpost\ValidationError;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Der Kern: anlegen, genau einmal ausliefern, danach nichts mehr.
 */
final class SecretStoreTest extends IntegrationTestCase
{
    private function store(): SecretStore
    {
        return new SecretStore($this->database());
    }

    // ------------------------------------------------------------------
    // Anlegen und Abrufen
    // ------------------------------------------------------------------

    public function testAngelegtesGeheimnisKommtUnveraendertZurueck(): void
    {
        $payload = random_bytes(284);

        $id = $this->store()->create($payload, 3600);

        self::assertSame(SecretStore::ID_LENGTH, strlen($id), 'Die ID ist 16 Byte lang.');
        self::assertSame($payload, $this->store()->consume(Base64Url::encode($id)));
    }

    public function testCreateLegtGenauEineZeileAn(): void
    {
        $this->store()->create(random_bytes(284), 3600);

        self::assertSame(1, $this->zeilenZahl('secrets'));
    }

    /**
     * Zusage 3, erste Hälfte: höchstens einmal. Der zweite Abruf findet nichts.
     */
    public function testZweiterAbrufLiefertNichts(): void
    {
        $id      = Base64Url::encode($this->store()->create(random_bytes(284), 3600));
        $store   = $this->store();

        self::assertNotNull($store->consume($id), 'Der erste Abruf liefert das Geheimnis.');
        self::assertNull($store->consume($id), 'Der zweite Abruf darf nichts mehr liefern.');
        self::assertNull($store->consume($id), 'Auch der dritte nicht.');
    }

    public function testAbrufLoeschtDieZeile(): void
    {
        $id = Base64Url::encode($this->store()->create(random_bytes(284), 3600));

        $this->store()->consume($id);

        self::assertSame(0, $this->zeilenZahl('secrets'), 'Nach dem Abruf ist die Zeile weg.');
    }

    // ------------------------------------------------------------------
    // Zusage 9: Abgelaufenes wird nicht ausgeliefert, auch ohne Cron
    // ------------------------------------------------------------------

    public function testAbgelaufenesGeheimnisWirdNichtAusgeliefertObwohlEsNochDaLiegt(): void
    {
        $id = $this->store()->create(random_bytes(284), 3600);
        $this->setzeAblauf($id, '-1 SECOND');

        self::assertSame(1, $this->zeilenZahl('secrets'), 'Die Zeile liegt noch da - kein Cron lief.');
        self::assertNull(
            $this->store()->consume(Base64Url::encode($id)),
            'Eine abgelaufene Zeile darf trotzdem nicht ausgeliefert werden.'
        );
    }

    public function testEineSekundeVorAblaufWirdNochAusgeliefert(): void
    {
        $id = $this->store()->create(random_bytes(284), 3600);
        $this->setzeAblauf($id, '+1 SECOND');

        self::assertNotNull($this->store()->consume(Base64Url::encode($id)));
    }

    public function testGenauAbgelaufenGiltAlsAbgelaufen(): void
    {
        $id = $this->store()->create(random_bytes(284), 3600);
        // Exakt jetzt: die Bedingung lautet expires_at > UTC_TIMESTAMP().
        $this->pdo()->prepare('UPDATE secrets SET expires_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$id]);

        self::assertNull($this->store()->consume(Base64Url::encode($id)));
    }

    // ------------------------------------------------------------------
    // Zusage 12: Grenzwerte
    // ------------------------------------------------------------------

    public function testPayloadMitNullBytesWirdAbgelehnt(): void
    {
        $this->expectException(ValidationError::class);
        $this->store()->create('', 3600);
    }

    public function testPayloadMitEinemByteWirdAngenommen(): void
    {
        $id = $this->store()->create('x', 3600);

        self::assertSame('x', $this->store()->consume(Base64Url::encode($id)));
    }

    public function testPayloadMitGenauDemMaximumWirdAngenommen(): void
    {
        $payload = random_bytes(SecretStore::PAYLOAD_MAX_BYTES);

        $id = $this->store()->create($payload, 3600);

        self::assertSame($payload, $this->store()->consume(Base64Url::encode($id)));
    }

    /**
     * Die Grenze bleibt unter max_allowed_packet.
     *
     * MariaDB überträgt keine größeren Pakete, unabhängig vom Spaltentyp.
     * Läge die Grenze darüber, ließe sich ein erlaubter payload nicht
     * speichern - und der Fehler träte erst beim größten Anhang auf, den
     * jemand je schickt.
     */
    public function testDieGrenzeBleibtUnterDemPaketlimit(): void
    {
        $limit = (int) $this->query('SELECT @@max_allowed_packet')->fetchColumn();

        self::assertGreaterThan(
            SecretStore::PAYLOAD_MAX_BYTES,
            $limit,
            'max_allowed_packet ist kleiner als die erlaubte payload-Größe.'
        );
    }

    /**
     * Was ein Absender hineinlegen darf, muss mit Kopf und Auffüllung noch
     * in die payload-Grenze passen.
     */
    public function testDieNutzlastPasstMitAllemDrumHerumInDieGrenze(): void
    {
        // Kopf des Klartexts: Typ, Länge des Namens, Name, Länge des Inhalts.
        // Großzügig gerechnet mit einem Namen von 255 Byte.
        $kopf = 1 + 2 + 255 + 4;
        $gepackt = SecretStore::NUTZLAST_MAX_BYTES + $kopf;
        $gefuellt = (int) (ceil($gepackt / 256) * 256);

        // payload = Version(1) + Salz(16) + IV(12) + Schlüsseltext + Tag(16)
        $payload = 1 + 16 + 12 + $gefuellt + 16;

        self::assertLessThanOrEqual(
            SecretStore::PAYLOAD_MAX_BYTES,
            $payload,
            sprintf(
                'Eine Nutzlast von %d Byte ergibt einen payload von %d Byte und passt damit '
                . 'nicht in die Grenze von %d.',
                SecretStore::NUTZLAST_MAX_BYTES,
                $payload,
                SecretStore::PAYLOAD_MAX_BYTES
            )
        );
    }

    public function testPayloadEinByteUeberDemMaximumWirdAbgelehnt(): void
    {
        $this->expectException(ValidationError::class);
        $this->store()->create(random_bytes(SecretStore::PAYLOAD_MAX_BYTES + 1), 3600);
    }

    public function testZuGrosserPayloadLegtKeineZeileAn(): void
    {
        try {
            $this->store()->create(random_bytes(SecretStore::PAYLOAD_MAX_BYTES + 1), 3600);
        } catch (ValidationError) {
            // erwartet
        }

        self::assertSame(0, $this->zeilenZahl('secrets'));
    }

    // ------------------------------------------------------------------
    // TTL-Whitelist
    // ------------------------------------------------------------------

    /**
     * @return list<array{int}>
     */
    public static function erlaubteTtl(): array
    {
        return [[3600], [86400], [604800]];
    }

    #[DataProvider('erlaubteTtl')]
    public function testErlaubteLebensdauerWirdAngenommen(int $ttl): void
    {
        $id = $this->store()->create('x', $ttl);

        self::assertSame(SecretStore::ID_LENGTH, strlen($id));
    }

    /**
     * @return list<array{int}>
     */
    public static function verboteneTtl(): array
    {
        return [[0], [-1], [1], [60], [3599], [3601], [86399], [604799], [604801], [PHP_INT_MAX]];
    }

    #[DataProvider('verboteneTtl')]
    public function testJedeAndereLebensdauerWirdAbgelehnt(int $ttl): void
    {
        $this->expectException(ValidationError::class);
        $this->store()->create('x', $ttl);
    }

    // ------------------------------------------------------------------
    // Zusage 19: Unbrauchbare Eingaben erzeugen keine Ausnahme
    // ------------------------------------------------------------------

    /**
     * @return list<array{string}>
     */
    public static function unbrauchbareIds(): array
    {
        return [
            ['' ],
            ['kurz'],
            ['++++'],
            ['../../etc/passwd'],
            ["' OR 1=1 --"],
            ['AAAAAAAAAAAAAAAAAAAAAA'],                       // gültiges Format, unbekannt
            [str_repeat('A', 10000)],
            ["\x00\x01\x02"],
            ['<script>alert(1)</script>'],
            ['%2e%2e%2f'],
            ['ÄÖÜ'],
            ['🔑'],
        ];
    }

    #[DataProvider('unbrauchbareIds')]
    public function testUnbrauchbareIdGibtNullUndKeineAusnahme(string $id): void
    {
        self::assertNull($this->store()->consume($id));
    }

    public function testUnbrauchbareIdLoeschtNichtsAnderes(): void
    {
        $this->store()->create('x', 3600);

        $this->store()->consume('../../etc/passwd');
        $this->store()->consume("' OR 1=1 --");
        $this->store()->consume('');

        self::assertSame(1, $this->zeilenZahl('secrets'), 'Fremde Zeilen bleiben unangetastet.');
    }

    // ------------------------------------------------------------------
    // Härtung der Verbindung
    // ------------------------------------------------------------------

    public function testDieVerbindungLaeuftImStrictModus(): void
    {
        $modus = $this->query('SELECT @@session.sql_mode')->fetchColumn();

        self::assertIsString($modus);
        self::assertStringContainsString('STRICT_ALL_TABLES', $modus);
    }

    public function testZuLangerWertWirdAbgelehntStattStillGekuerzt(): void
    {
        // Am Code vorbei, direkt in die Datenbank: Ohne Strict-Modus würde
        // MariaDB hier kürzen und Erfolg melden - ein zerstörter Wert, der
        // erst später auffällt.
        //
        // Geprüft an ip_hmac (BINARY(32)) statt an payload: Die
        // payload-Spalte ist ein LONGBLOB und endet erst bei vier Gigabyte;
        // dort ließe sich die Eigenschaft nur mit einem absurd großen Wert
        // zeigen. Die Einstellung gilt für die ganze Verbindung, nicht für
        // einzelne Spalten.
        $anweisung = $this->pdo()->prepare(
            'INSERT INTO rate_limits (ip_hmac, hits, expires_at) '
            . 'VALUES (?, 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3600 SECOND))'
        );
        $anweisung->bindValue(1, str_repeat('x', 64), PDO::PARAM_LOB);

        $this->expectException(PDOException::class);
        $anweisung->execute();
    }

    /**
     * Und die payload-Grenze hält die Datenbank ebenfalls ein - über den
     * CHECK-Constraint, nicht über die Spaltenbreite.
     */
    public function testUeberDerGrenzeLehntDieDatenbankAb(): void
    {
        $anweisung = $this->pdo()->prepare(
            'INSERT INTO secrets (id, payload, expires_at) '
            . 'VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3600 SECOND))'
        );
        $anweisung->bindValue(1, random_bytes(16), PDO::PARAM_LOB);
        $anweisung->bindValue(2, str_repeat('x', SecretStore::PAYLOAD_MAX_BYTES + 1), PDO::PARAM_LOB);

        $this->expectException(PDOException::class);
        $anweisung->execute();
    }

    // ------------------------------------------------------------------
    // Aufräumen
    // ------------------------------------------------------------------

    public function testPurgeLoeschtNurAbgelaufeneZeilen(): void
    {
        $frisch    = $this->store()->create('frisch', 3600);
        $abgelaufen = $this->store()->create('abgelaufen', 3600);
        $this->setzeAblauf($abgelaufen, '-1 SECOND');

        $geloescht = $this->store()->purgeExpired();

        self::assertSame(1, $geloescht);
        self::assertSame(1, $this->zeilenZahl('secrets'));
        self::assertSame('frisch', $this->store()->consume(Base64Url::encode($frisch)));
    }

    // ------------------------------------------------------------------
    // Hilfsmittel
    // ------------------------------------------------------------------

    private function setzeAblauf(string $id, string $intervall): void
    {
        $anweisung = $this->pdo()->prepare(
            'UPDATE secrets SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $intervall . ') WHERE id = ?'
        );
        $anweisung->bindValue(1, $id, PDO::PARAM_LOB);
        $anweisung->execute();
    }

    private function zeilenZahl(string $tabelle): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM ' . $tabelle)->fetchColumn();
    }
}
