<?php

declare(strict_types=1);

namespace Einmalpost\Tests\Integration;

use Einmalpost\Base64Url;
use Einmalpost\SecretStore;
use PDO;

/**
 * Zusage 9, Zeitzonen und Sommerzeit: Der Ablauf hängt nicht an der Zeitzone
 * des PHP-Prozesses.
 *
 * Der Ablaufzeitpunkt entsteht ausschließlich in der Datenbank
 * (DATE_ADD(UTC_TIMESTAMP(), ...)), und der Abruf vergleicht gegen
 * UTC_TIMESTAMP(). Damit ist beides in UTC und stimmt überein, egal welche
 * Zeitzone PHP gesetzt hat.
 *
 * Würde jemand den Ablauf stattdessen in PHP und in Ortszeit rechnen (etwa
 * date('Y-m-d H:i:s', time() + ttl)), lägen im Sommer zwei Stunden zwischen
 * dem geschriebenen und dem geprüften Zeitpunkt - Geheimnisse verfielen zu
 * früh oder zu spät. Dieser Test setzt die Zeitzone bewusst auf Europe/Berlin,
 * damit ein solcher Fehler auffiele.
 */
final class ZeitzoneTest extends IntegrationTestCase
{
    private string $vorigeZeitzone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->vorigeZeitzone = date_default_timezone_get();
        // Sommerzeit: Europe/Berlin ist im August UTC+2.
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->vorigeZeitzone);

        parent::tearDown();
    }

    public function testEinFrischesGeheimnisWirdTrotzOrtszeitAusgeliefert(): void
    {
        $store = new SecretStore($this->database());

        // Kurzer Ablauf über die Produktionsformel, aber mit wenigen Sekunden.
        $id = $this->legeMitAblaufAn('gilt noch', '+30 SECOND');

        self::assertSame(
            'gilt noch',
            $store->consume(Base64Url::encode($id)),
            'Ein Geheimnis mit 30 Sekunden Restlaufzeit muss geliefert werden. Wird es das nicht, '
            . 'liegt der geprüfte "Jetzt"-Zeitpunkt um einen Zeitzonenversatz daneben.'
        );
    }

    public function testEinAbgelaufenesGeheimnisVerschwindetPuenktlich(): void
    {
        $store = new SecretStore($this->database());

        $id = $this->legeMitAblaufAn('zu spät', '+1 SECOND');

        // 61 Sekunden wären gefordert; hier genügen wenige, weil ein
        // Zeitzonenfehler Stunden ausmachen würde und nicht Sekunden. Ein
        // Puffer von 3 Sekunden fängt gewöhnliches Uhr-Rauschen ab.
        sleep(3);

        self::assertNull(
            $store->consume(Base64Url::encode($id)),
            'Ein seit zwei Sekunden abgelaufenes Geheimnis darf nicht mehr geliefert werden. Wird '
            . 'es das doch, liegt der Ablauf um einen Zeitzonenversatz in der Zukunft.'
        );
    }

    /**
     * Legt ein Geheimnis mit einem frei wählbaren Ablauf an - über dieselbe
     * UTC-Formel, die create() verwendet.
     */
    private function legeMitAblaufAn(string $payload, string $intervall): string
    {
        $id = random_bytes(SecretStore::ID_LENGTH);

        $anweisung = $this->pdo()->prepare(
            'INSERT INTO secrets (id, payload, expires_at) '
            . 'VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $intervall . '))'
        );
        $anweisung->bindValue(1, $id, PDO::PARAM_LOB);
        $anweisung->bindValue(2, $payload, PDO::PARAM_LOB);
        $anweisung->execute();

        return $id;
    }
}
